<?php

namespace App\Services\Billing;

use App\Models\AuditLog;
use App\Models\Monitor;
use App\Models\Order;
use App\Models\User;
use App\Services\Alerting\Notifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Billing
{
    public static function enabled(): bool
    {
        return (bool) config('watchrex.billing.enabled');
    }

    public function gateway(): PaymentGateway
    {
        return match (config('watchrex.billing.gateway')) {
            'zarinpal' => new ZarinpalGateway,
            default => throw new \RuntimeException('Unknown payment gateway'),
        };
    }

    public function createOrder(User $user, string $plan, string $period): Order
    {
        $quote = Plans::quote($plan, $period);

        return Order::create([
            'number' => 'WRX-'.now()->format('ymd').'-'.strtoupper(Str::random(6)),
            'user_id' => $user->id,
            'plan' => $plan,
            'period' => $period,
            'subtotal' => $quote['subtotal'],
            'tax' => $quote['tax'],
            'amount' => $quote['amount'],
            'gateway' => config('watchrex.billing.gateway'),
        ]);
    }

    /**
     * Verifies a gateway callback exactly once (row lock) and activates the plan.
     *
     * @return array{0: Order, 1: bool, 2: ?string} order, success, error message
     */
    public function complete(Order $order, Request $request): array
    {
        return DB::transaction(function () use ($order, $request) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($order->isPaid()) {
                return [$order, true, null];
            }
            if ($order->status !== 'pending') {
                return [$order, false, $order->failure];
            }

            $result = $this->gateway()->verify($order, $request);

            if (! $result['ok']) {
                $order->update(['status' => $request->query('Status') === 'OK' ? 'failed' : 'canceled', 'failure' => mb_substr((string) $result['message'], 0, 255)]);

                return [$order, false, $result['message']];
            }

            $this->activate($order, $result['ref_id'], $result['card_pan']);

            return [$order->fresh(), true, null];
        });
    }

    /** Applies a paid order: renewals extend the current period, upgrades start now. */
    public function activate(Order $order, ?string $refId = null, ?string $cardPan = null): void
    {
        $user = $order->user;
        $months = Plans::PERIODS[$order->period];
        $renewal = $user->plan === $order->plan && $user->plan_expires_at?->isFuture();
        $starts = $renewal ? $user->plan_expires_at : now();
        $ends = $starts->copy()->addMonthsNoOverflow($months);

        $order->update([
            'status' => 'paid', 'paid_at' => now(), 'ref_id' => $refId, 'card_pan' => $cardPan,
            'period_starts_at' => $starts, 'period_ends_at' => $ends, 'failure' => null,
        ]);

        $user->forceFill(['plan' => $order->plan, 'plan_expires_at' => $ends, 'billing_notices' => null])->save();
        AuditLog::record('billing.paid', $order, ['plan' => $order->plan, 'amount' => $order->amount, 'ref' => $refId], $user->id);
    }

    /** Daily: renewal reminders, and downgrade to Free after the grace period. */
    public function enforceExpiry(): array
    {
        $stats = ['reminded' => 0, 'downgraded' => 0, 'paused' => 0];
        $notifier = app(Notifier::class);

        $users = User::whereNotNull('plan_expires_at')->where('plan', '!=', 'free')->where('role', '!=', 'admin')->get();
        foreach ($users as $user) {
            app()->setLocale($user->locale ?: config('app.locale'));
            $days = (int) floor(now()->diffInDays($user->plan_expires_at, false));
            $sent = $user->billing_notices ?? [];

            foreach ([7, 1] as $threshold) {
                $key = $user->plan_expires_at->toDateString().":{$threshold}";
                if ($days >= 0 && $days <= $threshold && ! in_array($key, $sent, true)) {
                    $notifier->userEvent($user, 'warning', 'WatchRex', __('Your :p plan expires in :d day(s). Renew to keep your monitors running.', ['p' => config("watchrex.plans.{$user->plan}.label"), 'd' => $days]), route('billing.index'));
                    $sent[] = $key;
                    $stats['reminded']++;
                }
            }
            $user->forceFill(['billing_notices' => $sent])->save();

            if ($user->plan_expires_at->lt(now()->subDays((int) config('watchrex.billing.grace_days')))) {
                $stats['paused'] += $this->downgrade($user);
                $stats['downgraded']++;
                $notifier->userEvent($user, 'warning', 'WatchRex', __('Your plan expired and your account was moved to the Free plan. Monitors above the Free limit were paused.'), route('billing.index'));
            }
        }

        return $stats;
    }

    /** Moves a user to Free and pauses the newest monitors above the Free limit. */
    public function downgrade(User $user): int
    {
        $user->forceFill(['plan' => 'free', 'plan_expires_at' => null])->save();
        $limit = (int) config('watchrex.plans.free.max_monitors');

        $excess = Monitor::where('user_id', $user->id)->where('is_active', true)->orderBy('id')->skip($limit)->take(PHP_INT_MAX)->pluck('id');
        Monitor::whereIn('id', $excess)->update(['is_active' => false, 'status' => 'paused']);
        AuditLog::record('billing.downgraded', $user, ['paused' => $excess->count()], $user->id);

        return $excess->count();
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Order;
use App\Models\User;
use App\Services\Billing\Billing;
use App\Services\Billing\Plans;
use App\Services\Format;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

class BillingController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        return view('billing.index', [
            'user' => $user,
            'plans' => config('watchrex.plans'),
            'enabled' => Billing::enabled(),
            'usage' => [
                'max_monitors' => $user->monitors()->count(),
                'max_servers' => $user->servers()->count(),
                'max_domains' => $user->domains()->count(),
                'max_status_pages' => $user->statusPages()->count(),
            ],
            'orders' => $user->orders()->latest()->limit(20)->get(),
            'vat' => config('watchrex.billing.vat_percent'),
            'yearlyMonths' => config('watchrex.billing.yearly_months'),
        ]);
    }

    public function checkout(Request $request, Billing $billing)
    {
        abort_unless(Billing::enabled(), 404);
        $data = $request->validate([
            'plan' => ['required', Rule::in(array_keys(Plans::purchasable()))],
            'period' => ['required', Rule::in(array_keys(Plans::PERIODS))],
        ]);

        $user = $request->user();
        // No silent downgrades while a higher plan is still paid for.
        if ($user->plan_expires_at?->isFuture() && Plans::rank($data['plan']) < Plans::rank($user->plan)) {
            return back()->with('error', __('You can switch to a lower plan after your current plan expires.'));
        }

        $order = $billing->createOrder($user, $data['plan'], $data['period']);
        AuditLog::record('billing.checkout', $order, ['plan' => $order->plan, 'amount' => $order->amount]);

        try {
            $url = $billing->gateway()->start($order, route('billing.callback', $order->number));
        } catch (Throwable $e) {
            report($e);
            $order->update(['status' => 'failed', 'failure' => mb_substr($e->getMessage(), 0, 255)]);

            return back()->with('error', __('Could not start the payment: :e', ['e' => $e->getMessage()]));
        }

        return redirect()->away($url);
    }

    /** Public: the gateway redirects here even if the session expired meanwhile. */
    public function callback(Request $request, string $number, Billing $billing)
    {
        $order = Order::with('user')->where('number', $number)->firstOrFail();
        $authority = $billing->gateway()->authority($request);
        abort_unless($authority && hash_equals((string) $order->authority, (string) $authority), 404);

        [$order, $ok, $error] = $billing->complete($order, $request);

        return redirect()->route('billing.index')->with($ok ? 'success' : 'error', $ok
            ? __('Payment successful (reference :r). Your :p plan is active until :d.', ['r' => $order->ref_id, 'p' => config("watchrex.plans.{$order->plan}.label"), 'd' => Format::date($order->period_ends_at, false)])
            : __('Payment was not completed: :e', ['e' => $error]));
    }

    public function invoice(Request $request, Order $order)
    {
        abort_unless($order->user_id === $request->user()->id || $request->user()->isAdmin(), 404);
        abort_unless($order->isPaid(), 404);

        return view('billing.invoice', ['order' => $order->load('user'), 'seller' => config('watchrex.billing.seller')]);
    }

    // ── Admin ────────────────────────────────────────────────────────────
    public function admin(Request $request)
    {
        $orders = Order::with('user:id,name,email')
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->latest()->paginate(40)->withQueryString();

        $paid = Order::where('status', 'paid');

        return view('admin.billing', [
            'orders' => $orders,
            'revenue' => [
                'month' => (clone $paid)->where('paid_at', '>=', now()->startOfMonth())->sum('amount'),
                'year' => (clone $paid)->where('paid_at', '>=', now()->startOfYear())->sum('amount'),
                'total' => (clone $paid)->sum('amount'),
                'tax' => (clone $paid)->where('paid_at', '>=', now()->startOfYear())->sum('tax'),
            ],
            'expiring' => User::whereNotNull('plan_expires_at')->whereBetween('plan_expires_at', [now(), now()->addDays(14)])->orderBy('plan_expires_at')->get(),
        ]);
    }

    /** Admin: activate a pending order paid offline (e.g. bank transfer). */
    public function markPaid(Request $request, Order $order, Billing $billing)
    {
        abort_unless($order->status === 'pending' || $order->status === 'failed', 422);
        $data = $request->validate(['reference' => ['required', 'string', 'max:100']]);
        $billing->activate($order->load('user'), 'manual:'.$data['reference']);
        AuditLog::record('billing.marked_paid', $order, ['reference' => $data['reference']]);

        return back()->with('success', __('Order activated.'));
    }
}

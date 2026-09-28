<?php

namespace App\Services\Billing;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/** Zarinpal payment gateway, REST API v4 (amounts in Rial). */
class ZarinpalGateway implements PaymentGateway
{
    private string $merchant;

    private string $base;

    public function __construct()
    {
        $cfg = config('watchrex.billing.zarinpal');
        $this->merchant = (string) $cfg['merchant_id'];
        $this->base = $cfg['sandbox'] ? 'https://sandbox.zarinpal.com' : 'https://payment.zarinpal.com';

        if ($this->merchant === '') {
            throw new RuntimeException('ZARINPAL_MERCHANT_ID is not configured');
        }
    }

    public function start(Order $order, string $callbackUrl): string
    {
        $response = Http::acceptJson()->timeout(20)->post($this->base.'/pg/v4/payment/request.json', [
            'merchant_id' => $this->merchant,
            'amount' => $order->amount,
            'currency' => 'IRR',
            'description' => "WatchRex {$order->plan} ({$order->period}) #{$order->number}",
            'callback_url' => $callbackUrl,
            'metadata' => ['email' => $order->user->email, 'order_id' => $order->number],
        ])->json();

        $authority = $response['data']['authority'] ?? null;
        if (($response['data']['code'] ?? null) !== 100 || ! $authority) {
            throw new RuntimeException(self::message($response['errors']['code'] ?? $response['data']['code'] ?? null));
        }

        $order->forceFill(['authority' => $authority])->save();

        return $this->base.'/pg/StartPay/'.$authority;
    }

    public function authority(Request $request): ?string
    {
        return $request->query('Authority');
    }

    public function verify(Order $order, Request $request): array
    {
        if ($request->query('Status') !== 'OK') {
            return ['ok' => false, 'ref_id' => null, 'card_pan' => null, 'message' => __('Payment was cancelled.')];
        }

        try {
            $response = Http::acceptJson()->timeout(20)->post($this->base.'/pg/v4/payment/verify.json', [
                'merchant_id' => $this->merchant,
                'amount' => $order->amount,
                'authority' => $order->authority,
            ])->json();
        } catch (Throwable $e) {
            report($e);

            return ['ok' => false, 'ref_id' => null, 'card_pan' => null, 'message' => __('Could not reach the payment gateway.')];
        }

        $code = $response['data']['code'] ?? $response['errors']['code'] ?? null;

        // 100 = verified now, 101 = already verified earlier (idempotent retry).
        if (in_array($code, [100, 101], true)) {
            return ['ok' => true, 'ref_id' => (string) ($response['data']['ref_id'] ?? ''), 'card_pan' => $response['data']['card_pan'] ?? null, 'message' => null];
        }

        return ['ok' => false, 'ref_id' => null, 'card_pan' => null, 'message' => self::message($code)];
    }

    private static function message(mixed $code): string
    {
        return match ((int) $code) {
            -9 => __('Payment gateway: invalid input.'),
            -10, -11 => __('Payment gateway: merchant is not active or invalid.'),
            -50, -51 => __('Payment failed or the amount does not match.'),
            -54 => __('Payment gateway: invalid authority.'),
            default => __('Payment gateway error (:c).', ['c' => $code ?? '?']),
        };
    }
}

<?php

namespace App\Services\Billing;

use App\Models\Order;
use Illuminate\Http\Request;

interface PaymentGateway
{
    /** Registers the payment and returns the URL the user is redirected to. Must set $order->authority. */
    public function start(Order $order, string $callbackUrl): string;

    /** Reads the order reference from the gateway callback request. */
    public function authority(Request $request): ?string;

    /**
     * Confirms the payment server-to-server.
     *
     * @return array{ok: bool, ref_id: ?string, card_pan: ?string, message: ?string}
     */
    public function verify(Order $order, Request $request): array;
}

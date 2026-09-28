<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'fa' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Invoice') }} {{ $order->number }}</title>
    <link rel="stylesheet" href="{{ asset('assets/app.css') }}?v={{ config('watchrex.version') }}">
    <script src="{{ asset('assets/app.js') }}?v={{ config('watchrex.version') }}" defer></script>
    <style>
        :root { --bg:#fff; --panel:#fff; --panel-2:#f7f9fc; --border:#e3e8ef; --border-2:#d3dae4; --text:#0f172a; --muted:#5b6b80; --faint:#94a3b8; color-scheme: light; }
        body { background:#fff; } .invoice { max-width: 820px; margin: 30px auto; padding: 36px; border: 1px solid var(--border); border-radius: 16px; }
        @media print { .no-print { display: none; } .invoice { border: 0; margin: 0; } }
    </style>
</head>
<body>
<div class="invoice">
    <div class="row between">
        <div class="row"><x-logo :size="44"/><div><div class="brand-name">Watch<b>Rex</b></div><div class="small muted">{{ $seller['name'] }}</div></div></div>
        <div style="text-align:end"><h1>{{ __('Invoice') }}</h1><div class="mono small">{{ $order->number }}</div><div class="small muted">{{ Fmt::date($order->paid_at, false) }}</div></div>
    </div>
    <hr>
    <div class="grid g-2">
        <div><div class="label">{{ __('Seller') }}</div><div><b>{{ $seller['name'] }}</b></div>
            @if ($seller['address'])<div class="small">{{ $seller['address'] }}</div>@endif
            @if ($seller['national_id'])<div class="small">{{ __('National ID') }}: {{ $seller['national_id'] }}</div>@endif
            @if ($seller['economic_code'])<div class="small">{{ __('Economic code') }}: {{ $seller['economic_code'] }}</div>@endif</div>
        <div><div class="label">{{ __('Customer') }}</div><div><b>{{ $order->user->name }}</b></div><div class="small ltr" style="text-align:start">{{ $order->user->email }}</div></div>
    </div>
    <table class="table mt" style="margin-top:24px">
        <thead><tr><th>{{ __('Description') }}</th><th>{{ __('Period') }}</th><th style="text-align:end">{{ __('Amount') }} ({{ __('Toman') }})</th></tr></thead>
        <tbody>
            <tr><td>WatchRex — {{ __(config('watchrex.plans.'.$order->plan.'.label', $order->plan)) }} ({{ $order->period === 'yearly' ? __('Yearly') : __('Monthly') }})</td>
                <td class="small">{{ Fmt::date($order->period_starts_at, false) }} — {{ Fmt::date($order->period_ends_at, false) }}</td>
                <td style="text-align:end">{{ \App\Models\Order::toman($order->subtotal) }}</td></tr>
            <tr><td colspan="2" style="text-align:end" class="muted">{{ __('VAT') }}</td><td style="text-align:end">{{ \App\Models\Order::toman($order->tax) }}</td></tr>
            <tr><td colspan="2" style="text-align:end"><b>{{ __('Total paid') }}</b></td><td style="text-align:end"><b>{{ \App\Models\Order::toman($order->amount) }}</b></td></tr>
        </tbody>
    </table>
    <p class="small muted mt">{{ __('Payment reference') }}: <span class="mono">{{ $order->ref_id }}</span> @if ($order->card_pan)· {{ __('Card') }}: <span class="mono ltr">{{ $order->card_pan }}</span>@endif</p>
    <button class="btn primary no-print mt" data-print>{{ __('Print / Save as PDF') }}</button>
</div>
</body>
</html>

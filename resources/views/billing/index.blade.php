@extends('layouts.app')
@section('title', __('Plan & billing'))
@section('content')
@php
    $current = $plans[$user->plan] ?? null;
    $limits = ['max_monitors' => __('Monitors'), 'max_servers' => __('Servers'), 'max_domains' => __('Domains'), 'max_status_pages' => __('Status pages')];
    $daysLeft = $user->plan_expires_at ? (int) floor(now()->diffInDays($user->plan_expires_at, false)) : null;
@endphp
<div class="page-head">
    <div><h1>💳 {{ __('Plan & billing') }}</h1>
        <div class="sub">{{ __('Current plan') }}: <b>{{ __($current['label'] ?? $user->plan) }}</b>
            @if ($user->plan_expires_at) · {{ __('valid until') }} <b>{{ Fmt::date($user->plan_expires_at, false) }}</b>
                <span class="badge {{ $daysLeft <= 7 ? 'warning' : 'up' }}">{{ $daysLeft < 0 ? __('expired') : trans_choice(':count day left|:count days left', $daysLeft) }}</span>
            @elseif ($user->isAdmin()) · {{ __('administrator (unlimited)') }}@endif
        </div>
    </div>
</div>

<div class="grid g-4">
    @foreach ($limits as $key => $label)
        @php $limit = $user->limit($key); @endphp
        <div class="card card-pad">
            <div class="row between small"><span class="muted">{{ $label }}</span><b>{{ $usage[$key] }} / {{ $limit ?? '∞' }}</b></div>
            <x-meter :value="$limit ? $usage[$key] / max(1, $limit) * 100 : 0" class="mt-s" />
        </div>
    @endforeach
</div>

<div class="row between mt" style="margin-top:28px">
    <h2>{{ __('Plans') }}</h2>
    @if ($enabled)
        <div class="seg" data-period-switch><a href="#" data-period="monthly" class="active">{{ __('Monthly') }}</a><a href="#" data-period="yearly">{{ __('Yearly') }} <span class="chip">{{ __(':n months free', ['n' => 12 - $yearlyMonths]) }}</span></a></div>
    @endif
</div>

<div class="grid g-4 mt">
    @foreach ($plans as $key => $plan)
        @php $isCurrent = $user->plan === $key; @endphp
        <div class="card card-pad plan-card {{ $isCurrent ? 'current' : '' }}">
            <div class="row between"><h3>{{ __($plan['label']) }}</h3>@if ($isCurrent)<span class="badge up">{{ __('Current') }}</span>@endif</div>
            <div class="plan-price mt-s">
                @if ($plan['price'] === null)
                    <b>{{ __('Contact us') }}</b>
                @elseif ($plan['price'] === 0)
                    <b>{{ __('Free') }}</b>
                @else
                    <b data-price-monthly>{{ number_format($plan['price']) }}</b><b data-price-yearly class="hidden">{{ number_format($plan['price'] * $yearlyMonths) }}</b>
                    <span class="muted small">{{ __('Toman') }} / <span data-label-monthly>{{ __('month') }}</span><span data-label-yearly class="hidden">{{ __('year') }}</span></span>
                @endif
            </div>
            <ul class="plan-features small">
                <li>{{ $plan['max_monitors'] ?? '∞' }} {{ __('monitors') }}</li>
                <li>{{ __('checks every :s s', ['s' => $plan['min_interval']]) }}</li>
                <li>{{ $plan['max_servers'] ?? '∞' }} {{ __('servers (agent)') }}</li>
                <li>{{ $plan['max_domains'] ?? '∞' }} {{ __('domains') }}</li>
                <li>{{ $plan['max_status_pages'] ?? '∞' }} {{ __('status pages') }}</li>
            </ul>
            @if ($enabled && ($plan['price'] ?? 0) > 0 && ! $user->isAdmin() && $user->canWrite())
                <form method="POST" action="{{ route('billing.checkout') }}">
                    @csrf
                    <input type="hidden" name="plan" value="{{ $key }}">
                    <input type="hidden" name="period" value="monthly" data-period-input>
                    <button class="btn {{ $isCurrent ? '' : 'primary' }}" style="width:100%">{{ $isCurrent ? __('Renew') : __('Choose :p', ['p' => __($plan['label'])]) }}</button>
                </form>
                <p class="tiny faint mt-s">{{ __('+ :v% VAT · secure payment via Zarinpal', ['v' => $vat]) }}</p>
            @elseif ($plan['price'] === null)
                <a class="btn" style="width:100%" href="{{ config('watchrex.vendor.url') }}" target="_blank" rel="noopener">{{ __('Contact sales') }}</a>
            @endif
        </div>
    @endforeach
</div>

<div class="card mt" style="margin-top:28px">
    <div class="card-head"><h2>{{ __('Payments & invoices') }}</h2></div>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>{{ __('Order') }}</th><th>{{ __('Plan') }}</th><th>{{ __('Amount') }}</th><th>{{ __('Status') }}</th><th>{{ __('Date') }}</th><th></th></tr></thead>
        <tbody>
        @forelse ($orders as $o)
            <tr>
                <td class="mono small">{{ $o->number }}</td>
                <td>{{ __(config('watchrex.plans.'.$o->plan.'.label', $o->plan)) }} · {{ $o->period === 'yearly' ? __('Yearly') : __('Monthly') }}</td>
                <td class="nowrap">{{ \App\Models\Order::toman($o->amount) }} {{ __('Toman') }}</td>
                <td><span class="badge {{ ['paid' => 'up', 'pending' => 'warning'][$o->status] ?? 'down' }}">{{ __(ucfirst($o->status)) }}</span>@if ($o->failure)<div class="tiny faint">{{ $o->failure }}</div>@endif</td>
                <td class="small muted">{{ Fmt::date($o->paid_at ?? $o->created_at) }}</td>
                <td>@if ($o->isPaid())<a class="btn sm" href="{{ route('billing.invoice', $o) }}" target="_blank">{{ __('Invoice') }}</a>@endif</td>
            </tr>
        @empty
            <tr><td colspan="6" class="muted">{{ __('No payments yet.') }}</td></tr>
        @endforelse
        </tbody>
    </table></div>
</div>
@endsection

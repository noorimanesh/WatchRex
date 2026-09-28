@extends('layouts.app')
@section('title', __('Billing'))
@section('content')
<div class="page-head"><div><h1>💳 {{ __('Billing') }}</h1><div class="sub">{{ config('watchrex.billing.enabled') ? __('Online payments are enabled (:g).', ['g' => config('watchrex.billing.gateway')]) : __('Online payments are disabled. Set WATCHREX_BILLING=true and ZARINPAL_MERCHANT_ID in .env.') }}</div></div></div>
<div class="grid g-4">
    <div class="card stat"><div class="label">{{ __('This month') }}</div><div class="value">{{ \App\Models\Order::toman($revenue['month']) }}</div><div class="hint">{{ __('Toman') }}</div></div>
    <div class="card stat"><div class="label">{{ __('This year') }}</div><div class="value">{{ \App\Models\Order::toman($revenue['year']) }}</div><div class="hint">{{ __('Toman') }}</div></div>
    <div class="card stat"><div class="label">{{ __('VAT collected this year') }}</div><div class="value">{{ \App\Models\Order::toman($revenue['tax']) }}</div><div class="hint">{{ __('Toman') }}</div></div>
    <div class="card stat"><div class="label">{{ __('All time') }}</div><div class="value">{{ \App\Models\Order::toman($revenue['total']) }}</div><div class="hint">{{ __('Toman') }}</div></div>
</div>
<div class="grid g-main mt">
    <div class="card">
        <div class="card-head"><h2>{{ __('Orders') }}</h2>
            <div class="seg">@foreach (['' => __('All'), 'paid' => __('Paid'), 'pending' => __('Pending'), 'failed' => __('Failed')] as $k => $l)<a href="?status={{ $k }}" class="{{ request('status', '') === $k ? 'active' : '' }}">{{ $l }}</a>@endforeach</div></div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>{{ __('Order') }}</th><th>{{ __('Customer') }}</th><th>{{ __('Plan') }}</th><th>{{ __('Amount') }}</th><th>{{ __('Status') }}</th><th></th></tr></thead>
            <tbody>
            @forelse ($orders as $o)
                <tr><td class="mono small">{{ $o->number }}<div class="faint">{{ Fmt::date($o->created_at) }}</div></td>
                    <td class="small">{{ $o->user->name ?? '—' }}<div class="muted ltr" style="text-align:start">{{ $o->user->email ?? '' }}</div></td>
                    <td class="small">{{ $o->plan }} · {{ $o->period }}</td>
                    <td class="nowrap">{{ \App\Models\Order::toman($o->amount) }}</td>
                    <td><span class="badge {{ ['paid' => 'up', 'pending' => 'warning'][$o->status] ?? 'down' }}">{{ __(ucfirst($o->status)) }}</span>@if ($o->ref_id)<div class="tiny mono faint">{{ $o->ref_id }}</div>@endif</td>
                    <td class="nowrap">
                        @if ($o->isPaid())<a class="btn sm" target="_blank" href="{{ route('billing.invoice', $o) }}">{{ __('Invoice') }}</a>
                        @elseif (in_array($o->status, ['pending', 'failed'], true))
                            <form method="POST" action="{{ route('admin.billing.paid', $o) }}" class="row" data-confirm="{{ __('Activate this order as paid?') }}">@csrf
                                <input class="input" name="reference" required placeholder="{{ __('Bank reference') }}" style="width:130px;padding:4px 8px"><button class="btn sm">{{ __('Mark paid') }}</button></form>
                        @endif
                    </td></tr>
            @empty
                <tr><td colspan="6" class="muted">{{ __('No orders yet.') }}</td></tr>
            @endforelse
            </tbody></table></div>
    </div>
    <div class="card">
        <div class="card-head"><h2>{{ __('Expiring in 14 days') }}</h2></div>
        <div class="card-body small">
            @forelse ($expiring as $u)
                <div class="row between" style="padding:4px 0"><a href="{{ route('admin.users.edit', $u) }}">{{ $u->name }}</a><span>{{ $u->plan }} · {{ Fmt::date($u->plan_expires_at, false) }}</span></div>
            @empty<span class="muted">—</span>@endforelse
        </div>
    </div>
</div>
{{ $orders->links('partials.pagination') }}
@endsection

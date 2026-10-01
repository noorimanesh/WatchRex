@extends('layouts.app')
@section('title', __('Administration'))
@section('content')
<div class="page-head">
    <div><h1>{{ __('Administration') }}</h1><div class="sub">{{ __('Platform-wide health, usage and items that need your attention.') }}</div></div>
    <div class="actions">
        <a class="btn" href="{{ route('admin.system') }}"><x-icon name="settings"/>{{ __('System health') }}</a>
        <a class="btn primary" href="{{ route('admin.users.create') }}"><x-icon name="plus"/>{{ __('New user') }}</a>
    </div>
</div>

@php $warnings = array_filter([
    ! $system['scheduler'] ? __('The scheduler is not running — checks are not being dispatched. Add the cron entry: * * * * * php artisan schedule:run') : null,
    $system['queue_failed'] ? __(':n failed queue jobs.', ['n' => $system['queue_failed']]) : null,
    $system['queue_pending'] !== null && $system['queue_pending'] > 500 ? __('Queue backlog: :n pending jobs. Add more queue workers.', ['n' => $system['queue_pending']]) : null,
    $system['debug'] ? __('APP_DEBUG is enabled in production.') : null,
    ! $system['https'] ? __('APP_URL does not use HTTPS.') : null,
]); @endphp
@foreach ($warnings as $w)<div class="alert warn">⚠ {{ $w }}</div>@endforeach

<div class="grid g-6">
    <a class="card stat" href="{{ route('admin.users.index') }}"><div class="label">{{ __('Users') }}</div><div class="value">{{ $stats['users'] }}</div><div class="hint">{{ $stats['users_active'] }} {{ __('active') }} · {{ $stats['admins'] }} {{ __('admins') }}</div></a>
    <div class="card stat"><div class="label">{{ __('New users (7 days)') }}</div><div class="value">{{ $stats['signups_7d'] }}</div><div class="hint">{{ $stats['active_7d'] }} {{ __('signed in this week') }}</div></div>
    <a class="card stat {{ $stats['monitors_down'] ? 'accent-down' : 'accent-up' }}" href="{{ route('monitors.index', ['status' => 'down']) }}"><div class="label">{{ __('Monitors') }}</div><div class="value">{{ $stats['monitors_active'] }}<small class="faint">/{{ $stats['monitors'] }}</small></div><div class="hint"><span class="text-down">{{ $stats['monitors_down'] }} {{ __('down') }}</span> · {{ $stats['monitors_warning'] }} {{ __('degraded') }}</div></a>
    <a class="card stat {{ $stats['servers_online'] < $stats['servers'] ? 'accent-warn' : '' }}" href="{{ route('servers.index') }}"><div class="label">{{ __('Servers online') }}</div><div class="value">{{ $stats['servers_online'] }}<small class="faint">/{{ $stats['servers'] }}</small></div><div class="hint">{{ $stats['status_pages'] }} {{ __('status pages') }}</div></a>
    <div class="card stat"><div class="label">{{ __('Checks (24h)') }}</div><div class="value">{{ number_format($stats['checks_24h']) }}</div><div class="hint">{{ __('Queue') }}: {{ $system['queue_driver'] }}{{ $system['queue_pending'] !== null ? ' · '.$system['queue_pending'].' '.__('pending') : '' }}</div></div>
    <a class="card stat" href="{{ route('admin.billing') }}"><div class="label">{{ __('Revenue this month') }}</div><div class="value">{{ number_format($stats['revenue_month']) }}</div><div class="hint">{{ __('Toman') }} · {{ $stats['orders_pending'] }} {{ __('pending orders') }}</div></a>
</div>

<div class="grid g-3 mt">
    <div class="card">
        <div class="card-head"><h2>{{ __('Plans') }}</h2></div>
        <div class="card-body">
            @php $max = max(1, $plans->max()); @endphp
            @foreach (config('watchrex.plans') as $key => $plan)
                <div class="row between small" style="margin-bottom:4px"><a href="{{ route('admin.users.index', ['plan' => $key]) }}">{{ __($plan['label']) }}</a><b>{{ $plans[$key] ?? 0 }}</b></div>
                <div class="meter" style="margin-bottom:10px"><span style="width:{{ round(($plans[$key] ?? 0) / $max * 100) }}%"></span></div>
            @endforeach
        </div>
    </div>
    <div class="card">
        <div class="card-head"><h2>{{ __('Needs attention') }}</h2></div>
        <div class="card-body">
            @foreach ($nearLimit as $u)
                <a href="{{ route('admin.users.edit', $u) }}" class="row between small" style="display:flex;padding:4px 0"><span class="truncate">{{ $u->name }}</span><span class="chip">{{ __('Monitors') }} {{ $u->monitors_count }}/{{ $u->limit('max_monitors') }}</span></a>
            @endforeach
            @foreach ($expiring as $u)
                <a href="{{ route('admin.users.edit', $u) }}" class="row between small" style="display:flex;padding:4px 0"><span class="truncate">{{ $u->name }}</span><span class="chip {{ $u->plan_expires_at->isPast() ? 'text-down' : 'text-warn' }}">{{ __('Plan expires') }} {{ Fmt::date($u->plan_expires_at, false) }}</span></a>
            @endforeach
            @if ($nearLimit->isEmpty() && $expiring->isEmpty())<p class="muted small">✓ {{ __('Nothing needs attention.') }}</p>@endif
        </div>
    </div>
    <div class="card">
        <div class="card-head"><h2>{{ __('Top users') }}</h2></div>
        <div class="card-body">
            @foreach ($topUsers as $u)
                <a href="{{ route('admin.users.edit', $u) }}" class="row between small" style="display:flex;padding:4px 0"><span class="truncate">{{ $u->name }} <span class="faint ltr">{{ $u->email }}</span></span><b>{{ $u->monitors_count }}</b></a>
            @endforeach
        </div>
    </div>
</div>

<div class="grid g-2 mt">
    <div class="card">
        <div class="card-head"><h2>{{ __('Newest users') }}</h2><a class="btn sm" href="{{ route('admin.users.index', ['sort' => 'newest']) }}">{{ __('All') }}</a></div>
        <div class="card-body">
            @foreach ($recentUsers as $u)
                <a href="{{ route('admin.users.edit', $u) }}" class="row between small" style="display:flex;padding:4px 0">
                    <span class="truncate {{ $u->is_active ? '' : 'faint' }}">{{ $u->name }} <span class="faint ltr">{{ $u->email }}</span></span>
                    <span class="muted">{{ $u->created_at?->diffForHumans() }}</span>
                </a>
            @endforeach
        </div>
    </div>
    <div class="card">
        <div class="card-head"><h2>{{ __('Recent activity') }}</h2><a class="btn sm" href="{{ route('admin.audit') }}">{{ __('Audit log') }}</a></div>
        <div class="card-body">
            @foreach ($audit as $l)
                <div class="row between small" style="padding:4px 0"><span class="truncate"><span class="badge {{ str_contains($l->action, 'failed') ? 'down' : '' }}">{{ $l->action }}</span> {{ $l->user->name ?? '—' }}</span><span class="muted nowrap">{{ $l->created_at?->diffForHumans() }}</span></div>
            @endforeach
        </div>
    </div>
</div>
@endsection

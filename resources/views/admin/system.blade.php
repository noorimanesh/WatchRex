@extends('layouts.app')
@section('title', __('System health'))
@section('content')
@php $c = $checks; @endphp
<div class="page-head"><div><h1>{{ __('System health') }}</h1><div class="sub">WatchRex {{ config('watchrex.version') }} · Laravel {{ $c['laravel'] }} · PHP {{ $c['php'] }}</div></div></div>
<div class="grid g-4">
    <div class="card stat {{ $c['scheduler'] ? '' : 'accent-down' }}"><div class="label">{{ __('Scheduler') }}</div><div class="value {{ $c['scheduler'] ? 'text-up' : 'text-down' }}">{{ $c['scheduler'] ? __('Running') : __('Stopped') }}</div><div class="hint">{{ $c['scheduler_last'] ? \Illuminate\Support\Carbon::createFromTimestamp($c['scheduler_last'])->diffForHumans() : __('never ran') }}</div></div>
    <div class="card stat"><div class="label">{{ __('Queue') }} ({{ $c['queue_driver'] }})</div><div class="value">{{ $c['queue_pending'] ?? '—' }}</div><div class="hint">{{ $c['queue_failed'] ?? 0 }} {{ __('failed jobs') }}</div></div>
    <div class="card stat"><div class="label">{{ __('Checks / minute') }}</div><div class="value">{{ $counts['checks_per_minute'] }}</div><div class="hint">{{ $counts['active_monitors'] }} {{ __('active monitors') }}</div></div>
    <div class="card stat"><div class="label">{{ __('Database') }} ({{ $c['db_driver'] }})</div><div class="value">{{ Fmt::bytes($c['db_size']) }}</div><div class="hint">{{ number_format($counts['heartbeats']) }} heartbeats</div></div>
</div>
<div class="grid g-2 mt">
    <div class="card card-pad"><h2 class="mb">{{ __('Security checklist') }}</h2>
        @foreach ([
            [! $c['debug'], __('APP_DEBUG is disabled')],
            [$c['https'], __('APP_URL uses HTTPS')],
            [$c['session_encrypt'], __('Session payload encrypted')],
            [config('watchrex.block_private_targets'), __('SSRF protection for tenant monitors')],
            [config('watchrex.security.csp'), __('Content-Security-Policy enabled')],
            [$c['opcache'], __('OPcache enabled (performance)')],
            [$c['exec'], __('exec() available for ICMP ping (TCP fallback otherwise)')],
            [$c['scheduler'], __('Cron: * * * * * php artisan schedule:run')],
        ] as [$ok, $label])
            <div class="row" style="padding:4px 0"><span class="{{ $ok ? 'text-up' : 'text-warn' }}">{{ $ok ? '✓' : '⚠' }}</span> {{ $label }}</div>
        @endforeach
    </div>
    <div class="card card-pad"><h2 class="mb">{{ __('Inventory') }}</h2>
        <dl class="kv">
            <dt>{{ __('Users') }}</dt><dd>{{ $counts['users'] }}</dd>
            <dt>{{ __('Monitors') }}</dt><dd>{{ $counts['monitors'] }}</dd>
            <dt>{{ __('Servers') }}</dt><dd>{{ $counts['servers'] }}</dd>
            <dt>{{ __('Domains') }}</dt><dd>{{ $counts['domains'] }}</dd>
            <dt>{{ __('Retention') }}</dt><dd>{{ config('watchrex.retention.heartbeats_days') }} {{ __('days raw') }} · {{ config('watchrex.retention.daily_stats_days') }} {{ __('days aggregated') }}</dd>
            <dt>{{ __('Probe location') }}</dt><dd>{{ config('watchrex.location') }}</dd>
            <dt>{{ __('Peak memory') }}</dt><dd>{{ Fmt::bytes($c['memory']) }}</dd>
        </dl>
    </div>
</div>
@endsection

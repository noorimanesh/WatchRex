@extends('layouts.app')
@section('title', __('Dashboard'))
@section('content')
<div class="page-head">
    <div>
        <h1>{{ __('Overview') }}</h1>
        <div class="sub">
            @if ($stats['down'] > 0)
                <span class="text-down">● {{ trans_choice(':count service is down|:count services are down', $stats['down']) }}</span>
            @elseif ($stats['warning'] > 0)
                <span class="text-warn">● {{ __('Some services are degraded') }}</span>
            @else
                <span class="text-up">● {{ __('All systems operational') }}</span>
            @endif
        </div>
    </div>
    <div class="actions">
        <a class="btn" href="{{ route('servers.create') }}"><x-icon name="server"/>{{ __('Add server') }}</a>
        <a class="btn primary" href="{{ route('monitors.create') }}"><x-icon name="plus"/>{{ __('New monitor') }}</a>
    </div>
</div>

<div class="grid g-6">
    <div class="card stat"><div class="label">{{ __('Monitors') }}</div><div class="value">{{ $stats['total'] }}</div><div class="hint">{{ $stats['paused'] }} {{ __('paused') }}</div></div>
    <div class="card stat accent-up"><div class="label"><span class="dot up"></span>{{ __('Up') }}</div><div class="value">{{ $stats['up'] }}</div><div class="hint">{{ $stats['maintenance'] }} {{ __('in maintenance') }}</div></div>
    <div class="card stat {{ $stats['down'] ? 'accent-down' : '' }}"><div class="label"><span class="dot down"></span>{{ __('Down') }}</div><div class="value">{{ $stats['down'] }}</div><div class="hint">{{ $stats['incidents_open'] }} {{ __('open incidents') }}</div></div>
    <div class="card stat {{ $stats['warning'] ? 'accent-warn' : '' }}"><div class="label"><span class="dot warning"></span>{{ __('Degraded') }}</div><div class="value">{{ $stats['warning'] }}</div><div class="hint">{{ __('slow, SSL or anomalies') }}</div></div>
    <div class="card stat"><div class="label">{{ __('Uptime (30 days)') }}</div><div class="value">{{ Fmt::uptime($stats['uptime_30d']) }}</div><div class="hint">{{ $stats['incidents_30d'] }} {{ __('incidents') }}</div></div>
    <div class="card stat"><div class="label">{{ __('Avg. response') }}</div><div class="value ltr" style="text-align:start">{{ Fmt::ms($stats['avg_response']) }}</div><div class="hint">{{ __('latest checks') }}</div></div>
</div>

<div class="grid g-6 mt">
    <a class="card stat {{ $stats['servers'] && $stats['servers_online'] < $stats['servers'] ? 'accent-down' : '' }}" href="{{ route('servers.index') }}"><div class="label"><x-icon name="server"/>{{ __('Servers online') }}</div><div class="value">{{ $stats['servers_online'] }}<small class="faint">/{{ $stats['servers'] }}</small></div><div class="hint">{{ __('agent heartbeat') }}</div></a>
    <a class="card stat {{ $stats['websites']['issues'] ? 'accent-warn' : '' }}" href="{{ route('groups.index', ['kind' => 'website']) }}"><div class="label">🌐 {{ __('Websites') }}</div><div class="value">{{ $stats['websites']['total'] }}</div><div class="hint">{{ $stats['websites']['issues'] ? __(':n with issues', ['n' => $stats['websites']['issues']]) : __('all healthy') }}</div></a>
    <a class="card stat {{ $stats['mail']['issues'] ? 'accent-down' : '' }}" href="{{ route('groups.index', ['kind' => 'mail']) }}"><div class="label">✉️ {{ __('Mail services') }}</div><div class="value">{{ $stats['mail']['total'] }}</div><div class="hint">{{ $stats['mail']['issues'] ? __(':n with issues', ['n' => $stats['mail']['issues']]) : __('all healthy') }}</div></a>
    <a class="card stat {{ $stats['incidents_today'] ? 'accent-warn' : '' }}" href="{{ route('incidents.index') }}"><div class="label">{{ __('Incidents today') }}</div><div class="value">{{ $stats['incidents_today'] }}</div><div class="hint">{{ __('MTTR') }}: {{ $stats['mttr'] !== null ? Fmt::duration($stats['mttr']) : '—' }}</div></a>
    <a class="card stat {{ $expiring->where('days', '<=', 7)->isNotEmpty() ? 'accent-down' : ($expiring->isNotEmpty() ? 'accent-warn' : '') }}" href="{{ route('domains.index') }}"><div class="label">{{ __('Expiring soon') }}</div><div class="value">{{ $expiring->count() }}</div><div class="hint">{{ __('SSL & domains') }}</div></a>
    <a class="card stat" href="{{ route('status-pages.index') }}"><div class="label">{{ __('Status pages') }}</div><div class="value">{{ $stats['status_pages'] }}</div><div class="hint">{{ __('public pages') }}</div></a>
</div>

<div class="card mt">
    <div class="card-head">
        <h2>{{ __('Response time — last 24 hours') }}</h2>
        <span class="small muted">{{ __(':n checks', ['n' => number_format($trend['checks'])]) }} · <span class="{{ $trend['failed'] ? 'text-down' : '' }}">{{ __(':n failed', ['n' => number_format($trend['failed'])]) }}</span></span>
    </div>
    <div class="card-body"><x-chart :chart="$trend['chart']" date-format="H:i" :height="140" /></div>
</div>

@if ($issues->isNotEmpty())
<div class="card mt" style="border-color:color-mix(in srgb, var(--down) 40%, var(--border))">
    <div class="card-head"><h2>⚠ {{ __('Needs attention') }}</h2><a class="btn sm" href="{{ route('monitors.index', ['status' => 'down']) }}">{{ __('All') }}</a></div>
    <div class="card-body" style="padding-top:0">
        @foreach ($issues as $m)
            <a href="{{ route('monitors.show', $m->id) }}" class="dash-issue">
                <x-status :status="$m->status" />
                <b class="truncate">{{ $m->name }}</b>
                <span class="small muted truncate" style="flex:1">{{ $m->last_message }}</span>
                <span class="chip">{{ $m->type->label() }}</span>
            </a>
        @endforeach
    </div>
</div>
@endif

@if ($groupRows->isNotEmpty())
<div class="grid g-main mt">
    <div class="card">
        <div class="card-head"><h2>{{ __('Group health') }}</h2><a class="btn sm" href="{{ route('groups.index') }}">{{ __('Groups') }}</a></div>
        <div class="group-tree">
            @foreach ($groupRows as $row)
                @php $g = $row['group']; $h = $row['health']; @endphp
                <a class="grow" href="{{ route('groups.show', $g) }}">
                    <div class="name" style="min-width:0"><span class="dot {{ $h['status'] ?? 'pending' }}"></span><span>{{ $g->icon() }}</span><b class="truncate">{{ $g->name }}</b></div>
                    <div class="small muted">{{ trans_choice(':count monitor|:count monitors', $h['total'] ?? 0) }}@if ($h['down'] ?? 0) · <span class="text-down">{{ __(':n down', ['n' => $h['down']]) }}</span>@endif</div>
                    <div class="metric"><b>{{ Fmt::uptime($h['uptime'] ?? null) }}</b><small>24h</small></div>
                    <div class="metric"><b class="ltr">{{ Fmt::ms($h['avg'] ?? null) }}</b><small>{{ __('avg') }}</small></div>
                </a>
            @endforeach
        </div>
    </div>
    <div class="card">
        <div class="card-head"><h2>{{ __('Slowest responses') }}</h2></div>
        <div class="card-body">
            @forelse ($slowest as $m)
                <a href="{{ route('monitors.show', $m->id) }}" class="row between" style="display:flex;padding:5px 0">
                    <span class="truncate">{{ $m->name }}</span><b class="ltr {{ $m->last_response_ms > 2000 ? 'text-warn' : '' }}">{{ Fmt::ms($m->last_response_ms) }}</b>
                </a>
            @empty
                <p class="muted small">{{ __('No data') }}</p>
            @endforelse
        </div>
    </div>
</div>
@endif

<div class="grid g-main mt">
    <div class="card">
        <div class="card-head">
            <h2>{{ __('Live status') }}</h2>
            <div class="row">
                <span class="muted small">{{ __('Auto-refresh 20s') }}</span>
                <a class="btn sm" href="{{ route('monitors.index') }}">{{ __('All monitors') }}</a>
            </div>
        </div>
        <div class="mon-list" data-live="{{ route('dashboard.live') }}" data-live-every="20">
            @include('monitors._grid', ['monitors' => $monitors])
        </div>
    </div>

    <div class="stack">
        <div class="card">
            <div class="card-head"><h2>{{ __('Servers') }}</h2><a class="btn sm" href="{{ route('servers.index') }}">{{ __('View') }}</a></div>
            <div class="card-body stack">
                @forelse ($servers as $s)
                    <a href="{{ route('servers.show', $s) }}" class="row between" style="display:flex">
                        <span class="row"><span class="dot {{ $s->isOnline() ? 'up' : 'down' }}"></span><b class="truncate">{{ $s->name }}</b></span>
                        <span class="small muted ltr">CPU {{ $s->stat('cpu', '—') }}% · RAM {{ $s->stat('ram', '—') }}% · {{ __('Disk') }} {{ $s->stat('disk', '—') }}%</span>
                    </a>
                @empty
                    <p class="muted small">{{ __('No servers yet. Install the lightweight agent to track CPU, RAM, disk, mail queue and logins.') }}</p>
                @endforelse
            </div>
        </div>

        <div class="card">
            <div class="card-head"><h2>{{ __('Expiring soon') }}</h2><a class="btn sm" href="{{ route('domains.index') }}">{{ __('Domains') }}</a></div>
            <div class="card-body">
                @forelse ($expiring as $e)
                    <a href="{{ $e['url'] }}" class="row between" style="display:flex;padding:5px 0">
                        <span class="row"><span class="chip">{{ $e['kind'] === 'ssl' ? 'SSL' : __('Domain') }}</span><span class="truncate ltr">{{ $e['name'] }}</span></span>
                        <b class="{{ $e['days'] <= 7 ? 'text-down' : 'text-warn' }}">{{ $e['days'] < 0 ? __('expired') : trans_choice(':count day|:count days', $e['days']) }}</b>
                    </a>
                @empty
                    <p class="muted small">✓ {{ __('No domain or certificate expires in the next weeks.') }}</p>
                @endforelse
            </div>
        </div>

        <div class="card">
            <div class="card-head"><h2>{{ __('Recent incidents') }}</h2><a class="btn sm" href="{{ route('incidents.index') }}">{{ __('All') }}</a></div>
            <div class="card-body">
                @forelse ($incidents as $i)
                    <a href="{{ route('incidents.show', $i) }}" style="display:block;padding:6px 0;border-bottom:1px solid var(--border)">
                        <div class="row between"><b class="truncate">{{ $i->title }}</b><span class="badge {{ $i->isOpen() ? $i->severity : 'up' }}">{{ $i->isOpen() ? __('Open') : __('Resolved') }}</span></div>
                        <div class="small muted">{{ Fmt::date($i->started_at) }} · {{ Fmt::duration($i->durationSeconds()) }}</div>
                    </a>
                @empty
                    <p class="muted small">🎉 {{ __('No incidents recorded.') }}</p>
                @endforelse
            </div>
        </div>
    </div>
</div>
@endsection

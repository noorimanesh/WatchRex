@extends('layouts.app')
@section('title', $server->name)
@section('content')
@php
    $online = $server->isOnline();
    $mailLatest = $server->stat('mail', []);
    $disks = $server->stat('disks', []);
    $accounts = collect($server->stat('accounts', []))->sortByDesc('disk_used_mb');
    $installCmd = 'curl -fsSL '.route('agent.installer').' | sudo bash -s -- '.($token ?? 'YOUR_AGENT_TOKEN').' '.$server->report_interval;
@endphp
<div class="page-head">
    <div>
        <div class="row wrap"><h1>{{ $server->name }}</h1><span class="badge {{ $online ? 'up' : 'down' }}"><span class="dot {{ $online ? 'up' : 'down' }}"></span>{{ $online ? __('Online') : __('Offline') }}</span><span class="chip">{{ $server->panelLabel() }}</span></div>
        <div class="sub ltr" style="text-align:start">{{ $server->hostname ?? '—' }} · {{ $server->ip ?? '—' }} · {{ $server->os ?? '' }} {{ $server->stat('kernel') ? '· '.$server->stat('kernel') : '' }}</div>
        <div class="small muted">{{ __('Last report') }}: {{ $server->last_seen_at?->diffForHumans() ?? __('never') }} @if ($server->stat('uptime')) · {{ __('Uptime') }} {{ Fmt::duration((int) $server->stat('uptime')) }}@endif @if ($server->agent_version) · agent {{ $server->agent_version }}@endif</div>
    </div>
    <div class="actions">
        @if ($monitor)<a class="btn" href="{{ route('monitors.show', $monitor) }}"><x-icon name="activity"/>{{ __('Uptime & alerts') }}</a>@endif
        <a class="btn" href="{{ route('servers.edit', $server) }}"><x-icon name="edit"/>{{ __('Thresholds') }}</a>
        <form method="POST" action="{{ route('servers.destroy', $server) }}" data-confirm="{{ __('Remove this server and its metrics?') }}">@csrf @method('DELETE')<button class="btn danger icon-btn"><x-icon name="trash"/></button></form>
    </div>
</div>

@if ($token || ! $server->last_seen_at)
<div class="card mb">
    <div class="card-head"><h2>⚡ {{ __('Install the agent') }}</h2>
        <form method="POST" action="{{ route('servers.token', $server) }}" data-confirm="{{ __('The current agent token will stop working. Continue?') }}">@csrf<button class="btn sm ghost">{{ __('New token') }}</button></form></div>
    <div class="card-body">
        @if ($token)
            <div class="alert warn small">{{ __('Copy the command now — the token is shown only once and stored hashed.') }}</div>
        @else
            <p class="small muted">{{ __('The token was shown once when the server was created. Generate a new one if you lost it.') }}</p>
        @endif
        <p class="small muted">{{ __('Run as root on the server (works on cPanel/WHM, DirectAdmin, Plesk, CyberPanel, Ubuntu, Debian, AlmaLinux, Rocky, CentOS):') }}</p>
        <div class="secret" id="install-cmd">{{ $installCmd }}</div>
        <button class="btn sm mt-s" data-copy="#install-cmd">{{ __('Copy') }}</button>
    </div>
</div>
@endif

@if ($problems)
    <div class="alert warn">⚠ {{ implode(' · ', $problems) }}</div>
@endif

<div class="card card-pad">
    <div class="row wrap between" style="gap:24px;justify-content:space-around">
        <x-gauge :value="$server->stat('cpu')" label="CPU" :crit="$server->threshold('cpu') ?? 90" />
        <x-gauge :value="$server->stat('ram')" label="RAM" :crit="$server->threshold('ram') ?? 90" />
        <x-gauge :value="$server->stat('disk')" :label="__('Disk /')" :crit="$server->threshold('disk') ?? 90" />
        <x-gauge :value="$server->stat('swap')" label="Swap" />
        <x-gauge :value="$server->stat('iowait')" label="IO wait" :warn="10" :crit="25" />
        <div class="gauge-wrap"><div style="font-size:24px;font-weight:800" class="ltr">{{ implode(' ', array_map(fn ($v) => $v ?? '—', $server->stat('load', []) ?: ['—'])) }}</div><span class="muted small">Load ({{ $server->stat('cores', '?') }} {{ __('cores') }})</span></div>
        <div class="gauge-wrap"><div style="font-size:15px;font-weight:700" class="ltr">↓ {{ Fmt::bytes($server->stat('net.rx_rate')) }}/s<br>↑ {{ Fmt::bytes($server->stat('net.tx_rate')) }}/s</div><span class="muted small">{{ __('Network') }}</span></div>
        <div class="gauge-wrap"><div style="font-size:22px;font-weight:800">{{ Fmt::bytes($server->stat('mem_used')) }}</div><span class="muted small">{{ __('of') }} {{ Fmt::bytes($server->stat('mem_total')) }} RAM</span></div>
    </div>
</div>

<div class="row between mt"><h2>{{ __('History') }}</h2><div class="seg">@foreach ([1, 6, 24, 72, 168] as $h)<a href="?hours={{ $h }}" class="{{ $hours === $h ? 'active' : '' }}">{{ $h < 24 ? $h.'h' : ($h / 24).'d' }}</a>@endforeach</div></div>
<div class="grid g-2 mt">
    @foreach (['cpu' => ['CPU %', '#10b981'], 'ram' => ['RAM %', '#6366f1'], 'disk' => [__('Disk %'), '#f59e0b'], 'load1' => ['Load', '#0ea5e9']] as $k => [$label, $color])
        <div class="card"><div class="card-head"><h3>{{ $label }}</h3></div><div class="card-body"><x-chart :chart="$charts[$k]" :height="140" :color="$color" :date-format="$hours > 24 ? 'm/d' : 'H:i'" /></div></div>
    @endforeach
</div>

@if ($server->stat('mail.mta') || $mail['login_ok'] || $mail['login_failed'])
<div class="grid g-main mt">
    <div class="card">
        <div class="card-head"><h2>📬 {{ __('Mail server') }} <span class="chip">{{ strtoupper($server->stat('mail.mta') ?? '—') }}</span></h2><span class="small muted">{{ __('last :h h', ['h' => $hours]) }}</span></div>
        <div class="card-body">
            <div class="grid g-4">
                <div class="stat" style="padding:0"><div class="label">{{ __('Queue now') }}</div><div class="value {{ ($mailLatest['queue'] ?? 0) >= ($server->threshold('mail_queue') ?? INF) ? 'text-down' : '' }}">{{ $mailLatest['queue'] ?? '—' }}</div></div>
                <div class="stat" style="padding:0"><div class="label">{{ __('Sent') }}</div><div class="value text-up">{{ number_format($mail['sent']) }}</div></div>
                <div class="stat" style="padding:0"><div class="label">{{ __('Received') }}</div><div class="value">{{ number_format($mail['received']) }}</div></div>
                <div class="stat" style="padding:0"><div class="label">{{ __('Bounced') }}</div><div class="value text-down">{{ number_format($mail['bounced']) }}</div></div>
                <div class="stat" style="padding:0"><div class="label">{{ __('Deferred') }}</div><div class="value text-warn">{{ number_format($mail['deferred']) }}</div></div>
                <div class="stat" style="padding:0"><div class="label">{{ __('Rejected') }}</div><div class="value">{{ number_format($mail['rejected']) }}</div></div>
                <div class="stat" style="padding:0"><div class="label">{{ __('Successful logins') }}</div><div class="value text-up">{{ number_format($mail['login_ok']) }}</div></div>
                <div class="stat" style="padding:0"><div class="label">{{ __('Failed logins') }}</div><div class="value text-down">{{ number_format($mail['login_failed']) }}</div></div>
            </div>
            @php $delivered = $mail['sent'] + $mail['bounced'] + $mail['deferred']; @endphp
            @if ($delivered)
                <div class="label mt">{{ __('Delivery ratio') }}</div>
                <div class="waterfall mt-s"><span style="width:{{ $mail['sent'] / $delivered * 100 }}%;background:var(--up)"></span><span style="width:{{ $mail['deferred'] / $delivered * 100 }}%;background:var(--warn)"></span><span style="width:{{ $mail['bounced'] / $delivered * 100 }}%;background:var(--down)"></span></div>
                <div class="legend"><span><i style="background:var(--up)"></i>{{ __('Delivered') }} {{ round($mail['sent'] / $delivered * 100, 1) }}%</span><span><i style="background:var(--warn)"></i>{{ __('Deferred') }}</span><span><i style="background:var(--down)"></i>{{ __('Bounced') }}</span></div>
            @endif
            <div class="label mt">{{ __('Mail queue') }}</div>
            <x-chart :chart="$charts['mail_queue']" :height="110" color="#f59e0b" :date-format="$hours > 24 ? 'm/d' : 'H:i'" />
            @if (! empty($mailLatest['recent_bounces']))
                <div class="label mt">{{ __('Recent bounces') }}</div>
                <pre class="mt-s" style="max-height:200px">@foreach ($mailLatest['recent_bounces'] as $b){{ $b }}
@endforeach</pre>
            @endif
        </div>
    </div>
    <div class="stack">
        <div class="card">
            <div class="card-head"><h3>🔐 {{ __('Top failed login IPs') }}</h3><span class="small muted">{{ __('last report') }}</span></div>
            <div class="card-body small">
                @forelse ($mailLatest['failed_ips'] ?? [] as $r)<div class="row between"><span class="ltr mono">{{ $r['ip'] }}</span><b>{{ $r['count'] }}</b></div>@empty<span class="muted">{{ __('None') }}</span>@endforelse
            </div>
        </div>
        <div class="card">
            <div class="card-head"><h3>👤 {{ __('Accounts with failed logins') }}</h3></div>
            <div class="card-body small">
                @forelse ($mailLatest['failed_users'] ?? [] as $r)<div class="row between"><span class="ltr truncate">{{ $r['user'] }}</span><b>{{ $r['count'] }}</b></div>@empty<span class="muted">{{ __('None') }}</span>@endforelse
            </div>
        </div>
        @if ($server->stat('ssh_failed'))
            <div class="card card-pad small">SSH: <b class="text-down">{{ $server->stat('ssh_failed') }}</b> {{ __('failed password attempts in the last interval') }}</div>
        @endif
    </div>
</div>
@endif

<div class="grid g-2 mt">
    <div class="card">
        <div class="card-head"><h3>💽 {{ __('Disks') }}</h3></div>
        <div class="table-wrap"><table class="table"><thead><tr><th>{{ __('Mount') }}</th><th>{{ __('Used') }}</th><th>{{ __('Free') }}</th><th style="width:30%">%</th><th>Inodes</th></tr></thead><tbody>
            @forelse ($disks as $disk)
                <tr><td class="ltr mono">{{ $disk['mount'] }}</td><td>{{ Fmt::bytes($disk['used']) }}</td><td>{{ Fmt::bytes(($disk['total'] ?? 0) - ($disk['used'] ?? 0)) }}</td>
                    <td><div class="row"><x-meter :value="$disk['percent']" style="flex:1" /><b>{{ $disk['percent'] }}%</b></div></td><td>{{ $disk['inodes'] !== null ? $disk['inodes'].'%' : '—' }}</td></tr>
            @empty<tr><td colspan="5" class="muted">—</td></tr>@endforelse
        </tbody></table></div>
    </div>
    <div class="card">
        <div class="card-head"><h3>⚙️ {{ __('Services') }}</h3></div>
        <div class="card-body">
            <div class="row wrap" style="gap:6px">
                @forelse ($server->stat('services', []) as $name => $state)
                    <span class="badge {{ $state === 'active' ? 'up' : (in_array($state, ['failed', 'inactive', 'dead'], true) ? 'down' : '') }}">{{ $name }}</span>
                @empty<span class="muted small">—</span>@endforelse
            </div>
            @if ($server->stat('top'))
                <div class="label mt">{{ __('Top processes') }}</div>
                <table class="table small mt-s"><tbody>@foreach ($server->stat('top') as $p)<tr><td class="mono ltr">{{ $p['cmd'] }}</td><td class="muted">{{ $p['user'] }}</td><td class="ltr">{{ $p['cpu'] }}% CPU</td><td class="ltr">{{ $p['mem'] }}% RAM</td></tr>@endforeach</tbody></table>
            @endif
        </div>
    </div>
</div>

@if ($server->stat('containers'))
<div class="card mt">
    <div class="card-head"><h3>🐳 Docker</h3><span class="small muted">{{ count($server->stat('containers')) }} {{ __('containers') }}</span></div>
    <div class="table-wrap"><table class="table"><thead><tr><th>{{ __('Container') }}</th><th>{{ __('State') }}</th><th>CPU</th><th>RAM</th><th>{{ __('Restarts') }}</th><th>{{ __('Image') }}</th></tr></thead><tbody>
        @foreach ($server->stat('containers') as $c)
            <tr><td><b>{{ $c['name'] }}</b></td>
                <td><span class="badge {{ $c['state'] === 'running' ? ($c['health'] === 'unhealthy' ? 'warning' : 'up') : 'down' }}">{{ $c['state'] }}{{ $c['health'] ? ' · '.$c['health'] : '' }}</span><div class="small faint">{{ $c['status'] }}</div></td>
                <td class="ltr">{{ $c['cpu'] !== null ? $c['cpu'].'%' : '—' }}</td><td class="ltr">{{ $c['mem'] ?: '—' }}</td>
                <td class="{{ ($c['restarts'] ?? 0) > 0 ? 'text-warn' : '' }}">{{ $c['restarts'] ?? 0 }}</td><td class="small muted ltr">{{ $c['image'] }}</td></tr>
        @endforeach
    </tbody></table></div>
</div>
@endif

@if ($accounts->isNotEmpty())
<div class="card mt">
    <div class="card-head"><h3>👥 {{ __('Hosting accounts') }} <span class="chip">{{ $accounts->count() }}</span></h3><span class="small muted">{{ __('disk usage, updated every 30 minutes') }}</span></div>
    <div class="table-wrap"><table class="table"><thead><tr><th>{{ __('User') }}</th><th>{{ __('Domain') }}</th><th>{{ __('Plan') }}</th><th>{{ __('Used') }}</th><th>{{ __('Limit') }}</th><th style="width:22%">%</th><th></th></tr></thead><tbody>
        @foreach ($accounts->take(300) as $a)
            @php $pct = ($a['disk_limit_mb'] ?? 0) > 0 ? round($a['disk_used_mb'] / $a['disk_limit_mb'] * 100, 1) : null; @endphp
            <tr><td class="mono">{{ $a['user'] }}</td><td class="ltr">{{ $a['domain'] }}</td><td class="small muted">{{ $a['plan'] ?? '' }}</td>
                <td>{{ Fmt::bytes(($a['disk_used_mb'] ?? 0) * 1048576) }}</td><td>{{ $a['disk_limit_mb'] ? Fmt::bytes($a['disk_limit_mb'] * 1048576) : '∞' }}</td>
                <td>@if ($pct !== null)<div class="row"><x-meter :value="$pct" style="flex:1" /><span class="small">{{ $pct }}%</span></div>@endif</td>
                <td>@if ($a['suspended'])<span class="badge down">{{ __('Suspended') }}</span>@endif</td></tr>
        @endforeach
    </tbody></table></div>
</div>
@endif
@endsection

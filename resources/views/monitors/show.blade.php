@extends('layouts.app')
@section('title', $monitor->name)
@section('content')
@php
    $d = $monitor->metaValue('last_details', []);
    $t = $d['timings'] ?? $monitor->metaValue('last_timings');
    $ssl = $monitor->metaValue('ssl');
    $causes = $monitor->metaValue('causes', []);
@endphp
<div class="page-head">
    <div style="min-width:0">
        <div class="row wrap"><h1>{{ $monitor->name }}</h1><x-status :status="$monitor->status" :active="$monitor->is_active" /><span class="chip">{{ $monitor->type->label() }}</span></div>
        <div class="sub ltr" style="text-align:start">
            @if ($monitor->type->usesUrl())<a href="{{ $monitor->target }}" target="_blank" rel="noopener noreferrer">{{ $monitor->displayTarget() }} ↗</a>@else{{ $monitor->displayTarget() }}@endif
        </div>
        <div class="small muted mt-s">{{ __('Every :s s', ['s' => $monitor->interval]) }} · {{ __('Last check') }}: {{ $monitor->last_checked_at?->diffForHumans() ?? __('never') }}
            @foreach ($monitor->groups as $g) · <a href="{{ route('groups.show', $g) }}">{{ $g->icon() }} {{ $g->name }}</a>@endforeach
            @foreach ($monitor->tags ?? [] as $tag) <span class="chip">#{{ $tag }}</span>@endforeach
        </div>
    </div>
    <div class="actions">
        <form method="POST" action="{{ route('monitors.check', $monitor) }}" class="inline">@csrf<button class="btn"><x-icon name="refresh"/>{{ __('Check now') }}</button></form>
        <form method="POST" action="{{ route('monitors.toggle', $monitor) }}" class="inline">@csrf<button class="btn">@if ($monitor->is_active)<x-icon name="pause"/>{{ __('Pause') }}@else<x-icon name="play"/>{{ __('Resume') }}@endif</button></form>
        <a class="btn" href="{{ route('monitors.edit', $monitor) }}"><x-icon name="edit"/>{{ __('Edit') }}</a>
        <form method="POST" action="{{ route('monitors.destroy', $monitor) }}" class="inline" data-confirm="{{ __('Delete this monitor and all its history?') }}">@csrf @method('DELETE')<button class="btn danger icon-btn" title="{{ __('Delete') }}"><x-icon name="trash"/></button></form>
    </div>
</div>

<div class="grid g-6">
    @foreach (['24h' => __('Uptime 24h'), '7d' => __('7 days'), '30d' => __('30 days'), '90d' => __('90 days'), '365d' => __('1 year')] as $k => $label)
        <div class="card stat"><div class="label">{{ $label }}</div><div class="value {{ ($periods[$k] ?? 100) < 99 ? 'text-warn' : '' }}">{{ Fmt::uptime($periods[$k]) }}</div></div>
    @endforeach
    <div class="card stat"><div class="label">{{ __('Current response') }}</div><div class="value ltr" style="text-align:start">{{ Fmt::ms($monitor->last_response_ms) }}</div>
        @if ($b = $monitor->metaValue('baseline'))<div class="hint">{{ __('normal ≈ :ms', ['ms' => Fmt::ms((int) $b['mean'])]) }}</div>@endif</div>
</div>

<div class="card card-pad mt">
    <div class="row between small muted" style="margin-bottom:8px"><span>{{ __('Last :n checks', ['n' => $beats->count()]) }}</span><span>{{ __('now') }}</span></div>
    <x-beats :beats="$beats" :slots="60" style="height:30px" />
</div>

<div class="grid g-main mt">
    <div class="stack">
        {{-- Smart diagnosis --}}
        <div class="card">
            <div class="card-head"><h2>🧠 {{ __('Diagnosis') }}</h2>@if ($monitor->status_changed_at)<span class="small muted">{{ __('Status since') }} {{ Fmt::date($monitor->status_changed_at) }}</span>@endif</div>
            <div class="card-body">
                <p class="{{ $monitor->status->value === 'down' ? 'text-down' : ($monitor->status->value === 'warning' ? 'text-warn' : '') }}"><b>{{ $monitor->last_message ?? __('Waiting for the first check…') }}</b></p>
                @foreach ($causes as $cause)<div class="cause">⚠ <span>{{ $cause }}</span></div>@endforeach
                @if (! empty($d['anomaly']))<div class="cause">📈 <span>{{ __('Response time is :x× higher than this monitor\'s learned baseline.', ['x' => $d['anomaly']]) }}</span></div>@endif

                @if ($t)
                    @php $sum = max(1, ($t['dns'] ?? 0) + ($t['connect'] ?? 0) + ($t['tls'] ?? 0) + ($t['server'] ?? 0) + ($t['download'] ?? 0)); @endphp
                    <div class="label mt">{{ __('Request waterfall') }}</div>
                    <div class="waterfall mt-s">
                        @foreach (['dns', 'connect', 'tls', 'server', 'download'] as $k)<span class="w-{{ $k }}" style="width: {{ ($t[$k] ?? 0) / $sum * 100 }}%"></span>@endforeach
                    </div>
                    <div class="legend">
                        <span><i class="w-dns"></i>DNS {{ $t['dns'] ?? 0 }}ms</span>
                        <span><i class="w-connect"></i>{{ __('Connect') }} {{ $t['connect'] ?? 0 }}ms</span>
                        <span><i class="w-tls"></i>TLS {{ $t['tls'] ?? 0 }}ms</span>
                        <span><i class="w-server"></i>{{ __('Server') }} {{ $t['server'] ?? 0 }}ms</span>
                        <span><i class="w-download"></i>{{ __('Download') }} {{ $t['download'] ?? 0 }}ms</span>
                        <span><b>TTFB {{ $t['ttfb'] ?? 0 }}ms</b></span>
                    </div>
                @endif

                @if (! empty($d['steps']))
                    <div class="label mt">{{ __('Synthetic steps') }}</div>
                    <ol class="small" style="margin:6px 0 0;padding-inline-start:18px">
                        @foreach ($d['steps'] as $step)
                            <li class="{{ $step['ok'] ? '' : 'text-down' }}">{{ $step['ok'] ? '✓' : '✗' }} <b>{{ $step['step'] }}</b> — {{ $step['status'] ?? '' }} {{ isset($step['ms']) ? $step['ms'].'ms' : '' }} {{ $step['error'] ?? '' }}</li>
                        @endforeach
                    </ol>
                @endif
            </div>
        </div>

        {{-- Response time chart --}}
        <div class="card">
            <div class="card-head">
                <h2>{{ __('Response time') }}</h2>
                <div class="seg">@foreach (array_keys(\App\Http\Controllers\MonitorController::RANGES) as $r)<a href="?range={{ $r }}" class="{{ $range === $r ? 'active' : '' }}">{{ $r }}</a>@endforeach</div>
            </div>
            <div class="card-body">
                <x-chart :chart="$chart" :date-format="in_array($range, ['7d', '30d']) ? 'm/d' : 'H:i'" />
                <div class="grid g-4 mt">
                    <div><div class="muted small">{{ __('Average') }}</div><b class="ltr">{{ Fmt::ms($responseStats['avg']) }}</b></div>
                    <div><div class="muted small">{{ __('Fastest') }}</div><b class="ltr">{{ Fmt::ms($responseStats['min']) }}</b></div>
                    <div><div class="muted small">{{ __('Slowest') }}</div><b class="ltr">{{ Fmt::ms($responseStats['max']) }}</b></div>
                    <div><div class="muted small">P95</div><b class="ltr">{{ Fmt::ms($responseStats['p95']) }}</b></div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-head"><h2>{{ __('90-day history') }}</h2><span class="small muted">{{ Fmt::uptime($periods['90d']) }}</span></div>
            <div class="card-body"><x-bars :bars="$bars" /><div class="row between small faint mt-s"><span>{{ __('90 days ago') }}</span><span>{{ __('Today') }}</span></div></div>
        </div>

        @if ($visuals->isNotEmpty() || $monitor->setting('visual'))
            <div class="card">
                <div class="card-head"><h2>🖼️ {{ __('Visual snapshots') }}</h2>
                    <form method="POST" action="{{ route('monitors.screenshot', $monitor) }}">@csrf<button class="btn sm">{{ __('Capture now') }}</button></form></div>
                <div class="card-body">
                    @if ($err = $monitor->metaValue('visual.error'))<div class="alert error small">{{ $err }}</div>@endif
                    <div class="grid g-2">
                        @foreach ($visuals as $v)
                            <div><a href="{{ route('monitors.snapshot', [$monitor, $v]) }}" target="_blank"><img src="{{ route('monitors.snapshot', [$monitor, $v]) }}" alt="" style="width:100%;border-radius:10px;border:1px solid var(--border)"></a>
                                <div class="small muted mt-s">{{ Fmt::date($v->created_at) }} @if ($loop->first && $v->change_percent !== null)· <b class="{{ $v->change_percent >= $monitor->setting('change_threshold', 5) ? 'text-warn' : '' }}">{{ __(':p% changed', ['p' => $v->change_percent]) }}</b>@endif</div></div>
                        @endforeach
                    </div>
                    @if ($visuals->isEmpty())<p class="small muted">{{ __('The first screenshot is taken after the next successful check.') }}</p>@endif
                </div>
            </div>
        @endif

        @if ($textChanges->isNotEmpty())
            <div class="card">
                <div class="card-head"><h2>🔍 {{ __('Content changes') }}</h2></div>
                <div class="card-body stack">
                    @foreach ($textChanges as $c)
                        <div>
                            <div class="row between small"><span>{{ Fmt::date($c->created_at) }}</span><b class="{{ $c->change_percent >= $monitor->setting('change_threshold', 5) ? 'text-warn' : '' }}">{{ __(':p% changed', ['p' => $c->change_percent]) }}</b></div>
                            <pre class="mt-s" style="max-height:160px">@foreach ($c->diff['removed'] ?? [] as $l)- {{ $l }}
@endforeach @foreach ($c->diff['added'] ?? [] as $l)+ {{ $l }}
@endforeach</pre>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        <div class="card">
            <div class="card-head"><h2>{{ __('Events') }}</h2><span class="small muted">{{ __('Failures, warnings & maintenance') }}</span></div>
            <div class="table-wrap">
                <table class="table">
                    <tbody>
                    @forelse ($events as $e)
                        <tr>
                            <td class="nowrap"><span class="badge {{ $e->statusEnum()->value }}">{{ $e->statusEnum()->label() }}</span></td>
                            <td>{{ $e->message }}@if (! empty($e->details['status_code'])) <span class="chip">HTTP {{ $e->details['status_code'] }}</span>@endif</td>
                            <td class="small muted nowrap">{{ Fmt::date($e->created_at) }}@if ($locations)<div>{{ \App\Services\LocationNames::label($e->location) }}</div>@endif</td>
                        </tr>
                    @empty
                        <tr><td class="muted">✓ {{ __('No failures recorded in the retention window.') }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="stack">
        <div class="card">
            <div class="card-head"><h2>{{ __('Details') }}</h2></div>
            <div class="card-body">
                <dl class="kv">
                    @isset($d['status_code'])<dt>{{ __('HTTP status') }}</dt><dd>{{ $d['status_code'] }}{{ isset($d['http_version']) ? ' · HTTP/'.$d['http_version'] : '' }}</dd>@endisset
                    @if ($ip = $monitor->metaValue('ip') ?? ($d['ip'] ?? null))<dt>IP</dt><dd class="ltr">{{ $ip }}</dd>@endif
                    @if (! empty($d['server']))<dt>{{ __('Server') }}</dt><dd>{{ $d['server'] }}</dd>@endif
                    @if (! empty($d['content_type']))<dt>{{ __('Content type') }}</dt><dd class="ltr">{{ $d['content_type'] }}</dd>@endif
                    @isset($d['size'])<dt>{{ __('Page size') }}</dt><dd>{{ Fmt::bytes($d['size']) }}</dd>@endisset
                    @if (! empty($d['redirects']))<dt>{{ __('Redirects') }}</dt><dd>{{ $d['redirects'] }} → <span class="ltr">{{ $d['final_url'] ?? '' }}</span></dd>@endif
                    @if (! empty($d['banner']))<dt>{{ __('Banner') }}</dt><dd class="mono small">{{ $d['banner'] }}</dd>@endif
                    @if (! empty($d['greeting']))<dt>{{ __('Greeting') }}</dt><dd class="mono small">{{ $d['greeting'] }}</dd>@endif
                    @if (! empty($d['tls']))<dt>TLS</dt><dd>{{ $d['tls'] }} {{ $d['cipher'] ?? '' }}</dd>@endif
                    @if (! empty($d['auth_methods']))<dt>SMTP AUTH</dt><dd>{{ $d['auth_methods'] }}</dd>@endif
                    @if (! empty($d['max_size_mb']))<dt>{{ __('Max message') }}</dt><dd>{{ $d['max_size_mb'] }} MB</dd>@endif
                    @isset($d['auth'])<dt>{{ __('Login test') }}</dt><dd class="{{ $d['auth'] === 'success' ? 'text-up' : 'text-down' }}">{{ $d['auth'] === 'success' ? __('Successful') : __('Failed') }}</dd>@endisset
                    @isset($d['open_relay'])<dt>{{ __('Open relay') }}</dt><dd class="{{ $d['open_relay'] ? 'text-down' : 'text-up' }}">{{ $d['open_relay'] ? __('YES — vulnerable') : __('No') }}</dd>@endisset
                    @if ($rbl = $monitor->metaValue('rbl'))<dt>{{ __('Blacklists') }}</dt><dd class="{{ $rbl['listed'] ? 'text-down' : 'text-up' }}">{{ $rbl['listed'] ? implode(', ', $rbl['listed']) : __('Not listed') }} <span class="small muted ltr">({{ $rbl['ip'] }})</span></dd>@endif
                    @isset($d['messages'])<dt>{{ __('Messages') }}</dt><dd>{{ number_format($d['messages']) }}{{ isset($d['unseen']) ? ' · '.$d['unseen'].' '.__('unread') : '' }}</dd>@endisset
                    @isset($d['mailbox_mb'])<dt>{{ __('Mailbox size') }}</dt><dd>{{ $d['mailbox_mb'] }} MB</dd>@endisset
                    @isset($d['quota_limit_mb'])<dt>{{ __('Quota') }}</dt><dd>{{ $d['quota_used_mb'] }} / {{ $d['quota_limit_mb'] }} MB <x-meter :value="$d['quota_percent']" class="mt-s" /></dd>@endisset
                    @isset($d['version'])<dt>{{ __('Version') }}</dt><dd>{{ $d['version'] }}</dd>@endisset
                    @isset($d['threads_connected'])<dt>{{ __('Connections') }}</dt><dd>{{ $d['threads_connected'] }} / {{ $d['max_connections'] ?? '?' }}</dd>@endisset
                    @isset($d['used_memory_human'])<dt>{{ __('Memory') }}</dt><dd>{{ $d['used_memory_human'] }}{{ ! empty($d['maxmemory_human']) && $d['maxmemory_human'] !== '0B' ? ' / '.$d['maxmemory_human'] : '' }}</dd>@endisset
                    @isset($d['packet_loss'])<dt>{{ __('Packet loss') }}</dt><dd>{{ $d['packet_loss'] }}%</dd>@endisset
                    @if (! empty($d['records']))<dt>{{ __('Records') }}</dt><dd class="mono small ltr">@foreach ($d['records'] as $r){{ $r['value'] }}<br>@endforeach</dd>@endif
                    <dt>{{ __('Retries') }}</dt><dd>{{ $monitor->retries }} · {{ __('timeout') }} {{ $monitor->timeout }}s</dd>
                </dl>
            </div>
        </div>

        @if ($locations)
            <div class="card">
                <div class="card-head"><h2>🌍 {{ __('Locations') }}</h2><span class="small muted">{{ __('quorum') }}: {{ $monitor->setting('quorum', 'majority') }}</span></div>
                <div class="card-body">
                    @foreach ($locations as $loc)
                        <div class="row between" style="padding:5px 0;{{ $loc['stale'] ? 'opacity:.5' : '' }}">
                            <span>{{ $loc['label'] }}</span>
                            <span class="row small"><span class="muted ltr">{{ Fmt::ms($loc['ms']) }}</span><span class="badge {{ $loc['status'] }}">{{ \App\Enums\MonitorStatus::from($loc['status'])->label() }}</span></span>
                        </div>
                        @if ($loc['status'] !== 'up')<div class="small faint" style="margin-top:-4px">{{ $loc['message'] }}</div>@endif
                    @endforeach
                </div>
            </div>
        @endif

        @if ($ssl)
            <div class="card">
                <div class="card-head"><h2>🔐 {{ __('Certificate') }}</h2>
                    @if (isset($ssl['days_left']))<span class="badge {{ $ssl['days_left'] < 0 ? 'down' : ($ssl['days_left'] <= 14 ? 'warning' : 'up') }}">{{ trans_choice(':count day left|:count days left', $ssl['days_left']) }}</span>@endif</div>
                <div class="card-body">
                    <dl class="kv">
                        @if (! empty($ssl['subject']))<dt>{{ __('Subject') }}</dt><dd class="ltr">{{ $ssl['subject'] }}</dd>@endif
                        @if (! empty($ssl['issuer']))<dt>{{ __('Issuer') }}</dt><dd>{{ $ssl['issuer'] }}</dd>@endif
                        @if (! empty($ssl['valid_to']))<dt>{{ __('Expires') }}</dt><dd>{{ Fmt::date(\Illuminate\Support\Carbon::parse($ssl['valid_to']), false) }}</dd>@endif
                        @if (! empty($ssl['protocol']))<dt>{{ __('Protocol') }}</dt><dd>{{ $ssl['protocol'] }}</dd>@endif
                        @if (! empty($ssl['signature']))<dt>{{ __('Signature') }}</dt><dd>{{ $ssl['signature'] }}</dd>@endif
                        @if (! empty($ssl['san']))<dt>SAN</dt><dd class="small ltr">{{ implode(', ', array_slice($ssl['san'], 0, 12)) }}{{ count($ssl['san']) > 12 ? ' …' : '' }}</dd>@endif
                    </dl>
                </div>
            </div>
        @endif

        @if ($monitor->type === \App\Enums\MonitorType::Push)
            <div class="card">
                <div class="card-head"><h2>{{ __('Push URL') }}</h2></div>
                <div class="card-body">
                    <p class="small muted">{{ __('Call this URL from your cron job or script at least every :s seconds:', ['s' => $monitor->interval]) }}</p>
                    <div class="secret" id="push-url">{{ route('api.push', $monitor->push_token) }}?status=up&amp;msg=OK</div>
                    <pre class="mt-s">curl -fsS -m 10 "{{ route('api.push', $monitor->push_token) }}?status=up&amp;msg=OK" &gt;/dev/null</pre>
                    <div class="row mt-s">
                        <button class="btn sm" data-copy="#push-url">{{ __('Copy') }}</button>
                        <form method="POST" action="{{ route('monitors.push-token', $monitor) }}" data-confirm="{{ __('The old URL stops working. Continue?') }}">@csrf<button class="btn sm ghost">{{ __('Regenerate') }}</button></form>
                    </div>
                </div>
            </div>
        @endif

        @if ($monitor->server)
            <div class="card card-pad"><a href="{{ route('servers.show', $monitor->server) }}" class="row between"><span>🖥️ {{ $monitor->server->name }}</span><span class="small muted">{{ __('Open server') }} →</span></a></div>
        @endif

        @if ($monitor->parent || $monitor->children->isNotEmpty())
            <div class="card">
                <div class="card-head"><h2>🔗 {{ __('Dependencies') }}</h2><a class="btn sm" href="{{ route('dependencies.index') }}">{{ __('Full map') }}</a></div>
                <div class="card-body">
                    <x-dependency-map :map="$depMap" :focus="$monitor->id" />
                    <p class="small faint mt-s">{{ __('Alerts for dependants are suppressed while their parent is down.') }}</p>
                </div>
            </div>
        @endif

        <div class="card">
            <div class="card-head"><h2>{{ __('Incidents') }}</h2></div>
            <div class="card-body">
                @forelse ($incidents as $i)
                    <a href="{{ route('incidents.show', $i) }}" class="row between" style="display:flex;padding:5px 0">
                        <span class="small">{{ Fmt::date($i->started_at) }}</span>
                        <span class="badge {{ $i->isOpen() ? $i->severity : 'up' }}">{{ Fmt::duration($i->durationSeconds()) }}</span>
                    </a>
                @empty
                    <p class="small muted">{{ __('No incidents.') }}</p>
                @endforelse
            </div>
        </div>

        <div class="card">
            <div class="card-head"><h2>{{ __('Badges') }}</h2></div>
            <div class="card-body">
                <div class="row wrap"><img src="{{ route('badge', [$monitor->uuid, 'status']) }}" alt="status"><img src="{{ route('badge', [$monitor->uuid, 'uptime']) }}" alt="uptime"><img src="{{ route('badge', [$monitor->uuid, 'response']) }}" alt="response"></div>
                <pre class="mt-s">&lt;img src="{{ route('badge', [$monitor->uuid, 'uptime']) }}"&gt;</pre>
            </div>
        </div>

        <div class="card card-pad small">
            <div class="label">{{ __('Alerts go to') }}</div>
            <div class="mt-s">
                @forelse ($monitor->channels as $c)<span class="chip">{{ $c->name }}</span> @empty<span class="muted">{{ __('Default channels') }}</span>@endforelse
            </div>
            @if ($monitor->statusPages->isNotEmpty())
                <div class="label mt">{{ __('Shown on') }}</div>
                <div class="mt-s">@foreach ($monitor->statusPages as $p)<a class="chip" href="{{ $p->publicUrl() }}" target="_blank">{{ $p->title }}</a> @endforeach</div>
            @endif
        </div>
    </div>
</div>
@endsection

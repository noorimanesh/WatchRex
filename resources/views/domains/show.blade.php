@extends('layouts.app')
@section('title', $domain->name)
@section('content')
@php
    $days = $domain->daysUntilExpiry(); $sslDays = $domain->sslDaysLeft();
    $es = $domain->email_security ?? []; $net = $domain->network ?? []; $geo = $net['geo'] ?? [];
    $subs = collect($domain->subdomains ?? []); $cert = $domain->ssl['certificate'] ?? null;
    $listed = collect($domain->blacklists ?? [])->filter();
@endphp
<div class="page-head">
    <div>
        <h1 class="ltr" style="text-align:start">{{ $domain->name }}</h1>
        <div class="sub">{{ $domain->registrar ?? __('Registrar unknown') }} · {{ __('Last analysed') }} {{ $domain->last_checked_at?->diffForHumans() ?? __('pending…') }}</div>
        @if ($domain->last_error)<div class="small text-warn mt-s">{{ $domain->last_error }}</div>@endif
    </div>
    <div class="actions">
        <a class="btn" href="{{ route('monitors.create', ['type' => 'http', 'target' => 'https://'.$domain->name, 'name' => $domain->name]) }}"><x-icon name="activity"/>{{ __('Monitor website') }}</a>
        <form method="POST" action="{{ route('domains.refresh', $domain) }}">@csrf<button class="btn"><x-icon name="refresh"/>{{ __('Refresh') }}</button></form>
        <form method="POST" action="{{ route('domains.destroy', $domain) }}" data-confirm="{{ __('Remove this domain?') }}">@csrf @method('DELETE')<button class="btn danger icon-btn"><x-icon name="trash"/></button></form>
    </div>
</div>

<div class="grid g-4">
    <div class="card stat {{ $days !== null && $days <= $domain->warn_days ? 'accent-warn' : '' }}"><div class="label">{{ __('Domain expires') }}</div><div class="value">{{ $days === null ? '—' : ($days < 0 ? __('expired') : trans_choice(':count day|:count days', $days)) }}</div><div class="hint">{{ Fmt::date($domain->expires_at, false) }}</div></div>
    <div class="card stat {{ $sslDays !== null && $sslDays <= 14 ? 'accent-warn' : '' }}"><div class="label">{{ __('SSL expires') }}</div><div class="value">{{ $sslDays === null ? '—' : trans_choice(':count day|:count days', $sslDays) }}</div><div class="hint">{{ $cert['issuer'] ?? '' }}</div></div>
    <div class="card stat"><div class="label">{{ __('Mail security score') }}</div><div class="value {{ ($es['score'] ?? 100) < 60 ? 'text-down' : '' }}">{{ $es['score'] ?? '—' }}<span class="small muted">/100</span></div><div class="hint">SPF · DKIM · DMARC</div></div>
    <div class="card stat {{ $listed->isNotEmpty() ? 'accent-down' : '' }}"><div class="label">{{ __('Blacklists') }}</div><div class="value {{ $listed->isNotEmpty() ? 'text-down' : 'text-up' }}">{{ $listed->isNotEmpty() ? __('Listed') : __('Clean') }}</div><div class="hint">{{ count($domain->blacklists ?? []) }} {{ __('IPs checked') }}</div></div>
</div>

<div class="card mt" data-tabs>
    <div class="tabs" style="padding:0 12px;margin:0">
        <button data-tab="overview">{{ __('Overview') }}</button>
        <button data-tab="dns">DNS</button>
        <button data-tab="mail">{{ __('E-mail') }}</button>
        <button data-tab="ssl">SSL</button>
        <button data-tab="subdomains">{{ __('Subdomains') }} <span class="chip">{{ $subs->where('resolves', true)->count() }}</span></button>
        <button data-tab="history">{{ __('History & uptime') }}</button>
    </div>
    <div class="card-body">
        <div data-tab-panel="overview" class="grid g-2">
            <dl class="kv">
                <dt>{{ __('Registrar') }}</dt><dd>{{ $domain->registrar ?? '—' }}</dd>
                <dt>{{ __('Registered') }}</dt><dd>{{ Fmt::date($domain->registered_at, false) }} @if ($domain->registered_at)<span class="muted small">({{ $domain->registered_at->diffForHumans(null, true) }})</span>@endif</dd>
                <dt>{{ __('Expires') }}</dt><dd>{{ Fmt::date($domain->expires_at, false) }}</dd>
                <dt>{{ __('Nameservers') }}</dt><dd class="ltr small">{!! collect($domain->nameservers ?? [])->map(fn ($n) => e($n))->implode('<br>') ?: '—' !!}</dd>
                <dt>{{ __('Alert before') }}</dt><dd><form method="POST" action="{{ route('domains.update', $domain) }}" class="row">@csrf @method('PUT')<input class="input" type="number" name="warn_days" value="{{ $domain->warn_days }}" min="1" max="120" style="width:90px"><button class="btn sm">{{ __('Save') }}</button></form></dd>
            </dl>
            <dl class="kv">
                <dt>IPv4</dt><dd class="ltr">{{ $net['ip'] ?? '—' }}</dd>
                <dt>IPv6</dt><dd class="ltr small">{{ $net['ipv6'] ?? '—' }}</dd>
                <dt>{{ __('Reverse DNS') }}</dt><dd class="ltr small">{{ $net['ptr'] ?? '—' }}</dd>
                <dt>{{ __('Location') }}</dt><dd>@if ($geo)@if (! empty($geo['country_code']))<span>{{ implode('', array_map(fn ($c) => mb_chr(127397 + ord($c)), str_split(strtoupper($geo['country_code'])))) }}</span>@endif {{ $geo['country'] ?? '' }}{{ ! empty($geo['city']) ? ', '.$geo['city'] : '' }}@else — @endif</dd>
                <dt>{{ __('Network') }}</dt><dd>{{ $geo['org'] ?? $geo['isp'] ?? '—' }} {{ ! empty($geo['asn']) ? '· AS'.ltrim((string) $geo['asn'], 'AS') : '' }}</dd>
                <dt>{{ __('Online since') }}</dt><dd>{{ $net['first_seen'] ?? '—' }} <span class="small faint">{{ __('(first web archive snapshot)') }}</span></dd>
            </dl>
        </div>

        <div data-tab-panel="dns">
            @if ($domain->dns_changed_at)<div class="alert warn small">{{ __('DNS changed on :d', ['d' => Fmt::date($domain->dns_changed_at)]) }}@if (! empty($domain->alerts_sent['dns_change']))<pre class="mt-s">{{ implode("\n", $domain->alerts_sent['dns_change']) }}</pre>@endif</div>@endif
            <table class="table"><tbody>
                @forelse ($domain->dns ?? [] as $type => $values)
                    @continue(empty($values))
                    <tr><td style="width:90px"><span class="chip">{{ $type === '_dmarc' ? 'DMARC' : $type }}</span></td><td class="mono small ltr" style="text-align:start">@foreach ($values as $v){{ $v }}<br>@endforeach</td></tr>
                @empty
                    <tr><td class="muted">{{ __('No data yet.') }}</td></tr>
                @endforelse
            </tbody></table>
        </div>

        <div data-tab-panel="mail">
            @foreach ($es['issues'] ?? [] as [$level, $text])
                <div class="alert {{ $level === 'critical' ? 'error' : ($level === 'warning' ? 'warn' : 'info') }} small">{{ $text }}</div>
            @endforeach
            <dl class="kv">
                <dt>SPF</dt><dd class="mono small ltr">{{ $es['spf'] ?? '✗' }}</dd>
                <dt>DMARC</dt><dd class="mono small ltr">{{ $es['dmarc'] ?? '✗' }}</dd>
                <dt>DKIM</dt><dd>{{ ! empty($es['dkim_selectors']) ? implode(', ', $es['dkim_selectors']) : '✗' }}</dd>
                <dt>MTA-STS</dt><dd>{{ ! empty($es['mta_sts']) ? '✓' : '✗' }}</dd>
                <dt>TLS-RPT</dt><dd>{{ ! empty($es['tls_rpt']) ? '✓' : '✗' }}</dd>
                <dt>BIMI</dt><dd>{{ ! empty($es['bimi']) ? '✓' : '✗' }}</dd>
            </dl>
            <div class="label mt">{{ __('Mail servers (MX)') }}</div>
            <table class="table mt-s"><thead><tr><th>{{ __('Priority') }}</th><th>{{ __('Host') }}</th><th>IP</th><th>PTR</th><th>{{ __('Blacklists') }}</th><th></th></tr></thead><tbody>
                @forelse ($es['mx'] ?? [] as $mx)
                    <tr><td>{{ $mx['priority'] }}</td><td class="ltr">{{ $mx['host'] }}</td><td class="ltr">{{ $mx['ip'] ?? '—' }}</td>
                        <td class="small ltr {{ $mx['ptr_ok'] ? '' : 'text-warn' }}">{{ $mx['ptr'] ?? __('missing') }}</td>
                        <td>@php $l = $domain->blacklists[$mx['ip'] ?? ''] ?? null; @endphp @if ($l === null) — @elseif ($l)<span class="badge down">{{ implode(', ', $l) }}</span>@else<span class="badge up">{{ __('Clean') }}</span>@endif</td>
                        <td><a class="btn sm" href="{{ route('monitors.create', ['type' => 'smtp', 'target' => $mx['host'], 'name' => 'SMTP '.$mx['host']]) }}">{{ __('Monitor SMTP') }}</a></td></tr>
                @empty<tr><td colspan="6" class="muted">—</td></tr>@endforelse
            </tbody></table>
        </div>

        <div data-tab-panel="ssl">
            @if ($cert)
                <dl class="kv">
                    <dt>{{ __('Status') }}</dt><dd>@if (($domain->ssl['valid_chain'] ?? false) && ($domain->ssl['host_match'] ?? false))<span class="badge up">{{ __('Valid & trusted') }}</span>@else<span class="badge down">{{ $domain->ssl['error'] ?? __('Hostname mismatch') }}</span>@endif</dd>
                    <dt>{{ __('Subject') }}</dt><dd class="ltr">{{ $cert['subject'] }}</dd>
                    <dt>{{ __('Issuer') }}</dt><dd>{{ $cert['issuer'] }} <span class="small muted">({{ $cert['issuer_cn'] ?? '' }})</span></dd>
                    <dt>{{ __('Valid from') }}</dt><dd>{{ Fmt::date(\Illuminate\Support\Carbon::parse($cert['valid_from'])) }}</dd>
                    <dt>{{ __('Valid to') }}</dt><dd>{{ Fmt::date(\Illuminate\Support\Carbon::parse($cert['valid_to'])) }}</dd>
                    <dt>{{ __('Protocol') }}</dt><dd>{{ $domain->ssl['protocol'] ?? '—' }} · {{ $domain->ssl['cipher'] ?? '' }}</dd>
                    <dt>{{ __('Signature') }}</dt><dd>{{ $cert['signature'] ?? '—' }}</dd>
                    <dt>SHA-256</dt><dd class="mono tiny ltr">{{ $cert['fingerprint'] ?? '—' }}</dd>
                    <dt>SAN</dt><dd class="small ltr">{{ implode(', ', $cert['san'] ?? []) }}</dd>
                </dl>
            @else
                <p class="muted">{{ __('No certificate information (port 443 closed or not analysed yet).') }}</p>
            @endif
        </div>

        <div data-tab-panel="subdomains">
            <p class="small muted">{{ __('Discovered via Certificate Transparency logs and DNS probing of common names.') }}</p>
            <div class="table-wrap"><table class="table"><thead><tr><th>{{ __('Subdomain') }}</th><th>IP / CNAME</th><th>SSL</th><th>{{ __('Source') }}</th><th></th></tr></thead><tbody>
                @forelse ($subs as $sub)
                    <tr class="{{ $sub['resolves'] ? '' : 'faint' }}">
                        <td class="ltr"><b>{{ $sub['name'] }}</b></td>
                        <td class="small ltr">{{ $sub['ip'] ?? $sub['cname'] ?? __('does not resolve') }}</td>
                        <td>@if (isset($sub['ssl_days']))<span class="badge {{ ! ($sub['ssl_valid'] ?? false) ? 'down' : ($sub['ssl_days'] <= 14 ? 'warning' : 'up') }}">{{ ($sub['ssl_valid'] ?? false) ? trans_choice(':count day|:count days', $sub['ssl_days']) : __('invalid') }}</span> <span class="small muted">{{ $sub['ssl_issuer'] ?? '' }}</span>@elseif ($sub['resolves'])<span class="muted small">—</span>@endif</td>
                        <td><span class="chip">{{ $sub['source'] === 'ct' ? 'CT log' : 'DNS' }}</span></td>
                        <td>@if ($sub['resolves'])
                            @if (in_array($sub['name'], $monitoredHosts, true))<span class="badge up">{{ __('Monitored') }}</span>
                            @else<form method="POST" action="{{ route('domains.monitor', $domain) }}">@csrf<input type="hidden" name="host" value="{{ $sub['name'] }}"><button class="btn sm">+ {{ __('Monitor') }}</button></form>@endif
                        @endif</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="muted">{{ __('No subdomains discovered yet.') }}</td></tr>
                @endforelse
            </tbody></table></div>
        </div>

        <div data-tab-panel="history">
            <div class="label">{{ __('Monitors for this domain') }}</div>
            <table class="table mt-s"><tbody>
                @forelse ($monitors as $m)
                    <tr><td><a href="{{ route('monitors.show', $m) }}"><span class="dot {{ $m->status->value }}"></span> {{ $m->name }}</a></td><td class="small ltr muted">{{ $m->displayTarget() }}</td><td><b>{{ Fmt::uptime($uptime[$m->id]['uptime'] ?? null) }}</b> <span class="small muted">24h</span></td></tr>
                @empty
                    <tr><td class="muted">{{ __('No monitors yet.') }}</td></tr>
                @endforelse
            </tbody></table>
            <div class="label mt">{{ __('Hosting IP history') }}</div>
            <table class="table mt-s"><tbody>
                @forelse ($net['ip_history'] ?? [] as $h)
                    <tr><td class="ltr">{{ $h['ip'] }}</td><td class="small muted">{{ $h['org'] ?? '' }}</td><td class="small">{{ __('since') }} {{ $h['since'] }}</td></tr>
                @empty<tr><td class="muted">—</td></tr>@endforelse
            </tbody></table>
        </div>
    </div>
</div>
@endsection

@extends('layouts.app')
@section('title', __('Domains & SSL'))
@section('content')
<div class="page-head">
    <div><h1>{{ __('Domains & SSL') }}</h1><div class="sub">{{ __('Expiry, WHOIS, DNS records & changes, SSL, subdomains, e-mail security (SPF/DKIM/DMARC), hosting IP & location and blacklists.') }}</div></div>
</div>

<form method="POST" action="{{ route('domains.store') }}" class="card card-pad mb">
    @csrf
    <div class="row wrap">
        <input class="input ltr" name="name" placeholder="example.com" required style="flex:1;min-width:220px" value="{{ old('name') }}">
        <input class="input" type="number" name="warn_days" value="{{ old('warn_days', 30) }}" min="1" max="120" style="width:120px" title="{{ __('Warn days before expiry') }}">
        @if ($teams->isNotEmpty())<select name="team_id" class="input" style="width:auto"><option value="">{{ __('Only me') }}</option>@foreach ($teams as $t)<option value="{{ $t->id }}">👥 {{ $t->name }}</option>@endforeach</select>@endif
        <button class="btn primary"><x-icon name="plus"/>{{ __('Add domain') }}</button>
    </div>
    @error('name')<div class="error mt-s">{{ $message }}</div>@enderror
</form>

<div class="card">
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>{{ __('Domain') }}</th><th>{{ __('Expires') }}</th><th>SSL</th><th>{{ __('Hosting') }}</th><th>{{ __('Mail security') }}</th><th>{{ __('Subdomains') }}</th><th>{{ __('Checked') }}</th></tr></thead>
            <tbody>
            @forelse ($domains as $d)
                @php $days = $d->daysUntilExpiry(); $sslDays = $d->sslDaysLeft(); $score = $d->email_security['score'] ?? null; @endphp
                <tr>
                    <td><a href="{{ route('domains.show', $d) }}"><b class="ltr">{{ $d->name }}</b></a><div class="small muted">{{ $d->registrar ?? '' }}</div></td>
                    <td class="nowrap">@if ($days !== null)<span class="badge {{ $days < 0 ? 'down' : ($days <= $d->warn_days ? 'warning' : 'up') }}">{{ $days < 0 ? __('expired') : trans_choice(':count day|:count days', $days) }}</span><div class="small faint">{{ Fmt::date($d->expires_at, false) }}</div>@else<span class="muted">—</span>@endif</td>
                    <td class="nowrap">@if ($sslDays !== null)<span class="badge {{ $sslDays < 0 || ! ($d->ssl['valid_chain'] ?? true) ? 'down' : ($sslDays <= 14 ? 'warning' : 'up') }}">{{ trans_choice(':count day|:count days', $sslDays) }}</span>@else<span class="muted">—</span>@endif</td>
                    <td class="small"><span class="ltr">{{ $d->network['ip'] ?? '—' }}</span><div class="muted">{{ $d->network['geo']['country_code'] ?? '' }} {{ \Illuminate\Support\Str::limit($d->network['geo']['org'] ?? '', 28) }}</div></td>
                    <td>@if ($score !== null)<span class="badge {{ $score >= 80 ? 'up' : ($score >= 50 ? 'warning' : 'down') }}">{{ $score }}/100</span>@else — @endif</td>
                    <td>{{ collect($d->subdomains ?? [])->where('resolves', true)->count() ?: '—' }}</td>
                    <td class="small muted nowrap">{{ $d->last_checked_at?->diffForHumans() ?? __('analysing…') }}</td>
                </tr>
            @empty
                <tr><td colspan="7"><x-empty icon="🌐" :title="__('No domains yet')" :text="__('Add a domain to track its registration, DNS, SSL and e-mail posture.')" /></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection

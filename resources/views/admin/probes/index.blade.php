@extends('layouts.app')
@section('title', __('Probe locations'))
@section('content')
<div class="page-head"><div><h1>🌍 {{ __('Probe locations') }}</h1><div class="sub">{{ __('Check the same service from several countries to tell real outages from ISP, routing or filtering problems.') }}</div></div></div>

@if ($token && $tokenProbe)
<div class="card mb">
    <div class="card-head"><h2>⚡ {{ __('Set up probe :n', ['n' => $tokenProbe->name]) }}</h2></div>
    <div class="card-body">
        <div class="alert warn small">{{ __('Copy the token now — it is shown only once.') }}</div>
        <p class="small muted">{{ __('On the probe server, deploy the same WatchRex code (no database needed), then add to .env:') }}</p>
        <pre id="probe-env">WATCHREX_HUB_URL={{ rtrim(config('app.url'), '/') }}
WATCHREX_PROBE_TOKEN={{ $token }}
WATCHREX_PROBE_CONCURRENCY=4
CACHE_STORE=file
QUEUE_CONNECTION=sync</pre>
        <button class="btn sm mt-s" data-copy="#probe-env">{{ __('Copy') }}</button>
        <p class="small muted mt">{{ __('Run it as a service (systemd):') }}</p>
<pre>[Unit]
Description=WatchRex probe
After=network-online.target

[Service]
User=www-data
WorkingDirectory=/opt/watchrex
ExecStart=/usr/bin/php artisan watchrex:probe
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target</pre>
    </div>
</div>
@endif

<div class="grid g-main">
    <div class="card">
        <div class="table-wrap"><table class="table">
            <thead><tr><th>{{ __('Location') }}</th><th>{{ __('Status') }}</th><th>{{ __('Monitors') }}</th><th>{{ __('Last seen') }}</th><th></th></tr></thead>
            <tbody>
                <tr><td>{{ \App\Services\LocationNames::label(config('watchrex.location')) }} <span class="chip">{{ config('watchrex.location') }}</span></td><td><span class="badge up">{{ __('This server') }}</span></td><td>—</td><td>—</td><td></td></tr>
                @foreach ($probes as $p)
                    <tr class="{{ $p->is_active ? '' : 'faint' }}">
                        <td><b>{{ $p->flag() }} {{ $p->name }}</b> <span class="chip">{{ $p->location }}</span><div class="small muted ltr">{{ $p->ip }} {{ $p->version ? '· v'.$p->version : '' }}</div></td>
                        <td><span class="badge {{ $p->isOnline() ? 'up' : 'down' }}">{{ $p->isOnline() ? __('Online') : __('Offline') }}</span></td>
                        <td>{{ $p->monitors_count }}</td>
                        <td class="small muted">{{ $p->last_seen_at?->diffForHumans() ?? __('never') }}</td>
                        <td class="nowrap">
                            <form class="inline" method="POST" action="{{ route('admin.probes.token', $p) }}" data-confirm="{{ __('The current token stops working. Continue?') }}">@csrf<button class="btn sm">{{ __('New token') }}</button></form>
                            <form class="inline" method="POST" action="{{ route('admin.probes.update', $p) }}">@csrf @method('PUT')
                                <input type="hidden" name="name" value="{{ $p->name }}"><input type="hidden" name="location" value="{{ $p->location }}"><input type="hidden" name="country_code" value="{{ $p->country_code }}"><input type="hidden" name="is_active" value="{{ $p->is_active ? 0 : 1 }}">
                                <button class="btn sm">{{ $p->is_active ? __('Disable') : __('Enable') }}</button></form>
                            <form class="inline" method="POST" action="{{ route('admin.probes.destroy', $p) }}" data-confirm="{{ __('Delete this probe?') }}">@csrf @method('DELETE')<button class="btn sm danger">{{ __('Delete') }}</button></form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table></div>
    </div>
    <form method="POST" action="{{ route('admin.probes.store') }}" class="card card-pad">
        @csrf
        <h2 class="mb">{{ __('Add probe') }}</h2>
        <x-field name="name" :label="__('Name')" required placeholder="Germany — Falkenstein" />
        <x-field name="location" :label="__('Location ID')" required placeholder="de-falkenstein" class="ltr" :help="__('Lowercase letters, digits and dashes.')" />
        <x-field name="country_code" :label="__('Country code')" placeholder="DE" maxlength="2" class="ltr" />
        <button class="btn primary">{{ __('Create') }}</button>
        <p class="small faint mt">{{ __('This server reports as :loc. Set WATCHREX_LOCATION, WATCHREX_LOCATION_LABEL and WATCHREX_LOCATION_COUNTRY in .env to rename it.', ['loc' => config('watchrex.location')]) }}</p>
    </form>
</div>
@endsection

@extends('layouts.app')
@section('title', $server->exists ? __('Edit server') : __('Add server'))
@section('content')
<div class="page-head"><div><h1>{{ $server->exists ? __('Edit server') : __('Add server') }}</h1><div class="sub">{{ __('After saving you get a one-line installer for the agent.') }}</div></div></div>
<form method="POST" action="{{ $server->exists ? route('servers.update', $server) : route('servers.store') }}" class="grid g-main">
    @csrf @if ($server->exists) @method('PUT') @endif
    <div>
        <fieldset>
            <legend>{{ __('Server') }}</legend>
            <x-field name="name" :label="__('Name')" :value="$server->name" required placeholder="cpanel-01 / DA-Germany / docker-prod" />
            <x-team-select :teams="$teams" :value="$server->team_id" />
            <x-field name="report_interval" type="number" :label="__('Expected report interval (s)')" :value="$server->report_interval" min="30" max="3600" required />
        </fieldset>
        <fieldset>
            <legend>{{ __('Alert thresholds') }}</legend>
            <div class="grid g-3">
                @foreach (['cpu' => 'CPU %', 'ram' => 'RAM %', 'disk' => __('Disk %'), 'load' => 'Load', 'mail_queue' => __('Mail queue size'), 'login_failed' => __('Failed mail logins / interval')] as $k => $label)
                    <x-field :name="'thresholds['.$k.']'" :dot-name="'thresholds.'.$k" type="number" step="any" :label="$label" :value="$server->thresholds[$k] ?? \App\Models\Server::DEFAULT_THRESHOLDS[$k]" />
                @endforeach
            </div>
            <p class="small faint">{{ __('Leave empty to disable a threshold. Stopped services and exited containers always raise a warning.') }}</p>
        </fieldset>
        <button class="btn primary">{{ __('Save') }}</button>
    </div>
</form>
@endsection

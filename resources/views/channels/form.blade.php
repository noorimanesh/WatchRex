@extends('layouts.app')
@section('title', $channel->exists ? __('Edit channel') : __('Add channel'))
@section('content')
<div class="page-head"><div><h1>{{ $channel->exists ? __('Edit channel') : __('Add channel') }}</h1></div></div>
@if (! $channel->exists)
    <div class="type-grid mb">
        @foreach (\App\Models\NotificationChannel::TYPES as $t => $label)
            <a href="{{ route('channels.create', ['type' => $t]) }}" class="btn {{ $channel->type === $t ? 'primary' : '' }}">{{ $label }}</a>
        @endforeach
    </div>
@endif
<form method="POST" action="{{ $channel->exists ? route('channels.update', $channel) : route('channels.store') }}" class="card card-pad" style="max-width:640px">
    @csrf @if ($channel->exists) @method('PUT') @endif
    <input type="hidden" name="type" value="{{ $channel->type }}">
    <x-field name="name" :label="__('Name')" :value="$channel->name ?? \App\Models\NotificationChannel::TYPES[$channel->type]" required />
    @foreach (\App\Models\NotificationChannel::FIELDS[$channel->type] ?? [] as $key => [$label, $secret])
        <x-field :name="'config['.$key.']'" :dot-name="'config.'.$key" :type="$secret ? 'password' : 'text'" :label="__($label)" :value="$secret ? null : ($channel->config[$key] ?? null)" class="ltr" autocomplete="off"
            :placeholder="$secret && ! empty($channel->config[$key]) ? '•••••••• ('.__('unchanged').')' : ''" />
    @endforeach
    @if ($channel->type === 'telegram')<p class="small muted">{{ __('Create a bot with @BotFather, add it to your group, then get the chat ID (e.g. via @userinfobot). In Iran you can set a proxy/worker URL as API base.') }}</p>@endif
    @if ($channel->type === 'bale')<p class="small muted">{{ __('Create a bot with @BotFather inside Bale (بله) and use its token.') }}</p>@endif
    @if ($channel->type === 'webhook')<p class="small muted">{{ __('WatchRex POSTs JSON with X-WatchRex-Event and (if a secret is set) an X-WatchRex-Signature: sha256=HMAC header.') }}</p>@endif
    <x-team-select :teams="$teams" :value="$channel->team_id" />
    <label class="check"><input type="checkbox" name="is_default" value="1" @checked(old('is_default', $channel->is_default))> {{ __('Default channel (used by monitors without explicit channels, domains and servers)') }}</label>
    <label class="check"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $channel->is_active))> {{ __('Active') }}</label>
    <div class="row mt"><button class="btn primary">{{ __('Save') }}</button><a class="btn ghost" href="{{ route('channels.index') }}">{{ __('Cancel') }}</a></div>
</form>
@endsection

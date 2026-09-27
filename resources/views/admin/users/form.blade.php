@extends('layouts.app')
@section('title', $user->exists ? __('Edit user') : __('New user'))
@section('content')
<div class="page-head"><div><h1>{{ $user->exists ? $user->name : __('New user') }}</h1></div></div>
<form method="POST" action="{{ $user->exists ? route('admin.users.update', $user) : route('admin.users.store') }}" class="grid g-2">
    @csrf @if ($user->exists) @method('PUT') @endif
    <div class="card card-pad">
        <x-field name="name" :label="__('Name')" :value="$user->name" required />
        <x-field name="email" type="email" :label="__('E-mail')" :value="$user->email" required class="ltr" />
        <x-field name="password" type="password" :label="$user->exists ? __('New password (leave empty to keep)') : __('Password')" autocomplete="new-password" :required="! $user->exists" />
        <div class="grid g-2">
            <div class="field"><label>{{ __('Language') }}</label><select name="locale" class="input">@foreach (config('watchrex.locales') as $k => $l)<option value="{{ $k }}" @selected(old('locale', $user->locale) === $k)>{{ $l }}</option>@endforeach</select></div>
            <x-field name="timezone" :label="__('Timezone')" :value="$user->timezone" required class="ltr" />
        </div>
        <label class="check"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $user->is_active))> {{ __('Account active') }}</label>
        @if ($user->exists && $user->hasTwoFactor())<label class="check"><input type="checkbox" name="reset_2fa" value="1"> {{ __('Reset two-factor authentication') }}</label>@endif
    </div>
    <div class="card card-pad">
        <div class="field"><label>{{ __('Role') }}</label><select name="role" class="input">@foreach (\App\Enums\UserRole::cases() as $r)<option value="{{ $r->value }}" @selected(old('role', $user->role?->value) === $r->value)>{{ $r->label() }}</option>@endforeach</select></div>
        <div class="field"><label>{{ __('Plan') }}</label><select name="plan" class="input">@foreach (config('watchrex.plans') as $k => $p)<option value="{{ $k }}" @selected(old('plan', $user->plan) === $k)>{{ $p['label'] }} — {{ $p['max_monitors'] ?? '∞' }} {{ __('monitors') }}, {{ $p['min_interval'] }}s</option>@endforeach</select></div>
        <div class="grid g-2">
            <x-field name="max_monitors" type="number" :label="__('Max monitors (override)')" :value="$user->max_monitors" min="0" :help="__('Empty = plan default')" />
            <x-field name="min_interval" type="number" :label="__('Min interval s (override)')" :value="$user->min_interval" min="10" />
        </div>
        <button class="btn primary">{{ __('Save') }}</button>
    </div>
</form>
@endsection

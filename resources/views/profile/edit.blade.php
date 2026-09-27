@extends('layouts.app')
@section('title', __('Profile & security'))
@section('content')
<div class="page-head"><div><h1>{{ __('Profile & security') }}</h1><div class="sub">{{ __('Plan') }}: <b>{{ config('watchrex.plans.'.$user->plan.'.label', $user->plan) }}</b> · {{ __('Monitors') }} {{ $user->monitors()->count() }}/{{ $user->limit('max_monitors') ?? '∞' }} · {{ __('Min interval') }} {{ $user->limit('min_interval') }}s</div></div></div>

<div class="grid g-2">
    <form method="POST" action="{{ route('profile.update') }}" class="card card-pad">
        @csrf @method('PUT')
        <h2 class="mb">{{ __('Profile') }}</h2>
        <x-field name="name" :label="__('Name')" :value="$user->name" required />
        <x-field name="email" type="email" :label="__('E-mail')" :value="$user->email" required class="ltr" />
        <div class="grid g-2">
            <div class="field"><label>{{ __('Language') }}</label><select name="locale" class="input">@foreach (config('watchrex.locales') as $k => $l)<option value="{{ $k }}" @selected($user->locale === $k)>{{ $l }}</option>@endforeach</select></div>
            <div class="field"><label>{{ __('Timezone') }}</label><select name="timezone" class="input">@foreach (\DateTimeZone::listIdentifiers() as $tz)<option @selected($user->timezone === $tz)>{{ $tz }}</option>@endforeach</select></div>
        </div>
        <button class="btn primary">{{ __('Save') }}</button>
    </form>

    <form method="POST" action="{{ route('profile.password') }}" class="card card-pad">
        @csrf @method('PUT')
        <h2 class="mb">{{ __('Change password') }}</h2>
        <x-field name="current_password" type="password" :label="__('Current password')" autocomplete="current-password" required />
        <x-field name="password" type="password" :label="__('New password')" autocomplete="new-password" required :help="__('Min. 10 characters with upper/lower case letters and numbers.')" />
        <x-field name="password_confirmation" type="password" :label="__('Confirm new password')" autocomplete="new-password" required />
        <button class="btn primary">{{ __('Update password') }}</button>
    </form>

    <div class="card card-pad" id="security">
        <h2 class="mb">🔐 {{ __('Two-factor authentication') }}</h2>
        @if ($recoveryCodes)
            <div class="alert warn small">{{ __('Store these recovery codes safely — they are shown only once.') }}</div>
            <div class="secret" id="rc">{{ implode('  ', $recoveryCodes) }}</div><button class="btn sm mt-s" data-copy="#rc">{{ __('Copy') }}</button>
        @endif
        @if ($user->hasTwoFactor())
            <p><span class="badge up">{{ __('Enabled') }}</span> <span class="small muted">{{ __('since') }} {{ Fmt::date($user->two_factor_confirmed_at) }} · {{ count($user->two_factor_recovery_codes ?? []) }} {{ __('recovery codes left') }}</span></p>
            <form method="POST" action="{{ route('profile.2fa.disable') }}" class="mt">@csrf @method('DELETE')
                <x-field name="current_password" type="password" :label="__('Confirm with your password to disable')" required />
                <button class="btn danger">{{ __('Disable 2FA') }}</button></form>
        @elseif ($pendingSecret)
            <p class="small muted">{{ __('Scan with Google Authenticator, Authy, Microsoft Authenticator or 1Password, then enter the code.') }}</p>
            <div class="qr">{!! $qr !!}</div>
            <div class="secret mt-s small">{{ chunk_split($pendingSecret, 4, ' ') }}</div>
            <form method="POST" action="{{ route('profile.2fa.confirm') }}" class="row mt">@csrf<input class="input ltr" name="code" placeholder="123456" inputmode="numeric" autocomplete="one-time-code" style="max-width:160px"><button class="btn primary">{{ __('Confirm') }}</button></form>
            @error('code')<div class="error">{{ $message }}</div>@enderror
        @else
            <p class="small muted">{{ __('Protect your account with a time-based one-time code (TOTP).') }}</p>
            <form method="POST" action="{{ route('profile.2fa.enable') }}">@csrf<button class="btn primary">{{ __('Enable 2FA') }}</button></form>
        @endif
        <div class="label mt">{{ __('Recent sign-in activity') }}</div>
        <table class="table small mt-s"><tbody>
            @forelse ($logins as $l)<tr><td><span class="badge {{ $l->action === 'auth.login' ? 'up' : 'down' }}">{{ $l->action === 'auth.login' ? __('Success') : __('Failed') }}</span></td><td class="ltr">{{ $l->ip }}</td><td class="muted">{{ Fmt::date($l->created_at) }}</td></tr>@empty<tr><td class="muted">—</td></tr>@endforelse
        </tbody></table>
    </div>

    <div class="card card-pad" id="api">
        <h2 class="mb">🔑 {{ __('API tokens') }}</h2>
        @if ($newToken)
            <div class="alert warn small">{{ __('Copy your token now — it will not be shown again.') }}</div>
            <div class="secret" id="nt">{{ $newToken }}</div><button class="btn sm mt-s" data-copy="#nt">{{ __('Copy') }}</button>
        @endif
        <form method="POST" action="{{ route('profile.tokens.store') }}" class="row wrap mt">@csrf
            <input class="input" name="name" placeholder="{{ __('Token name') }}" required style="flex:1;min-width:140px">
            <input class="input" type="number" name="expires_days" placeholder="{{ __('Expires (days)') }}" min="1" style="width:130px">
            <label class="check" style="margin:0"><input type="checkbox" name="can_write" value="1"> {{ __('Write') }}</label>
            <button class="btn">{{ __('Create') }}</button>
        </form>
        <table class="table small mt"><tbody>
            @forelse ($tokens as $t)
                <tr><td><b>{{ $t->name }}</b> @if ($t->can_write)<span class="chip">write</span>@endif</td><td class="muted">{{ __('used') }} {{ $t->last_used_at?->diffForHumans() ?? __('never') }}</td><td class="muted">{{ $t->expires_at ? __('expires').' '.Fmt::date($t->expires_at, false) : '' }}</td>
                    <td><form method="POST" action="{{ route('profile.tokens.destroy', $t) }}" data-confirm="{{ __('Revoke this token?') }}">@csrf @method('DELETE')<button class="btn sm danger">{{ __('Revoke') }}</button></form></td></tr>
            @empty<tr><td class="muted">{{ __('No tokens.') }}</td></tr>@endforelse
        </tbody></table>
        <pre class="mt">curl -H "Authorization: Bearer wrx_api_…" {{ url('/api/v1/monitors') }}</pre>
        <p class="small muted mt-s">GET /api/v1/summary · /monitors · /monitors/{id} · /monitors/{id}/heartbeats · /incidents · /servers · POST /monitors/{id}/toggle</p>
    </div>
</div>
@endsection

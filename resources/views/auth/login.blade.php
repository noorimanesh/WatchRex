@extends('layouts.guest')
@section('title', __('Sign in'))
@section('content')
<h1 style="margin-bottom:4px">{{ __('Welcome back') }}</h1>
<p class="muted" style="margin-bottom:20px">{{ __('Sign in to your monitoring dashboard.') }}</p>
@if (config('watchrex.sso.enabled'))
    <a class="btn primary" style="width:100%" href="{{ route('sso.redirect') }}"><x-icon name="shield"/>{{ __('Sign in with :p', ['p' => config('watchrex.sso.label')]) }}</a>
    <div class="row" style="margin:16px 0;color:var(--faint)"><hr style="flex:1;margin:0"><span class="small">{{ __('or') }}</span><hr style="flex:1;margin:0"></div>
@endif
<form method="POST" action="{{ route('login') }}">
    @csrf
    <x-field name="email" type="email" :label="__('E-mail')" autocomplete="username" required autofocus class="ltr" />
    <x-field name="password" type="password" :label="__('Password')" autocomplete="current-password" required class="ltr" />
    <label class="check"><input type="checkbox" name="remember" value="1"> {{ __('Remember me') }}</label>
    <button class="btn {{ config('watchrex.sso.enabled') ? '' : 'primary' }}" style="width:100%;margin-top:8px">{{ __('Sign in') }}</button>
</form>
@endsection

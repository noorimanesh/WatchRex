@extends('layouts.guest')
@section('title', __('Sign in'))
@section('content')
<h1 style="margin-bottom:4px">{{ __('Welcome back') }}</h1>
<p class="muted" style="margin-bottom:20px">{{ __('Sign in to your monitoring dashboard.') }}</p>
<form method="POST" action="{{ route('login') }}">
    @csrf
    <x-field name="email" type="email" :label="__('E-mail')" autocomplete="username" required autofocus class="ltr" />
    <x-field name="password" type="password" :label="__('Password')" autocomplete="current-password" required class="ltr" />
    <label class="check"><input type="checkbox" name="remember" value="1"> {{ __('Remember me') }}</label>
    <button class="btn primary" style="width:100%;margin-top:8px">{{ __('Sign in') }}</button>
</form>
@endsection

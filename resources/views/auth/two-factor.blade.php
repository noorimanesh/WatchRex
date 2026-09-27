@extends('layouts.guest')
@section('title', __('Two-factor authentication'))
@section('content')
<h1 style="margin-bottom:4px">{{ __('Two-factor authentication') }}</h1>
<p class="muted" style="margin-bottom:20px">{{ __('Enter the 6-digit code from your authenticator app, or one of your recovery codes.') }}</p>
<form method="POST" action="{{ route('two-factor.challenge') }}">
    @csrf
    <x-field name="code" :label="__('Code')" autocomplete="one-time-code" inputmode="numeric" required autofocus class="ltr" />
    <button class="btn primary" style="width:100%">{{ __('Verify') }}</button>
</form>
@endsection

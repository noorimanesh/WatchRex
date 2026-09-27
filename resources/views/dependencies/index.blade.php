@extends('layouts.app')
@section('title', __('Dependency map'))
@section('content')
<div class="page-head">
    <div><h1>🕸️ {{ __('Dependency map') }}</h1><div class="sub">{{ __('How your services depend on each other. When a parent fails, dependants are marked as impacted and their alerts are suppressed.') }}</div></div>
    <div class="legend" style="margin:0"><span><i style="background:var(--up)"></i>{{ __('Up') }}</span><span><i style="background:var(--warn)"></i>{{ __('Degraded') }} / {{ __('impacted') }}</span><span><i style="background:var(--down)"></i>{{ __('Down') }}</span></div>
</div>
<div data-live="{{ route('dependencies.live') }}" data-live-every="30">
    @include('dependencies._map')
</div>
@endsection

<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'fa' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', __('Dashboard')) · WatchRex</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;500;600;700;800&display=swap">
    <link rel="stylesheet" href="{{ asset('assets/app.css') }}?v={{ config('watchrex.version') }}">
    <script src="{{ asset('assets/app.js') }}?v={{ config('watchrex.version') }}" defer></script>
</head>
<body>
@php
    $user = auth()->user();
    $openIncidents = \App\Models\Incident::visibleTo($user)->whereNull('resolved_at')->count();
    $nav = fn ($pattern) => request()->routeIs($pattern) ? 'active' : '';
@endphp
<div class="shell">
    <aside class="sidebar">
        <a href="{{ route('dashboard') }}" class="brand">
            <x-logo />
            <div><div class="brand-name">Watch<b>Rex</b></div><div class="brand-sub">Infrastructure &amp; Uptime Monitoring</div></div>
        </a>
        <nav class="nav">
            <a href="{{ route('dashboard') }}" class="{{ $nav('dashboard') }}"><x-icon name="dashboard"/>{{ __('Dashboard') }}</a>
            <a href="{{ route('monitors.index') }}" class="{{ $nav('monitors.*') }}"><x-icon name="activity"/>{{ __('Monitors') }}</a>
            <a href="{{ route('dependencies.index') }}" class="{{ $nav('dependencies.*') }}"><x-icon name="box"/>{{ __('Dependency map') }}</a>
            <a href="{{ route('servers.index') }}" class="{{ $nav('servers.*') }}"><x-icon name="server"/>{{ __('Servers') }}</a>
            <a href="{{ route('domains.index') }}" class="{{ $nav('domains.*') }}"><x-icon name="globe"/>{{ __('Domains & SSL') }}</a>
            <a href="{{ route('incidents.index') }}" class="{{ $nav('incidents.*') }}"><x-icon name="alert"/>{{ __('Incidents') }}@if ($openIncidents)<span class="count">{{ $openIncidents }}</span>@endif</a>

            <div class="nav-title">{{ __('Configure') }}</div>
            <a href="{{ route('channels.index') }}" class="{{ $nav('channels.*') }}"><x-icon name="bell"/>{{ __('Alert channels') }}</a>
            <a href="{{ route('status-pages.index') }}" class="{{ $nav('status-pages.*') }}"><x-icon name="layout"/>{{ __('Status pages') }}</a>
            <a href="{{ route('maintenance.index') }}" class="{{ $nav('maintenance.*') }}"><x-icon name="wrench"/>{{ __('Maintenance') }}</a>
            <a href="{{ route('billing.index') }}" class="{{ $nav('billing.*') }}"><x-icon name="zap"/>{{ __('Plan & billing') }}</a>
            <a href="{{ route('teams.index') }}" class="{{ $nav('teams.*') }}"><x-icon name="users"/>{{ __('Teams') }}</a>

            @if ($user->isAdmin())
                <div class="nav-title">{{ __('Administration') }}</div>
                <a href="{{ route('admin.users.index') }}" class="{{ $nav('admin.users.*') }}"><x-icon name="users"/>{{ __('Users') }}</a>
                <a href="{{ route('admin.billing') }}" class="{{ $nav('admin.billing') }}"><x-icon name="list"/>{{ __('Billing') }}</a>
                <a href="{{ route('admin.probes.index') }}" class="{{ $nav('admin.probes.*') }}"><x-icon name="globe"/>{{ __('Probe locations') }}</a>
                <a href="{{ route('admin.system') }}" class="{{ $nav('admin.system') }}"><x-icon name="settings"/>{{ __('System health') }}</a>
                <a href="{{ route('admin.audit') }}" class="{{ $nav('admin.audit') }}"><x-icon name="shield"/>{{ __('Audit log') }}</a>
            @endif
        </nav>
        <div class="sidebar-foot">
            WatchRex v{{ config('watchrex.version') }}<br>
            {{ __('Developed by') }} <a href="{{ config('watchrex.vendor.url') }}" target="_blank" rel="noopener">{{ app()->getLocale() === 'fa' ? 'فابا پارس' : 'Fabapars' }}</a>
        </div>
    </aside>

    <div class="main">
        <header class="topbar">
            <button class="btn ghost icon-btn menu-toggle" data-menu aria-label="Menu"><x-icon name="menu"/></button>
            <form class="search" action="{{ route('monitors.index') }}">
                <input class="input" type="search" name="q" value="{{ request('q') }}" placeholder="{{ __('Search monitors…') }}">
            </form>
            <div class="spacer"></div>
            <button class="btn ghost icon-btn" data-theme-toggle title="{{ __('Toggle theme') }}"><x-icon name="theme"/></button>
            <a class="btn ghost" href="{{ route('profile.edit') }}"><x-icon name="user"/><span class="truncate" style="max-width:140px">{{ $user->name }}</span></a>
            <form method="POST" action="{{ route('logout') }}" class="inline">@csrf<button class="btn ghost icon-btn" title="{{ __('Sign out') }}"><x-icon name="logout"/></button></form>
        </header>

        <main class="content">
            @if (session('success'))<div class="alert success">{{ session('success') }}</div>@endif
            @if (session('error'))<div class="alert error">{{ session('error') }}</div>@endif
            @if (! $user->canWrite())<div class="alert info">{{ __('You have read-only access.') }}</div>@endif
            @yield('content')
        </main>
    </div>
</div>
</body>
</html>

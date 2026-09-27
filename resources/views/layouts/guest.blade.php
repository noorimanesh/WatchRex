<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'fa' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title') · WatchRex</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;600;800&display=swap">
    <link rel="stylesheet" href="{{ asset('assets/app.css') }}?v={{ config('watchrex.version') }}">
    <script src="{{ asset('assets/app.js') }}?v={{ config('watchrex.version') }}" defer></script>
</head>
<body>
<div class="auth">
    <div>
        <div class="card auth-card">
            <div class="row" style="gap:12px;margin-bottom:22px">
                <x-logo :size="44"/>
                <div><div class="brand-name">Watch<b>Rex</b></div><div class="brand-sub">Infrastructure &amp; Uptime Monitoring</div></div>
            </div>
            @yield('content')
        </div>
        <div class="auth-foot">
            <a href="?lang=fa">فارسی</a> · <a href="?lang=en">English</a><br>
            © {{ date('Y') }} <a href="{{ config('watchrex.vendor.url') }}" target="_blank" rel="noopener">{{ app()->getLocale() === 'fa' ? 'فابا پارس' : 'Fabapars' }}</a>
        </div>
    </div>
</div>
</body>
</html>

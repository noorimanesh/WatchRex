<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'fa' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="wr-reload" content="60">
    <title>{{ $page->title }}</title>
    <meta name="description" content="{{ $page->description ?? $page->title }}">
    <link rel="alternate" type="application/rss+xml" href="{{ route('status.rss', $page->slug) }}">
    <link rel="icon" href="{{ $page->logo_url ?: asset('favicon.svg') }}">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;600;800&display=swap">
    <link rel="stylesheet" href="{{ asset('assets/app.css') }}?v={{ config('watchrex.version') }}">
    <style>:root { --brand: {{ $page->accent }}; }</style>
    <script src="{{ asset('assets/app.js') }}?v={{ config('watchrex.version') }}" defer></script>
</head>
<body>
@php
    $labels = [
        'operational' => __('All systems operational'),
        'degraded' => __('Some systems are degraded'),
        'partial_outage' => __('Partial outage'),
        'major_outage' => __('Major outage'),
        'maintenance' => __('Scheduled maintenance in progress'),
    ];
@endphp
<div class="status-wrap">
    <div class="row between">
        <div class="row">
            @if ($page->logo_url)<img src="{{ $page->logo_url }}" alt="" style="height:40px;max-width:180px;object-fit:contain">@endif
            <h1>{{ $page->title }}</h1>
        </div>
        <button class="btn ghost icon-btn" data-theme-toggle aria-label="theme"><x-icon name="theme"/></button>
    </div>
    @if ($page->description)<p class="muted mt-s">{{ $page->description }}</p>@endif

    <div class="status-hero {{ $overall }}">{{ $overall === 'operational' ? '✓' : '!' }} {{ $labels[$overall] }}</div>

    @foreach ($maintenance as $w)
        <div class="alert info"><b>🛠 {{ $w->title }}</b> — {{ Fmt::date($w->starts_at) }} → {{ Fmt::date($w->ends_at) }}@if ($w->description)<div class="small">{{ $w->description }}</div>@endif</div>
    @endforeach

    <div class="card">
        @foreach ($monitors as $m)
            <div class="status-item">
                <div class="row between">
                    <b>{{ $m->pivot->display_name ?: $m->name }}</b>
                    <div class="row small">
                        @if ($page->show_response && $m->last_response_ms)<span class="muted ltr">{{ Fmt::ms($m->last_response_ms) }}</span>@endif
                        @if ($page->show_uptime)<span class="muted">{{ Fmt::uptime($m->uptime_90d) }}</span>@endif
                        <x-status :status="$m->status" />
                    </div>
                </div>
                @if ($page->show_uptime)<div class="mt-s"><x-bars :bars="$m->bars" /></div>@endif
            </div>
        @endforeach
        @if ($monitors->isEmpty())<div class="status-item muted">{{ __('No services configured.') }}</div>@endif
    </div>
    @if ($page->show_uptime)<div class="row between small faint mt-s"><span>{{ __('90 days ago') }}</span><span>{{ __('Today') }}</span></div>@endif

    <h2 class="mt" style="margin-top:34px">{{ __('Past incidents') }}</h2>
    <div class="card mt">
        @forelse ($incidents as $i)
            <div class="status-item">
                <div class="row between"><b>{{ $i->title }}</b><span class="badge {{ $i->isOpen() ? 'down' : 'up' }}">{{ $i->isOpen() ? __('Investigating') : __('Resolved') }}</span></div>
                <div class="small muted">{{ Fmt::date($i->started_at) }} · {{ Fmt::duration($i->durationSeconds()) }}</div>
                @foreach ($i->updates->where('type', 'public') as $u)<p class="small mt-s">{{ $u->message }}</p>@endforeach
            </div>
        @empty
            <div class="status-item muted">{{ __('No incidents in the last 14 days.') }}</div>
        @endforelse
    </div>

    <div class="auth-foot" style="margin-top:30px">
        {{ $page->footer_text }}
        <div class="mt-s"><a href="{{ route('status.rss', $page->slug) }}">RSS</a> · <a href="{{ route('status.json', $page->slug) }}">JSON</a>
        @unless ($page->hide_branding) · {{ __('Powered by') }} <a href="{{ config('watchrex.vendor.url') }}" target="_blank" rel="noopener">WatchRex · Fabapars</a>@endunless</div>
    </div>
</div>
</body>
</html>

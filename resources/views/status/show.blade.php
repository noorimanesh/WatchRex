@php
    $theme = $page->option('theme');
    $layout = $page->option('layout') === 'cards' ? 'cards' : 'list';
    $labels = [
        'operational' => __('All systems operational'),
        'degraded' => __('Some systems are degraded'),
        'partial_outage' => __('Partial outage'),
        'major_outage' => __('Major outage'),
        'maintenance' => __('Scheduled maintenance in progress'),
    ];
    $statusLabels = ['up' => __('Operational'), 'warning' => __('Degraded'), 'down' => __('Outage'), 'maintenance' => __('Maintenance'), 'pending' => __('Pending'), 'paused' => __('Paused')];
    $issues = collect($sections)->whereIn('status', ['down', 'warning', 'maintenance'])->count();
    $level = in_array($page->option('announcement_level'), ['info', 'warn', 'success', 'error'], true) ? $page->option('announcement_level') : 'info';
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'fa' ? 'rtl' : 'ltr' }}" @if (in_array($theme, ['light', 'dark'], true)) data-theme="{{ $theme }}" data-theme-lock @endif>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="wr-reload" content="60">
    <title>{{ $overall === 'operational' ? '' : '⚠ ' }}{{ $page->title }}</title>
    <meta name="description" content="{{ $page->description ?? $page->title }}">
    <meta property="og:title" content="{{ $page->title }} — {{ $labels[$overall] }}">
    <meta property="og:description" content="{{ $page->description ?? $labels[$overall] }}">
    <link rel="alternate" type="application/rss+xml" href="{{ route('status.rss', $page->slug) }}">
    <link rel="icon" href="{{ $page->logo_url ?: asset('favicon.svg') }}">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;600;800&display=swap">
    <link rel="stylesheet" href="{{ asset('assets/app.css') }}?v={{ config('watchrex.version') }}">
    <style>:root { --brand: {{ $page->accent }}; }</style>
    <script src="{{ asset('assets/app.js') }}?v={{ config('watchrex.version') }}" defer></script>
</head>
<body class="sp-body">
<div class="status-wrap">
    <header class="sp-head">
        <div class="row" style="min-width:0">
            @if ($page->logo_url)<img src="{{ $page->logo_url }}" alt="" class="sp-logo">@endif
            <div style="min-width:0">
                <h1 class="truncate">{{ $page->title }}</h1>
                @if ($page->description)<p class="muted small" style="margin:2px 0 0">{{ $page->description }}</p>@endif
            </div>
        </div>
        <div class="row">
            @if ($page->option('support_url'))<a class="btn ghost sm" href="{{ $page->option('support_url') }}" target="_blank" rel="noopener">{{ __('Get support') }}</a>@endif
            @if ($page->allow_subscribers)<a class="btn ghost sm" href="#subscribe">🔔 {{ __('Subscribe') }}</a>@endif
            @unless (in_array($theme, ['light', 'dark'], true))<button class="btn ghost icon-btn" data-theme-toggle aria-label="theme"><x-icon name="theme"/></button>@endunless
        </div>
    </header>

    @if ($page->option('announcement'))
        <div class="alert {{ $level }} sp-announce">📣 {{ $page->option('announcement') }}</div>
    @endif

    <div class="status-hero {{ $overall }}">
        <span class="sp-hero-ico">{{ $overall === 'operational' ? '✓' : '!' }}</span>
        <div style="flex:1">
            {{ $labels[$overall] }}
            <div class="sp-hero-sub">{{ __('Last updated') }}: {{ Fmt::date($updatedAt) }} · {{ __('Refreshes every minute') }}</div>
        </div>
    </div>

    <div class="sp-kpis">
        <div class="sp-kpi"><b>{{ $counts['total'] }}</b><span>{{ __('Services') }}</span></div>
        <div class="sp-kpi"><b class="text-up">{{ $counts['up'] }}</b><span>{{ __('Operational') }}</span></div>
        <div class="sp-kpi"><b class="{{ $counts['down'] + $counts['warning'] ? 'text-down' : '' }}">{{ $counts['down'] + $counts['warning'] }}</b><span>{{ __('With issues') }}</span></div>
        @if ($page->show_uptime)
            <div class="sp-kpi"><b>{{ Fmt::uptime($uptime_24h) }}</b><span>{{ __('Uptime 24h') }}</span></div>
            <div class="sp-kpi"><b>{{ Fmt::uptime($uptime) }}</b><span>{{ __('Uptime :n days', ['n' => $historyDays]) }}</span></div>
        @endif
    </div>

    @foreach ($maintenance as $w)
        <div class="alert info"><b>🛠 {{ $w->title }}</b> — {{ Fmt::date($w->starts_at) }} → {{ Fmt::date($w->ends_at) }}@if ($w->description)<div class="small">{{ $w->description }}</div>@endif</div>
    @endforeach

    @if ($page->option('show_filters') && count($sections) > 1)
        <div class="sp-filters" data-sp-filters>
            <button type="button" class="sp-chip active" data-sp-filter="all">{{ __('All') }} <span>{{ count($sections) }}</span></button>
            <button type="button" class="sp-chip" data-sp-filter="issues">{{ __('Issues only') }} <span>{{ $issues }}</span></button>
            @foreach (collect($sections)->pluck('kind')->unique()->filter(fn ($k) => isset(\App\Models\MonitorGroup::KINDS[$k])) as $kind)
                @if (collect($sections)->where('kind', $kind)->count() > 1)
                    <button type="button" class="sp-chip" data-sp-filter="kind-{{ $kind }}">{{ \App\Models\MonitorGroup::KINDS[$kind][1] }} {{ __(\App\Models\MonitorGroup::KINDS[$kind][0]) }}</button>
                @endif
            @endforeach
            @foreach ($sections as $s)
                <button type="button" class="sp-chip" data-sp-filter="{{ $s['key'] }}"><span class="dot {{ $s['status'] }}"></span>{{ $s['name'] }}</button>
            @endforeach
        </div>
    @endif

    <div class="sp-sections {{ $layout }}">
        @forelse ($sections as $s)
            <section class="card sp-section {{ $s['status'] }}" id="{{ $s['key'] }}" data-sp-section="{{ $s['key'] }}" data-sp-kind="kind-{{ $s['kind'] }}" data-sp-issue="{{ in_array($s['status'], ['down', 'warning', 'maintenance'], true) ? 1 : 0 }}">
                <details @if ($s['expanded'] || $s['status'] === 'down') open @endif>
                    <summary class="sp-sum">
                        <span class="sp-ico">{{ $s['icon'] }}</span>
                        <div class="sp-title">
                            <b>{{ $s['name'] }}</b>
                            <div class="small muted">
                                @if ($s['domain'])<span class="ltr">{{ $s['domain'] }}</span> · @endif
                                {{ trans_choice(':count service|:count services', $s['counts']['total']) }}
                                @if ($s['counts']['down'])· <span class="text-down">{{ __(':n down', ['n' => $s['counts']['down']]) }}</span>@endif
                                @if ($s['counts']['warning'])· <span class="text-warn">{{ __(':n degraded', ['n' => $s['counts']['warning']]) }}</span>@endif
                            </div>
                        </div>
                        @if ($s['chart'])<svg class="sp-spark" viewBox="0 0 160 34" preserveAspectRatio="none" aria-hidden="true"><path d="{{ $s['chart'] }}"/></svg>@endif
                        @if ($page->show_uptime)<span class="sp-pct">{{ Fmt::uptime($s['uptime']) }}</span>@endif
                        <span class="badge {{ $s['status'] }}"><span class="dot {{ $s['status'] }}"></span>{{ $statusLabels[$s['status']] ?? $s['status'] }}</span>
                    </summary>

                    @if ($page->show_uptime)
                        <div class="sp-pad"><x-bars :bars="$s['bars']" /></div>
                    @endif

                    @if ($s['details'])
                        <div class="sp-facts">
                            @foreach ($s['details'] as $d)
                                <div class="sp-fact {{ $d['status'] }}"><span>{{ $d['label'] }}</span><b class="ltr">{{ $d['value'] }}</b></div>
                            @endforeach
                            @if ($page->show_uptime && $s['uptime_24h'] !== null)<div class="sp-fact"><span>{{ __('Uptime 24h') }}</span><b>{{ Fmt::uptime($s['uptime_24h']) }}</b></div>@endif
                        </div>
                    @endif

                    <div class="sp-members">
                        @foreach ($s['monitors'] as $m)
                            <div class="sp-member">
                                <div class="row" style="min-width:0;gap:8px">
                                    <span class="dot {{ $m->public_status }}"></span>
                                    <span class="truncate">{{ $m->public_name }}</span>
                                    <span class="chip">{{ $m->type->label() }}</span>
                                </div>
                                <div class="row small" style="gap:10px">
                                    @if ($page->show_response && $m->last_response_ms && $m->public_status !== 'down')<span class="muted ltr">{{ Fmt::ms($m->last_response_ms) }}</span>@endif
                                    @if ($page->show_uptime)<span class="muted" title="{{ __('Uptime 24h') }}">{{ Fmt::uptime($m->uptime_24h ?? $m->uptime_period) }}</span>@endif
                                    <span class="sp-st {{ $m->public_status }}">{{ $statusLabels[$m->public_status] ?? $m->public_status }}</span>
                                </div>
                                @if ($page->show_uptime && $s['monitors']->count() > 1)<div class="sp-mbars"><x-bars :bars="$m->bars" /></div>@endif
                            </div>
                        @endforeach
                        @if ($s['monitors']->isEmpty())<div class="sp-member muted">{{ __('No services configured.') }}</div>@endif
                    </div>

                    @if ($s['mail'])
                        <div class="sp-pad">
                            <div class="row between" style="margin-bottom:8px">
                                <b>✉️ {{ __('Mail service health') }}</b>
                                <span class="row small">
                                    @if ($s['mail']['score'] !== null)<span class="chip">{{ __('Score') }}: {{ $s['mail']['score'] }}/100</span>@endif
                                    <span class="badge {{ $s['mail']['overall'] === 'unknown' ? '' : $s['mail']['overall'] }}">{{ $statusLabels[$s['mail']['overall']] ?? __('Unknown') }}</span>
                                </span>
                            </div>
                            @include('groups._mail', ['mail' => $s['mail']])
                        </div>
                    @endif
                </details>
            </section>
        @empty
            <div class="card status-item muted">{{ __('No services configured.') }}</div>
        @endforelse
    </div>
    @if ($page->show_uptime && $sections)<div class="row between small faint mt-s"><span>{{ __(':n days ago', ['n' => $historyDays]) }}</span><span>{{ __('Today') }}</span></div>@endif

    <h2 style="margin-top:34px">{{ __('Past incidents') }}</h2>
    <div class="card mt">
        @forelse ($incidents as $i)
            <div class="status-item">
                <div class="row between"><b>{{ $i->title }}</b><span class="badge {{ $i->isOpen() ? 'down' : 'up' }}">{{ $i->isOpen() ? __('Investigating') : __('Resolved') }}</span></div>
                <div class="small muted">{{ Fmt::date($i->started_at) }} · {{ Fmt::duration($i->durationSeconds()) }}</div>
                @foreach ($i->updates->where('type', 'public') as $u)<p class="small mt-s">{{ $u->message }}</p>@endforeach
            </div>
        @empty
            <div class="status-item muted">{{ __('No incidents in the last :n days.', ['n' => (int) $page->option('incident_days')]) }}</div>
        @endforelse
    </div>

    @if ($page->allow_subscribers)
        <div class="card card-pad" id="subscribe" style="margin-top:24px">
            @if (request()->hasSession() && session('subscribed'))<div class="alert success small">{{ session('subscribed') }}</div>@endif
            <form method="POST" action="{{ route('status.subscribe', $page->slug) }}" class="row wrap">
                @csrf
                <span class="small muted" style="flex-basis:100%">📬 {{ __('Get e-mail notifications about incidents and maintenance') }}</span>
                <input class="input ltr" type="email" name="email" required placeholder="you@example.com" style="flex:1;min-width:200px">
                <button class="btn primary">{{ __('Subscribe') }}</button>
            </form>
            @error('email')<div class="error mt-s">{{ $message }}</div>@enderror
        </div>
    @endif

    <div class="auth-foot" style="margin-top:30px">
        {{ $page->footer_text }}
        <div class="mt-s"><a href="{{ route('status.rss', $page->slug) }}">RSS</a> · <a href="{{ route('status.json', $page->slug) }}">JSON</a>
        @unless ($page->hide_branding) · {{ __('Powered by') }} <a href="{{ config('watchrex.vendor.url') }}" target="_blank" rel="noopener">WatchRex · Fabapars</a>@endunless</div>
    </div>
</div>
</body>
</html>

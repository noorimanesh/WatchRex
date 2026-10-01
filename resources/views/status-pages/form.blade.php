@extends('layouts.app')
@section('title', __('Status page'))
@section('content')
<div class="page-head"><div><h1>{{ $page->exists ? $page->title : __('New status page') }}</h1></div></div>
<form method="POST" action="{{ $page->exists ? route('status-pages.update', $page) : route('status-pages.store') }}" class="grid g-main">
    @csrf @if ($page->exists) @method('PUT') @endif
    <div>
        <fieldset><legend>{{ __('Page') }}</legend>
            <x-field name="title" :label="__('Title')" :value="$page->title" required placeholder="Acme Status" />
            <x-field name="slug" :label="__('Slug')" :value="$page->slug" required class="ltr" :help="url('/status').'/<slug>'" />
            <x-field name="description" type="textarea" :label="__('Description')" :value="$page->description" rows="2" style="font-family:var(--font)" />
            <x-team-select :teams="$teams" :value="$page->team_id" />
            <x-field name="custom_domain" :label="__('Custom domain (optional)')" :value="$page->custom_domain" placeholder="status.company.com" class="ltr" :help="__('Point a CNAME/A record to this server and add the domain to your web server/SSL.')" />
        </fieldset>
        <fieldset><legend>{{ __('Branding') }}</legend>
            <div class="grid g-2">
                <x-field name="logo_url" :label="__('Logo URL (https)')" :value="$page->logo_url" class="ltr" />
                <x-field name="accent" type="color" :label="__('Accent colour')" :value="$page->accent" style="height:42px;padding:4px" />
            </div>
            <x-field name="footer_text" :label="__('Footer text')" :value="$page->footer_text" />
            <label class="check"><input type="checkbox" name="is_public" value="1" @checked(old('is_public', $page->is_public))> {{ __('Public') }}</label>
            <label class="check"><input type="checkbox" name="show_uptime" value="1" @checked(old('show_uptime', $page->show_uptime))> {{ __('Show uptime percentages') }}</label>
            <label class="check"><input type="checkbox" name="show_response" value="1" @checked(old('show_response', $page->show_response))> {{ __('Show response times') }}</label>
            <label class="check"><input type="checkbox" name="allow_subscribers" value="1" @checked(old('allow_subscribers', $page->allow_subscribers))> {{ __('Allow visitors to subscribe to incident e-mails') }} @isset($subscriberCount)<span class="chip">{{ trans_choice(':count subscriber|:count subscribers', $subscriberCount) }}</span>@endisset</label>
            <label class="check"><input type="checkbox" name="hide_branding" value="1" @checked(old('hide_branding', $page->hide_branding))> {{ __('White label (hide "Powered by WatchRex")') }}</label>
        </fieldset>
        <fieldset><legend>{{ __('Appearance & options') }}</legend>
            <div class="grid g-2">
                <div class="field"><label>{{ __('Layout') }}</label><select class="input" name="settings[layout]">@foreach (['list' => __('List'), 'cards' => __('Cards (two columns)')] as $k => $v)<option value="{{ $k }}" @selected(old('settings.layout', $page->option('layout')) === $k)>{{ $v }}</option>@endforeach</select></div>
                <div class="field"><label>{{ __('Theme') }}</label><select class="input" name="settings[theme]">@foreach (['auto' => __('Automatic (visitor choice)'), 'light' => __('Light'), 'dark' => __('Dark')] as $k => $v)<option value="{{ $k }}" @selected(old('settings.theme', $page->option('theme')) === $k)>{{ $v }}</option>@endforeach</select></div>
                <div class="field"><label>{{ __('Uptime history') }}</label><select class="input" name="settings[history_days]">@foreach ([30, 60, 90] as $d)<option value="{{ $d }}" @selected((int) old('settings.history_days', $page->option('history_days')) === $d)>{{ __(':n days', ['n' => $d]) }}</option>@endforeach</select></div>
                <x-field name="settings[incident_days]" type="number" min="1" max="90" :label="__('Show incidents of the last (days)')" :value="old('settings.incident_days', $page->option('incident_days'))" />
            </div>
            @foreach (['show_filters' => __('Show filter chips (all / issues / per group)'), 'show_details' => __('Show website details (SSL, domain expiry, response)'), 'show_mail_health' => __('Show the full mail health checklist for mail groups'), 'show_chart' => __('Show response time trend per group')] as $k => $label)
                <input type="hidden" name="settings[{{ $k }}]" value="0">
                <label class="check"><input type="checkbox" name="settings[{{ $k }}]" value="1" @checked(old('settings.'.$k, $page->option($k)))> {{ $label }}</label>
            @endforeach
            <x-field name="settings[support_url]" :label="__('Support link (optional)')" :value="old('settings.support_url', $page->option('support_url'))" class="ltr" placeholder="https://fabapars.com/support" />
            <div class="grid g-2" style="grid-template-columns:1fr 160px">
                <x-field name="settings[announcement]" :label="__('Announcement banner (optional)')" :value="old('settings.announcement', $page->option('announcement'))" />
                <div class="field"><label>{{ __('Banner style') }}</label><select class="input" name="settings[announcement_level]">@foreach (['info' => __('Info'), 'success' => __('Success'), 'warn' => __('Warning'), 'error' => __('Critical')] as $k => $v)<option value="{{ $k }}" @selected(old('settings.announcement_level', $page->option('announcement_level')) === $k)>{{ $v }}</option>@endforeach</select></div>
            </div>
        </fieldset>
        <button class="btn primary">{{ __('Save') }}</button>
    </div>
    <div>
    <fieldset style="max-height:560px;overflow:auto"><legend>{{ __('Groups on this page') }}</legend>
        <p class="small muted">{{ __('Each group becomes a section with all of its monitors (sub-groups included), aggregated uptime, website details and — for mail groups — every mail component.') }}</p>
        <input class="input" type="search" data-filter-list="#sp-groups" placeholder="{{ __('Search') }}…" style="margin-bottom:8px">
        <div id="sp-groups">
        @forelse ($groups as $i => $g)
            @php $sel = $selectedGroups->get($g->id); @endphp
            <div class="row" style="margin-bottom:8px" data-filter-item="{{ mb_strtolower($g->name.' '.$g->domain) }}">
                <label class="check" style="flex:1;margin:0;min-width:0"><input type="checkbox" name="groups[{{ $g->id }}][enabled]" value="1" @checked($sel)> <span class="truncate">{{ $g->icon() }} {{ $g->name }}</span> <span class="chip">{{ $g->monitors_count }}</span></label>
                <input class="input" name="groups[{{ $g->id }}][display_name]" value="{{ $sel?->pivot->display_name }}" placeholder="{{ __('Public name') }}" style="width:34%;padding:5px 8px">
                <input class="input" type="number" name="groups[{{ $g->id }}][sort]" value="{{ $sel?->pivot->sort ?? $i }}" style="width:58px;padding:5px 8px" title="{{ __('Order') }}">
                <label class="check small" style="margin:0" title="{{ __('Expanded by default') }}"><input type="checkbox" name="groups[{{ $g->id }}][expanded]" value="1" @checked($sel ? $sel->pivot->expanded : true)> {{ __('Open') }}</label>
            </div>
        @empty
            <p class="muted small">{{ __('No groups yet.') }} <a href="{{ route('groups.create') }}">{{ __('Create group') }}</a></p>
        @endforelse
        </div>
    </fieldset>
    <fieldset style="max-height:560px;overflow:auto"><legend>{{ __('Individual monitors') }}</legend>
        <p class="small muted">{{ __('Monitors not covered by a selected group are listed in an "Other services" section.') }}</p>
        @foreach ($monitors as $i => $m)
            @php $sel = $selected->get($m->id); @endphp
            <div class="row" style="margin-bottom:8px">
                <label class="check" style="flex:1;margin:0"><input type="checkbox" name="monitors[{{ $m->id }}][enabled]" value="1" @checked($sel)> {{ $m->name }}</label>
                <input class="input" name="monitors[{{ $m->id }}][display_name]" value="{{ $sel?->pivot->display_name }}" placeholder="{{ __('Public name') }}" style="width:40%;padding:5px 8px">
                <input class="input" type="number" name="monitors[{{ $m->id }}][sort]" value="{{ $sel?->pivot->sort ?? $i }}" style="width:64px;padding:5px 8px" title="{{ __('Order') }}">
            </div>
        @endforeach
    </fieldset>
    </div>
</form>
@endsection

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
        <button class="btn primary">{{ __('Save') }}</button>
    </div>
    <fieldset style="max-height:640px;overflow:auto"><legend>{{ __('Monitors on this page') }}</legend>
        @foreach ($monitors as $i => $m)
            @php $sel = $selected->get($m->id); @endphp
            <div class="row" style="margin-bottom:8px">
                <label class="check" style="flex:1;margin:0"><input type="checkbox" name="monitors[{{ $m->id }}][enabled]" value="1" @checked($sel)> {{ $m->name }}</label>
                <input class="input" name="monitors[{{ $m->id }}][display_name]" value="{{ $sel?->pivot->display_name }}" placeholder="{{ __('Public name') }}" style="width:40%;padding:5px 8px">
                <input class="input" type="number" name="monitors[{{ $m->id }}][sort]" value="{{ $sel?->pivot->sort ?? $i }}" style="width:64px;padding:5px 8px" title="{{ __('Order') }}">
            </div>
        @endforeach
    </fieldset>
</form>
@endsection

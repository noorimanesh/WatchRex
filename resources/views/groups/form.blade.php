@extends('layouts.app')
@section('title', $group->exists ? __('Edit group') : __('New group'))
@section('content')
<div class="page-head"><div><h1>{{ $group->exists ? __('Edit group') : __('New group') }}</h1></div></div>
<form method="POST" action="{{ $group->exists ? route('groups.update', $group) : route('groups.store') }}" class="grid g-main">
    @csrf @if ($group->exists) @method('PUT') @endif
    <input type="hidden" name="monitors_present" value="1">
    <div>
        <fieldset><legend>{{ __('Group') }}</legend>
            <x-field name="name" :label="__('Name')" :value="$group->name" required placeholder="fabapars.com" />
            <div class="field"><label>{{ __('Kind') }}</label>
                <div class="type-grid">@foreach (\App\Models\MonitorGroup::KINDS as $k => [$label, $icon])
                    <label><input type="radio" name="kind" value="{{ $k }}" @checked(old('kind', $group->kind ?? 'general') === $k)> {{ $icon }} {{ __($label) }}</label>
                @endforeach</div>
                <span class="help">{{ __('Mail groups get a full mail health check (SMTP, IMAP, POP3, TLS, blacklists, SPF, DKIM, DMARC) on status pages.') }}</span>
            </div>
            <div class="grid g-2">
                <x-field name="domain" :label="__('Domain (optional)')" :value="$group->domain" class="ltr" placeholder="example.com" :help="__('Links the group to its domain analysis (expiry, SSL, DNS, e-mail security).')" />
                <div class="field"><label>{{ __('Parent group') }}</label>
                    <select name="parent_id" class="input"><option value="">—</option>
                        @foreach ($parents as $p)<option value="{{ $p->id }}" @selected((int) old('parent_id', $group->parent_id) === $p->id)>{{ $p->icon() }} {{ $p->name }}</option>@endforeach</select>
                    @error('parent_id')<span class="error">{{ $message }}</span>@enderror</div>
            </div>
            <div class="grid g-3">
                <div class="field"><label>{{ __('Server') }}</label><select name="server_id" class="input"><option value="">—</option>@foreach ($servers as $s)<option value="{{ $s->id }}" @selected((int) old('server_id', $group->server_id) === $s->id)>{{ $s->name }}</option>@endforeach</select></div>
                <x-field name="color" type="color" :label="__('Colour')" :value="$group->color ?? '#10b981'" style="height:42px;padding:4px" />
                <x-field name="sort" type="number" :label="__('Order')" :value="$group->sort ?? 0" />
            </div>
            <x-field name="description" type="textarea" :label="__('Description')" :value="$group->description" rows="2" style="font-family:var(--font)" />
            <x-team-select :teams="$teams" :value="$group->team_id" />
        </fieldset>
        <button class="btn primary">{{ __('Save') }}</button>
    </div>
    <fieldset style="max-height:640px;overflow:auto"><legend>{{ __('Monitors in this group') }}</legend>
        <input class="input mb" type="search" placeholder="{{ __('Filter…') }}" data-filter-list="#group-monitors">
        <div id="group-monitors">
            @foreach ($monitors as $m)
                <label class="check" data-filter-item="{{ mb_strtolower($m->name.' '.$m->target) }}"><input type="checkbox" name="monitors[]" value="{{ $m->id }}" @checked(in_array($m->id, old('monitors', $selected)))> {{ $m->name }} <span class="chip">{{ $m->type->label() }}</span></label>
            @endforeach
        </div>
    </fieldset>
</form>
@endsection

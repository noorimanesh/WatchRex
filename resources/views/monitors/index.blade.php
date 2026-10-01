@extends('layouts.app')
@section('title', __('Monitors'))
@section('content')
<div class="page-head">
    <div><h1>{{ __('Monitors') }}</h1><div class="sub">{{ __('Websites, APIs, ports, mail servers, databases, DNS, SSL and cron jobs.') }}</div></div>
    <a class="btn primary" href="{{ route('monitors.create') }}"><x-icon name="plus"/>{{ __('New monitor') }}</a>
</div>

<form class="filters" method="GET">
    <input class="input" type="search" name="q" value="{{ request('q') }}" placeholder="{{ __('Name or target…') }}">
    <select class="input" name="status" data-autosubmit>
        <option value="">{{ __('Any status') }}</option>
        @foreach (['up' => __('Up'), 'down' => __('Down'), 'warning' => __('Degraded'), 'pending' => __('Pending'), 'maintenance' => __('Maintenance'), 'paused' => __('Paused')] as $v => $l)
            <option value="{{ $v }}" @selected(request('status') === $v)>{{ $l }}</option>
        @endforeach
    </select>
    <select class="input" name="category" data-autosubmit>
        <option value="">{{ __('Any category') }}</option>
        @foreach (['web' => __('Web & API'), 'mail' => __('Mail'), 'database' => __('Databases'), 'network' => __('Network'), 'passive' => __('Agents & push')] as $v => $l)
            <option value="{{ $v }}" @selected(request('category') === $v)>{{ $l }}</option>
        @endforeach
    </select>
    <select class="input" name="type" data-autosubmit>
        <option value="">{{ __('Any type') }}</option>
        @foreach (\App\Enums\MonitorType::options() as $v => $l)<option value="{{ $v }}" @selected(request('type') === $v)>{{ $l }}</option>@endforeach
    </select>
    @if ($facets['groups']->isNotEmpty())
        <select class="input" name="group" data-autosubmit>
            <option value="">{{ __('Any group') }}</option>
            @foreach ($facets['groups'] as $g)<option value="{{ $g->id }}" @selected((int) request('group') === $g->id)>{{ $g->icon() }} {{ $g->name }}</option>@endforeach
        </select>
    @endif
    @if ($facets['tags']->isNotEmpty())
        <select class="input" name="tag" data-autosubmit>
            <option value="">{{ __('Any tag') }}</option>
            @foreach ($facets['tags'] as $t)<option @selected(request('tag') === $t)>{{ $t }}</option>@endforeach
        </select>
    @endif
    @if ($owners->isNotEmpty())
        <select class="input" name="owner" data-autosubmit>
            <option value="">{{ __('All users') }}</option>
            @foreach ($owners as $o)<option value="{{ $o->id }}" @selected((int) request('owner') === $o->id)>{{ $o->name }}</option>@endforeach
        </select>
    @endif
    <button class="btn">{{ __('Filter') }}</button>
    @if (request()->hasAny(\App\Services\MonitorList::FILTERS))<a class="btn ghost" href="{{ route('monitors.index') }}">{{ __('Clear') }}</a>@endif
</form>

<div class="card">
    <div class="table-wrap">
        <table class="table">
            <thead><tr>
                <th>{{ __('Monitor') }}</th><th>{{ __('Status') }}</th><th>{{ __('Last checks') }}</th>
                <th>{{ __('Response') }}</th><th>{{ __('Uptime 24h') }}</th><th>{{ __('Checked') }}</th>@if (auth()->user()->isAdmin())<th>{{ __('Owner') }}</th>@endif
            </tr></thead>
            <tbody>
            @forelse ($monitors as $m)
                <tr>
                    <td style="max-width:340px">
                        <a href="{{ route('monitors.show', $m) }}"><b>{{ $m->name }}</b></a>
                        <div class="small muted truncate ltr" style="text-align:start">{{ $m->displayTarget() }}</div>
                        <div class="row wrap mt-s" style="gap:4px"><span class="chip">{{ $m->type->label() }}</span>@foreach ($m->groups as $g)<a class="chip" href="{{ route('groups.show', $g) }}">{{ $g->icon() }} {{ $g->name }}</a>@endforeach @foreach ($m->tags ?? [] as $t)<span class="chip">#{{ $t }}</span>@endforeach</div>
                    </td>
                    <td><x-status :status="$m->status" :active="$m->is_active" /></td>
                    <td style="min-width:160px"><x-beats :beats="$m->beats" /></td>
                    <td class="nowrap ltr">{{ Fmt::ms($m->last_response_ms) }}</td>
                    <td class="nowrap"><b>{{ Fmt::uptime($m->uptime_24h) }}</b></td>
                    <td class="small muted nowrap">{{ $m->last_checked_at?->diffForHumans() ?? '—' }}</td>
                    @if (auth()->user()->isAdmin())<td class="small">{{ $m->user->name ?? '—' }}</td>@endif
                </tr>
            @empty
                <tr><td colspan="7"><x-empty :title="__('No monitors found')" /></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
{{ $monitors->links('partials.pagination') }}
@endsection

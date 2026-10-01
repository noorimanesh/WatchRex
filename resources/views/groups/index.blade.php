@extends('layouts.app')
@section('title', __('Groups'))
@section('content')
<div class="page-head">
    <div><h1>🗂️ {{ __('Groups') }}</h1><div class="sub">{{ __('Organise websites, mail services, servers and customers. Groups can be nested and shown on status pages.') }}</div></div>
    <a class="btn primary" href="{{ route('groups.create') }}"><x-icon name="plus"/>{{ __('New group') }}</a>
</div>

<div class="grid g-4 mb">
    <a href="?status=" class="card stat"><div class="label">{{ __('Groups') }}</div><div class="value">{{ $total }}</div></a>
    <a href="?status=up" class="card stat accent-up"><div class="label"><span class="dot up"></span>{{ __('Healthy') }}</div><div class="value">{{ $summary['up'] ?? 0 }}</div></a>
    <a href="?status=warning" class="card stat {{ ($summary['warning'] ?? 0) ? 'accent-warn' : '' }}"><div class="label"><span class="dot warning"></span>{{ __('Degraded') }}</div><div class="value">{{ $summary['warning'] ?? 0 }}</div></a>
    <a href="?status=down" class="card stat {{ ($summary['down'] ?? 0) ? 'accent-down' : '' }}"><div class="label"><span class="dot down"></span>{{ __('Down') }}</div><div class="value">{{ $summary['down'] ?? 0 }}</div></a>
</div>

<form class="filters">
    <input class="input" type="search" name="q" value="{{ request('q') }}" placeholder="{{ __('Name or domain…') }}">
    <select class="input" name="kind" data-autosubmit><option value="">{{ __('All kinds') }}</option>
        @foreach (\App\Models\MonitorGroup::KINDS as $k => [$label, $icon])<option value="{{ $k }}" @selected(request('kind') === $k)>{{ $icon }} {{ __($label) }}</option>@endforeach</select>
    <select class="input" name="status" data-autosubmit><option value="">{{ __('Any status') }}</option>
        @foreach (['issues' => __('With issues'), 'up' => __('Up'), 'warning' => __('Degraded'), 'down' => __('Down')] as $v => $l)<option value="{{ $v }}" @selected(request('status') === $v)>{{ $l }}</option>@endforeach</select>
    <button class="btn">{{ __('Filter') }}</button>
    @if ($filtered)<a class="btn ghost" href="{{ route('groups.index') }}">{{ __('Clear') }}</a>@endif
</form>

<div class="card">
    <div class="group-tree">
        @forelse ($roots as $group)
            @include('groups._row', ['group' => $group, 'depth' => 0])
        @empty
            <x-empty icon="🗂️" :title="__('No groups yet')" :text="__('Create groups manually, or import all sites of a server from its page — WatchRex creates a group per website with its mail service as a sub-group.')" />
        @endforelse
    </div>
</div>
@endsection

@extends('layouts.app')
@section('title', __('Teams'))
@section('content')
<div class="page-head"><div><h1>{{ __('Teams') }}</h1><div class="sub">{{ __('Share monitors, servers, domains, channels and status pages with colleagues or customers.') }}</div></div></div>
<div class="grid g-main">
    <div class="grid g-2" style="align-content:start">
        @forelse ($teams as $t)
            <a class="card card-pad" href="{{ route('teams.show', $t) }}">
                <div class="row between"><b>👥 {{ $t->name }}</b>@if ($t->pivot?->role)<span class="chip">{{ __(\App\Models\Team::ROLES[$t->pivot->role][0]) }}</span>@endif</div>
                <div class="small muted mt-s">{{ trans_choice(':count member|:count members', $t->members_count) }} · {{ trans_choice(':count monitor|:count monitors', $t->monitors_count) }} · {{ __('Owner') }}: {{ $t->owner->name ?? '—' }}</div>
            </a>
        @empty
            <div class="card" style="grid-column:1/-1"><x-empty icon="👥" :title="__('No teams yet')" /></div>
        @endforelse
    </div>
    @if (auth()->user()->canWrite())
    <form method="POST" action="{{ route('teams.store') }}" class="card card-pad">
        @csrf
        <h2 class="mb">{{ __('New team') }}</h2>
        <x-field name="name" :label="__('Name')" required placeholder="Fabapars NOC" />
        <button class="btn primary">{{ __('Create') }}</button>
        <div class="small faint mt">
            <b>{{ __('Owner / Admin') }}</b>: {{ __('manage members and all shared resources') }}<br>
            <b>{{ __('Developer') }}</b>: {{ __('create and edit shared resources') }}<br>
            <b>{{ __('Viewer') }}</b>: {{ __('read-only') }}
        </div>
    </form>
    @endif
</div>
@endsection

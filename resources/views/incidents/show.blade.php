@extends('layouts.app')
@section('title', $incident->title)
@section('content')
<div class="page-head">
    <div>
        <div class="row wrap"><h1>{{ $incident->title }}</h1><span class="badge {{ $incident->isOpen() ? $incident->severity : 'up' }}">{{ $incident->isOpen() ? __('Open') : __('Resolved') }}</span></div>
        <div class="sub">{{ Fmt::date($incident->started_at) }} → {{ $incident->resolved_at ? Fmt::date($incident->resolved_at) : __('ongoing') }} · {{ Fmt::duration($incident->durationSeconds()) }}</div>
        @if ($incident->monitor)<div class="small mt-s"><a href="{{ route('monitors.show', $incident->monitor) }}">{{ $incident->monitor->name }} →</a></div>@endif
    </div>
    <div class="actions">
        @if (! $incident->acknowledged_at && $incident->isOpen())<form method="POST" action="{{ route('incidents.acknowledge', $incident) }}">@csrf<button class="btn">{{ __('Acknowledge') }}</button></form>@endif
        @if ($incident->isOpen())<form method="POST" action="{{ route('incidents.resolve', $incident) }}" data-confirm="{{ __('Mark as resolved?') }}">@csrf<button class="btn primary">{{ __('Resolve') }}</button></form>@endif
    </div>
</div>
<div class="grid g-main">
    <div class="card"><div class="card-head"><h2>{{ __('Timeline') }}</h2></div><div class="card-body">
        <ul class="timeline">
            @foreach ($incident->updates as $u)
                <li class="{{ $u->type }}"><div class="small muted">{{ Fmt::date($u->created_at) }} · {{ $u->type }} @if ($u->user) · {{ $u->user->name }}@endif</div><div>{{ $u->message }}</div></li>
            @endforeach
        </ul>
    </div></div>
    <div class="card"><div class="card-head"><h2>{{ __('Add update') }}</h2></div><div class="card-body">
        <form method="POST" action="{{ route('incidents.note', $incident) }}">
            @csrf
            <x-field name="message" type="textarea" :label="__('Message')" required rows="4" style="font-family:var(--font)" />
            <label class="check"><input type="checkbox" name="public" value="1"> {{ __('Publish on status pages') }}</label>
            <button class="btn primary">{{ __('Post') }}</button>
        </form>
        @if ($incident->cause)<div class="label mt">{{ __('Root cause (detected)') }}</div><p class="small">{{ $incident->cause }}</p>@endif
    </div></div>
</div>
@endsection

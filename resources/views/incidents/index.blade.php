@extends('layouts.app')
@section('title', __('Incidents'))
@section('content')
<div class="page-head"><div><h1>{{ __('Incidents') }}</h1><div class="sub">{{ __('Every outage and degradation with its timeline and duration.') }}</div></div>
    <div class="seg">
        <a href="{{ route('incidents.index') }}" class="{{ ! request('state') ? 'active' : '' }}">{{ __('All') }}</a>
        <a href="?state=open" class="{{ request('state') === 'open' ? 'active' : '' }}">{{ __('Open') }}</a>
        <a href="?state=resolved" class="{{ request('state') === 'resolved' ? 'active' : '' }}">{{ __('Resolved') }}</a>
    </div>
</div>
<div class="card"><div class="table-wrap"><table class="table">
    <thead><tr><th>{{ __('Incident') }}</th><th>{{ __('Severity') }}</th><th>{{ __('Started') }}</th><th>{{ __('Duration') }}</th><th>{{ __('Status') }}</th></tr></thead>
    <tbody>
    @forelse ($incidents as $i)
        <tr>
            <td><a href="{{ route('incidents.show', $i) }}"><b>{{ $i->title }}</b></a><div class="small muted truncate" style="max-width:420px">{{ $i->cause }}</div></td>
            <td><span class="badge {{ $i->severity }}">{{ $i->severity === 'critical' ? __('Critical') : __('Warning') }}</span></td>
            <td class="small nowrap">{{ Fmt::date($i->started_at) }}</td>
            <td class="nowrap">{{ Fmt::duration($i->durationSeconds()) }}</td>
            <td>@if ($i->isOpen())<span class="badge down">{{ $i->acknowledged_at ? __('Acknowledged') : __('Open') }}</span>@else<span class="badge up">{{ __('Resolved') }}</span>@endif</td>
        </tr>
    @empty
        <tr><td colspan="5"><x-empty icon="🎉" :title="__('No incidents')" /></td></tr>
    @endforelse
    </tbody>
</table></div></div>
{{ $incidents->links('partials.pagination') }}
@endsection

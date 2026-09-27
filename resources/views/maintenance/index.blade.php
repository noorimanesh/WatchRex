@extends('layouts.app')
@section('title', __('Maintenance'))
@section('content')
<div class="page-head"><div><h1>{{ __('Maintenance windows') }}</h1><div class="sub">{{ __('Checks keep running but no alerts are sent and uptime is not affected.') }}</div></div>
    <a class="btn primary" href="{{ route('maintenance.create') }}"><x-icon name="plus"/>{{ __('Schedule') }}</a></div>
<div class="card"><div class="table-wrap"><table class="table">
    <thead><tr><th>{{ __('Title') }}</th><th>{{ __('Window') }}</th><th>{{ __('Monitors') }}</th><th>{{ __('State') }}</th><th></th></tr></thead>
    <tbody>
    @forelse ($windows as $w)
        <tr><td><b>{{ $w->title }}</b><div class="small muted">{{ \Illuminate\Support\Str::limit($w->description, 80) }}</div></td>
            <td class="small">{{ Fmt::date($w->starts_at) }} → {{ Fmt::date($w->ends_at) }}</td>
            <td>{{ $w->monitors_count }}</td>
            <td>@php $st = $w->state(); @endphp<span class="badge {{ $st === 'active' ? 'maintenance' : ($st === 'scheduled' ? 'warning' : '') }}">{{ __(ucfirst($st)) }}</span></td>
            <td class="nowrap"><a class="btn sm" href="{{ route('maintenance.edit', $w) }}">{{ __('Edit') }}</a>
                <form class="inline" method="POST" action="{{ route('maintenance.destroy', $w) }}" data-confirm="{{ __('Delete?') }}">@csrf @method('DELETE')<button class="btn sm danger">{{ __('Delete') }}</button></form></td></tr>
    @empty
        <tr><td colspan="5"><x-empty icon="🛠️" :title="__('No maintenance scheduled')" /></td></tr>
    @endforelse
    </tbody></table></div></div>
{{ $windows->links('partials.pagination') }}
@endsection

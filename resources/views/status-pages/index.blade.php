@extends('layouts.app')
@section('title', __('Status pages'))
@section('content')
<div class="page-head"><div><h1>{{ __('Status pages') }}</h1><div class="sub">{{ __('Public, branded pages for your customers — with custom domain, JSON API and RSS.') }}</div></div>
    <a class="btn primary" href="{{ route('status-pages.create') }}"><x-icon name="plus"/>{{ __('New status page') }}</a></div>
<div class="grid g-3">
    @forelse ($pages as $p)
        <div class="card card-pad">
            <div class="row between"><b>{{ $p->title }}</b>@if (! $p->is_public)<span class="badge">{{ __('Private') }}</span>@endif</div>
            <div class="small muted ltr mt-s" style="text-align:start"><a href="{{ $p->publicUrl() }}" target="_blank" rel="noopener">{{ $p->publicUrl() }} ↗</a></div>
            <div class="small muted">{{ trans_choice(':count monitor|:count monitors', $p->monitors_count) }}</div>
            <div class="row mt"><a class="btn sm" href="{{ route('status-pages.edit', $p) }}">{{ __('Edit') }}</a>
                <form method="POST" action="{{ route('status-pages.destroy', $p) }}" data-confirm="{{ __('Delete this status page?') }}">@csrf @method('DELETE')<button class="btn sm danger">{{ __('Delete') }}</button></form></div>
        </div>
    @empty
        <div class="card" style="grid-column:1/-1"><x-empty icon="📣" :title="__('No status pages yet')" /></div>
    @endforelse
</div>
@endsection

@extends('layouts.app')
@section('title', __('Servers'))
@section('content')
<div class="page-head">
    <div><h1>{{ __('Servers') }}</h1><div class="sub">{{ __('cPanel, DirectAdmin, Plesk, Docker hosts and plain Linux servers via the WatchRex agent.') }}</div></div>
    <a class="btn primary" href="{{ route('servers.create') }}"><x-icon name="plus"/>{{ __('Add server') }}</a>
</div>

@if ($servers->isEmpty())
    <div class="card"><x-empty icon="🖥️" :title="__('No servers yet')" :text="__('The agent is a single bash script (no dependencies). It reports CPU, RAM, disks, network, services, Docker containers, mail queue, bounces, deferred mail, IMAP/POP3 login success & failures and hosting accounts disk usage.')">
        <a class="btn primary mt" href="{{ route('servers.create') }}">{{ __('Add your first server') }}</a></x-empty></div>
@else
<div class="grid g-3">
    @foreach ($servers as $s)
        @php $online = $s->isOnline(); @endphp
        <a class="card card-pad" href="{{ route('servers.show', $s) }}">
            <div class="row between">
                <div class="row"><span class="dot {{ $online ? 'up' : 'down' }}"></span><b>{{ $s->name }}</b></div>
                <span class="chip">{{ $s->panelLabel() }}</span>
            </div>
            <div class="small muted ltr mt-s" style="text-align:start">{{ $s->hostname ?? __('awaiting first report') }} {{ $s->ip ? '· '.$s->ip : '' }}</div>
            <div class="stack mt" style="--gap:10px">
                @foreach (['cpu' => 'CPU', 'ram' => 'RAM', 'disk' => __('Disk')] as $k => $label)
                    <div><div class="row between small"><span class="muted">{{ $label }}</span><b class="ltr">{{ $s->stat($k) !== null ? $s->stat($k).'%' : '—' }}</b></div><x-meter :value="$s->stat($k)" /></div>
                @endforeach
            </div>
            <div class="row between small muted mt">
                <span>Load <b class="ltr">{{ implode(' ', $s->stat('load', []) ?: ['—']) }}</b></span>
                @if ($s->stat('mail.mta'))<span>📬 {{ __('Queue') }} <b>{{ $s->stat('mail.queue') ?? '—' }}</b></span>@endif
                <span>{{ $s->last_seen_at?->diffForHumans() ?? '—' }}</span>
            </div>
            @if (auth()->user()->isAdmin())<div class="small faint mt-s">{{ $s->user->name }}</div>@endif
        </a>
    @endforeach
</div>
@endif
@endsection

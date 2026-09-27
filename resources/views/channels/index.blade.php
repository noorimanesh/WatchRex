@extends('layouts.app')
@section('title', __('Alert channels'))
@section('content')
<div class="page-head"><div><h1>{{ __('Alert channels') }}</h1><div class="sub">{{ __('Telegram, Bale, e-mail, SMS, Slack, Discord, Teams, WhatsApp, ntfy push and webhooks.') }}</div></div>
    <a class="btn primary" href="{{ route('channels.create') }}"><x-icon name="plus"/>{{ __('Add channel') }}</a></div>
<div class="grid g-3">
    @forelse ($channels as $c)
        <div class="card card-pad">
            <div class="row between"><b>{{ $c->name }}</b><span class="chip">{{ \App\Models\NotificationChannel::TYPES[$c->type] ?? $c->type }}</span></div>
            <div class="small muted mt-s">
                @if ($c->is_default)<span class="badge up">{{ __('Default') }}</span>@endif
                @if (! $c->is_active)<span class="badge">{{ __('Disabled') }}</span>@endif
                {{ trans_choice(':count monitor|:count monitors', $c->monitors_count) }} · {{ __('last sent') }} {{ $c->last_sent_at?->diffForHumans() ?? '—' }}
            </div>
            @if ($c->last_error)<div class="small text-down mt-s">{{ \Illuminate\Support\Str::limit($c->last_error, 140) }}</div>@endif
            <div class="row mt">
                <form method="POST" action="{{ route('channels.test', $c) }}">@csrf<button class="btn sm"><x-icon name="zap"/>{{ __('Test') }}</button></form>
                <a class="btn sm" href="{{ route('channels.edit', $c) }}">{{ __('Edit') }}</a>
                <form method="POST" action="{{ route('channels.destroy', $c) }}" data-confirm="{{ __('Delete this channel?') }}">@csrf @method('DELETE')<button class="btn sm danger">{{ __('Delete') }}</button></form>
            </div>
        </div>
    @empty
        <div class="card" style="grid-column:1/-1"><x-empty icon="🔔" :title="__('No alert channels')" :text="__('Default channels receive every alert unless a monitor chooses specific ones.')" /></div>
    @endforelse
</div>
@endsection

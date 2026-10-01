@extends('layouts.app')
@section('title', $group->name)
@section('content')
@php $h = $health[$group->id]; @endphp
<div class="page-head">
    <div>
        <div class="row wrap"><h1>{{ $group->icon() }} {{ $group->name }}</h1><x-status :status="\App\Services\GroupHealth::statusEnum($h['status'])" /><span class="chip">{{ $group->kindLabel() }}</span></div>
        <div class="sub">
            @if ($group->parent)<a href="{{ route('groups.show', $group->parent) }}">{{ $group->parent->icon() }} {{ $group->parent->name }}</a> › @endif
            {{ trans_choice(':count monitor|:count monitors', $h['total']) }}
            @if ($group->server) · 🖥️ <a href="{{ route('servers.show', $group->server) }}">{{ $group->server->name }}</a>@endif
            @if ($group->domain) · <span class="ltr">{{ $group->domain }}</span>@endif
        </div>
        @if ($group->description)<p class="small muted mt-s">{{ $group->description }}</p>@endif
    </div>
    <div class="actions">
        <a class="btn" href="{{ route('status-pages.create', ['group' => $group->id]) }}"><x-icon name="layout"/>{{ __('Create status page') }}</a>
        <a class="btn" href="{{ route('groups.create', ['parent' => $group->id]) }}"><x-icon name="plus"/>{{ __('Sub-group') }}</a>
        <a class="btn" href="{{ route('groups.edit', $group) }}"><x-icon name="edit"/>{{ __('Edit') }}</a>
        <form method="POST" action="{{ route('groups.destroy', $group) }}" data-confirm="{{ __('Delete this group? Monitors are kept.') }}">@csrf @method('DELETE')<button class="btn danger icon-btn"><x-icon name="trash"/></button></form>
    </div>
</div>

<div class="grid g-6">
    @foreach (['24h' => __('Uptime 24h'), '7d' => __('7 days'), '30d' => __('30 days'), '90d' => __('90 days')] as $k => $label)
        <div class="card stat"><div class="label">{{ $label }}</div><div class="value {{ ($periods[$k] ?? 100) < 99 ? 'text-warn' : '' }}">{{ Fmt::uptime($periods[$k] ?? null) }}</div></div>
    @endforeach
    <div class="card stat"><div class="label">{{ __('Avg. response') }}</div><div class="value ltr" style="text-align:start">{{ Fmt::ms($h['avg']) }}</div></div>
    <div class="card stat {{ $h['down'] ? 'accent-down' : '' }}"><div class="label">{{ __('Down / degraded') }}</div><div class="value"><span class="text-down">{{ $h['down'] }}</span> / <span class="text-warn">{{ $h['warning'] }}</span></div></div>
</div>

<div class="grid g-main mt">
    <div class="stack">
        @if ($group->children->isNotEmpty())
            <div class="card">
                <div class="card-head"><h2>{{ __('Sub-groups') }}</h2></div>
                <div class="group-tree">
                    @foreach ($group->children as $child)
                        @include('groups._row', ['group' => $child, 'depth' => 0, 'byParent' => collect()])
                    @endforeach
                </div>
            </div>
        @endif

        <div class="card">
            <div class="card-head"><h2>{{ __('Monitors') }}</h2><span class="small muted">{{ __('including sub-groups') }}</span></div>
            <div class="mon-list">
                @forelse ($monitors as $m)
                    <div class="row" style="position:relative">
                        <div style="flex:1;min-width:0">@include('monitors._grid', ['monitors' => collect([$m])])</div>
                        @if ($group->monitors->contains('id', $m->id))
                            <form method="POST" action="{{ route('groups.members', $group) }}" style="padding-inline-end:12px">@csrf<input type="hidden" name="remove" value="{{ $m->id }}"><button class="btn ghost sm" title="{{ __('Remove from group') }}">✕</button></form>
                        @endif
                    </div>
                @empty
                    <x-empty icon="📭" :title="__('No monitors in this group yet')" />
                @endforelse
            </div>
            @if ($available->isNotEmpty())
                <form method="POST" action="{{ route('groups.members', $group) }}" class="card-body row" style="border-top:1px solid var(--border)">
                    @csrf
                    <select name="add[]" class="input" style="flex:1">@foreach ($available as $a)<option value="{{ $a->id }}">{{ $a->name }} ({{ $a->type->label() }})</option>@endforeach</select>
                    <button class="btn">{{ __('Add to group') }}</button>
                </form>
            @endif
        </div>
    </div>

    <div class="stack">
        @if ($mail)
            <div class="card">
                <div class="card-head"><h2>📬 {{ __('Mail health') }}</h2>@if ($mail['score'] !== null)<span class="badge {{ $mail['overall'] }}">{{ $mail['score'] }}/100</span>@endif</div>
                <div class="card-body">@include('groups._mail', ['mail' => $mail])</div>
            </div>
        @endif

        @if ($domain)
            @php $dd = $domain->daysUntilExpiry(); $sd = $domain->sslDaysLeft(); @endphp
            <div class="card">
                <div class="card-head"><h2>🌐 {{ __('Domain') }}</h2><a class="btn sm" href="{{ route('domains.show', $domain) }}">{{ __('Details') }}</a></div>
                <div class="card-body">
                    <dl class="kv">
                        <dt>{{ __('Domain expires') }}</dt><dd>@if ($dd !== null)<span class="badge {{ $dd <= 30 ? 'warning' : 'up' }}">{{ trans_choice(':count day|:count days', $dd) }}</span>@else — @endif</dd>
                        <dt>SSL</dt><dd>@if ($sd !== null)<span class="badge {{ $sd <= 14 ? 'warning' : 'up' }}">{{ trans_choice(':count day|:count days', $sd) }}</span>@else — @endif</dd>
                        <dt>{{ __('Hosting') }}</dt><dd class="ltr small">{{ $domain->network['ip'] ?? '—' }} {{ $domain->network['geo']['country_code'] ?? '' }}</dd>
                        <dt>{{ __('Mail security') }}</dt><dd>{{ $domain->email_security['score'] ?? '—' }}/100</dd>
                    </dl>
                </div>
            </div>
        @elseif ($group->domain)
            <div class="card card-pad small">
                <p class="muted">{{ __('No domain analysis for :d yet.', ['d' => $group->domain]) }}</p>
                <form method="POST" action="{{ route('domains.store') }}">@csrf<input type="hidden" name="name" value="{{ $group->domain }}"><button class="btn sm">{{ __('Analyse domain') }}</button></form>
            </div>
        @endif

        @if ($group->statusPages()->exists())
            <div class="card card-pad small"><div class="label">{{ __('Shown on') }}</div>
                <div class="mt-s">@foreach ($group->statusPages as $p)<a class="chip" href="{{ $p->publicUrl() }}" target="_blank">{{ $p->title }}</a> @endforeach</div></div>
        @endif
    </div>
</div>
@endsection

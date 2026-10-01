@php $h = $health[$group->id]; $kids = $byParent->get($group->id, collect()); @endphp
<a class="grow" href="{{ route('groups.show', $group) }}" style="--depth: {{ $depth }}">
    <div class="name" style="min-width:0">
        <span class="dot {{ $h['status'] }}"></span>
        <span>{{ $group->icon() }}</span>
        <b class="truncate">{{ $group->name }}</b>
        <span class="chip">{{ $group->kindLabel() }}</span>
        @if ($group->server)<span class="chip">🖥️ {{ $group->server->name }}</span>@endif
    </div>
    <div class="small muted nowrap">{{ trans_choice(':count monitor|:count monitors', $h['total']) }}
        @if ($h['down'])<span class="text-down"> · {{ $h['down'] }} {{ __('down') }}</span>@endif
        @if ($h['warning'])<span class="text-warn"> · {{ $h['warning'] }} {{ __('degraded') }}</span>@endif
    </div>
    <div class="metric"><b class="ltr">{{ Fmt::ms($h['avg']) }}</b><small>{{ __('Response') }}</small></div>
    <div class="metric"><b class="{{ ($h['uptime'] ?? 100) < 99 ? 'text-warn' : '' }}">{{ Fmt::uptime($h['uptime']) }}</b><small>24h</small></div>
</a>
@foreach ($kids as $child)
    @include('groups._row', ['group' => $child, 'depth' => $depth + 1])
@endforeach

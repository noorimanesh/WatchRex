@props(['map', 'focus' => null])
@php $W = \App\Services\DependencyMap::NODE_W; $H = \App\Services\DependencyMap::NODE_H; @endphp
@if (! $map['nodes'])
    <x-empty icon="🕸️" :title="__('No dependencies defined yet')" :text="__('Open a monitor, edit it and choose what it «depends on» (e.g. website → PHP-FPM → MySQL → server).')" />
@else
<div style="overflow:auto;direction:ltr">
<svg class="depmap" width="{{ $map['width'] }}" height="{{ $map['height'] }}" viewBox="-10 -10 {{ $map['width'] }} {{ $map['height'] }}" role="img" aria-label="{{ __('Dependency map') }}">
    @foreach ($map['edges'] as $e)
        <path d="{{ $e['path'] }}" class="edge {{ $e['broken'] ? 'broken' : '' }}"/>
    @endforeach
    @foreach ($map['nodes'] as $id => $n)
        @php $m = $n['monitor']; $st = $m->is_active ? $m->status->value : 'paused'; @endphp
        <a href="{{ route('monitors.show', $m) }}">
            <g transform="translate({{ $n['x'] }} {{ $n['y'] }})" class="node {{ $st }} {{ $n['impacted'] ? 'impacted' : '' }} {{ $focus === $id ? 'focus' : '' }}">
                <title>{{ $m->name }} — {{ $m->status->label() }}{{ $m->last_message ? ': '.$m->last_message : '' }}</title>
                <rect width="{{ $W }}" height="{{ $H }}" rx="12"/>
                <rect width="6" height="{{ $H }}" rx="3" class="bar"/>
                <text x="18" y="23" class="name">{{ \Illuminate\Support\Str::limit($m->name, 24) }}</text>
                <text x="18" y="42" class="meta">{{ $m->type->label() }}{{ $m->last_response_ms !== null ? ' · '.$m->last_response_ms.'ms' : '' }}{{ $n['impacted'] ? ' · ⚠' : '' }}</text>
                <circle cx="{{ $W - 16 }}" cy="16" r="5" class="dot-{{ $st }}"/>
            </g>
        </a>
    @endforeach
</svg>
</div>
@endif

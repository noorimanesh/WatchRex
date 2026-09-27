@forelse ($monitors as $m)
    <a class="mon" href="{{ route('monitors.show', $m) }}">
        <div style="min-width:0">
            <div class="name"><span class="dot {{ $m->is_active ? $m->status->value : 'paused' }}"></span><span class="truncate">{{ $m->name }}</span>
                <span class="chip">{{ $m->type->label() }}</span></div>
            <div class="target truncate ltr" style="text-align:start">{{ $m->displayTarget() }}@if ($m->status->value !== 'up' && $m->last_message) — <span class="{{ $m->status->value === 'down' ? 'text-down' : 'text-warn' }}">{{ \Illuminate\Support\Str::limit($m->last_message, 80) }}</span>@endif</div>
        </div>
        <div class="beats-col"><x-beats :beats="$m->beats" /></div>
        <div class="metric resp"><b class="ltr">{{ Fmt::ms($m->last_response_ms) }}</b><small>{{ __('Response') }}</small></div>
        <div class="metric"><b class="{{ ($m->uptime_24h ?? 100) < 99 ? 'text-warn' : '' }}">{{ Fmt::uptime($m->uptime_24h) }}</b><small>24h</small></div>
    </a>
@empty
    <x-empty :title="__('No monitors match')" :text="__('Create a monitor or clear the filters.')">
        <a class="btn primary mt" href="{{ route('monitors.create') }}"><x-icon name="plus"/>{{ __('New monitor') }}</a>
    </x-empty>
@endforelse

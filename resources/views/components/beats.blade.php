@props(['beats', 'slots' => 30])
@php $beats = collect($beats)->take(-$slots)->values(); $pad = max(0, $slots - $beats->count()); @endphp
<div class="beats" {{ $attributes }}>
    @for ($i = 0; $i < $pad; $i++)<i></i>@endfor
    @foreach ($beats as $b)<i class="s{{ $b->status }}" title="{{ Fmt::date($b->created_at) }} · {{ Fmt::ms($b->response_ms) }} · {{ $b->message }}"></i>@endforeach
</div>

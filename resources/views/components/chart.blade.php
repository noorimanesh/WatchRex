@props(['chart', 'height' => 180, 'unit' => 'ms', 'color' => null, 'dateFormat' => 'H:i'])
@php $id = 'cf'.substr(md5(uniqid('', true)), 0, 6); @endphp
<svg class="chart" viewBox="-34 -6 842 {{ $height + 26 }}" preserveAspectRatio="none" role="img" style="{{ $color ? '--brand:'.$color : '' }}">
    <defs><linearGradient id="{{ $id }}" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="{{ $color ?? '#10b981' }}" stop-opacity=".28"/><stop offset="1" stop-color="{{ $color ?? '#10b981' }}" stop-opacity="0"/></linearGradient></defs>
    @foreach ($chart['ticks'] as $t)
        <line class="grid-line" x1="0" x2="800" y1="{{ $t['y'] }}" y2="{{ $t['y'] }}"/>
        <text class="axis" x="-6" y="{{ $t['y'] + 3 }}" text-anchor="end">{{ $t['label'] }}</text>
    @endforeach
    @if ($chart['area'])<path d="{{ $chart['area'] }}" fill="url(#{{ $id }})"/>@endif
    @if ($chart['line'])<path class="line" d="{{ $chart['line'] }}"/>@endif
    @foreach ($chart['markers'] as $m)<circle class="m{{ $m['code'] }}" cx="{{ $m['x'] }}" cy="{{ $m['y'] }}" r="3"/>@endforeach
    @foreach ($chart['xlabels'] as $x)
        <text class="axis" x="{{ $x['x'] }}" y="{{ $height + 16 }}" text-anchor="{{ $loop->first ? 'start' : ($loop->last ? 'end' : 'middle') }}">{{ \Illuminate\Support\Carbon::createFromTimestamp($x['label'])->setTimezone(auth()->user()?->timezone ?? config('app.timezone'))->format($dateFormat) }}</text>
    @endforeach
    @if (! $chart['line'])<text class="axis" x="400" y="{{ $height / 2 }}" text-anchor="middle">{{ __('No data yet') }}</text>@endif
</svg>

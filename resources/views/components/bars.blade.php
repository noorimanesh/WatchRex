@props(['bars'])
<div class="bars">
    @foreach ($bars as $bar)
        <i class="{{ \App\Services\Uptime::barClass($bar['uptime']) }}" title="{{ $bar['date'] }} — {{ $bar['uptime'] === null ? __('No data') : Fmt::uptime($bar['uptime']) }}{{ $bar['avg'] ? ' · '.Fmt::ms($bar['avg']) : '' }}"></i>
    @endforeach
</div>

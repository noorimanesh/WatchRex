@props(['value', 'warn' => 75, 'crit' => 90])
@php $v = $value === null ? 0 : max(0, min(100, (float) $value)); $cls = $v >= $crit ? 'down' : ($v >= $warn ? 'warn' : ''); @endphp
<div class="meter {{ $cls }}" {{ $attributes }}><span style="width: {{ $v }}%"></span></div>

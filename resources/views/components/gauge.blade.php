@props(['value', 'label', 'warn' => 75, 'crit' => 90, 'suffix' => '%'])
@php $v = $value === null ? null : round((float) $value, 1); $c = $v === null ? 'var(--pending)' : ($v >= $crit ? 'var(--down)' : ($v >= $warn ? 'var(--warn)' : 'var(--up)')); @endphp
<div class="gauge-wrap">
    <div class="gauge" style="--v: {{ min(100, $v ?? 0) }}; --c: {{ $c }}"><b class="ltr">{{ $v ?? '—' }}{{ $v !== null ? $suffix : '' }}</b></div>
    <span class="muted small">{{ $label }}</span>
</div>

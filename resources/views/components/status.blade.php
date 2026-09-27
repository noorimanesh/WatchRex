@props(['status', 'active' => true])
@php $s = $active ? ($status instanceof \App\Enums\MonitorStatus ? $status : \App\Enums\MonitorStatus::from($status)) : \App\Enums\MonitorStatus::Paused; @endphp
<span {{ $attributes->class(['badge', $s->value]) }}><span class="dot {{ $s->value }}"></span>{{ $s->label() }}</span>

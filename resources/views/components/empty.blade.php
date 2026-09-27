@props(['icon' => '🦖', 'title', 'text' => null])
<div class="empty">
    <div class="ico">{{ $icon }}</div>
    <h3>{{ $title }}</h3>
    @if ($text)<p class="muted mt-s">{{ $text }}</p>@endif
    {{ $slot }}
</div>

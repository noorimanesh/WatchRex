{{-- Mail health checklist. Expects $mail = ['components' => [...], 'overall' => ..., 'score' => ...] --}}
<div class="mail-health">
    @foreach ($mail['components'] as $c)
        <div class="mh-row {{ $c['status'] }}">
            <span class="mh-ico">{{ ['up' => '✓', 'warning' => '!', 'down' => '✕', 'unknown' => '·'][$c['status']] ?? '·' }}</span>
            <div style="min-width:0"><b>{{ $c['label'] }}</b><div class="small muted truncate ltr" style="text-align:start" title="{{ $c['detail'] }}">{{ $c['detail'] }}</div>
                @if (! empty($c['message']))<div class="small text-down">{{ $c['message'] }}</div>@endif</div>
        </div>
    @endforeach
    @if (! $mail['components'])<p class="muted small">{{ __('No mail monitors or domain analysis yet.') }}</p>@endif
</div>

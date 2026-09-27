<!DOCTYPE html>
<html><body style="margin:0;background:#f4f6fa;font-family:Tahoma,Arial,sans-serif;color:#0f172a">
@php $color = ['down' => '#ef4444', 'recovered' => '#10b981'][$event] ?? '#6366f1'; @endphp
<table width="100%" cellpadding="0" cellspacing="0" style="padding:24px 12px"><tr><td align="center">
<table width="520" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:14px;overflow:hidden;border:1px solid #e3e8ef">
<tr><td style="background:{{ $color }};height:6px"></td></tr>
<tr><td style="padding:26px 28px">
    <div style="font-size:12px;color:#5b6b80">{{ $page->title }}</div>
    <h2 style="margin:6px 0 12px;font-size:18px">{{ $service }} — {{ ['down' => __('Service disruption'), 'recovered' => __('Resolved')][$event] ?? __('Update') }}</h2>
    <p style="font-size:14px;line-height:1.7">
        @if ($event === 'down'){{ __('We are investigating an issue affecting :s since :t.', ['s' => $service, 't' => $incident->started_at->toDayDateTimeString()]) }}
        @elseif ($event === 'recovered'){{ __(':s is operating normally again. Duration: :d.', ['s' => $service, 'd' => \App\Services\Format::duration($incident->durationSeconds())]) }}
        @else{{ $note }}@endif
    </p>
    <a href="{{ $page->publicUrl() }}" style="color:{{ $page->accent }};font-size:13px">{{ __('View status page') }}</a>
    <p style="font-size:11px;color:#94a3b8;margin-top:18px"><a href="{{ route('status.unsubscribe', [$page->slug, $subscriber->token]) }}" style="color:#94a3b8">{{ __('Unsubscribe') }}</a></p>
</td></tr></table></td></tr></table>
</body></html>

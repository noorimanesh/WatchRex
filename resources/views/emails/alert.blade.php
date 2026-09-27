<!DOCTYPE html>
<html><body style="margin:0;background:#f4f6fa;font-family:Tahoma,Arial,sans-serif;color:#0f172a">
<table width="100%" cellpadding="0" cellspacing="0" style="padding:24px 12px"><tr><td align="center">
<table width="560" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:14px;overflow:hidden;border:1px solid #e3e8ef">
    <tr><td style="background:{{ $p['color'] }};height:6px"></td></tr>
    <tr><td style="padding:24px 28px">
        <div style="font-size:12px;color:#5b6b80;margin-bottom:8px">🦖 WatchRex · Infrastructure &amp; Uptime Monitoring</div>
        <h2 style="margin:0 0 12px;font-size:19px">{{ $p['title'] }}</h2>
        <p style="margin:0 0 12px;font-size:14px;line-height:1.7;white-space:pre-line">{{ $p['message'] }}</p>
        @isset($p['monitor']['target'])<p style="margin:0 0 12px;font-family:monospace;font-size:13px;color:#5b6b80">{{ $p['monitor']['target'] }}</p>@endisset
        <p style="margin:0 0 18px;font-size:12px;color:#94a3b8">{{ $p['time'] }}</p>
        @if (! empty($p['url']))<a href="{{ $p['url'] }}" style="display:inline-block;background:#10b981;color:#fff;text-decoration:none;padding:10px 18px;border-radius:9px;font-weight:bold;font-size:13px">Open in WatchRex</a>@endif
    </td></tr>
    <tr><td style="padding:14px 28px;border-top:1px solid #e3e8ef;font-size:11px;color:#94a3b8">WatchRex — developed by <a href="{{ config('watchrex.vendor.url') }}" style="color:#10b981">Fabapars</a></td></tr>
</table></td></tr></table>
</body></html>

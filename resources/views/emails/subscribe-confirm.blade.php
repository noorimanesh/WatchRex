<!DOCTYPE html>
<html><body style="margin:0;background:#f4f6fa;font-family:Tahoma,Arial,sans-serif;color:#0f172a">
<table width="100%" cellpadding="0" cellspacing="0" style="padding:24px 12px"><tr><td align="center">
<table width="520" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:14px;border:1px solid #e3e8ef">
<tr><td style="padding:26px 28px">
    <h2 style="margin:0 0 12px;font-size:18px">{{ $page->title }}</h2>
    <p style="font-size:14px;line-height:1.7">{{ __('Please confirm that you want to receive incident and maintenance notifications for :t.', ['t' => $page->title]) }}</p>
    <a href="{{ route('status.confirm', [$page->slug, $subscriber->token]) }}" style="display:inline-block;background:{{ $page->accent }};color:#fff;text-decoration:none;padding:10px 18px;border-radius:9px;font-weight:bold;font-size:13px">{{ __('Confirm subscription') }}</a>
    <p style="font-size:12px;color:#94a3b8;margin-top:18px">{{ __('If you did not request this, ignore this e-mail.') }}</p>
</td></tr></table></td></tr></table>
</body></html>

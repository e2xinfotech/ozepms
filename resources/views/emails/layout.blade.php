<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ $title ?? $hotel }}</title></head>
<body style="margin:0;padding:0;background:#eef5fb;font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#0d1b3e;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef5fb;padding:24px 12px;">
<tr><td align="center">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:14px;overflow:hidden;box-shadow:0 2px 10px rgba(1,96,182,.12);">
<tr><td style="background:#0160b6;background-image:linear-gradient(135deg,#0160b6,#0a93b8);padding:26px 32px;color:#ffffff;">
<div style="font-size:22px;font-weight:800;letter-spacing:-.02em;">{{ $hotel }}</div>
@isset($tagline)<div style="font-size:13px;opacity:.9;margin-top:4px;">{{ $tagline }}</div>@endisset
</td></tr>
<tr><td style="padding:30px 32px 8px;font-size:15px;line-height:1.6;">
@yield('content')
</td></tr>
<tr><td style="padding:18px 32px 28px;font-size:12px;line-height:1.6;color:#64748b;border-top:1px solid #e3e8f1;">
{{ $footer }}
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>

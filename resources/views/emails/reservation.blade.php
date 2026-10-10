@extends('emails.layout')
@section('content')
<p style="margin:0 0 14px;">{{ __('emails.greeting', ['name' => $guest]) }}</p>
<div style="margin:0 0 20px;white-space:pre-line;">{{ $intro }}</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f0f9fd;border-radius:10px;margin:0 0 22px;">
@foreach ($rows as $label => $value)
<tr>
<td style="padding:10px 16px;font-size:13px;color:#64748b;width:42%;border-bottom:1px solid #dcecf5;">{{ $label }}</td>
<td style="padding:10px 16px;font-size:14px;font-weight:600;border-bottom:1px solid #dcecf5;">{{ $value }}</td>
</tr>
@endforeach
</table>
@if ($url)
<p style="margin:0 0 22px;"><a href="{{ $url }}" style="display:inline-block;background:#0670cc;color:#ffffff;text-decoration:none;font-weight:600;padding:12px 26px;border-radius:999px;">{{ __('emails.view') }}</a></p>
@endif
@if ($contact)
<p style="margin:0 0 14px;color:#33415c;">{{ __('emails.contact', ['hotel' => $hotel, 'contact' => $contact]) }}</p>
@endif
<p style="margin:0 0 18px;">{{ __('emails.salutation', ['hotel' => $hotel]) }}</p>
@endsection

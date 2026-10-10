@extends('emails.layout')
@section('content')
<p style="margin:0 0 14px;">{{ __('emails.greeting', ['name' => $guest]) }}</p>
<div style="margin:0 0 22px;white-space:pre-line;">{{ $body }}</div>
@if ($contact)
<p style="margin:0 0 14px;color:#33415c;">{{ __('emails.contact', ['hotel' => $hotel, 'contact' => $contact]) }}</p>
@endif
<p style="margin:0 0 18px;">{{ __('emails.salutation', ['hotel' => $hotel]) }}</p>
@endsection

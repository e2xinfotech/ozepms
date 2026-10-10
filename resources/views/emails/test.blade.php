@extends('emails.layout')
@section('content')
<p style="margin:0 0 14px;">{{ __('emails.test.body') }}</p>
<p style="margin:0 0 18px;color:#64748b;font-size:13px;">{{ __('emails.test.sent_with', ['host' => $via]) }}</p>
@endsection

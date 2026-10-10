@extends('emails.layout')
@section('content')
@php($supplier = $s['parties']['supplier'] ?? [])
@php($billTo = $s['parties']['bill_to'] ?? [])
@php($totals = $s['totals'] ?? [])
<p style="margin:0 0 14px;">{{ __('emails.greeting', ['name' => $guest]) }}</p>
<div style="margin:0 0 20px;white-space:pre-line;">{{ $intro }}</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 18px;font-size:13px;color:#33415c;">
<tr>
<td valign="top" style="padding:0 12px 0 0;width:50%;">
<div style="font-weight:700;color:#0d1b3e;">{{ $supplier['legal_name'] ?? $supplier['name'] ?? $hotel }}</div>
@foreach ((array) ($supplier['address'] ?? []) as $line)<div>{{ $line }}</div>@endforeach
@if (! empty($supplier['tax_no']))<div>{{ __('emails.invoice.tax_no') }}: {{ $supplier['tax_no'] }}</div>@endif
</td>
<td valign="top" style="padding:0 0 0 12px;width:50%;text-align:right;">
<div style="font-weight:700;color:#0d1b3e;">{{ __($credit ? 'emails.invoice.credit_note' : 'emails.invoice.tax_invoice') }} {{ $s['number'] ?? '' }}</div>
<div>{{ $fdate($s['date'] ?? now()) }}</div>
<div>{{ __('emails.labels.ref') }}: {{ $s['reservation']['ref'] ?? '' }}</div>
</td>
</tr>
<tr><td colspan="2" style="padding-top:12px;">
<div style="color:#64748b;">{{ __('emails.invoice.bill_to') }}</div>
<div style="font-weight:700;color:#0d1b3e;">{{ $billTo['name'] ?? $guest }}</div>
@if (! empty($billTo['tax_no']))<div>{{ __('emails.invoice.tax_no') }}: {{ $billTo['tax_no'] }}</div>@endif
@if (! empty($s['reservation']['check_in']))<div>{{ $fdate($s['reservation']['check_in']) }} – {{ $fdate($s['reservation']['check_out']) }} ({{ (int) ($s['reservation']['nights'] ?? 0) }} {{ __('emails.labels.nights') }})</div>@endif
</td></tr>
</table>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:13px;margin:0 0 14px;">
<tr style="background:#f0f9fd;color:#64748b;text-align:left;">
<th style="padding:8px 10px;">{{ __('emails.invoice.description') }}</th>
<th style="padding:8px 10px;text-align:right;">{{ __('emails.invoice.taxable') }}</th>
<th style="padding:8px 10px;text-align:right;">{{ __('emails.invoice.tax') }}</th>
<th style="padding:8px 10px;text-align:right;">{{ __('emails.labels.total') }}</th>
</tr>
@foreach ((array) ($s['lines'] ?? []) as $l)
<tr>
<td style="padding:8px 10px;border-bottom:1px solid #e3e8f1;">{{ $l['description'] ?? '' }}<div style="color:#94a3b8;font-size:12px;">{{ ! empty($l['date']) ? $fdate($l['date']) : '' }}@if (($l['quantity'] ?? '1') != '1') · × {{ rtrim(rtrim((string) $l['quantity'], '0'), '.') }}@endif</div></td>
<td style="padding:8px 10px;border-bottom:1px solid #e3e8f1;text-align:right;white-space:nowrap;">{{ $money($l['taxable'] ?? 0) }}</td>
<td style="padding:8px 10px;border-bottom:1px solid #e3e8f1;text-align:right;white-space:nowrap;">{{ $money($l['tax_total'] ?? 0) }}</td>
<td style="padding:8px 10px;border-bottom:1px solid #e3e8f1;text-align:right;white-space:nowrap;">{{ $money($l['total'] ?? 0) }}</td>
</tr>
@endforeach
</table>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:13px;margin:0 0 18px;">
@foreach (['taxable' => 'taxable', 'cgst' => 'CGST', 'sgst' => 'SGST', 'igst' => 'IGST', 'other' => 'other_tax', 'round_off' => 'round_off'] as $key => $label)
@if (isset($totals[$key]) && (float) $totals[$key] != 0.0)
<tr><td style="padding:3px 10px;text-align:right;color:#64748b;">{{ in_array($label, ['CGST', 'SGST', 'IGST'], true) ? $label : __('emails.invoice.'.$label) }}</td><td style="padding:3px 10px;text-align:right;width:130px;white-space:nowrap;">{{ $money($totals[$key]) }}</td></tr>
@endif
@endforeach
<tr><td style="padding:8px 10px;text-align:right;font-weight:700;font-size:15px;">{{ __('emails.labels.total') }}</td><td style="padding:8px 10px;text-align:right;font-weight:700;font-size:15px;white-space:nowrap;">{{ $money($totals['grand'] ?? 0) }}</td></tr>
@if (! empty($s['amount_in_words']))<tr><td colspan="2" style="padding:0 10px;text-align:right;color:#64748b;font-size:12px;">{{ $s['amount_in_words'] }}</td></tr>@endif
</table>
@if (! empty($s['payments']))
<p style="margin:0 0 6px;font-weight:700;">{{ __('emails.invoice.payments') }}</p>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:13px;margin:0 0 18px;">
@foreach ($s['payments'] as $p)
<tr><td style="padding:3px 10px;color:#64748b;">{{ ! empty($p['date']) ? $fdate($p['date']) : '' }} · {{ __('billing.methods.'.$p['method']) }}</td><td style="padding:3px 10px;text-align:right;white-space:nowrap;">{{ ($p['kind'] ?? '') === 'refund' ? '−' : '' }}{{ $money($p['amount']) }}</td></tr>
@endforeach
</table>
@endif
@if ($contact)
<p style="margin:0 0 14px;color:#33415c;">{{ __('emails.contact', ['hotel' => $hotel, 'contact' => $contact]) }}</p>
@endif
<p style="margin:0 0 18px;">{{ __('emails.salutation', ['hotel' => $hotel]) }}</p>
@endsection

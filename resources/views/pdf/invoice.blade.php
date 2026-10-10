<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<title>{{ $s['number'] ?? 'Invoice' }}</title>
<style>
  @page { margin: 36px 40px; }
  body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #0d1b3e; }
  h1 { font-size: 18px; margin: 0 0 2px; }
  .muted { color: #64748b; }
  .head { width: 100%; border-bottom: 2px solid #0160b6; padding-bottom: 10px; margin-bottom: 14px; }
  .head td { vertical-align: top; }
  .right { text-align: right; }
  table.lines { width: 100%; border-collapse: collapse; margin-top: 12px; }
  table.lines th { background: #eef6fb; color: #33415c; text-align: left; padding: 6px 6px; font-size: 9px; border-bottom: 1px solid #cfd8e6; }
  table.lines td { padding: 6px 6px; border-bottom: 1px solid #e3e8f1; vertical-align: top; }
  .num { text-align: right; white-space: nowrap; }
  table.totals { width: 52%; margin-left: 48%; border-collapse: collapse; margin-top: 10px; }
  table.totals td { padding: 3px 6px; }
  .grand td { font-size: 12px; font-weight: bold; border-top: 1px solid #0d1b3e; padding-top: 6px; }
  .box { background: #f6fafd; border: 1px solid #dcecf5; padding: 8px 10px; }
  .small { font-size: 8.5px; }
  .cancelled { color: #c42b1c; font-weight: bold; }
</style>
</head>
<body>
@php($supplier = $s['parties']['supplier'] ?? [])
@php($billTo = $s['parties']['bill_to'] ?? [])
@php($totals = $s['totals'] ?? [])
@php($res = $s['reservation'] ?? [])
<table class="head"><tr>
<td>
<h1>{{ $supplier['legal_name'] ?? $supplier['name'] ?? $hotel }}</h1>
@foreach ((array) ($supplier['address'] ?? []) as $line)<div class="muted">{{ $line }}</div>@endforeach
@if (! empty($supplier['phone']))<div class="muted">{{ $supplier['phone'] }}</div>@endif
@if (! empty($supplier['email']))<div class="muted">{{ $supplier['email'] }}</div>@endif
@if (! empty($supplier['tax_no']))<div>{{ __('emails.invoice.tax_no') }}: <b>{{ $supplier['tax_no'] }}</b></div>@endif
</td>
<td class="right">
<h1>{{ __($credit ? 'emails.invoice.credit_note' : 'emails.invoice.tax_invoice') }}</h1>
<div><b>{{ $s['number'] ?? '' }}</b></div>
<div class="muted">{{ $fdate($s['date'] ?? now()) }}</div>
@if ($cancelled)<div class="cancelled">{{ __('emails.invoice.cancelled') }}</div>@endif
</td>
</tr></table>

<table width="100%"><tr>
<td width="55%" valign="top">
<div class="muted">{{ __('emails.invoice.bill_to') }}</div>
<div><b>{{ $billTo['name'] ?? '' }}</b></div>
@foreach ((array) ($billTo['address'] ?? []) as $line)<div>{{ $line }}</div>@endforeach
@if (! empty($billTo['tax_no']))<div>{{ __('emails.invoice.tax_no') }}: {{ $billTo['tax_no'] }}</div>@endif
</td>
<td width="45%" valign="top" class="box small">
<div>{{ __('emails.labels.ref') }}: <b>{{ $res['ref'] ?? '' }}</b></div>
@if (! empty($res['check_in']))<div>{{ __('emails.labels.check_in') }}: {{ $fdate($res['check_in']) }}</div><div>{{ __('emails.labels.check_out') }}: {{ $fdate($res['check_out']) }} ({{ (int) ($res['nights'] ?? 0) }} {{ __('emails.labels.nights') }})</div>@endif
@if (! empty($res['rooms']))<div>{{ __('emails.labels.rooms') }}: {{ implode(', ', (array) $res['rooms']) }}</div>@endif
@if (! empty($s['parties']['place_of_supply']['name']))<div>{{ __('emails.invoice.place_of_supply') }}: {{ $s['parties']['place_of_supply']['name'] }}</div>@endif
</td></tr></table>

<table class="lines">
<tr>
<th>#</th><th>{{ __('emails.invoice.description') }}</th>
<th class="num">{{ __('emails.invoice.qty') }}</th>
<th class="num">{{ __('emails.invoice.rate') }}</th>
<th class="num">{{ __('emails.invoice.taxable') }}</th>
<th class="num">{{ __('emails.invoice.tax') }}</th>
<th class="num">{{ __('emails.labels.total') }}</th>
</tr>
@foreach ((array) ($s['lines'] ?? []) as $i => $l)
<tr>
<td>{{ $i + 1 }}</td>
<td>{{ $l['description'] ?? '' }}<div class="muted small">{{ ! empty($l['date']) ? $fdate($l['date']) : '' }}@if (! empty($l['sac'])) · SAC {{ $l['sac'] }}@endif</div></td>
<td class="num">{{ rtrim(rtrim((string) ($l['quantity'] ?? '1'), '0'), '.') ?: '1' }}</td>
<td class="num">{{ $money($l['unit_price'] ?? 0) }}</td>
<td class="num">{{ $money($l['taxable'] ?? 0) }}</td>
<td class="num">{{ $money($l['tax_total'] ?? 0) }}</td>
<td class="num">{{ $money($l['total'] ?? 0) }}</td>
</tr>
@endforeach
</table>

@if (! empty($s['tax_summary']))
<table class="lines" style="width:52%;margin-top:14px;">
<tr><th>{{ __('emails.invoice.tax') }}</th><th class="num">%</th><th class="num">{{ __('emails.invoice.taxable') }}</th><th class="num">{{ __('emails.invoice.amount') }}</th></tr>
@foreach ($s['tax_summary'] as $t)
<tr><td>{{ $t['name'] ?? $t['component'] }}</td><td class="num">{{ ! empty($t['fixed']) ? '' : rtrim(rtrim((string) $t['rate'], '0'), '.') }}</td><td class="num">{{ $money($t['taxable'] ?? 0) }}</td><td class="num">{{ $money($t['amount'] ?? 0) }}</td></tr>
@endforeach
</table>
@endif

<table class="totals">
@foreach (['taxable' => 'taxable', 'cgst' => 'CGST', 'sgst' => 'SGST', 'igst' => 'IGST', 'other' => 'other_tax', 'round_off' => 'round_off'] as $key => $label)
@if (isset($totals[$key]) && (float) $totals[$key] != 0.0)
<tr><td class="muted">{{ in_array($label, ['CGST', 'SGST', 'IGST'], true) ? $label : __('emails.invoice.'.$label) }}</td><td class="num">{{ $money($totals[$key]) }}</td></tr>
@endif
@endforeach
<tr class="grand"><td>{{ __('emails.labels.total') }}</td><td class="num">{{ $money($totals['grand'] ?? 0) }}</td></tr>
</table>
@if (! empty($s['amount_in_words']))<p class="muted small right">{{ $s['amount_in_words'] }}</p>@endif

@if (! empty($s['payments']))
<p><b>{{ __('emails.invoice.payments') }}</b></p>
<table style="width:60%;border-collapse:collapse;">
@foreach ($s['payments'] as $p)
<tr><td class="muted">{{ ! empty($p['date']) ? $fdate($p['date']) : '' }} · {{ __('billing.methods.'.$p['method']) }}@if (! empty($p['reference'])) · {{ $p['reference'] }}@endif</td><td class="num">{{ ($p['kind'] ?? '') === 'refund' ? '−' : '' }}{{ $money($p['amount']) }}</td></tr>
@endforeach
</table>
@endif
@if (! empty($s['reason']))<p class="muted small">{{ $s['reason'] }}</p>@endif
<p class="muted small" style="margin-top:24px;">{{ __('emails.invoice.computer_generated') }}</p>
</body>
</html>

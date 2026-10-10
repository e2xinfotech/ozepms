<?php

namespace App\Domain\Billing;

use App\Models\Invoice;
use App\Models\Property;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Dompdf\Dompdf;
use Dompdf\Options;

/** The PDF of a tax invoice or credit note, drawn from the frozen invoice (so it never changes after issue). */
class InvoicePdf
{
    public function render(Invoice $invoice): string
    {
        $property = Property::query()->find($invoice->property_id);
        $s = (array) $invoice->snapshot;
        $cur = (string) ($s['currency'] ?? '');
        $locale = $property?->default_language ?: app()->getLocale();
        $previous = app()->getLocale();
        app()->setLocale($locale);
        try {
            $html = view('pdf.invoice', [
                's' => $s, 'hotel' => (string) $property?->name, 'credit' => ($s['type'] ?? '') === 'credit_note', 'cancelled' => $invoice->cancelled_at !== null,
                'money' => fn ($v) => Money::display((string) $v, $cur),
                'fdate' => fn ($d) => CarbonImmutable::parse($d)->locale(app()->getLocale())->translatedFormat('d M Y'),
            ])->render();
        } finally {
            app()->setLocale($previous);
        }

        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('chroot', base_path());
        $pdf = new Dompdf($options);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();

        return (string) $pdf->output();
    }

    /** A file name that is safe on every system: P1001/2627/00123 → Invoice-P1001-2627-00123.pdf */
    public function fileName(Invoice $invoice): string
    {
        $prefix = ($invoice->invoice_type === 'credit_note') ? 'Credit-note-' : 'Invoice-';

        return $prefix.trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $invoice->invoice_no), '-').'.pdf';
    }
}

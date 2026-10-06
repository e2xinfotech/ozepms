<?php

namespace App\Domain\Billing;

use App\Models\Property;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Gap-free per-property sequences for folios, invoices and credit notes (table property_counters).
 * The counter row stays locked until the surrounding transaction commits and a rollback gives the
 * number back, so numbers never repeat and never skip. Call it as late as possible inside the
 * transaction that stores the document.
 */
class BillingNumbers
{
    public function next(int $propertyId, string $key): int
    {
        // LAST_INSERT_ID(expr) returns the new value on this connection without a second statement lock.
        DB::statement(
            'INSERT INTO property_counters (property_id, counter_key, current_value) VALUES (?, ?, LAST_INSERT_ID(1))
             ON DUPLICATE KEY UPDATE current_value = LAST_INSERT_ID(current_value + 1)',
            [$propertyId, $key],
        );

        return (int) DB::selectOne('SELECT LAST_INSERT_ID() AS v')->v;
    }

    public function folioNo(Property $property): string
    {
        return config('ozepms.billing.folio_prefix', 'F-').str_pad((string) $this->next($property->id, 'folio'), 6, '0', STR_PAD_LEFT);
    }

    /** Financial year of a date: "2026-27" (year starting in the configured month, April for India). */
    public static function financialYear(CarbonImmutable $date): string
    {
        $startMonth = (int) config('ozepms.billing.financial_year_start_month', 4);
        if ($startMonth <= 1) {
            return $date->year.'-'.substr((string) ($date->year + 1), -2);
        }
        $start = $date->month >= $startMonth ? $date->year : $date->year - 1;

        return $start.'-'.substr((string) ($start + 1), -2);
    }

    /**
     * Next invoice / credit note number, at most 16 characters (GST rule):
     *   tax invoice  P1001/2627/00123
     *   credit note  P1001/C2627/0012
     */
    public function invoiceNo(Property $property, string $financialYear, bool $creditNote = false): string
    {
        $fy = substr($financialYear, 2, 2).substr($financialYear, -2);
        $seq = $this->next($property->id, ($creditNote ? 'credit_note:' : 'invoice:').$financialYear);
        $digits = (int) config($creditNote ? 'ozepms.billing.credit_note_seq_digits' : 'ozepms.billing.invoice_seq_digits', 4);
        $prefix = (string) $property->code;

        return substr($prefix.'/'.($creditNote ? 'C' : '').$fy.'/'.str_pad((string) $seq, $digits, '0', STR_PAD_LEFT), 0, 16);
    }
}

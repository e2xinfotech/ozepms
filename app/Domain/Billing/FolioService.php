<?php

namespace App\Domain\Billing;

use App\Domain\Access\AccessService;
use App\Domain\Audit\AuditLogger;
use App\Domain\Tax\Reference\TaxReference;
use App\Domain\Tax\TaxService;
use App\Infrastructure\Database\Tx;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\FolioLineTax;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\TaxCategory;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The folio of a reservation: what the guest owes.
 *
 * Room nights are posted one by one (night audit for in-house guests, every remaining night at
 * check-out) with their taxes from TaxService (GST slab per room-night tariff, CGST/SGST split).
 * Nights not posted yet are still part of the total ("pending room charges"), read from the
 * reservation's active nights, so the balance always covers the whole stay.
 * Lines are never deleted; voids post a reversal line (and a credit note when the original was invoiced).
 * After every change the folio totals and the reservation's money columns are refreshed.
 */
class FolioService
{
    /** @var array<int, Property> */
    private array $properties = [];

    /** Payment rows that count as money received. */
    public const RECEIVED = ['captured', 'refunded', 'partially_refunded'];

    public function __construct(
        private readonly TaxService $taxes,
        private readonly BillingNumbers $numbers,
        private readonly AuditLogger $audit,
        private readonly InvoiceService $invoices,
        private readonly AccessService $access,
    ) {}

    // ------------------------------------------------------------------ reading

    public function folioOf(Reservation $reservation): ?Folio
    {
        return Folio::query()->where('property_id', $reservation->property_id)
            ->where('reservation_id', $reservation->id)->orderBy('id')->first();
    }

    /**
     * Money summary used by the reservation pages and the check-out guard.
     * Keys: total, paid, balance, currency, lines_count (+ aliases charges = total, payments = paid,
     * posted = posted charges, pending_room_charges = room nights not posted yet, folio_no, status).
     *
     * @return array<string, mixed>
     */
    public function summary(Reservation $reservation): array
    {
        $folio = $this->folioOf($reservation);
        [$pendingNet, $pendingTax] = $this->pendingRoomTotals($reservation);
        $pending = Money::add($pendingNet, $pendingTax);
        if ($folio !== null) {
            $posted = Money::add((string) $folio->charges_total, (string) $folio->tax_total);
            $paid = (string) $folio->payments_total;
            $lines = (int) FolioLine::query()->where('folio_id', $folio->id)->where('is_void', false)->whereNull('void_of_line_id')->count();
        } else {
            $posted = '0';
            $paid = $this->paymentsTotal($reservation);
            $lines = 0;
        }
        $places = Money::minorUnits((string) $reservation->currency_code);
        $total = Money::round(Money::add($posted, $pending), $places);
        $paid = Money::round($paid, $places);

        return [
            'total' => $total,
            'paid' => $paid,
            'balance' => Money::round(Money::sub($total, $paid), $places),
            'currency' => (string) $reservation->currency_code,
            'lines_count' => $lines,
            'charges' => $total,
            'payments' => $paid,
            'posted' => Money::round($posted, $places),
            'pending_room_charges' => Money::round($pending, $places),
            'folio_no' => $folio?->folio_no,
            'status' => $folio?->status ?? 'open',
        ];
    }

    /**
     * Check-out guard: the balance must be settled unless the user may override it
     * (permission billing.override, or checkout.override_balance of the front desk).
     */
    public function canCheckOut(Reservation $reservation, ?User $user = null): bool
    {
        if (! Money::isPositive($this->summary($reservation)['balance'])) {
            return true;
        }

        return $user !== null && ($this->access->allows($user, 'billing.override') || $this->access->allows($user, 'checkout.override_balance'));
    }

    // ------------------------------------------------------------------ folio and room charges

    /** The reservation's folio, opened on first use (one folio per reservation). */
    public function open(Reservation $reservation): Folio
    {
        if ($folio = $this->folioOf($reservation)) {
            return $folio;
        }

        return Tx::run(function () use ($reservation) {
            // Serialises concurrent first postings for the same reservation.
            Reservation::query()->where('property_id', $reservation->property_id)->whereKey($reservation->id)->lockForUpdate()->first();
            if ($folio = $this->folioOf($reservation)) {
                return $folio;
            }
            $property = $this->propertyOf($reservation);
            $folio = Folio::query()->create([
                'property_id' => $reservation->property_id,
                'reservation_id' => $reservation->id,
                'folio_no' => $this->numbers->folioNo($property),
                'bill_to' => 'guest',
                'bill_to_guest_id' => $reservation->primary_guest_id,
                'status' => 'open',
                'currency_code' => $reservation->currency_code,
            ]);
            $this->audit->log('folio.opened', $folio, ['after' => ['folio_no' => $folio->folio_no]], $reservation->property_id);

            return $folio;
        });
    }

    /**
     * Posts the active room nights not posted yet, up to and including $upTo (all when null),
     * optionally for some reservation rooms only. Idempotent: a posted night is never posted twice.
     *
     * @param  list<int>|null  $roomIds
     */
    public function postRoomNights(Reservation $reservation, ?CarbonImmutable $upTo = null, ?User $by = null, ?array $roomIds = null): int
    {
        $folio = $this->open($reservation);

        return Tx::run(function () use ($reservation, $folio, $upTo, $by, $roomIds) {
            $folio = $this->lock($folio);
            $posted = $this->liveKeys($folio, 'room:');
            $count = 0;
            foreach ($this->roomNightCharges($reservation) as $key => $night) {
                if (isset($posted[$key]) || ($upTo !== null && $night['date'] > $upTo->toDateString())
                    || ($roomIds !== null && ! in_array($night['room_id'], $roomIds, true))) {
                    continue;
                }
                $this->insertLine($folio, [
                    'reservation_room_id' => $night['room_id'],
                    'business_date' => $night['date'],
                    'line_type' => 'room',
                    'tax_category_id' => $night['tax_category_id'],
                    'description' => $night['description'],
                    'sac_hsn_code' => $night['sac'],
                    'quantity' => '1',
                    'unit_price' => $night['taxable'],
                    'amount' => $night['taxable'],
                    'posting_key' => $key,
                    'posted_by' => $by?->id,
                ], $night['components']);
                $count++;
            }
            if ($count > 0) {
                $this->refreshTotals($folio, $reservation);
                $this->audit->log('folio.room_charges_posted', $folio, ['after' => ['nights' => $count, 'up_to' => $upTo?->toDateString()]], $folio->property_id, $by?->id);
            }

            return $count;
        });
    }

    /**
     * After a change of dates / rooms / rates / status: posted room nights that are no longer
     * part of the stay are voided (credit note when already invoiced); totals are refreshed.
     */
    public function syncRoomCharges(Reservation $reservation, ?User $by = null): int
    {
        $folio = $this->open($reservation);

        return Tx::run(function () use ($reservation, $folio, $by) {
            $folio = $this->lock($folio);
            $active = array_flip($this->activeNightKeys($reservation));
            $lines = FolioLine::query()->where('folio_id', $folio->id)->where('line_type', 'room')
                ->where('is_void', false)->whereNull('void_of_line_id')->where('posting_key', 'like', 'room:%')->get();
            $voided = 0;
            $reason = __('billing.reasons.stay_changed', [], $this->locale($reservation));
            $toCredit = [];
            foreach ($lines as $line) {
                if (! isset($active[$line->posting_key])) {
                    $reversal = $this->reverse($folio, $line, $reason, $by, false);
                    if ($line->invoice_id !== null) {
                        $toCredit[(int) $line->invoice_id][] = $reversal;
                    }
                    $voided++;
                }
            }
            // One credit note per invoice for all nights that left the stay.
            foreach ($toCredit as $invoiceId => $reversals) {
                $this->invoices->creditLines($folio, $invoiceId, $reversals, $reason, $by);
            }
            $this->refreshTotals($folio, $reservation);
            if ($voided > 0) {
                $this->audit->log('folio.room_charges_voided', $folio, ['after' => ['nights' => $voided]], $folio->property_id, $by?->id);
            }

            return $voided;
        });
    }

    // ------------------------------------------------------------------ manual charges

    /**
     * Posts an extra (service), an adjustment or a discount.
     *
     * $data: type service|adjustment|discount, service (?Service), description, quantity,
     *   unit_price (decimal string; discounts are entered positive), discount_percent (discounts:
     *   percentage of the room charges instead of unit_price), tax_category_id (?int; null = no tax),
     *   idempotency_key (?string).
     */
    public function postCharge(Reservation $reservation, array $data, ?User $by = null): FolioLine
    {
        $type = (string) ($data['type'] ?? 'service');
        if (! in_array($type, ['service', 'adjustment', 'discount'], true)) {
            throw ValidationException::withMessages(['type' => __('billing.errors.invalid_type')]);
        }
        $property = $this->propertyOf($reservation);
        $places = Money::minorUnits((string) $reservation->currency_code);
        /** @var Service|null $service */
        $service = $data['service'] ?? null;
        $quantity = Money::round((string) ($data['quantity'] ?? '1'), 2);
        if (! Money::isPositive($quantity)) {
            throw ValidationException::withMessages(['quantity' => __('billing.errors.quantity')]);
        }

        if ($type === 'discount' && isset($data['discount_percent']) && $data['discount_percent'] !== '' && $data['discount_percent'] !== null) {
            [$roomNet] = $this->roomTaxableTotal($reservation);
            $unit = Money::round(Money::percent($roomNet, (string) $data['discount_percent']), $places);
            $quantity = '1.00';
        } else {
            $unit = Money::normalize((string) ($data['unit_price'] ?? ($service?->price ?? '0')));
        }
        if ($type === 'discount') {
            $unit = Money::negate(Money::abs($unit));
        }
        $amount = Money::round(Money::mul($quantity, $unit), $places);
        if (Money::isZero($amount)) {
            throw ValidationException::withMessages(['unit_price' => __('billing.errors.amount_zero')]);
        }

        $categoryId = array_key_exists('tax_category_id', $data) ? ($data['tax_category_id'] !== null ? (int) $data['tax_category_id'] : null)
            : ($service?->tax_category_id ? (int) $service->tax_category_id : null);
        $category = $categoryId ? TaxCategory::query()->find($categoryId) : null;
        $description = trim((string) ($data['description'] ?? '')) ?: ($service?->name ?? __('billing.types.'.$type, [], $this->locale($reservation)));
        $date = BusinessDate::of($property);
        $folio = $this->open($reservation);
        // Keys are unique per property; the folio id keeps two reservations' keys apart.
        $key = isset($data['idempotency_key']) && $data['idempotency_key'] !== '' ? mb_substr('charge:'.$folio->id.':'.$data['idempotency_key'], 0, 80) : null;

        if ($key !== null && ($existing = $this->lineByKey($folio, $key))) {
            return $existing;
        }

        // Taxes outside the transaction (read-only), so the folio lock is held briefly.
        $taxed = $this->taxLine($property, $category, $amount, $date, $type === 'discount' ? $this->averageNightTariff($reservation) : null);

        try {
            return Tx::run(function () use ($folio, $reservation, $type, $service, $category, $description, $quantity, $unit, $taxed, $date, $key, $by, $data) {
                $folio = $this->lock($folio);
                if ($key !== null && ($existing = $this->lineByKey($folio, $key))) {
                    return $existing;
                }
                if ($folio->status === 'closed') {
                    $folio->status = 'open';
                    $folio->closed_at = null;
                    $folio->save();
                }
                $line = $this->insertLine($folio, [
                    'business_date' => $date->toDateString(),
                    'line_type' => $type,
                    'service_id' => $service?->id,
                    'tax_category_id' => $category?->id,
                    'description' => mb_substr($description, 0, 190),
                    'sac_hsn_code' => $service?->sac_hsn_code ?: $category?->default_sac_hsn,
                    'quantity' => $quantity,
                    'unit_price' => Money::round($unit, 4),
                    'amount' => $taxed['taxable'],
                    'posting_key' => $key,
                    'posted_by' => $by?->id,
                ], $taxed['components']);
                $this->refreshTotals($folio, $reservation);
                $this->audit->log('folio.charge_posted', $line, ['after' => [
                    'type' => $type, 'description' => $line->description, 'amount' => (string) $line->amount, 'tax' => (string) $line->tax_amount,
                    'note' => $data['note'] ?? null,
                ]], $folio->property_id, $by?->id);

                return $line;
            });
        } catch (QueryException $e) {
            // Two requests with the same key at the same moment: the first one won.
            if ($key !== null && ($existing = $this->lineByKey($folio, $key))) {
                return $existing;
            }
            throw $e;
        }
    }

    /** Cancellation / no-show fee from the reservations module (posted once per kind). */
    public function postFee(Reservation $reservation, string $fee, string $kind, ?User $by = null): ?FolioLine
    {
        $this->syncRoomCharges($reservation, $by);
        if (! Money::isPositive($fee)) {
            return null;
        }
        $property = $this->propertyOf($reservation);
        $folio = $this->open($reservation);
        $key = 'fee:'.$kind;
        if ($existing = $this->lineByKey($folio, $key)) {
            return $existing;
        }
        $code = config('ozepms.billing.cancellation_fee_tax_category');
        $category = $code ? TaxCategory::query()->where('code', $code)->first() : null;
        $amount = Money::forCurrency($fee, (string) $reservation->currency_code);
        $date = BusinessDate::of($property);
        $taxed = $this->taxLine($property, $category, $amount, $date, $this->averageNightTariff($reservation));

        return Tx::run(function () use ($folio, $reservation, $key, $kind, $category, $amount, $taxed, $date, $by) {
            $folio = $this->lock($folio);
            if ($existing = $this->lineByKey($folio, $key)) {
                return $existing;
            }
            $line = $this->insertLine($folio, [
                'business_date' => $date->toDateString(),
                'line_type' => 'cancellation_fee',
                'tax_category_id' => $category?->id,
                'description' => __('billing.lines.'.$kind.'_fee', [], $this->locale($reservation)),
                'sac_hsn_code' => $category?->default_sac_hsn,
                'quantity' => '1',
                'unit_price' => $amount,
                'amount' => $taxed['taxable'],
                'posting_key' => $key,
                'posted_by' => $by?->id,
            ], $taxed['components']);
            $this->refreshTotals($folio, $reservation);
            $this->audit->log('folio.fee_posted', $line, ['after' => ['kind' => $kind, 'amount' => (string) $line->amount]], $folio->property_id, $by?->id);

            return $line;
        });
    }

    /**
     * Voids a line: the line is marked void and a reversal is posted today; an invoiced line is
     * credited with a credit note. Room nights follow the stay (change the reservation, or post
     * an adjustment); a cancellation fee can only be waived with permission billing.override.
     */
    public function voidLine(FolioLine $line, string $reason, ?User $by = null, bool $override = false): FolioLine
    {
        if ($line->is_void || $line->void_of_line_id !== null) {
            throw ValidationException::withMessages(['line' => __('billing.errors.already_void')]);
        }
        if ($line->line_type === 'room') {
            throw ValidationException::withMessages(['line' => __('billing.errors.room_line_void')]);
        }
        if ($line->line_type === 'cancellation_fee' && ! $override) {
            throw ValidationException::withMessages(['line' => __('billing.errors.override_required')]);
        }
        $folio = Folio::query()->where('property_id', $line->property_id)->findOrFail($line->folio_id);
        $reservation = Reservation::query()->where('property_id', $folio->property_id)->findOrFail($folio->reservation_id);

        return Tx::run(function () use ($folio, $line, $reservation, $reason, $by) {
            $folio = $this->lock($folio);
            $line = FolioLine::query()->where('folio_id', $folio->id)->whereKey($line->id)->lockForUpdate()->firstOrFail();
            if ($line->is_void) {
                throw ValidationException::withMessages(['line' => __('billing.errors.already_void')]);
            }
            $reversal = $this->reverse($folio, $line, $reason, $by);
            $this->refreshTotals($folio, $reservation);
            $this->audit->log('folio.line_voided', $line, ['after' => ['reason' => $reason, 'amount' => (string) $line->amount]], $folio->property_id, $by?->id);

            return $reversal;
        });
    }

    /** Closes the folio when everything is settled (after check-out). */
    public function closeIfSettled(Reservation $reservation): void
    {
        $folio = $this->folioOf($reservation);
        if ($folio === null || $folio->status === 'closed') {
            return;
        }
        $summary = $this->summary($reservation);
        if (Money::isZero($summary['balance']) && Money::isZero($summary['pending_room_charges'])) {
            $folio->forceFill(['status' => 'closed', 'closed_at' => now()])->save();
            $this->audit->log('folio.closed', $folio, [], $folio->property_id);
        }
    }

    /**
     * Recomputes the folio totals and the reservation's money columns from the lines, the
     * payments and the nights not posted yet. Call inside the transaction that changed them.
     */
    public function refreshTotals(Folio $folio, Reservation $reservation): void
    {
        $byType = DB::table('folio_lines')->where('folio_id', $folio->id)
            ->groupBy('line_type')->selectRaw('line_type, SUM(amount) AS amount, SUM(tax_amount) AS tax')->get()->keyBy('line_type');
        $sum = fn (array $types, string $col) => Money::sum(collect($types)->map(fn ($t) => (string) ($byType[$t]->{$col} ?? '0')));
        $charges = $sum(FolioLine::TYPES, 'amount');
        $tax = $sum(FolioLine::TYPES, 'tax');
        $paid = $this->paymentsTotal($reservation);
        [$pendingNet, $pendingTax] = $this->pendingRoomTotals($reservation);
        $places = Money::minorUnits((string) $reservation->currency_code);

        $folio->forceFill([
            'charges_total' => Money::round($charges, $places),
            'tax_total' => Money::round($tax, $places),
            'payments_total' => Money::round($paid, $places),
        ])->save();

        $total = Money::round(Money::add($charges, $tax, $pendingNet, $pendingTax), $places);
        $extras = Money::add($sum(['service', 'adjustment', 'cancellation_fee'], 'amount'), $sum(['service', 'adjustment', 'cancellation_fee'], 'tax'));
        $discount = Money::negate(Money::add($sum(['discount'], 'amount'), $sum(['discount'], 'tax')));
        $hasRefunds = DB::table('payments')->where('reservation_id', $reservation->id)->where('kind', 'refund')->where('status', 'captured')->exists();
        $status = match (true) {
            ! Money::isPositive($paid) => $hasRefunds ? 'refunded' : 'unpaid',
            Money::compare($paid, $total) >= 0 => 'paid',
            default => 'partial',
        };

        // Money columns of the reservation follow the folio (list filters and reports read them).
        DB::table('reservations')->where('property_id', $reservation->property_id)->where('id', $reservation->id)->update([
            'room_total' => Money::round(Money::add($sum(['room'], 'amount'), $pendingNet), $places),
            'tax_total' => Money::round(Money::add($sum(['room'], 'tax'), $pendingTax), $places),
            'extras_total' => Money::round($extras, $places),
            'discount_total' => Money::round($discount, $places),
            'grand_total' => $total,
            'paid_total' => Money::round($paid, $places),
            'payment_status' => $status,
            'updated_at' => now(),
        ]);
    }

    /** Refreshes totals with the folio locked (payments, invoices). */
    public function refresh(Reservation $reservation): void
    {
        $folio = $this->open($reservation);
        Tx::run(function () use ($folio, $reservation) {
            $this->refreshTotals($this->lock($folio), $reservation);
        });
    }

    // ------------------------------------------------------------------ internals

    public function lock(Folio $folio): Folio
    {
        return Folio::query()->where('property_id', $folio->property_id)->whereKey($folio->id)->lockForUpdate()->firstOrFail();
    }

    /** Net payments received (payments − refunds), captured only. */
    public function paymentsTotal(Reservation $reservation): string
    {
        $row = DB::table('payments')->where('property_id', $reservation->property_id)->where('reservation_id', $reservation->id)
            ->selectRaw("COALESCE(SUM(CASE WHEN kind = 'payment' AND status IN ('captured','refunded','partially_refunded') THEN amount ELSE 0 END), 0) AS paid")
            ->selectRaw("COALESCE(SUM(CASE WHEN kind = 'refund' AND status = 'captured' THEN amount ELSE 0 END), 0) AS refunded")
            ->first();

        return Money::sub((string) $row->paid, (string) $row->refunded);
    }

    /** @return array{0: string, 1: string} net and tax of active nights not posted yet */
    private function pendingRoomTotals(Reservation $reservation): array
    {
        $row = DB::table('reservation_room_nights as n')
            ->join('reservation_rooms as rr', 'rr.id', '=', 'n.reservation_room_id')
            ->where('rr.property_id', $reservation->property_id)->where('rr.reservation_id', $reservation->id)->where('n.is_active', 1)
            ->whereNotExists(fn ($q) => $q->from('folio_lines as fl')->where('fl.property_id', $reservation->property_id)
                ->whereRaw("fl.live_key = CONCAT('room:', n.reservation_room_id, ':', n.stay_date)"))
            ->selectRaw('COALESCE(SUM(n.net_price), 0) AS net, COALESCE(SUM(n.tax_amount), 0) AS tax')->first();

        return [(string) $row->net, (string) $row->tax];
    }

    /** @return array{0: string, 1: string} taxable room charges (posted + pending) and number of active nights */
    private function roomTaxableTotal(Reservation $reservation): array
    {
        $posted = (string) (DB::table('folio_lines')->where('property_id', $reservation->property_id)
            ->whereIn('folio_id', fn ($q) => $q->from('folios')->select('id')->where('reservation_id', $reservation->id))
            ->where('line_type', 'room')->sum('amount') ?: '0');
        [$pending] = $this->pendingRoomTotals($reservation);
        $nights = (int) DB::table('reservation_room_nights as n')->join('reservation_rooms as rr', 'rr.id', '=', 'n.reservation_room_id')
            ->where('rr.reservation_id', $reservation->id)->where('n.is_active', 1)->count();

        return [Money::add($posted, $pending), (string) $nights];
    }

    /** Average tariff per room-night: the GST slab of discounts and fees on accommodation. */
    private function averageNightTariff(Reservation $reservation): ?string
    {
        [$net, $nights] = $this->roomTaxableTotal($reservation);
        if ((int) $nights === 0) {
            $row = DB::table('reservation_room_nights as n')->join('reservation_rooms as rr', 'rr.id', '=', 'n.reservation_room_id')
                ->where('rr.reservation_id', $reservation->id)->selectRaw('COALESCE(SUM(n.net_price), 0) AS net, COUNT(*) AS c')->first();
            $net = (string) $row->net;
            $nights = (string) $row->c;
        }

        return (int) $nights > 0 ? Money::round(Money::div($net, $nights), 2) : null;
    }

    /** @return list<string> posting keys of the reservation's active nights */
    private function activeNightKeys(Reservation $reservation): array
    {
        return DB::table('reservation_room_nights as n')->join('reservation_rooms as rr', 'rr.id', '=', 'n.reservation_room_id')
            ->where('rr.property_id', $reservation->property_id)->where('rr.reservation_id', $reservation->id)->where('n.is_active', 1)
            ->get(['n.reservation_room_id', 'n.stay_date'])
            ->map(fn ($n) => 'room:'.$n->reservation_room_id.':'.substr((string) $n->stay_date, 0, 10))->all();
    }

    /**
     * Charges of every active night of the reservation, taxed in one TaxService call (so a
     * per-booking fee is charged once), keyed by posting key "room:{room id}:{date}".
     *
     * @return array<string, array<string, mixed>>
     */
    private function roomNightCharges(Reservation $reservation): array
    {
        $rows = DB::table('reservation_room_nights as n')
            ->join('reservation_rooms as rr', 'rr.id', '=', 'n.reservation_room_id')
            ->leftJoin('room_types as rt', 'rt.id', '=', 'rr.room_type_id')
            ->leftJoin('rate_plans as rp', 'rp.id', '=', 'rr.rate_plan_id')
            ->where('rr.property_id', $reservation->property_id)->where('rr.reservation_id', $reservation->id)->where('n.is_active', 1)
            ->orderBy('rr.sort_order')->orderBy('rr.id')->orderBy('n.stay_date')
            ->get([
                'n.reservation_room_id', 'n.stay_date', 'n.base_price', 'n.occupancy_adjust', 'n.discount',
                'rr.room_type_id', 'rr.rate_plan_id', 'rr.adults', 'rr.children', 'rr.infants',
                'rt.name as room_type_name', 'rp.name as rate_plan_name',
            ]);
        if ($rows->isEmpty()) {
            return [];
        }
        $property = $this->propertyOf($reservation);
        $category = TaxReference::category('accommodation');
        $sac = $category->default_sac_hsn ?: config('ozepms.billing.accommodation_sac');
        $places = Money::minorUnits((string) $reservation->currency_code);

        $lines = [];
        foreach ($rows as $n) {
            $price = Money::round(Money::sub(Money::add((string) $n->base_price, (string) $n->occupancy_adjust), (string) $n->discount), $places);
            $lines[] = [
                'category' => 'accommodation', 'amount' => $price, 'unit_night_tariff' => $price, 'date' => substr((string) $n->stay_date, 0, 10),
                'room_type_id' => (int) $n->room_type_id, 'rate_plan_id' => (int) $n->rate_plan_id, 'nights' => 1,
                'persons' => max(1, (int) $n->adults + (int) $n->children + (int) $n->infants),
            ];
        }
        $breakdown = $this->taxes->calculate($property, $lines);

        $out = [];
        foreach ($rows as $i => $n) {
            $date = substr((string) $n->stay_date, 0, 10);
            $line = $breakdown->lines[$i];
            $out['room:'.$n->reservation_room_id.':'.$date] = [
                'room_id' => (int) $n->reservation_room_id,
                'date' => $date,
                'description' => trim(($n->room_type_name ?? '').($n->rate_plan_name ? ' · '.$n->rate_plan_name : '')) ?: __('billing.types.room', [], $this->locale($reservation)),
                'taxable' => (string) $line['taxable'],
                'components' => $line['components'],
                'tax_category_id' => (int) $category->id,
                'sac' => $sac,
            ];
        }

        return $out;
    }

    /**
     * Taxes of one manual line. Discounts and fees on accommodation use the stay's average
     * tariff per night for the slab.
     *
     * @return array{taxable: string, components: list<array<string, mixed>>}
     */
    private function taxLine(Property $property, ?TaxCategory $category, string $amount, CarbonImmutable $date, ?string $tariff): array
    {
        if ($category === null) {
            return ['taxable' => $amount, 'components' => []];
        }
        $line = ['category' => (string) $category->code, 'amount' => $amount, 'date' => $date->toDateString(), 'nights' => 1, 'persons' => 1];
        if ($category->code === 'accommodation' && $tariff !== null) {
            $line['unit_night_tariff'] = $tariff;
        }
        $result = $this->taxes->calculate($property, [$line])->lines[0];

        return ['taxable' => (string) $result['taxable'], 'components' => $result['components']];
    }

    /** @param  list<array<string, mixed>>  $components  TaxService components */
    private function insertLine(Folio $folio, array $attributes, array $components): FolioLine
    {
        $tax = Money::sum(array_map(fn ($c) => (string) $c['amount'], $components));
        $places = Money::minorUnits((string) $folio->currency_code);
        /** @var FolioLine $line */
        $line = FolioLine::query()->create($attributes + [
            'property_id' => $folio->property_id,
            'folio_id' => $folio->id,
            'tax_amount' => Money::round($tax, $places),
        ]);
        if ($components !== []) {
            FolioLineTax::query()->insert(array_map(fn ($c) => [
                'folio_line_id' => $line->id,
                'tax_rule_id' => $c['tax_rule_id'] ?? null,
                'component' => mb_substr((string) $c['component'], 0, 20),
                'tax_name' => mb_substr((string) ($c['name'] ?? $c['component']), 0, 80),
                'rate' => Money::round((string) ($c['rate'] ?? '0'), 4),
                'taxable_amount' => Money::round((string) ($c['taxable'] ?? $attributes['amount']), 2),
                'tax_amount' => Money::round((string) $c['amount'], 2),
            ], $components));
        }

        return $line;
    }

    /** Marks $line void and posts its reversal today (credit note when it was invoiced). */
    private function reverse(Folio $folio, FolioLine $line, string $reason, ?User $by, bool $credit = true): FolioLine
    {
        $property = $this->propertyOf($folio);
        $line->forceFill(['is_void' => true, 'void_reason' => mb_substr($reason, 0, 255)])->save();
        $taxes = FolioLineTax::query()->where('folio_line_id', $line->id)->get();
        $reversal = $this->insertLine($folio, [
            'reservation_room_id' => $line->reservation_room_id,
            'business_date' => BusinessDate::of($property)->toDateString(),
            'line_type' => $line->line_type,
            'service_id' => $line->service_id,
            'tax_category_id' => $line->tax_category_id,
            'description' => $line->description,
            'sac_hsn_code' => $line->sac_hsn_code,
            'quantity' => (string) $line->quantity,
            'unit_price' => Money::round(Money::negate((string) $line->unit_price), 4),
            'amount' => Money::round(Money::negate((string) $line->amount), 2),
            'void_of_line_id' => $line->id,
            'void_reason' => mb_substr($reason, 0, 255),
            'posted_by' => $by?->id,
        ], $taxes->map(fn (FolioLineTax $t) => [
            'tax_rule_id' => $t->tax_rule_id, 'component' => $t->component, 'name' => $t->tax_name, 'rate' => (string) $t->rate,
            'taxable' => Money::negate((string) $t->taxable_amount), 'amount' => Money::negate((string) $t->tax_amount),
        ])->all());

        if ($credit && $line->invoice_id !== null) {
            $this->invoices->creditLines($folio, (int) $line->invoice_id, [$reversal], $reason, $by);
        }

        return $reversal;
    }

    /** @return array<string, true> live posting keys of the folio starting with $prefix */
    private function liveKeys(Folio $folio, string $prefix): array
    {
        return FolioLine::query()->where('property_id', $folio->property_id)->where('folio_id', $folio->id)
            ->where('live_key', 'like', $prefix.'%')->pluck('live_key')->flip()->map(fn () => true)->all();
    }

    private function lineByKey(Folio $folio, string $key): ?FolioLine
    {
        return FolioLine::query()->where('property_id', $folio->property_id)->where('live_key', $key)->first();
    }

    private function propertyOf(Reservation|Folio $model): Property
    {
        // Memoised per service instance (the container builds a new one per resolution).
        return $this->properties[(int) $model->property_id] ??= Property::query()->findOrFail($model->property_id);
    }

    /** Line texts are stored in the property's language (they are printed on invoices). */
    private function locale(Reservation $reservation): string
    {
        $locale = (string) ($this->propertyOf($reservation)->default_language ?: config('ozepms.locales.default'));

        return array_key_exists($locale, config('ozepms.locales.available')) ? $locale : 'en';
    }
}

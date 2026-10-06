<?php

namespace Database\Seeders;

use App\Domain\Accommodation\InProperty;
use App\Domain\Billing\BusinessDate;
use App\Domain\Billing\FolioService;
use App\Domain\Billing\InvoiceService;
use App\Domain\Billing\PaymentService;
use App\Domain\Billing\ServiceCatalogService;
use App\Models\FolioLine;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Seeder;

/**
 * Demo billing for P1001 / P1002 on top of the demo reservations: services & extras, room nights
 * posted for in-house and departed guests, extras, deposits and payments, tax invoices for
 * departed guests and one credit note. Idempotent (fixed idempotency keys, checks before invoicing).
 */
class BillingDemoSeeder extends Seeder
{

    /** code, name, price (property currency), tax category, posting rule */
    private const SERVICES = [
        ['XBED', 'Extra bed', '1500.00', 'service', 'per_night'],
        ['APT', 'Airport pickup', '1200.00', 'service', 'once'],
        ['BRKF', 'Breakfast', '450.00', 'food', 'per_person_night'],
        ['LAUN', 'Laundry', '300.00', 'service', 'per_person'],
        ['SPA', 'Spa treatment', '2500.00', 'service', 'per_person'],
    ];

    public function run(): void
    {
        $user = User::query()->where('email', 'manager@demo.ozepms.test')->first();
        foreach (AccommodationDemoSeeder::demoProperties() as $property) {
            $count = InProperty::run($property, fn () => $this->seedProperty($property, $user));
            $this->command?->info("Billing demo data ready for {$property->code} ({$count} reservations).");
        }
    }

    private function seedProperty(Property $property, ?User $user): int
    {
        $catalog = app(ServiceCatalogService::class);
        foreach (self::SERVICES as $i => [$code, $name, $price, $category, $rule]) {
            if (! Service::query()->where('property_id', $property->id)->where('code', $code)->exists()) {
                $catalog->create($property, ['code' => $code, 'name' => $name, 'price' => $price, 'tax_category' => $category, 'posting_rule' => $rule, 'sort_order' => $i]);
            }
        }
        $services = Service::query()->where('property_id', $property->id)->get()->keyBy('code');
        $folios = app(FolioService::class);
        $payments = app(PaymentService::class);
        $invoices = app(InvoiceService::class);
        $yesterday = BusinessDate::localToday($property)->subDay();

        $reservations = Reservation::query()->where('property_id', $property->id)->orderBy('id')->get();
        $creditNoteDone = false;
        foreach ($reservations as $n => $r) {
            $ref = $r->booking_ref;
            $folios->open($r);
            $folios->refresh($r);

            if ($r->status === 'checked_in') {
                $folios->postRoomNights($r, $yesterday, $user);
                $folios->postCharge($r, ['type' => 'service', 'service' => $services['APT'], 'quantity' => '1', 'idempotency_key' => "demo-apt-$ref"], $user);
                if ($n % 2 === 0) {
                    $folios->postCharge($r, ['type' => 'service', 'service' => $services['BRKF'], 'quantity' => (string) max(1, $r->adults), 'idempotency_key' => "demo-brkf-$ref"], $user);
                }
                $this->pay($payments, $folios, $r, '0.5', 'card', "demo-dep-$ref", $user, '4242');
            } elseif ($r->status === 'checked_out') {
                $folios->postRoomNights($r, null, $user);
                $laundry = $folios->postCharge($r, ['type' => 'service', 'service' => $services['LAUN'], 'quantity' => '2', 'idempotency_key' => "demo-laun-$ref"], $user);
                $this->pay($payments, $folios, $r, '1', $n % 2 ? 'upi' : 'cash', "demo-pay-$ref", $user, $n % 2 ? 'UPI'.substr(md5($ref), 0, 10) : null);
                $folio = $folios->open($r);
                if ($invoices->hasBillableLines($folio)) {
                    $invoices->issue($folio, [], $user);
                }
                // One correction after invoicing: the laundry was posted by mistake → credit note.
                if (! $creditNoteDone && ! FolioLine::query()->whereKey($laundry->id)->value('is_void')) {
                    $folios->voidLine($laundry->fresh(), 'Posted to the wrong guest', $user);
                    $creditNoteDone = true;
                } elseif (FolioLine::query()->whereKey($laundry->id)->value('is_void')) {
                    $creditNoteDone = true;
                }
                $folios->closeIfSettled($r->fresh());
            } elseif (in_array($r->status, ['confirmed', 'pending'], true) && $n % 3 === 0) {
                $this->pay($payments, $folios, $r, '0.3', 'bank_transfer', "demo-adv-$ref", $user, 'UTR'.strtoupper(substr(md5($ref), 0, 12)));
            }
        }

        return $reservations->count();
    }

    /** Pays $share of the open balance (once, by key). */
    private function pay(PaymentService $payments, FolioService $folios, Reservation $r, string $share, string $method, string $key, ?User $user, ?string $reference): void
    {
        if (Payment::query()->where('reservation_id', $r->id)->where('idempotency_key', $key)->exists()) {
            return;
        }
        $balance = $folios->summary($r->fresh())['balance'];
        $amount = Money::forCurrency(Money::mul($balance, $share), (string) $r->currency_code);
        if (Money::isPositive($amount)) {
            $payments->record($r, ['method' => $method, 'amount' => $amount, 'reference' => $reference, 'idempotency_key' => $key], $user);
        }
    }
}

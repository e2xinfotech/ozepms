<?php

namespace Tests\Feature\Billing;

use App\Domain\Billing\FolioService;
use App\Domain\Billing\InvoiceService;
use App\Domain\Billing\PaymentService;
use App\Models\Folio;
use App\Models\FolioLine;
use App\Models\Reservation;
use App\Models\State;
use App\Support\PropertyContext;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Reservations\ReservationTestCase;

/**
 * A GST-registered property in Maharashtra (India slabs from the country templates) with two room
 * types (DLX 4000/night, STE 9000/night) and helpers for billing.
 */
abstract class BillingTestCase extends ReservationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $state = State::query()->where('code', 'IN-MH')->value('id');
        DB::table('properties')->whereIn('id', [$this->property->id, $this->other->id])->update([
            'state_id' => $state, 'tax_registration_no' => '27AABCS1234F1Z5', 'legal_name' => 'Main Hotel Pvt Ltd',
            'address_line1' => '1 Marine Drive', 'city' => 'Mumbai', 'postcode' => '400001',
        ]);
        $this->property->refresh();
        $this->other->refresh();
    }

    protected function folios(): FolioService
    {
        return app(FolioService::class);
    }

    protected function invoices(): InvoiceService
    {
        return app(InvoiceService::class);
    }

    protected function payments(): PaymentService
    {
        return app(PaymentService::class);
    }

    protected function folioOf(Reservation $r): Folio
    {
        return Folio::acrossProperties()->where('reservation_id', $r->id)->firstOrFail();
    }

    /** @return \Illuminate\Support\Collection<int, FolioLine> */
    protected function lines(Reservation $r)
    {
        return FolioLine::acrossProperties()->where('folio_id', $this->folioOf($r)->id)->orderBy('id')->get();
    }

    protected function makeService(array $data = [], $property = null): \App\Models\Service
    {
        return $this->within(fn () => app(\App\Domain\Billing\ServiceCatalogService::class)->create($property ?? $this->property, array_merge([
            'code' => 'XBED', 'name' => 'Extra bed', 'price' => '1500.00', 'tax_category' => 'service', 'posting_rule' => 'per_night',
        ], $data)), $property);
    }

    /** Books a stay that started today in the first DLX room and checks it in. */
    protected function inHouse(int $out = 3): Reservation
    {
        $r = $this->book([$this->room($this->dlxBar, 0, $out, ['unit' => $this->firstUnit($this->deluxe)])]);
        $this->within(fn () => $this->service()->checkIn($r, null, $this->owner));

        return $r->fresh();
    }

    /** Runs $fn as a member of the main property (permissions from the member's role). */
    protected function asMember(\App\Models\User $user, callable $fn): mixed
    {
        $membership = \App\Models\PropertyUser::query()->withoutGlobalScope('property')->where('property_id', $this->property->id)->where('user_id', $user->id)->firstOrFail();
        app(PropertyContext::class)->set($this->property, $membership);
        try {
            return $fn();
        } finally {
            app(PropertyContext::class)->clear();
        }
    }

    /** Runs $fn inside the main property (services expect a selected property, as in a request). */
    protected function within(callable $fn, $property = null): mixed
    {
        $this->inProperty($property ?? $this->property);
        try {
            return $fn();
        } finally {
            app(PropertyContext::class)->clear();
        }
    }
}

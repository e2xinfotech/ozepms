<?php

namespace App\Http\Controllers\WebApi\Property\Concerns;

use App\Domain\Access\AccessService;
use App\Models\FolioLine;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Service;

/**
 * Public id → model lookups for the billing endpoints, through the tenant scope: another
 * property's id is a 404.
 */
trait FindsBilling
{
    protected function reservationOr404(string $id): Reservation
    {
        return Reservation::query()->where('public_id', $id)->firstOrFail();
    }

    protected function lineOr404(Reservation $reservation, string $id): FolioLine
    {
        return FolioLine::query()->where('public_id', $id)
            ->whereIn('folio_id', fn ($q) => $q->from('folios')->select('id')->where('reservation_id', $reservation->id))
            ->firstOrFail();
    }

    protected function paymentOr404(Reservation $reservation, string $id): Payment
    {
        return Payment::query()->where('reservation_id', $reservation->id)->where('public_id', $id)->firstOrFail();
    }

    protected function invoiceOr404(string $id): Invoice
    {
        return Invoice::query()->where('public_id', $id)->firstOrFail();
    }

    protected function serviceOr404(string $id): Service
    {
        return Service::query()->where('public_id', $id)->firstOrFail();
    }

    protected function allowed(string $permission): bool
    {
        $user = request()->user();

        return $user !== null && app(AccessService::class)->allows($user, $permission);
    }
}

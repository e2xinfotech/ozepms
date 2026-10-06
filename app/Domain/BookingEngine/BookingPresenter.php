<?php

namespace App\Domain\BookingEngine;

use App\Models\Reservation;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/** What the guest sees about a booking (confirmation page, e-mail): no internal ids. */
class BookingPresenter
{
    public function confirmationUrl(Reservation $reservation): string
    {
        $code = DB::table('properties')->where('id', $reservation->property_id)->value('code');

        return URL::signedRoute('booking.confirmation', ['code' => $code, 'reservation' => $reservation->public_id]);
    }

    public function booking(Reservation $r): array
    {
        $rooms = DB::table('reservation_rooms as rr')->join('room_types as t', 't.id', '=', 'rr.room_type_id')->join('rate_plans as p', 'p.id', '=', 'rr.rate_plan_id')
            ->where('rr.reservation_id', $r->id)->orderBy('rr.sort_order')
            ->get(['t.name as room_type', 'p.name as rate_plan', 'rr.adults', 'rr.children', 'rr.infants', 'rr.status', 'rr.grand_total']);
        $offers = DB::table('offer_applications')->where('reservation_id', $r->id)->get(['discount_amount', 'snapshot'])
            ->groupBy(fn ($a) => json_decode((string) $a->snapshot, true)['name'] ?? '—')
            ->map(fn ($g, $name) => ['name' => $name, 'amount' => Money::round(Money::sum($g->pluck('discount_amount')->map(fn ($v) => (string) $v)))])->values()->all();
        $paid = (string) DB::table('payments')->where('reservation_id', $r->id)->where('kind', 'payment')->where('status', 'captured')->sum('amount');
        $pending = DB::table('payments')->where('reservation_id', $r->id)->where('method', 'gateway')->where('status', 'pending')->orderByDesc('id')->first(['public_id', 'amount']);

        return [
            'ref' => $r->booking_ref, 'status' => $r->status, 'guest' => $r->guest_name,
            'check_in' => $r->check_in?->toDateString(), 'check_out' => $r->check_out?->toDateString(), 'nights' => (int) $r->nights,
            'rooms' => $rooms->map(fn ($x) => (array) $x)->all(), 'offers' => $offers,
            'room_total' => (string) $r->room_total, 'tax_total' => (string) $r->tax_total, 'grand_total' => (string) $r->grand_total,
            'paid' => Money::round($paid ?: '0'), 'currency' => $r->currency_code, 'special_requests' => $r->special_requests,
            'hold_expires_at' => $r->hold_expires_at?->toIso8601String(), 'pending_payment' => $pending ? ['id' => $pending->public_id, 'amount' => (string) $pending->amount] : null,
            'created_at' => $r->created_at?->toIso8601String(),
        ];
    }
}

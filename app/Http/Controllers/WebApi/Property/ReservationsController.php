<?php

namespace App\Http\Controllers\WebApi\Property;

use App\Domain\Access\AccessService;
use App\Domain\Availability\AvailabilityService;
use App\Domain\Reservations\Queries\ReservationPresenter;
use App\Domain\Reservations\Queries\ReservationQuery;
use App\Domain\Reservations\ReservationService;
use App\Domain\Reservations\StayPricer;
use App\Http\Controllers\Controller;
use App\Http\Controllers\WebApi\Property\Concerns\FindsReservations;
use App\Http\Requests\Property\Reservations\ActionRequest;
use App\Http\Requests\Property\Reservations\AvailabilityRequest;
use App\Http\Requests\Property\Reservations\QuoteReservationRequest;
use App\Http\Requests\Property\Reservations\SaveReservationRequest;
use App\Models\Reservation;
use App\Support\Money;
use App\Support\PropertyContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReservationsController extends Controller
{
    use FindsReservations;

    public function __construct(
        private readonly ReservationService $service,
        private readonly ReservationPresenter $presenter,
        private readonly PropertyContext $context,
    ) {}

    /** Step 1 of the create page: room types × rate plans with rooms left, price and policy. */
    public function availability(AvailabilityRequest $request, AvailabilityService $availability): JsonResponse
    {
        $property = $this->context->property();
        $data = $request->validated();
        $result = $availability->search($property, CarbonImmutable::parse($data['check_in']), CarbonImmutable::parse($data['check_out']),
            (int) $data['adults'], (int) ($data['children'] ?? 0), (int) ($data['infants'] ?? 0), 'pms');

        $policies = DB::table('rate_plans')->join('cancellation_policies', 'cancellation_policies.id', '=', 'rate_plans.cancellation_policy_id')
            ->leftJoin('meal_plans', 'meal_plans.id', '=', 'rate_plans.meal_plan_id')
            ->where('rate_plans.property_id', $property->id)
            ->get(['rate_plans.id', 'cancellation_policies.name as policy', 'cancellation_policies.is_refundable', 'meal_plans.code as meal_plan'])->keyBy('id');
        $images = DB::table('room_type_images')->where('property_id', $property->id)->orderBy('sort_order')->get(['room_type_id', 'path'])->groupBy('room_type_id');

        $rows = [];
        foreach ($result->roomTypes as $rt) {
            $image = $images->get($rt['room_type_id'])?->first();
            foreach ($rt['products'] as $p) {
                $policy = $policies->get($p['rate_plan_id']);
                $rows[] = [
                    'room_type' => $rt['room_type'] + ['image' => $image ? \Illuminate\Support\Facades\Storage::disk(\App\Models\RoomTypeImage::DISK)->url($image->path) : null],
                    'rate_plan' => $p['rate_plan'],
                    'meal_plan' => $policy?->meal_plan,
                    'policy' => ['name' => $policy?->policy, 'refundable' => (bool) ($policy?->is_refundable ?? true)],
                    'available' => (int) $rt['available_units'],
                    'sellable' => (bool) $p['sellable'],
                    'reasons' => array_map(fn ($r) => ['code' => $r, 'label' => __('inventory.reasons.'.$r)], $p['reasons']),
                    'nightly' => $p['nightly'],
                    'total' => $p['total'],
                    'average' => $p['total'] !== null && $result->nights > 0 ? Money::round(Money::div($p['total'], $result->nights)) : null,
                ];
            }
        }

        return response()->json(['check_in' => $result->checkIn, 'check_out' => $result->checkOut, 'nights' => $result->nights, 'currency' => $property->currency_code, 'rows' => $rows]);
    }

    /** Live price summary of the form (rooms + taxes), before saving. */
    public function quote(QuoteReservationRequest $request, StayPricer $pricer): JsonResponse
    {
        $property = $this->context->property();
        $rooms = $this->roomSpecs($request->validated('rooms') ?? []);
        foreach ($rooms as $i => &$r) {
            $r['check_in'] = CarbonImmutable::parse($r['check_in']);
            $r['check_out'] = CarbonImmutable::parse($r['check_out']);
            $r['child_ages'] = $r['child_ages'] ?? [...array_fill(0, (int) ($r['children'] ?? 0), 8), ...array_fill(0, (int) ($r['infants'] ?? 0), 0)];
        }
        unset($r);
        $priced = $pricer->price($property, $rooms);
        $sum = fn (string $k) => Money::round(Money::sum(array_column($priced, $k)));
        $components = [];
        foreach ($priced as $p) {
            foreach ($p['components'] as $code => $amount) {
                $components[$code] = Money::round(Money::add($components[$code] ?? '0', $amount));
            }
        }

        return response()->json([
            'currency' => $property->currency_code,
            'rooms' => array_map(fn ($p) => ['room_total' => $p['room_total'], 'tax_total' => $p['tax_total'], 'grand_total' => $p['grand_total'],
                'rate' => array_values($p['nights'])[0]['price'] ?? null, 'nights' => count($p['nights'])], $priced),
            'room_total' => $sum('room_total'), 'tax_total' => $sum('tax_total'), 'grand_total' => $sum('grand_total'), 'taxes' => $components,
        ]);
    }

    public function show(Request $request, mixed $property, string $reservation): JsonResponse
    {
        $r = $this->reservationOr404($reservation);

        return response()->json(['reservation' => $this->presenter->detail($r, $this->context->property(), $request->user())]);
    }

    public function history(mixed $property, string $reservation): JsonResponse
    {
        return response()->json($this->presenter->history($this->reservationOr404($reservation)));
    }

    public function store(SaveReservationRequest $request): JsonResponse
    {
        $data = $this->payload($request);
        $data['idempotency_key'] = $request->validated('idempotency_key') ?? $this->headerKey($request);
        $reservation = $this->service->create($this->context->property(), $data, $request->user());

        return response()->json([
            'message' => $this->service->replayed ? __('reservations.messages.already_created', ['ref' => $reservation->booking_ref]) : __('reservations.messages.created', ['ref' => $reservation->booking_ref]),
            'reservation' => ['id' => $reservation->public_id, 'ref' => $reservation->booking_ref, 'status' => $reservation->status],
            'replayed' => $this->service->replayed,
        ], $this->service->replayed ? 200 : 201);
    }

    public function update(SaveReservationRequest $request, mixed $property, string $reservation): JsonResponse
    {
        $r = $this->reservationOr404($reservation);
        $data = $this->payload($request, $r);
        $r = $this->service->modify($r, $data, $request->user());

        return $this->done($request, $r, __('reservations.messages.updated', ['ref' => $r->booking_ref]));
    }

    public function confirm(Request $request, mixed $property, string $reservation): JsonResponse
    {
        $r = $this->service->confirm($this->reservationOr404($reservation), $request->user());

        return $this->done($request, $r, __('reservations.messages.confirmed', ['ref' => $r->booking_ref]));
    }

    public function cancel(ActionRequest $request, mixed $property, string $reservation): JsonResponse
    {
        $r = $this->service->cancel($this->reservationOr404($reservation), $request->validated('reason'), $request->user(), (bool) $request->validated('waive_fee'));

        return $this->done($request, $r, __('reservations.messages.cancelled', ['ref' => $r->booking_ref]));
    }

    public function noShow(ActionRequest $request, mixed $property, string $reservation): JsonResponse
    {
        $r = $this->service->noShow($this->reservationOr404($reservation), $request->user(), (bool) $request->validated('waive_fee'));

        return $this->done($request, $r, __('reservations.messages.no_show', ['ref' => $r->booking_ref]));
    }

    public function checkIn(ActionRequest $request, mixed $property, string $reservation): JsonResponse
    {
        $r = $this->reservationOr404($reservation);
        $guest = array_filter((array) ($request->validated('guest') ?? []), fn ($v) => $v !== null && $v !== '');
        $r = $this->service->checkIn($r, $this->roomIds($r, $request->validated('rooms')), $request->user(), $guest, $request->validated('note'));

        return $this->done($request, $r, __('reservations.messages.checked_in', ['guest' => $r->guest_name]));
    }

    public function checkOut(ActionRequest $request, AccessService $access, mixed $property, string $reservation): JsonResponse
    {
        $r = $this->reservationOr404($reservation);
        $override = (bool) $request->validated('override_balance') && $access->allows($request->user(), 'checkout.override_balance');
        $r = $this->service->checkOut($r, $this->roomIds($r, $request->validated('rooms')), $request->user(), $override, $request->validated('note'));

        return $this->done($request, $r, __('reservations.messages.checked_out', ['guest' => $r->guest_name]));
    }

    public function assign(ActionRequest $request, mixed $property, string $reservation): JsonResponse
    {
        $r = $this->reservationOr404($reservation);
        $room = $this->roomOr404($r, (string) $request->validated('room_id'));
        $unitId = $request->validated('unit_id');
        $unit = $unitId ? $this->unitField($unitId, 'unit_id') : null;
        $this->service->assignUnit($r, $room, $unit, $request->user());

        return $this->done($request, $r->fresh(), $unit ? __('reservations.messages.assigned', ['room' => $unit->name]) : __('reservations.messages.unassigned'));
    }

    /** Free PMS rooms of a room type for a stay (room picker); the reservation's own room counts as free. */
    public function units(Request $request): JsonResponse
    {
        $data = $request->validate([
            'room_type_id' => ['required', 'string', 'size:26'], 'check_in' => ['required', 'date_format:Y-m-d'],
            'check_out' => ['required', 'date_format:Y-m-d', 'after:check_in'], 'room_id' => ['nullable', 'string', 'size:26'],
        ]);
        $roomType = $this->roomTypeField($data['room_type_id'], 'room_type_id');
        $except = ! empty($data['room_id']) ? \App\Models\ReservationRoom::query()->where('public_id', $data['room_id'])->value('id') : null;
        $units = $this->service->freeUnits($roomType->id, CarbonImmutable::parse($data['check_in']), CarbonImmutable::parse($data['check_out']), $except);

        return response()->json(['units' => $units->map(fn ($u) => [
            'id' => $u->public_id, 'name' => $u->name, 'floor' => $u->floor, 'housekeeping' => $u->housekeeping_status,
        ])->values()->all()]);
    }

    public function note(ActionRequest $request, mixed $property, string $reservation): JsonResponse
    {
        $note = $this->service->addNote($this->reservationOr404($reservation), (string) $request->validated('body'), $request->user());

        return response()->json(['message' => __('reservations.messages.note_added'), 'note' => [
            'id' => $note->id, 'body' => $note->body, 'user' => $request->user()?->name, 'at' => $note->created_at?->toIso8601String(),
        ]], 201);
    }

    /** CSV of the filtered list (same query string as the page), streamed in chunks. */
    public function export(Request $request, ReservationQuery $query): StreamedResponse
    {
        $property = $this->context->property();
        $rows = $query->exportQuery($property, $request);
        $name = 'reservations-'.$property->code.'-'.now($property->timezone)->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, array_map(fn ($k) => __('reservations.export.'.$k), ['ref', 'status', 'guest', 'phone', 'check_in', 'check_out', 'nights', 'rooms', 'room_type', 'rate_plan', 'adults', 'children', 'total', 'paid', 'balance', 'currency', 'source', 'created']));
            $rows->chunk(500, function ($chunk) use ($out) {
                foreach ($chunk as $r) {
                    fputcsv($out, [
                        $r->booking_ref, __('reservations.status.'.$r->status), $r->guest_name, $r->guest_phone, $r->check_in->toDateString(), $r->check_out->toDateString(),
                        $r->nights, $r->room_count, $r->rooms->map(fn ($x) => $x->roomType?->name)->unique()->implode(', '),
                        $r->rooms->map(fn ($x) => $x->ratePlan?->name)->unique()->implode(', '), $r->adults, $r->children,
                        (string) $r->grand_total, (string) $r->paid_total, (string) $r->balance_due, $r->currency_code, $r->source?->name, $r->created_at?->toDateTimeString(),
                    ]);
                }
            });
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // ------------------------------------------------------------------------------------------

    private function done(Request $request, Reservation $r, string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'reservation' => $this->presenter->detail($r->fresh(), $this->context->property(), $request->user())]);
    }

    private function headerKey(Request $request): ?string
    {
        $key = (string) $request->header('Idempotency-Key', '');

        return preg_match('/^[A-Za-z0-9_.:-]{8,64}$/', $key) ? $key : null;
    }

    /** @return list<int>|null */
    private function roomIds(Reservation $r, ?array $publicIds): ?array
    {
        if ($publicIds === null || $publicIds === []) {
            return null;
        }

        return array_map(fn ($id) => $this->roomOr404($r, $id)->id, $publicIds);
    }
}

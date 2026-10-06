<?php

namespace App\Http\Controllers\Web\Property;

use App\Domain\Reservations\Queries\FrontDeskQuery;
use App\Domain\Reservations\Queries\ReservationOptions;
use App\Domain\Reservations\Queries\ReservationPresenter;
use App\Domain\Reservations\Queries\ReservationQuery;
use App\Domain\Reservations\ReservationService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Property\Concerns\ChecksPermissions;
use App\Models\Guest;
use App\Models\Reservation;
use App\Support\Page;
use App\Support\PropertyContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class ReservationsController extends Controller
{
    use ChecksPermissions;

    public function __construct(private readonly PropertyContext $context) {}

    public function index(Request $request, ReservationQuery $query, ReservationOptions $options): View
    {
        return Page::render('property/reservations/index', [
            'list' => $query->list($this->context->property(), $request),
            'filters' => $request->only(ReservationQuery::FILTERS + [99 => 'selected', 100 => 'sort', 101 => 'dir']),
            'options' => $options->listFilters(),
            'can' => $this->can(['create' => 'reservations.create', 'update' => 'reservations.update']),
        ], __('reservations.title'));
    }

    public function create(Request $request, ReservationOptions $options, ReservationService $service): View
    {
        $property = $this->context->property();
        // "New Reservation" from a guest profile starts with that guest.
        $guest = $request->query('guest') ? Guest::query()->where('public_id', (string) $request->query('guest'))->first() : null;

        return Page::render('property/reservations/form', [
            'reservation' => null,
            'guest' => $guest ? [
                'id' => $guest->public_id, 'title' => $guest->title, 'guest_type' => $guest->guest_type, 'first_name' => $guest->first_name,
                'last_name' => $guest->last_name, 'email' => $guest->email, 'phone' => $guest->phone_e164, 'nationality_iso2' => $guest->nationality_iso2,
                'id_type' => $guest->id_type, 'company_name' => $guest->company_name,
            ] : null,
            'options' => $options->form(),
            'defaults' => [
                'today' => $service->today($property)->toDateString(),
                'check_in_time' => substr((string) $property->check_in_time, 0, 5),
                'check_out_time' => substr((string) $property->check_out_time, 0, 5),
            ],
        ], __('reservations.create_title'));
    }

    public function show(Request $request, ReservationPresenter $presenter, mixed $property, string $reservation): View
    {
        $r = Reservation::query()->where('public_id', $reservation)->firstOrFail();

        return Page::render('property/reservations/show', [
            'reservation' => $presenter->detail($r, $this->context->property(), $request->user()),
            'tab' => (string) $request->query('tab', 'overview'),
        ], __('reservations.details_title').' '.$r->booking_ref);
    }

    public function edit(Request $request, ReservationPresenter $presenter, ReservationOptions $options, ReservationService $service, mixed $property, string $reservation): View
    {
        $r = Reservation::query()->where('public_id', $reservation)->firstOrFail();
        $p = $this->context->property();

        return Page::render('property/reservations/form', [
            'reservation' => $presenter->detail($r, $p, $request->user()),
            'guest' => null,
            'options' => $options->form(),
            'defaults' => [
                'today' => $service->today($p)->toDateString(),
                'check_in_time' => substr((string) $p->check_in_time, 0, 5),
                'check_out_time' => substr((string) $p->check_out_time, 0, 5),
            ],
        ], __('reservations.edit_title').' '.$r->booking_ref);
    }

    public function frontDesk(Request $request, FrontDeskQuery $query): View
    {
        return Page::render('property/front-desk/index', [
            'list' => $query->list($this->context->property(), $request),
            'filters' => $request->only(['tab', 'q']),
            'can' => $this->can(['check_in' => 'checkin.perform', 'check_out' => 'checkout.perform', 'assign' => 'reservations.update',
                'override_balance' => 'checkout.override_balance', 'create' => 'reservations.create']),
        ], __('reservations.front_desk.title'));
    }
}

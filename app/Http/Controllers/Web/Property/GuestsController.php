<?php

namespace App\Http\Controllers\Web\Property;

use App\Domain\Guests\Queries\GuestQuery;
use App\Domain\Reservations\Queries\ReservationOptions;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Property\Concerns\ChecksPermissions;
use App\Support\Page;
use App\Support\PropertyContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class GuestsController extends Controller
{
    use ChecksPermissions;

    public function index(Request $request, GuestQuery $query, ReservationOptions $options, PropertyContext $context): View
    {
        $form = $options->form();

        return Page::render('property/guests/index', [
            'list' => $query->list($context->property(), $request, $request->user()),
            'filters' => $request->only(['q', 'nationality', 'type', 'vip', 'from', 'to', 'tab', 'selected', 'sort', 'dir']),
            'options' => ['countries' => $form['countries'], 'guest_types' => $form['guest_types'], 'titles' => $form['titles'], 'id_types' => $form['id_types']],
            'can' => $this->can(['update' => 'guests.update', 'reserve' => 'reservations.create', 'reservations' => 'reservations.view', 'folio' => 'folio.view']),
        ], __('guests.title'));
    }
}

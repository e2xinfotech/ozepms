<?php

namespace App\Http\Controllers\Booking;

use App\Domain\BookingEngine\BookingEngineService;
use App\Domain\BookingEngine\BookingPresenter;
use App\Http\Controllers\Booking\Concerns\ResolvesBookingProperty;
use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Support\Page;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Public booking pages (no login): search & results, guest details, confirmation. */
class BookingPageController extends Controller
{
    use ResolvesBookingProperty;

    public function __construct(
        private readonly BookingEngineService $engine,
        private readonly BookingPresenter $presenter,
    ) {}

    public function search(Request $request, string $code): View
    {
        $property = $this->bookingProperty($code);
        $this->bookingLocale($request, $property);

        return Page::render('booking/search', [
            'property' => $this->engine->profile($property),
            'query' => $request->only(['check_in', 'check_out', 'adults', 'children', 'infants', 'rooms', 'promo_code']),
        ], $property->name, 'booking');
    }

    public function checkout(Request $request, string $code): View|RedirectResponse
    {
        $property = $this->bookingProperty($code);
        $this->bookingLocale($request, $property);
        $q = Validator::make($request->query(), [
            'check_in' => ['required', 'date_format:Y-m-d'], 'check_out' => ['required', 'date_format:Y-m-d'],
            'adults' => ['required', 'integer', 'min:1', 'max:20'], 'children' => ['sometimes', 'integer', 'min:0', 'max:10'],
            'infants' => ['sometimes', 'integer', 'min:0', 'max:6'], 'rooms' => ['sometimes', 'integer', 'min:1', 'max:'.(int) config('ozepms.booking_engine.max_rooms')],
            'promo_code' => ['nullable', 'string', 'max:30'], 'room_type' => ['required', 'string', 'size:26'], 'rate_plan' => ['required', 'string', 'size:26'],
        ])->valid();
        $back = fn () => redirect()->route('booking.search', ['code' => $property->code] + array_intersect_key($request->query(), array_flip(['check_in', 'check_out', 'adults', 'children', 'infants', 'rooms', 'promo_code'])))
            ->with('notice', __('booking.errors.rate_unavailable'));
        if (! isset($q['check_in'], $q['check_out'], $q['adults'], $q['room_type'], $q['rate_plan'])) {
            return $back();
        }
        try {
            $result = $this->engine->search($property, $q + ['children' => 0, 'infants' => 0]);
        } catch (ValidationException) {
            return $back();
        }
        $room = collect($result['room_types'])->firstWhere('id', $q['room_type']);
        $rate = $room ? collect($room['rates'])->firstWhere('rate_plan_id', $q['rate_plan']) : null;
        if ($rate === null) {
            return $back();
        }
        unset($room['rates']);

        return Page::render('booking/checkout', [
            'property' => $this->engine->profile($property),
            'stay' => array_diff_key($result, ['room_types' => 1]),
            'room' => $room, 'rate' => $rate,
            'countries' => DB::table('countries')->where('is_active', 1)->orderBy('name')->get(['iso2', 'name'])->map(fn ($c) => ['value' => $c->iso2, 'label' => $c->name])->all(),
        ], $property->name, 'booking');
    }

    public function confirmation(Request $request, string $code, string $reservation): View
    {
        $property = $this->bookingProperty($code);
        $this->bookingLocale($request, $property);
        $model = Reservation::acrossProperties()->where('property_id', $property->id)->where('public_id', $reservation)->firstOrFail();

        return Page::render('booking/confirmation', [
            'property' => $this->engine->profile($property),
            'booking' => $this->presenter->booking($model),
        ], $property->name, 'booking');
    }
}

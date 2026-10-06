<?php

namespace App\Http\Controllers\Web\Property;

use App\Domain\Accommodation\Queries\FormOptions;
use App\Domain\Offers\Queries\OfferPresenter;
use App\Domain\Offers\Queries\OfferQuery;
use App\Http\Controllers\Controller;
use App\Models\Offer;
use App\Support\Page;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OffersController extends Controller
{
    public function index(Request $request, OfferQuery $query, FormOptions $options): View
    {
        return Page::render('property/offers/index', [
            'list' => $query->list($request),
            'filters' => $request->only(['q', 'status', 'type', 'room_type', 'channel', 'tab', 'sort', 'dir', 'selected']),
            'options' => ['room_types' => $options->roomTypes()],
        ], __('offers.title'));
    }

    public function create(FormOptions $options): View
    {
        return Page::render('property/offers/form', ['offer' => null, 'options' => $this->options($options)], __('offers.add'));
    }

    public function edit(OfferPresenter $presenter, FormOptions $options, mixed $property, string $offer): View
    {
        $model = Offer::query()->where('public_id', $offer)->firstOrFail();

        return Page::render('property/offers/form', ['offer' => $presenter->form($model), 'options' => $this->options($options)], __('offers.edit'));
    }

    /** @return array<string, mixed> */
    private function options(FormOptions $options): array
    {
        return [
            'room_types' => $options->roomTypes(),
            'rate_plans' => $options->ratePlans(),
            'sources' => DB::table('booking_sources')->whereNull('property_id')->where('is_active', 1)->orderBy('id')->pluck('code')
                ->map(fn ($c) => ['value' => $c, 'label' => __('reservations.sources.'.$c)])->values()->all(),
            'countries' => DB::table('countries')->orderBy('name')->get(['iso2', 'name'])->map(fn ($c) => ['value' => $c->iso2, 'label' => $c->name])->all(),
        ];
    }
}

<?php

namespace App\Http\Controllers\WebApi\Property;

use App\Domain\Offers\OfferAdminService;
use App\Domain\Offers\Queries\OfferPresenter;
use App\Domain\Offers\Queries\OfferQuery;
use App\Http\Controllers\Controller;
use App\Http\Controllers\WebApi\Property\Concerns\FindsAccommodation;
use App\Http\Requests\Property\Accommodation\StatusRequest;
use App\Http\Requests\Property\Offers\OfferImageRequest;
use App\Http\Requests\Property\Offers\SaveOfferRequest;
use App\Models\Offer;
use App\Support\PropertyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Offers & promotions JSON endpoints (permission offers.manage). */
class OffersController extends Controller
{
    use FindsAccommodation;

    public function __construct(
        private readonly OfferAdminService $offers,
        private readonly OfferPresenter $presenter,
    ) {}

    public function show(mixed $property, string $offer): JsonResponse
    {
        return response()->json(['offer' => $this->presenter->detail($this->offerOr404($offer))]);
    }

    public function store(SaveOfferRequest $request): JsonResponse
    {
        $offer = $this->offers->create($this->payload($request));

        return response()->json(['message' => __('offers.messages.created'), 'offer' => $this->presenter->form($offer->refresh())], 201);
    }

    public function update(SaveOfferRequest $request, mixed $property, string $offer): JsonResponse
    {
        $model = $this->offers->update($this->offerOr404($offer), $this->payload($request));

        return response()->json(['message' => __('offers.messages.updated'), 'offer' => $this->presenter->form($model->refresh())]);
    }

    public function status(StatusRequest $request, mixed $property, string $offer): JsonResponse
    {
        $model = $this->offers->setActive($this->offerOr404($offer), (bool) $request->validated('is_active'));

        return response()->json(['message' => __($model->is_active ? 'offers.messages.activated' : 'offers.messages.deactivated'), 'is_active' => $model->is_active]);
    }

    public function copy(mixed $property, string $offer): JsonResponse
    {
        $copy = $this->offers->copy($this->offerOr404($offer));

        return response()->json(['message' => __('offers.messages.copied', ['code' => $copy->code]), 'offer' => ['id' => $copy->public_id]], 201);
    }

    public function destroy(mixed $property, string $offer): JsonResponse
    {
        $result = $this->offers->delete($this->offerOr404($offer));

        return response()->json(['message' => __('offers.messages.'.$result), 'result' => $result]);
    }

    public function image(OfferImageRequest $request, mixed $property, string $offer): JsonResponse
    {
        $model = $this->offers->storeImage($this->offerOr404($offer), $request->file('image'));

        return response()->json(['message' => __('offers.messages.image_saved'), 'image_url' => $this->presenter->form($model)['image_url']]);
    }

    public function removeImage(mixed $property, string $offer): JsonResponse
    {
        $this->offers->removeImage($this->offerOr404($offer));

        return response()->json(['message' => __('offers.messages.image_removed'), 'image_url' => null]);
    }

    public function export(Request $request, OfferQuery $query, PropertyContext $context): StreamedResponse
    {
        $property = $context->property();
        $name = 'offers-'.$property->code.'-'.now($property->timezone)->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($query, $request) {
            $out = fopen('php://output', 'w');
            $first = true;
            foreach ($query->export($request) as $row) {
                if ($first) {
                    \App\Support\Csv::put($out, array_keys($row));
                    $first = false;
                }
                \App\Support\Csv::put($out, array_values($row));
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function offerOr404(string $id): Offer
    {
        return Offer::query()->where('public_id', $id)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function payload(SaveOfferRequest $request): array
    {
        $data = $request->validated();
        foreach (['discount_value', 'min_amount'] as $k) {
            if (isset($data[$k])) {
                $data[$k] = (string) $data[$k];
            }
        }
        if (array_key_exists('room_types', $data)) {
            $data['room_types'] = array_map(fn ($id, $i) => $this->roomTypeField($id, "room_types.$i"), $data['room_types'], array_keys($data['room_types']));
        }
        if (array_key_exists('rate_plans', $data)) {
            $data['rate_plans'] = array_map(fn ($id, $i) => $this->ratePlanField($id, "rate_plans.$i"), $data['rate_plans'], array_keys($data['rate_plans']));
        }
        if (! empty($data['sources'])) {
            $known = DB::table('booking_sources')->where(fn ($q) => $q->whereNull('property_id')->orWhere('property_id', app(PropertyContext::class)->id()))->pluck('code')->all();
            foreach ($data['sources'] as $i => $code) {
                if (! in_array($code, $known, true)) {
                    throw ValidationException::withMessages(["sources.$i" => __('validation.exists', ['attribute' => __('offers.fields.sources')])]);
                }
            }
        }
        if (! empty($data['countries'])) {
            $known = DB::table('countries')->whereIn('iso2', array_map('strtoupper', $data['countries']))->pluck('iso2')->all();
            foreach ($data['countries'] as $i => $iso) {
                if (! in_array(strtoupper($iso), $known, true)) {
                    throw ValidationException::withMessages(["countries.$i" => __('validation.exists', ['attribute' => __('offers.fields.countries')])]);
                }
            }
        }

        return $data;
    }
}

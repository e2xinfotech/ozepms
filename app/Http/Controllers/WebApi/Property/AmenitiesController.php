<?php

namespace App\Http\Controllers\WebApi\Property;

use App\Domain\Accommodation\AmenityService;
use App\Domain\Accommodation\Queries\AmenityQuery;
use App\Http\Controllers\Controller;
use App\Http\Controllers\WebApi\Property\Concerns\FindsAccommodation;
use App\Http\Requests\Property\Accommodation\SaveAmenityRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Custom amenities of the property. Global amenities are read only. */
class AmenitiesController extends Controller
{
    use FindsAccommodation;

    public function __construct(
        private readonly AmenityService $amenities,
        private readonly AmenityQuery $query,
    ) {}

    public function store(SaveAmenityRequest $request): JsonResponse
    {
        $amenity = $this->amenities->create($request->validated());

        return response()->json(['message' => __('amenities.messages.created'), 'amenity' => $this->query->row($amenity)], 201);
    }

    public function update(SaveAmenityRequest $request, mixed $property, string $amenity): JsonResponse
    {
        $model = $this->amenities->update($this->amenityOr404($amenity), $request->validated());

        return response()->json(['message' => __('amenities.messages.updated'), 'amenity' => $this->query->row($model)]);
    }

    public function facility(Request $request, mixed $property, string $amenity): JsonResponse
    {
        $offered = (bool) $request->validate(['offered' => ['required', 'boolean']])['offered'];
        $model = $this->amenityOr404($amenity);
        $this->amenities->setPropertyFacility($model, $offered);

        return response()->json(['message' => __('amenities.messages.facility_saved'), 'amenity' => $this->query->row($model)]);
    }
}

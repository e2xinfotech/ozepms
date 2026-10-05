<?php

namespace App\Http\Controllers\WebApi\Property;

use App\Domain\Accommodation\RoomTypeImageService;
use App\Domain\Accommodation\RoomTypeService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\WebApi\Property\Concerns\FindsAccommodation;
use App\Http\Requests\Property\Accommodation\DefaultRatePlanRequest;
use App\Http\Requests\Property\Accommodation\SaveRoomTypeRequest;
use App\Http\Requests\Property\Accommodation\StatusRequest;
use App\Http\Requests\Property\Accommodation\UploadRoomTypeImageRequest;
use App\Http\Resources\Accommodation\RoomTypeResource;
use App\Models\RoomType;
use App\Models\RoomTypeImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoomTypesController extends Controller
{
    use FindsAccommodation;

    public function __construct(private readonly RoomTypeService $roomTypes) {}

    public function show(Request $request, mixed $property, string $roomType): JsonResponse
    {
        return response()->json(['room_type' => (new RoomTypeResource($this->roomTypeOr404($roomType)))->resolve($request)]);
    }

    public function store(SaveRoomTypeRequest $request): JsonResponse
    {
        $roomType = $this->roomTypes->create($this->payload($request));

        return response()->json([
            'message' => __('rooms.messages.room_type_created'),
            'room_type' => (new RoomTypeResource($roomType->refresh()))->resolve($request),
        ], 201);
    }

    public function update(SaveRoomTypeRequest $request, mixed $property, string $roomType): JsonResponse
    {
        $model = $this->roomTypeOr404($roomType);
        $this->roomTypes->update($model, $this->payload($request));

        return response()->json([
            'message' => __('rooms.messages.room_type_updated'),
            'room_type' => (new RoomTypeResource($model->refresh()))->resolve($request),
        ]);
    }

    public function status(StatusRequest $request, mixed $property, string $roomType): JsonResponse
    {
        $model = $this->roomTypes->setActive($this->roomTypeOr404($roomType), (bool) $request->validated('is_active'));

        return response()->json([
            'message' => $model->is_active ? __('rooms.messages.room_type_activated') : __('rooms.messages.room_type_deactivated'),
            'is_active' => $model->is_active,
        ]);
    }

    public function defaultRatePlan(DefaultRatePlanRequest $request, mixed $property, string $roomType): JsonResponse
    {
        $model = $this->roomTypeOr404($roomType);
        $this->roomTypes->setDefaultProduct($model, $this->productField($request->validated('product_id'), 'product_id'));

        return response()->json(['message' => __('rooms.messages.default_rate_plan_changed')]);
    }

    public function storeImage(UploadRoomTypeImageRequest $request, RoomTypeImageService $images, mixed $property, string $roomType): JsonResponse
    {
        $image = $images->store($this->roomTypeOr404($roomType), $request->file('image'), $request->validated('alt'));

        return response()->json([
            'message' => __('rooms.messages.image_added'),
            'image' => ['id' => $image->id, 'url' => $image->url(), 'alt' => $image->alt_text],
        ], 201);
    }

    public function destroyImage(RoomTypeImageService $images, mixed $property, string $roomType, int $image): JsonResponse
    {
        $model = $this->roomTypeOr404($roomType);
        $images->delete(RoomTypeImage::query()->where('room_type_id', $model->id)->whereKey($image)->firstOrFail());

        return response()->json(['message' => __('rooms.messages.image_removed')]);
    }

    /**
     * Turns public ids of the request into models for the service.
     *
     * @return array<string, mixed>
     */
    private function payload(SaveRoomTypeRequest $request): array
    {
        $data = $request->validated();
        foreach (['size_value'] as $key) {
            if (isset($data[$key])) {
                $data[$key] = (string) $data[$key];
            }
        }

        if (isset($data['products'])) {
            $data['products'] = array_map(function (array $entry, int $i) {
                $entry['rate_plan'] = $this->ratePlanField($entry['rate_plan_id'], "products.$i.rate_plan_id");
                $entry['parent_rate_plan'] = ! empty($entry['parent_rate_plan_id'])
                    ? $this->ratePlanField($entry['parent_rate_plan_id'], "products.$i.parent_rate_plan_id")
                    : null;
                foreach (['default_price', 'adjust_value'] as $key) {
                    if (isset($entry[$key])) {
                        $entry[$key] = (string) $entry[$key];
                    }
                }

                return $entry;
            }, $data['products'], array_keys($data['products']));
        }

        return $data;
    }
}

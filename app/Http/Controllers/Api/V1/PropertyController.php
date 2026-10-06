<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\BookingEngine\BookingEngineService;
use App\Http\Controllers\Controller;
use App\Models\RoomType;
use App\Models\RoomTypeImage;
use App\Support\PropertyContext;
use Illuminate\Http\JsonResponse;

/** GET /api/v1/property — the key's property and its room types (public content). */
class PropertyController extends Controller
{
    public function __invoke(PropertyContext $context, BookingEngineService $engine): JsonResponse
    {
        $property = $context->property();
        $types = RoomType::query()->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get();
        $images = RoomTypeImage::query()->whereIn('room_type_id', $types->pluck('id'))->orderBy('sort_order')->get()->groupBy('room_type_id');

        return response()->json(['data' => array_diff_key($engine->profile($property), ['enabled' => 1, 'online_payments' => 1]) + [
            'booking_url' => route('booking.search', ['code' => $property->code]),
            'room_types' => $types->map(fn (RoomType $t) => [
                'id' => $t->public_id, 'code' => $t->code, 'name' => $t->name, 'description' => $t->description,
                'max_adults' => (int) $t->max_adults, 'max_children' => (int) $t->max_children, 'max_occupancy' => (int) $t->max_occupancy,
                'size' => $t->size_value ? rtrim(rtrim((string) $t->size_value, '0'), '.').' '.$t->size_unit : null, 'view' => $t->view_label,
                'images' => ($images[$t->id] ?? collect())->map(fn (RoomTypeImage $i) => $i->url())->values()->all(),
            ])->values()->all(),
        ]]);
    }
}

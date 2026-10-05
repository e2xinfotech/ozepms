<?php

namespace App\Http\Controllers\WebApi\Property;

use App\Domain\Accommodation\PhysicalUnitService;
use App\Domain\Accommodation\Queries\PhysicalUnitQuery;
use App\Domain\Accommodation\UnitBlockService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\WebApi\Property\Concerns\FindsAccommodation;
use App\Http\Requests\Property\Accommodation\BlockRoomRequest;
use App\Http\Requests\Property\Accommodation\BulkStoreRoomsRequest;
use App\Http\Requests\Property\Accommodation\HousekeepingRequest;
use App\Http\Requests\Property\Accommodation\StatusRequest;
use App\Http\Requests\Property\Accommodation\StoreRoomRequest;
use App\Http\Requests\Property\Accommodation\UpdateRoomRequest;
use App\Http\Resources\Accommodation\PhysicalUnitResource;
use App\Models\UnitBlock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** PMS rooms: panel data, create (one / bulk), edit, activate, housekeeping and blocks. */
class RoomsController extends Controller
{
    use FindsAccommodation;

    public function __construct(
        private readonly PhysicalUnitService $units,
        private readonly PhysicalUnitQuery $query,
    ) {}

    public function show(Request $request, mixed $property, string $room): JsonResponse
    {
        return response()->json(['room' => $this->resource($request, $room)]);
    }

    public function store(StoreRoomRequest $request): JsonResponse
    {
        $roomType = $this->roomTypeField($request->validated('room_type_id'), 'room_type_id');
        $created = $this->units->addUnits($roomType, [$request->safe()->only(['name', 'floor', 'building'])], 'name');

        return response()->json([
            'message' => __('rooms.messages.room_created'),
            'room' => $this->resource($request, $created->first()->public_id),
        ], 201);
    }

    public function bulkStore(BulkStoreRoomsRequest $request): JsonResponse
    {
        $roomType = $this->roomTypeField($request->validated('room_type_id'), 'room_type_id');
        $created = $this->units->bulkCreate($roomType, $request->safe()->except('room_type_id'));

        return response()->json([
            'message' => trans_choice('rooms.messages.rooms_created', $created->count(), ['count' => $created->count()]),
            'rooms' => $created->pluck('name')->all(),
        ], 201);
    }

    public function update(UpdateRoomRequest $request, mixed $property, string $room): JsonResponse
    {
        $unit = $this->unitOr404($room);
        $data = $request->safe()->except('room_type_id');
        if ($request->has('room_type_id')) {
            $data['room_type'] = $this->roomTypeField($request->validated('room_type_id'), 'room_type_id');
        }
        $this->units->update($unit, $data);

        return response()->json(['message' => __('rooms.messages.room_updated'), 'room' => $this->resource($request, $room)]);
    }

    public function status(StatusRequest $request, mixed $property, string $room): JsonResponse
    {
        $unit = $this->units->setActive($this->unitOr404($room), (bool) $request->validated('is_active'));

        return response()->json([
            'message' => $unit->is_active ? __('rooms.messages.room_activated') : __('rooms.messages.room_deactivated'),
            'room' => $this->resource($request, $room),
        ]);
    }

    public function housekeeping(HousekeepingRequest $request, mixed $property, string $room): JsonResponse
    {
        $this->units->setHousekeeping($this->unitOr404($room), (string) $request->validated('housekeeping_status'));

        return response()->json(['message' => __('rooms.messages.housekeeping_updated'), 'room' => $this->resource($request, $room)]);
    }

    public function block(BlockRoomRequest $request, UnitBlockService $blocks, mixed $property, string $room): JsonResponse
    {
        $data = $request->validated();
        $blocks->block($this->unitOr404($room), $data['block_type'], $data['start_date'], $data['end_date'], $data['reason'] ?? null);

        return response()->json(['message' => __('rooms.messages.room_blocked'), 'room' => $this->resource($request, $room)], 201);
    }

    public function release(Request $request, UnitBlockService $blocks, mixed $property, string $room, int $block): JsonResponse
    {
        $unit = $this->unitOr404($room);
        $blocks->release(UnitBlock::query()->where('unit_id', $unit->id)->whereKey($block)->firstOrFail());

        return response()->json(['message' => __('rooms.messages.block_released'), 'room' => $this->resource($request, $room)]);
    }

    /** @return array<string, mixed> */
    private function resource(Request $request, string $publicId): array
    {
        return (new PhysicalUnitResource($this->query->find($publicId)))->resolve($request);
    }
}

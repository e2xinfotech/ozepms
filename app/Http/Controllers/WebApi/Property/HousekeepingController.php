<?php

namespace App\Http\Controllers\WebApi\Property;

use App\Domain\Housekeeping\HousekeepingPresenter;
use App\Domain\Housekeeping\HousekeepingService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Property\Housekeeping\AssignRequest;
use App\Http\Requests\Property\Housekeeping\StaffRequest;
use App\Models\HousekeepingStaff;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/** Housekeeping staff and room assignments. */
class HousekeepingController extends Controller
{
    public function __construct(private readonly HousekeepingService $service, private readonly HousekeepingPresenter $presenter) {}

    public function storeStaff(StaffRequest $request): JsonResponse
    {
        $staff = $this->service->createStaff($request->validated(), $request->user());

        return response()->json(['message' => __('housekeeping.messages.staff_created'), 'staff' => $this->presenter->staff($staff)], 201);
    }

    public function updateStaff(StaffRequest $request, mixed $property, string $staff): JsonResponse
    {
        $staff = $this->service->updateStaff($this->find($staff), $request->validated(), $request->user());

        return response()->json(['message' => __('housekeeping.messages.staff_updated'), 'staff' => $this->presenter->staff($staff)]);
    }

    public function destroyStaff(mixed $property, string $staff): JsonResponse
    {
        $this->service->deleteStaff($this->find($staff), request()->user());

        return response()->json(['message' => __('housekeeping.messages.staff_deleted')]);
    }

    public function assign(AssignRequest $request): JsonResponse
    {
        $id = $request->validated('staff_id');
        $staff = null;
        if ($id !== null && $id !== '') {
            $staff = HousekeepingStaff::query()->where('public_id', $id)->first()
                ?? throw ValidationException::withMessages(['staff_id' => __('housekeeping.errors.unknown_staff')]);
        }
        $n = $this->service->assign($request->validated('rooms'), $staff, $request->user());

        return response()->json(['message' => trans_choice($staff ? 'housekeeping.messages.assigned' : 'housekeeping.messages.unassigned', $n, ['count' => $n, 'name' => $staff?->name])]);
    }

    private function find(string $id): HousekeepingStaff
    {
        return HousekeepingStaff::query()->where('public_id', $id)->firstOrFail();
    }
}

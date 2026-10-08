<?php

namespace App\Domain\Housekeeping;

use App\Models\HousekeepingStaff;
use App\Models\HousekeepingTask;
use App\Models\PhysicalUnit;
use Illuminate\Support\Facades\DB;

/** Data of the Housekeeping page: staff, rooms with their responsible person, and the cleaning tasks. */
class HousekeepingPresenter
{
    public function page(): array
    {
        $staff = HousekeepingStaff::query()->orderBy('name')->get();
        $roomCounts = PhysicalUnit::query()->whereNotNull('housekeeping_staff_id')->where('is_active', true)
            ->select('housekeeping_staff_id', DB::raw('count(*) as n'))->groupBy('housekeeping_staff_id')->pluck('n', 'housekeeping_staff_id');
        $byId = $staff->keyBy('id');

        $rooms = PhysicalUnit::query()->with('roomType:id,name,code')->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get()
            ->map(fn (PhysicalUnit $u) => [
                'id' => $u->public_id, 'name' => $u->name, 'floor' => $u->floor,
                'room_type' => $u->roomType?->name, 'housekeeping_status' => $u->housekeeping_status,
                'staff_id' => $byId->get($u->housekeeping_staff_id)?->public_id,
            ])->values();

        $tasks = HousekeepingTask::query()->with(['staff:id,public_id,name', 'unit:id,public_id,housekeeping_staff_id'])
            ->orderByRaw("status = 'pending' desc")->orderByDesc('id')->limit(200)->get()
            ->map(function (HousekeepingTask $t) use ($byId) {
                $responsible = $t->staff ?? $byId->get($t->unit?->housekeeping_staff_id);

                return [
                    'id' => $t->public_id, 'room_id' => $t->unit?->public_id, 'room' => $t->room_name, 'status' => $t->status, 'note' => $t->note,
                    'staff' => $responsible?->name, 'created_at' => $t->created_at?->toIso8601String(),
                    'notified_at' => $t->notified_at?->toIso8601String(), 'notified_to' => $t->notified_to,
                    'completed_at' => $t->completed_at?->toIso8601String(),
                ];
            })->values();

        return [
            'staff' => $staff->map(fn (HousekeepingStaff $s) => $this->staff($s, (int) ($roomCounts[$s->id] ?? 0)))->values(),
            'rooms' => $rooms,
            'tasks' => $tasks,
            'counts' => [
                'pending' => $tasks->where('status', 'pending')->count(),
                'unassigned' => $tasks->where('status', 'pending')->whereNull('staff')->count(),
                'unassigned_rooms' => $rooms->whereNull('staff_id')->count(),
                'staff' => $staff->where('is_active', true)->count(),
            ],
        ];
    }

    public function staff(HousekeepingStaff $s, ?int $rooms = null): array
    {
        return [
            'id' => $s->public_id, 'name' => $s->name, 'email' => $s->email, 'phone' => $s->phone, 'notes' => $s->notes,
            'is_active' => (bool) $s->is_active,
            'rooms' => $rooms ?? PhysicalUnit::query()->where('housekeeping_staff_id', $s->id)->where('is_active', true)->count(),
        ];
    }
}

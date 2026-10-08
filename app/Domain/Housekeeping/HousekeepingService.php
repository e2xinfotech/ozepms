<?php

namespace App\Domain\Housekeeping;

use App\Domain\Accommodation\InProperty;
use App\Domain\Audit\AuditLogger;
use App\Infrastructure\Database\Tx;
use App\Models\HousekeepingStaff;
use App\Models\HousekeepingTask;
use App\Models\PhysicalUnit;
use App\Models\Property;
use App\Models\User;
use App\Notifications\CleaningRequestNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Housekeeping: the cleaning staff of a property, who is responsible for which room, and the
 * cleaning tasks. A task is opened when a room is checked out (or marked dirty) and e-mailed to
 * the responsible person by the scheduler; it is closed when the room is marked clean.
 */
class HousekeepingService
{
    public function __construct(private readonly AuditLogger $audit) {}

    // --- Staff ---------------------------------------------------------------------------

    public function createStaff(array $data, ?User $by = null): HousekeepingStaff
    {
        $this->assertEmailFree((string) $data['email'], null);
        $staff = HousekeepingStaff::query()->create($this->staffFields($data) + ['is_active' => (bool) ($data['is_active'] ?? true)]);
        $this->audit->log('housekeeping_staff.created', $staff, ['after' => ['name' => $staff->name, 'email' => $staff->email]], $staff->property_id, $by?->id);

        return $staff;
    }

    public function updateStaff(HousekeepingStaff $staff, array $data, ?User $by = null): HousekeepingStaff
    {
        $this->assertEmailFree((string) $data['email'], $staff->id);
        $staff->fill($this->staffFields($data));
        if (array_key_exists('is_active', $data)) {
            $staff->is_active = (bool) $data['is_active'];
        }
        $diff = $this->audit->diff($staff);
        $staff->save();
        $this->audit->log('housekeeping_staff.updated', $staff, $diff, $staff->property_id, $by?->id);

        return $staff;
    }

    /** Removing a person frees their rooms; their open tasks wait for the next person assigned. */
    public function deleteStaff(HousekeepingStaff $staff, ?User $by = null): void
    {
        Tx::run(function () use ($staff, $by) {
            PhysicalUnit::query()->where('housekeeping_staff_id', $staff->id)->update(['housekeeping_staff_id' => null]);
            HousekeepingTask::query()->where('staff_id', $staff->id)->where('status', 'pending')->whereNull('notified_at')->update(['staff_id' => null]);
            $this->audit->log('housekeeping_staff.deleted', $staff, ['before' => ['name' => $staff->name]], $staff->property_id, $by?->id);
            $staff->delete();
        });
    }

    // --- Assignment ----------------------------------------------------------------------

    /**
     * Makes $staff responsible for the rooms (null clears the assignment).
     *
     * @param  list<string>  $roomIds  PMS room public ids of this property
     * @return int rooms changed
     */
    public function assign(array $roomIds, ?HousekeepingStaff $staff, ?User $by = null): int
    {
        if ($staff !== null && ! $staff->is_active) {
            throw ValidationException::withMessages(['staff_id' => __('housekeeping.errors.staff_inactive')]);
        }
        $units = PhysicalUnit::query()->whereIn('public_id', $roomIds)->get();
        if ($units->count() !== count(array_unique($roomIds))) {
            throw ValidationException::withMessages(['rooms' => __('housekeeping.errors.unknown_room')]);
        }

        return Tx::run(function () use ($units, $staff, $by) {
            PhysicalUnit::query()->whereIn('id', $units->pluck('id'))->update(['housekeeping_staff_id' => $staff?->id, 'updated_at' => now()]);
            // A cleaning that has not been sent yet goes to the new person.
            HousekeepingTask::query()->whereIn('unit_id', $units->pluck('id'))->where('status', 'pending')->whereNull('notified_at')->update(['staff_id' => $staff?->id]);
            $this->audit->log('housekeeping.assigned', $staff ?? $units->first(), ['after' => ['staff' => $staff?->name, 'rooms' => $units->pluck('name')->all()]], $units->first()->property_id, $by?->id);

            return $units->count();
        });
    }

    // --- Tasks ---------------------------------------------------------------------------

    /** Opens a cleaning task for the room unless one is already waiting. */
    public function openTask(PhysicalUnit $unit, ?int $reservationRoomId = null, ?string $note = null): HousekeepingTask
    {
        $existing = HousekeepingTask::query()->where('unit_id', $unit->id)->where('status', 'pending')->first();
        if ($existing !== null) {
            return $existing;
        }

        return HousekeepingTask::query()->create([
            'property_id' => $unit->property_id, 'unit_id' => $unit->id, 'staff_id' => $unit->housekeeping_staff_id,
            'reservation_room_id' => $reservationRoomId, 'room_name' => $unit->name, 'status' => 'pending', 'note' => $note,
        ]);
    }

    /** The room is clean again: its waiting tasks are done. */
    public function completeFor(PhysicalUnit $unit, ?User $by = null): void
    {
        HousekeepingTask::query()->where('unit_id', $unit->id)->where('status', 'pending')
            ->update(['status' => 'done', 'completed_at' => now(), 'completed_by' => $by?->id, 'updated_at' => now()]);
    }

    /** Tasks of rooms that were checked out in the given reservation rooms. */
    public function openForCheckout(Property $property, array $reservationRoomIds): int
    {
        return InProperty::run($property, function () use ($reservationRoomIds) {
            $opened = 0;
            foreach ($reservationRoomIds as $roomId) {
                $unitId = DB::table('unit_nights')->where('reservation_room_id', $roomId)->orderByDesc('stay_date')->value('unit_id');
                $unit = $unitId ? PhysicalUnit::query()->find($unitId) : null;
                if ($unit !== null && $unit->is_active) {
                    $this->openTask($unit, (int) $roomId);
                    $opened++;
                }
            }

            return $opened;
        });
    }

    // --- Scheduler -----------------------------------------------------------------------

    /**
     * E-mails every waiting cleaning that was not sent yet: one e-mail per person and property
     * listing all of their rooms. Rooms without a responsible person stay waiting (shown on the page).
     *
     * @return array{sent:int, rooms:int, failed:int}
     */
    public function notifyDue(): array
    {
        $result = ['sent' => 0, 'rooms' => 0, 'failed' => 0];
        $tasks = HousekeepingTask::acrossProperties()->where('status', 'pending')->whereNull('notified_at')->orderBy('id')->limit(2000)->get();
        $units = PhysicalUnit::acrossProperties()->withTrashed()->whereIn('id', $tasks->pluck('unit_id'))->get()->keyBy('id');

        $groups = [];
        foreach ($tasks as $task) {
            $staffId = $task->staff_id ?? $units->get($task->unit_id)?->housekeeping_staff_id;
            if ($staffId !== null) {
                $groups[$staffId][] = $task;
            }
        }
        $staff = HousekeepingStaff::acrossProperties()->whereIn('id', array_keys($groups))->get()->keyBy('id');

        foreach ($groups as $staffId => $list) {
            $person = $staff->get($staffId);
            if ($person === null || ! $person->is_active) {
                continue;
            }
            $property = Property::query()->find($person->property_id);
            if ($property === null) {
                continue;
            }
            $rooms = array_map(fn (HousekeepingTask $t) => ['room' => $t->room_name, 'since' => $t->created_at, 'note' => $t->note], $list);
            try {
                Notification::route('mail', [$person->email => $person->name])
                    ->notify((new CleaningRequestNotification($property, $person->name, $rooms))->locale($property->default_language));
                HousekeepingTask::acrossProperties()->whereIn('id', array_map(fn ($t) => $t->id, $list))
                    ->update(['staff_id' => $person->id, 'notified_at' => now(), 'notified_to' => $person->email, 'updated_at' => now()]);
                $result['sent']++;
                $result['rooms'] += count($list);
            } catch (Throwable $e) {
                // Stays waiting and is tried again on the next run.
                report($e);
                $result['failed']++;
            }
        }

        return $result;
    }

    private function staffFields(array $data): array
    {
        return [
            'name' => trim((string) $data['name']),
            'email' => strtolower(trim((string) $data['email'])),
            'phone' => trim((string) ($data['phone'] ?? '')) ?: null,
            'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
        ];
    }

    private function assertEmailFree(string $email, ?int $ignoreId): void
    {
        $taken = HousekeepingStaff::query()->where('email', strtolower(trim($email)))
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists();
        if ($taken) {
            throw ValidationException::withMessages(['email' => __('housekeeping.errors.email_taken')]);
        }
    }
}

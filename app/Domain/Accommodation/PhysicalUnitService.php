<?php

namespace App\Domain\Accommodation;

use App\Domain\Accommodation\Events\RoomTypeUnitsChanged;
use App\Domain\Audit\AuditLogger;
use App\Infrastructure\Database\Tx;
use App\Models\PhysicalUnit;
use App\Models\RoomType;
use App\Support\PropertyContext;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/** PMS rooms: bulk creation, edits, activation and housekeeping status. */
class PhysicalUnitService
{
    public function __construct(
        private readonly PropertyContext $context,
        private readonly AuditLogger $audit,
        private readonly PlanLimits $limits,
        private readonly UnitNameGenerator $names,
        private readonly UnitNightGuard $guard,
    ) {}

    /**
     * Adds rooms from a bulk spec (range / sequence / quantity), see UnitNameGenerator.
     *
     * @param  array<string, mixed>  $spec
     * @return Collection<int, PhysicalUnit>
     */
    public function bulkCreate(RoomType $roomType, array $spec): Collection
    {
        $existing = PhysicalUnit::query()->withTrashed()->where('room_type_id', $roomType->id)->pluck('name')->all();
        $names = $this->names->generate($spec, $roomType->code, $existing);
        $floor = isset($spec['floor']) && $spec['floor'] !== '' ? (string) $spec['floor'] : null;

        return $this->addUnits($roomType, array_map(fn (string $name) => ['name' => $name, 'floor' => $floor], $names));
    }

    /**
     * @param  list<array{name: string, floor?: ?string, building?: ?string}>  $rows
     * @return Collection<int, PhysicalUnit>
     */
    public function addUnits(RoomType $roomType, array $rows, string $field = 'units'): Collection
    {
        if ($rows === []) {
            return collect();
        }

        return Tx::run(function () use ($roomType, $rows, $field) {
            $property = $this->context->property();
            // Lock before checking names and the plan limit, so parallel requests cannot both pass.
            $this->limits->lockInventory($property);
            $this->assertUniqueNames(array_column($rows, 'name'), $field);
            $this->limits->assertCanAddUnits($property, count($rows), $field);

            $sort = (int) PhysicalUnit::query()->where('room_type_id', $roomType->id)->max('sort_order');
            $created = collect();
            foreach ($rows as $row) {
                $created->push(PhysicalUnit::query()->create([
                    'room_type_id' => $roomType->id,
                    'name' => trim($row['name']),
                    'floor' => $row['floor'] ?? null,
                    'building' => $row['building'] ?? null,
                    'housekeeping_status' => 'clean',
                    'is_active' => true,
                    'sort_order' => ++$sort,
                ]));
            }

            $this->audit->log('physical_unit.bulk_created', $roomType, ['after' => ['rooms' => $created->pluck('name')->all()]]);
            RoomTypeUnitsChanged::dispatch($property->id, $roomType->id);

            return $created;
        });
    }

    /** @param  array<string, mixed>  $data  name, floor, building, notes, room_type (RoomType), is_active */
    public function update(PhysicalUnit $unit, array $data): PhysicalUnit
    {
        return Tx::run(function () use ($unit, $data) {
            $property = $this->context->property();
            $oldRoomTypeId = (int) $unit->room_type_id;

            if (isset($data['name']) && trim((string) $data['name']) !== $unit->name) {
                $this->limits->lockInventory($property);
                $this->assertUniqueNames([(string) $data['name']], 'name', $unit->id);
            }

            $fill = array_intersect_key($data, array_flip(['name', 'floor', 'building', 'notes']));
            if (isset($fill['name'])) {
                $fill['name'] = trim((string) $fill['name']);
            }
            $unit->fill($fill);

            if (isset($data['room_type']) && $data['room_type'] instanceof RoomType && $data['room_type']->id !== $oldRoomTypeId) {
                $this->guard->assertNoFutureNights($unit, $property, 'room_type_id');
                $unit->room_type_id = $data['room_type']->id;
            }

            if (array_key_exists('is_active', $data) && (bool) $data['is_active'] !== $unit->is_active) {
                $this->applyActive($unit, (bool) $data['is_active']);
            }

            if (! $unit->isDirty()) {
                return $unit;
            }

            $diff = $this->audit->diff($unit);
            $unitsChanged = $unit->isDirty('room_type_id') || $unit->isDirty('is_active');
            $unit->save();
            $this->audit->log('physical_unit.updated', $unit, $diff);

            if ($unitsChanged) {
                RoomTypeUnitsChanged::dispatch($property->id, $oldRoomTypeId);
                if ((int) $unit->room_type_id !== $oldRoomTypeId) {
                    RoomTypeUnitsChanged::dispatch($property->id, (int) $unit->room_type_id);
                }
            }

            return $unit;
        });
    }

    public function setActive(PhysicalUnit $unit, bool $active): PhysicalUnit
    {
        return $this->update($unit, ['is_active' => $active]);
    }

    public function setHousekeeping(PhysicalUnit $unit, string $status): PhysicalUnit
    {
        if (! in_array($status, PhysicalUnit::HOUSEKEEPING, true)) {
            throw ValidationException::withMessages(['housekeeping_status' => __('rooms.errors.housekeeping_status')]);
        }
        if ($unit->housekeeping_status === $status) {
            return $unit;
        }

        $unit->housekeeping_status = $status;
        if ($status === 'clean' || $status === 'inspected') {
            $unit->last_cleaned_at = now();
        }
        $diff = $this->audit->diff($unit);
        $unit->save();
        $this->audit->log('physical_unit.housekeeping_changed', $unit, $diff);
        $housekeeping = app(\App\Domain\Housekeeping\HousekeepingService::class);
        $status === 'dirty' ? $housekeeping->openTask($unit) : $housekeeping->completeFor($unit);

        return $unit;
    }

    /** Activation counts against the plan; deactivation is refused while guests are assigned. */
    private function applyActive(PhysicalUnit $unit, bool $active): void
    {
        $property = $this->context->property();
        if ($active) {
            $this->limits->assertCanAddUnits($property, 1, 'is_active');
        } else {
            $this->guard->assertNoFutureNights($unit, $property, 'is_active');
        }
        $unit->is_active = $active;
    }

    /**
     * Names are unique per property (soft-deleted rooms included, as in the database index).
     *
     * @param  list<string>  $names
     */
    public function assertUniqueNames(array $names, string $field, ?int $ignoreId = null): void
    {
        $clean = array_map(fn ($n) => trim((string) $n), $names);
        if (in_array('', $clean, true)) {
            throw ValidationException::withMessages([$field => __('rooms.errors.unit_name_required')]);
        }

        $lower = array_map('mb_strtolower', $clean);
        $duplicates = array_unique(array_diff_assoc($lower, array_unique($lower)));
        if ($duplicates !== []) {
            throw ValidationException::withMessages([$field => __('rooms.errors.unit_name_duplicate_input', ['names' => implode(', ', $duplicates)])]);
        }

        $taken = PhysicalUnit::query()->withTrashed()
            ->whereIn('name', $clean)
            ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->pluck('name')->all();
        if ($taken !== []) {
            throw ValidationException::withMessages([$field => __('rooms.errors.unit_name_taken', ['names' => implode(', ', array_slice($taken, 0, 10))])]);
        }
    }
}

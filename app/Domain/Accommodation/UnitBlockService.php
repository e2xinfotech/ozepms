<?php

namespace App\Domain\Accommodation;

use App\Domain\Accommodation\Events\UnitBlockChanged;
use App\Domain\Audit\AuditLogger;
use App\Infrastructure\Database\Tx;
use App\Models\PhysicalUnit;
use App\Models\UnitBlock;
use App\Support\PropertyContext;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/** Out-of-service / out-of-order periods of PMS rooms. Dates are stay dates; end is exclusive. */
class UnitBlockService
{
    public function __construct(
        private readonly PropertyContext $context,
        private readonly AuditLogger $audit,
        private readonly UnitNightGuard $guard,
    ) {}

    public function block(PhysicalUnit $unit, string $type, string $from, string $to, ?string $reason = null): UnitBlock
    {
        if (! in_array($type, UnitBlock::TYPES, true)) {
            throw ValidationException::withMessages(['block_type' => __('rooms.errors.block_type')]);
        }
        if ($to <= $from) {
            throw ValidationException::withMessages(['end_date' => __('rooms.errors.block_dates')]);
        }

        return Tx::run(function () use ($unit, $type, $from, $to, $reason) {
            $property = $this->context->property();
            if ($from < $this->guard->today($property)) {
                throw ValidationException::withMessages(['start_date' => __('rooms.errors.block_in_past')]);
            }

            // Lock the unit row so two people cannot block the same dates at once.
            PhysicalUnit::query()->whereKey($unit->id)->lockForUpdate()->first();

            if (UnitBlock::query()->where('unit_id', $unit->id)->overlapping($from, $to)->exists()) {
                throw ValidationException::withMessages(['start_date' => __('rooms.errors.block_overlap', ['room' => $unit->name])]);
            }
            $this->guard->assertNoNightsBetween($unit, $from, $to);

            $block = UnitBlock::query()->create([
                'unit_id' => $unit->id,
                'block_type' => $type,
                'start_date' => $from,
                'end_date' => $to,
                'reason' => $reason,
                'created_by' => auth()->id(),
            ]);

            $this->audit->log('unit_block.created', $block, ['after' => [
                'room' => $unit->name, 'type' => $type, 'from' => $from, 'to' => $to, 'reason' => $reason,
            ]]);
            UnitBlockChanged::dispatch($property->id, (int) $unit->room_type_id, CarbonImmutable::parse($from), CarbonImmutable::parse($to));

            return $block;
        });
    }

    public function release(UnitBlock $block): UnitBlock
    {
        if ($block->released_at !== null) {
            return $block;
        }

        return Tx::run(function () use ($block) {
            $property = $this->context->property();
            $unit = PhysicalUnit::query()->withTrashed()->findOrFail($block->unit_id);

            $block->released_at = now();
            $block->released_by = auth()->id();
            $block->save();

            $this->audit->log('unit_block.released', $block, ['after' => ['room' => $unit->name]]);

            // Only nights from today on change availability; past nights stay as they were.
            $today = CarbonImmutable::parse($this->guard->today($property));
            $from = $block->start_date->greaterThan($today) ? $block->start_date : $today;
            if ($from->lessThan($block->end_date)) {
                UnitBlockChanged::dispatch($property->id, (int) $unit->room_type_id, $from, $block->end_date);
            }

            return $block;
        });
    }

    /** The block covering the given date, if any. */
    public function current(PhysicalUnit $unit, string $date): ?UnitBlock
    {
        return UnitBlock::query()->where('unit_id', $unit->id)
            ->overlapping($date, CarbonImmutable::parse($date)->addDay()->toDateString())
            ->orderBy('start_date')
            ->first();
    }
}

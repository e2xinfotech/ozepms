<?php

namespace App\Domain\Inventory;

use App\Models\Property;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Retention of the daily tables (spec §17: years of data must stay fast).
 *
 * Nights older than ozepms.inventory.retention_days (counted back from the property's today) are
 * deleted from inventory_daily, ari_daily and ari_daily_occupancy; ari_change_log rows older than
 * ozepms.inventory.change_log_retention_days are deleted too. Deletes run per property in small
 * batches on the (property_id, stay_date) indexes, so they never lock a large range at once.
 *
 * Safety
 *   - today and future nights are never touched (the retention is at least one day);
 *   - the cut-off never passes the first night of a reservation that is still open (hold, pending,
 *     confirmed, checked in), so nothing an open booking may still read is removed;
 *   - reservations keep their own nightly prices (reservation_room_nights) and rate snapshot, and
 *     reports use their own daily rollups, so closed bookings and statistics lose nothing.
 */
final class InventoryArchiver
{
    private const OPEN_STATUSES = ['hold', 'pending', 'confirmed', 'checked_in'];

    public function __construct(private readonly InventoryService $inventory) {}

    /**
     * @return array{cutoff: string, inventory_daily: int, ari_daily: int, ari_daily_occupancy: int, ari_change_log: int}
     */
    public function archive(Property $property, ?int $retentionDays = null, ?int $logRetentionDays = null, bool $dryRun = false): array
    {
        $retentionDays ??= (int) config('ozepms.inventory.retention_days', 400);
        $logRetentionDays ??= (int) config('ozepms.inventory.change_log_retention_days', 400);
        if ($retentionDays < 1 || $logRetentionDays < 1) {
            throw new InvalidArgumentException('Retention must be at least one day.');
        }
        $batch = max(100, (int) config('ozepms.inventory.archive_batch', 5000));
        $pid = (int) $property->id;

        $cutoff = $this->cutoff($pid, $retentionDays);
        $logBefore = now()->subDays($logRetentionDays)->toDateTimeString();

        $counts = ['cutoff' => $cutoff->toDateString()];
        foreach (['inventory_daily', 'ari_daily', 'ari_daily_occupancy'] as $table) {
            $counts[$table] = $dryRun
                ? DB::table($table)->where('property_id', $pid)->where('stay_date', '<', $cutoff->toDateString())->count()
                : $this->deleteInBatches(fn () => DB::table($table)->where('property_id', $pid)->where('stay_date', '<', $cutoff->toDateString())->limit($batch)->delete(), $batch);
        }
        $counts['ari_change_log'] = $dryRun
            ? DB::table('ari_change_log')->where('property_id', $pid)->where('created_at', '<', $logBefore)->count()
            : $this->deleteInBatches(fn () => DB::table('ari_change_log')->where('property_id', $pid)->where('created_at', '<', $logBefore)
                ->orderBy('id')->limit($batch)->delete(), $batch);

        return $counts;
    }

    /** First night that is kept: today − retention, or the first night of an open reservation if earlier. */
    public function cutoff(int $propertyId, int $retentionDays): CarbonImmutable
    {
        $cutoff = $this->inventory->today($propertyId)->subDays($retentionDays);
        $open = DB::table('reservation_rooms')->where('property_id', $propertyId)->whereIn('status', self::OPEN_STATUSES)->min('check_in');
        if ($open !== null && $open < $cutoff->toDateString()) {
            $cutoff = CarbonImmutable::createFromFormat('!Y-m-d', substr((string) $open, 0, 10));
        }

        return $cutoff;
    }

    private function deleteInBatches(\Closure $delete, int $batch): int
    {
        $total = 0;
        do {
            $deleted = $delete();
            $total += $deleted;
        } while ($deleted >= $batch);

        return $total;
    }
}

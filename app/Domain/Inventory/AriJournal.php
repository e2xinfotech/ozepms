<?php

namespace App\Domain\Inventory;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Records ARI changes: one ari_change_log row per change (read by the channel manager as
 * "everything after id X") and properties.ari_version + 1 (cache key of availability results).
 */
class AriJournal
{
    public const SCOPES = ['inventory', 'rate', 'restriction'];

    public const SOURCES = ['user', 'reservation', 'system', 'channel'];

    /** Increments properties.ari_version and returns the new value. */
    public function bump(int $propertyId): int
    {
        DB::table('properties')->where('id', $propertyId)->increment('ari_version');

        return (int) DB::table('properties')->where('id', $propertyId)->value('ari_version');
    }

    public function version(int $propertyId): int
    {
        return (int) DB::table('properties')->where('id', $propertyId)->value('ari_version');
    }

    /**
     * @param  CarbonImmutable  $to  inclusive last stay date
     * @param  array<string, mixed>  $payload
     */
    public function record(
        int $propertyId,
        string $scope,
        ?int $roomTypeId,
        ?int $productId,
        CarbonImmutable $from,
        CarbonImmutable $to,
        array $payload,
        string $source = 'system',
        ?int $userId = null,
        int $weekdays = 127,
    ): void {
        DB::table('ari_change_log')->insert([
            'property_id' => $propertyId,
            'scope' => $scope,
            'room_type_id' => $roomTypeId,
            'product_id' => $productId,
            'date_from' => $from->toDateString(),
            'date_to' => $to->toDateString(),
            'weekdays' => $weekdays,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
            'source' => $source,
            'user_id' => $userId,
            'created_at' => now()->format('Y-m-d H:i:s.v'),
        ]);
    }
}

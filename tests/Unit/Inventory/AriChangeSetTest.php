<?php

namespace Tests\Unit\Inventory;

use App\Domain\Inventory\AriChangeSet;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AriChangeSetTest extends TestCase
{
    /** Validation messages need the translator, so this runs inside the application. */
    protected bool $seed = false;

    private function errors(array $data): array
    {
        try {
            AriChangeSet::fromArray(1, $data);
        } catch (ValidationException $e) {
            return array_keys($e->errors());
        }

        return [];
    }

    public function test_valid_change_set(): void
    {
        $set = AriChangeSet::fromArray(1, [
            'date_from' => '2026-12-01', 'date_to' => '2026-12-14', 'weekdays' => [5, 6, '6'],
            'product_ids' => ['3', 4], 'price' => '1500.5', 'occupancy_prices' => ['1' => '900', 2 => null], 'min_los' => 2, 'cta' => '1',
        ]);

        $this->assertSame([5, 6], $set->weekdays);
        $this->assertSame([3, 4], $set->productIds);
        $this->assertSame('1500.50', $set->price);
        $this->assertSame([1 => '900.00', 2 => null], $set->occupancyPrices);
        $this->assertSame(16 + 32, $set->weekdayMask());
        $this->assertSame(['2026-12-04', '2026-12-05', '2026-12-11', '2026-12-12'], $set->dates());
        $this->assertSame(14, $set->days());
        $this->assertTrue($set->cta);
        $this->assertNull($set->ctd);
        $this->assertTrue($set->hasPriceChanges());
        $this->assertTrue($set->hasRestrictionChanges());
        $this->assertFalse($set->hasRoomTypeChanges());
        $this->assertSame(['price' => '1500.50', 'occupancy_prices' => [1 => '900.00', 2 => null], 'min_los' => 2, 'cta' => true], $set->payload());
    }

    public function test_all_weekdays_equals_no_filter(): void
    {
        $set = AriChangeSet::fromArray(1, ['date_from' => '2026-12-01', 'date_to' => '2026-12-01', 'weekdays' => [1, 2, 3, 4, 5, 6, 7], 'room_type_ids' => [1], 'stop_sell' => true]);
        $this->assertSame([], $set->weekdays);
        $this->assertSame(127, $set->weekdayMask());
        $this->assertTrue($set->productStopSell());
    }

    public function test_closed_wins_over_stop_sell_for_products(): void
    {
        $set = AriChangeSet::fromArray(1, ['date_from' => '2026-12-01', 'product_ids' => [1], 'stop_sell' => true, 'closed' => false]);
        $this->assertFalse($set->productStopSell());
        $this->assertSame('2026-12-01', $set->dateTo->toDateString(), 'date_to defaults to date_from');
    }

    public function test_range_and_value_validation(): void
    {
        $base = ['date_from' => '2026-12-01', 'date_to' => '2026-12-02', 'product_ids' => [1]];

        $this->assertSame(['date_to'], $this->errors(['date_to' => '2026-11-30', 'price' => '1'] + $base));
        $this->assertSame(['date_to'], $this->errors(['date_from' => '2026-01-01', 'date_to' => '2028-01-02', 'price' => '1'] + $base));
        $this->assertSame([], $this->errors(['date_from' => '2026-01-01', 'date_to' => '2028-01-01', 'price' => '1'] + $base), '731 days is the maximum');
        $this->assertSame(['date_from'], $this->errors(['date_from' => 'tomorrow'] + $base));
        $this->assertSame(['weekdays'], $this->errors(['weekdays' => [0], 'price' => '1'] + $base));
        $this->assertSame(['price'], $this->errors(['price' => '-5'] + $base));
        $this->assertSame(['price'], $this->errors(['price' => 'abc'] + $base));
        $this->assertSame(['occupancy_prices'], $this->errors(['occupancy_prices' => ['0' => '100']] + $base));
        $this->assertSame(['max_los'], $this->errors(['min_los' => 5, 'max_los' => 3] + $base));
        $this->assertSame(['max_advance'], $this->errors(['min_advance' => 10, 'max_advance' => 3] + $base));
        $this->assertSame(['fields'], $this->errors($base));
        $this->assertSame(['targets'], $this->errors(['date_from' => '2026-12-01', 'price' => '1']));
        $this->assertSame(['sell_limit'], $this->errors(['sell_limit' => 3] + $base));
        $this->assertSame(['products'], $this->errors(['date_from' => '2026-12-01', 'room_type_ids' => [1], 'price' => '1']));
    }
}

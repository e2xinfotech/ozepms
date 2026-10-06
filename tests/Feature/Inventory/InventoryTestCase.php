<?php

namespace Tests\Feature\Inventory;

use App\Domain\Inventory\AriChangeSet;
use App\Domain\Inventory\AriService;
use App\Domain\Rates\ProductService;
use App\Models\Product;
use App\Models\Property;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Support\PropertyContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Accommodation\AccommodationTestCase;

/** Helpers for the inventory, rates and availability tests. */
abstract class InventoryTestCase extends AccommodationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // A shorter horizon keeps the tests fast; the logic does not depend on its length.
        config(['ozepms.inventory.horizon_days' => 60]);
    }

    protected function day(int $offset = 0, ?Property $property = null): CarbonImmutable
    {
        return CarbonImmutable::parse($this->today($property))->addDays($offset);
    }

    /** A rate plan copied from BAR (same meal plan and policy) with its own code. */
    protected function plan(string $code, array $attributes = [], ?Property $property = null): RatePlan
    {
        $bar = $this->bar($property);
        $id = DB::table('rate_plans')->insertGetId(array_merge([
            'public_id' => (string) Str::ulid(), 'property_id' => $bar->property_id, 'code' => $code, 'name' => $code,
            'meal_plan_id' => $bar->meal_plan_id, 'cancellation_policy_id' => $bar->cancellation_policy_id,
            'default_min_los' => 1, 'created_at' => now(), 'updated_at' => now(),
        ], $attributes));

        return RatePlan::acrossProperties()->findOrFail($id);
    }

    /** Creates / updates the product room type × rate plan through the Phase 2 service. */
    protected function product(RoomType $roomType, RatePlan $plan, array $data = []): Product
    {
        $this->inProperty(Property::query()->findOrFail($roomType->property_id));
        $product = app(ProductService::class)->upsert($roomType, $plan, array_merge(['pricing_mode' => 'manual', 'default_price' => '1000.00'], $data));
        app(PropertyContext::class)->clear();

        return Product::acrossProperties()->findOrFail($product->id);
    }

    protected function apply(array $data, ?Property $property = null): array
    {
        $property ??= $this->property;

        return app(AriService::class)->apply(AriChangeSet::fromArray($property->id, $data))->toArray();
    }

    protected function inv(RoomType $roomType, CarbonImmutable $date): ?object
    {
        return DB::table('inventory_daily')->where('room_type_id', $roomType->id)->where('stay_date', $date->toDateString())->first();
    }

    protected function ari(Product $product, CarbonImmutable $date): ?object
    {
        return DB::table('ari_daily')->where('product_id', $product->id)->where('stay_date', $date->toDateString())->first();
    }

    protected function version(?Property $property = null): int
    {
        return (int) DB::table('properties')->where('id', ($property ?? $this->property)->id)->value('ari_version');
    }
}

<?php

namespace Tests\Feature\Billing;

use App\Domain\Billing\NightAuditService;
use App\Models\NightAuditRun;
use App\Models\Property;
use App\Models\Reservation;
use Illuminate\Support\Facades\DB;

class NightAuditTest extends BillingTestCase
{
    private function setBusinessDate(?string $date): void
    {
        DB::table('properties')->where('id', $this->property->id)->update(['business_date' => $date]);
    }

    public function test_audit_posts_in_house_nights_marks_no_shows_and_rolls_the_date(): void
    {
        $inHouse = $this->inHouse(3);
        $arrival = $this->book([$this->room($this->dlxBar, 0, 2)]);
        $future = $this->book([$this->room($this->dlxBar, 1, 2)]);
        $today = $this->day(0)->toDateString();
        $tomorrow = $this->day(1)->toDateString();
        $this->setBusinessDate($today);
        $this->travel(1)->days();

        $results = app(NightAuditService::class)->run(Property::query()->find($this->property->id), null, true);
        $this->assertCount(1, $results);
        $this->assertSame('completed', $results[0]['status']);
        $this->assertSame(1, $results[0]['nights_posted']);
        $this->assertSame(1, $results[0]['no_shows']);

        $this->assertSame($tomorrow, Property::query()->find($this->property->id)->business_date->toDateString());
        $this->assertCount(1, $this->lines($inHouse)->where('line_type', 'room'));
        $this->assertSame($today, $this->lines($inHouse)->first()->business_date->toDateString());
        $this->assertSame('no_show', Reservation::acrossProperties()->find($arrival->id)->status);
        $this->assertSame('confirmed', Reservation::acrossProperties()->find($future->id)->status);
        $this->assertSame('completed', NightAuditRun::acrossProperties()->where('business_date', $today)->value('status'));
        $this->assertTrue(DB::table('audit_logs')->where('action', 'billing.night_audit')->exists());
    }

    public function test_audit_is_idempotent_per_property_and_day(): void
    {
        $inHouse = $this->inHouse(3);
        $today = $this->day(0);
        $this->setBusinessDate($today->toDateString());
        $this->travel(1)->days();
        $service = app(NightAuditService::class);
        $property = Property::query()->find($this->property->id);

        $this->assertSame('completed', $service->audit($property, $today)['status']);
        $this->assertSame('skipped', $service->audit($property, $today)['status']);
        $this->assertSame([], $service->run($property->fresh(), null, true), 'today is never closed');
        $this->assertCount(1, $this->lines($inHouse));
        $this->assertSame(1, NightAuditRun::acrossProperties()->count());
    }

    public function test_due_check_respects_the_audit_time_and_catches_up(): void
    {
        $this->setBusinessDate($this->day(-3)->toDateString());
        DB::table('property_settings')->insert(['property_id' => $this->property->id, 'key' => 'night_audit_time', 'value' => json_encode('23:59'), 'updated_at' => now()]);
        $property = Property::query()->find($this->property->id);
        $service = app(NightAuditService::class);

        // Two days behind are closed at once; yesterday waits for the audit time unless forced.
        $results = $service->run($property);
        $this->assertSame([$this->day(-3)->toDateString(), $this->day(-2)->toDateString()], array_column($results, 'date'));
        $this->assertSame($this->day(-1)->toDateString(), $property->fresh()->business_date->toDateString());

        $this->artisan('billing:night-audit', ['--property' => $property->code, '--force' => true])->assertSuccessful();
        $this->assertSame($this->day(0)->toDateString(), $property->fresh()->business_date->toDateString());
        $this->artisan('billing:night-audit')->assertSuccessful();
        $this->assertSame(3, NightAuditRun::acrossProperties()->where('property_id', $property->id)->count());
    }
}

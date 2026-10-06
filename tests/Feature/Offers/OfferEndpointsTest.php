<?php

namespace Tests\Feature\Offers;

use App\Models\Offer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Offers pages and JSON endpoints: success, validation, permission and other-property isolation. */
class OfferEndpointsTest extends OfferTestCase
{
    private function valid(array $extra = []): array
    {
        return array_merge([
            'name' => 'Summer Getaway', 'offer_type' => 'room_discount', 'discount_type' => 'percent', 'discount_value' => '20',
            'stay_from' => $this->day(10)->toDateString(), 'stay_to' => $this->day(40)->toDateString(),
            'room_types' => [$this->deluxe->public_id], 'rate_plans' => [], 'sources' => ['direct'], 'countries' => ['AE', 'fr'], 'country_mode' => 'not_in',
            'min_adults' => 2, 'weekdays' => 127, 'priority' => 3, 'promo_code' => 'summer-26',
        ], $extra);
    }

    public function test_create_show_update_copy_status_delete(): void
    {
        $this->actingAs($this->owner)->get($this->page('/offers'))->assertOk();
        $this->actingAs($this->owner)->get($this->page('/offers/new'))->assertOk();

        $res = $this->actingAs($this->owner)->postJson($this->api('/offers'), $this->valid());
        $this->assertSame(201, $res->status(), $res->getContent());
        $res
            ->assertJsonPath('offer.code', 'P-00001')->assertJsonPath('offer.promo_code', 'SUMMER-26')
            ->assertJsonPath('offer.country_mode', 'not_in')->assertJsonPath('offer.countries', ['AE', 'FR']);
        $id = $res->json('offer.id');
        $offer = Offer::acrossProperties()->where('public_id', $id)->first();
        $this->assertSame(1, $offer->scopes()->count());
        $this->assertSame(3, $offer->conditions()->count());

        $this->actingAs($this->owner)->get($this->page("/offers/$id/edit"))->assertOk();
        $this->actingAs($this->owner)->getJson($this->api("/offers/$id"))->assertOk()
            ->assertJsonPath('offer.discount_label', '20% Off')->assertJsonPath('offer.room_types', ['Deluxe Room'])
            ->assertJsonPath('offer.status', 'active')->assertJsonPath('offer.history.0.action', 'offer.created');
        $this->actingAs($this->owner)->get($this->page('/offers?tab=active'))->assertOk()->assertSee('Summer Getaway');

        $this->actingAs($this->owner)->putJson($this->api("/offers/$id"), ['name' => 'Summer Escape', 'room_types' => [], 'sources' => [], 'countries' => []])
            ->assertOk()->assertJsonPath('offer.name', 'Summer Escape')->assertJsonPath('offer.room_types', []);
        $this->assertSame(0, $offer->scopes()->count());
        $this->assertSame(1, $offer->conditions()->count(), 'sources and countries cleared; min_adults (not sent) kept');

        $copy = $this->actingAs($this->owner)->postJson($this->api("/offers/$id/copy"))->assertCreated()->json('offer.id');
        $this->assertFalse((bool) Offer::acrossProperties()->where('public_id', $copy)->value('is_active'));
        $this->actingAs($this->owner)->postJson($this->api("/offers/$id/status"), ['is_active' => false])->assertOk()->assertJsonPath('is_active', false);

        $csv = $this->actingAs($this->owner)->get($this->api('/offers/export'))->assertOk()->streamedContent();
        $this->assertStringContainsString('Summer Escape', $csv);

        $this->actingAs($this->owner)->deleteJson($this->api("/offers/$copy"))->assertOk()->assertJsonPath('result', 'deleted');
        $this->assertSoftDeleted('offers', ['public_id' => $copy]);
    }

    public function test_used_offers_are_deactivated_instead_of_deleted(): void
    {
        $offer = $this->offer();
        $r = $this->book([$this->room($this->dlxBar, 3, 4)]);
        DB::table('offer_applications')->insert(['property_id' => $this->property->id, 'offer_id' => $offer->id, 'reservation_id' => $r->id, 'discount_amount' => '100.00', 'snapshot' => '{}', 'created_at' => now()]);
        $this->actingAs($this->owner)->deleteJson($this->api("/offers/{$offer->public_id}"))->assertOk()->assertJsonPath('result', 'deactivated');
        $this->assertFalse((bool) $offer->fresh()->is_active);
        $this->assertNull($offer->fresh()->deleted_at);
    }

    public function test_validation(): void
    {
        $url = $this->api('/offers');
        $this->actingAs($this->owner)->postJson($url, [])->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['name', 'offer_type', 'discount_type', 'discount_value']]]);
        $this->actingAs($this->owner)->postJson($url, $this->valid(['discount_value' => '120']))->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['discount_value']]]);
        $this->actingAs($this->owner)->postJson($url, $this->valid(['stay_to' => $this->day(5)->toDateString()]))->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['stay_to']]]);
        $this->actingAs($this->owner)->postJson($url, $this->valid(['discount_type' => 'free_nights', 'discount_value' => '1', 'min_nights' => 1]))->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['min_nights']]]);
        $this->actingAs($this->owner)->postJson($url, $this->valid(['discount_type' => 'free_nights', 'discount_value' => '3', 'min_nights' => 3]))->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['discount_value']]]);
        $this->actingAs($this->owner)->postJson($url, $this->valid(['on_pms' => false, 'on_booking_engine' => false]))->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['on_pms']]]);
        $this->actingAs($this->owner)->postJson($url, $this->valid(['sources' => ['nope']]))->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['sources.0']]]);
        $this->actingAs($this->owner)->postJson($url, $this->valid(['countries' => ['XX']]))->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['countries.0']]]);
        $this->actingAs($this->owner)->postJson($url, $this->valid(['room_types' => [str_repeat('A', 26)]]))->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['room_types.0']]]);
        $this->actingAs($this->owner)->postJson($url, $this->valid())->assertCreated();
        $this->actingAs($this->owner)->postJson($url, $this->valid(['name' => 'Other']))->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['promo_code']]]);
        $this->actingAs($this->owner)->postJson($url, $this->valid(['promo_code' => null, 'code' => 'P-00001']))->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['code']]]);
    }

    public function test_image_upload_and_remove(): void
    {
        Storage::fake('public');
        $offer = $this->offer();
        $res = $this->actingAs($this->owner)->post($this->api("/offers/{$offer->public_id}/image"), ['image' => UploadedFile::fake()->image('pool.jpg', 800, 600)], ['Accept' => 'application/json'])->assertOk();
        $path = $offer->fresh()->image_path;
        Storage::disk('public')->assertExists($path);
        $this->assertNotNull($res->json('image_url'));
        $this->actingAs($this->owner)->deleteJson($this->api("/offers/{$offer->public_id}/image"))->assertOk();
        Storage::disk('public')->assertMissing($path);
    }

    public function test_permission_and_other_property(): void
    {
        $offer = $this->offer();
        $desk = $this->actingMember('front_desk');
        $this->actingAs($desk)->get($this->page('/offers'))->assertForbidden();
        $this->actingAs($desk)->getJson($this->api("/offers/{$offer->public_id}"))->assertForbidden();
        $this->actingAs($desk)->postJson($this->api('/offers'), $this->valid())->assertForbidden();
        $this->actingAs($this->actingMember('revenue_manager'))->getJson($this->api("/offers/{$offer->public_id}"))->assertOk();

        // The other property's owner cannot reach this property's offers, and ids do not cross properties.
        $this->actingAs($this->otherOwner)->getJson($this->api("/offers/{$offer->public_id}"))->assertNotFound();
        $otherApi = '/web-api/p/'.$this->other->code;
        $this->actingAs($this->otherOwner)->getJson($otherApi."/offers/{$offer->public_id}")->assertNotFound();
        $this->actingAs($this->otherOwner)->putJson($otherApi."/offers/{$offer->public_id}", ['name' => 'x'])->assertNotFound();
    }
}

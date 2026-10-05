<?php

namespace Tests\Feature\Accommodation;

use App\Models\Amenity;

class AmenitiesTest extends AccommodationTestCase
{
    public function test_list_page_shows_global_and_custom_amenities(): void
    {
        $this->actingAs($this->owner)->postJson($this->api('/amenities'), ['name' => 'Rooftop Yoga', 'category' => 'service'])->assertCreated();

        $this->actingAs($this->owner)->get($this->page('/amenities?per_page=100'))->assertOk()
            ->assertSee($this->pageName('property/amenities/index'), false)->assertSee('"id":"wifi"', false)->assertSee('Rooftop Yoga', false);
        $this->actingAs($this->owner)->get($this->page('/amenities?tab=custom'))->assertOk()->assertDontSee('"id":"wifi"', false)->assertSee('"name":"Rooftop Yoga"', false);
        $this->actingAs($this->member('guest_relations'))->get($this->page('/amenities'))->assertForbidden();
    }

    public function test_custom_amenities_are_private_to_their_property(): void
    {
        $this->actingAs($this->otherOwner)->postJson('/web-api/p/'.$this->other->code.'/amenities', ['name' => 'Private Beach', 'category' => 'property'])->assertCreated();

        $this->actingAs($this->owner)->get($this->page('/amenities'))->assertOk()->assertDontSee('Private Beach', false);
        $code = Amenity::query()->where('name', 'Private Beach')->value('code');
        $this->actingAs($this->owner)->putJson($this->api('/amenities/'.$code), ['name' => 'Mine'])->assertNotFound();
    }

    public function test_store_validates_and_rejects_duplicates(): void
    {
        $this->actingAs($this->owner)->postJson($this->api('/amenities'), ['name' => '', 'category' => 'nope'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['name', 'category']]]);
        $this->actingAs($this->owner)->postJson($this->api('/amenities'), ['name' => 'Free Wi-Fi', 'category' => 'room'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['name']]]);
        $this->actingAs($this->member('front_desk'))->postJson($this->api('/amenities'), ['name' => 'X', 'category' => 'room'])->assertForbidden();
    }

    public function test_update_custom_but_not_global(): void
    {
        $code = $this->actingAs($this->owner)->postJson($this->api('/amenities'), ['name' => 'Hammock', 'category' => 'room'])->json('amenity.id');

        $this->actingAs($this->owner)->putJson($this->api('/amenities/'.$code), ['name' => 'Garden Hammock', 'is_active' => false])
            ->assertOk()->assertJsonPath('amenity.name', 'Garden Hammock')->assertJsonPath('amenity.is_active', false);
        $this->actingAs($this->owner)->putJson($this->api('/amenities/wifi'), ['name' => 'Wi-Fi 6'])->assertStatus(422);
    }
}

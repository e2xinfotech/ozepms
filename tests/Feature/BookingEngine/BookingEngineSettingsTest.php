<?php

namespace Tests\Feature\BookingEngine;

class BookingEngineSettingsTest extends BookingEngineTestCase
{
    public function test_hotel_can_switch_the_booking_engine_and_edit_texts(): void
    {
        $this->actingAs($this->owner)->get('/p/'.$this->property->code.'/settings')->assertOk()->assertSee('booking_engine', false);
        $url = '/web-api/p/'.$this->property->code.'/settings/booking-engine';
        $this->actingAs($this->owner)->putJson($url, ['enabled' => false, 'intro' => 'Best rate direct', 'terms' => 'Check-in from 14:00'])->assertOk()
            ->assertJsonPath('booking_engine.open', false)->assertJsonPath('booking_engine.reason', 'switched_off')->assertJsonPath('booking_engine.intro', 'Best rate direct');
        $this->get('/book/'.$this->property->code)->assertNotFound();
        $this->actingAs($this->owner)->putJson($url, ['enabled' => true, 'intro' => 'Best rate direct', 'terms' => null])->assertOk()->assertJsonPath('booking_engine.open', true);
        auth()->logout();
        $this->get('/book/'.$this->property->code)->assertOk()->assertSee('Best rate direct');

        $this->actingAs($this->owner)->putJson($url, ['enabled' => 'maybe'])->assertStatus(422);
        $this->actingAs($this->actingMember('front_desk'))->putJson($url, ['enabled' => false])->assertForbidden();
        $this->actingAs($this->otherOwner)->putJson($url, ['enabled' => false])->assertNotFound();
    }
}

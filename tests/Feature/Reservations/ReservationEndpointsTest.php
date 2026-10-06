<?php

namespace Tests\Feature\Reservations;

use App\Models\Reservation;
use App\Models\ReservationRoom;
use Illuminate\Support\Facades\DB;

/** JSON endpoints of reservations: success, validation, permission, other property → 404. */
class ReservationEndpointsTest extends ReservationTestCase
{
    private function payload(array $extra = []): array
    {
        return array_merge([
            'status' => 'confirmed', 'source' => 'phone',
            'guest' => ['title' => 'mr', 'first_name' => 'Priya', 'last_name' => 'Sharma', 'email' => 'priya@example.com', 'phone' => '98765 43210', 'nationality_iso2' => 'IN'],
            'rooms' => [[
                'room_type_id' => $this->deluxe->public_id, 'rate_plan_id' => $this->bar()->public_id,
                'check_in' => $this->day(3)->toDateString(), 'check_out' => $this->day(5)->toDateString(), 'adults' => 2, 'children' => 0,
            ]],
            'special_requests' => 'High floor',
        ], $extra);
    }

    public function test_create_show_and_list(): void
    {
        $res = $this->actingAs($this->owner)->postJson($this->api('/reservations'), $this->payload(), ['Idempotency-Key' => 'key-0001-abcd']);
        $res->assertCreated()->assertJsonPath('reservation.status', 'confirmed');
        $id = $res->json('reservation.id');
        $this->assertSame('+919876543210', DB::table('guests')->value('phone_e164'), 'local number gets the country code');

        $again = $this->actingAs($this->owner)->postJson($this->api('/reservations'), $this->payload(), ['Idempotency-Key' => 'key-0001-abcd']);
        $again->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('reservation.id', $id);
        $this->assertSame(1, Reservation::acrossProperties()->count());

        $this->actingAs($this->owner)->getJson($this->api('/reservations/'.$id))->assertOk()
            ->assertJsonPath('reservation.ref', $res->json('reservation.ref'))
            ->assertJsonPath('reservation.rooms.0.nights', 2)
            ->assertJsonPath('reservation.source.code', 'phone')
            ->assertJsonPath('reservation.actions.cancel', true);

        $this->actingAs($this->owner)->get($this->page('/reservations'))->assertOk()->assertSee($this->pageName('property/reservations/index'), false);
        $this->actingAs($this->owner)->get($this->page('/reservations/'.$id))->assertOk();
        $this->actingAs($this->owner)->get($this->page('/reservations/'.$id.'/edit'))->assertOk();
        $this->actingAs($this->owner)->get($this->page('/reservations/new'))->assertOk();
        $this->actingAs($this->owner)->get($this->page('/front-desk'))->assertOk();
        $this->actingAs($this->owner)->get($this->page('/guests'))->assertOk();
        $this->actingAs($this->owner)->getJson($this->api('/reservations/'.$id.'/history'))->assertOk()->assertJsonCount(1, 'events');
    }

    public function test_validation_errors(): void
    {
        $this->actingAs($this->owner)->postJson($this->api('/reservations'), $this->payload(['rooms' => []]))
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['rooms']]]);
        $bad = $this->payload();
        $bad['rooms'][0]['check_out'] = $bad['rooms'][0]['check_in'];
        $bad['guest']['first_name'] = '';
        $this->actingAs($this->owner)->postJson($this->api('/reservations'), $bad)
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['rooms.0.check_out', 'guest.first_name']]]);
        // Another property's room type in the body is a field error, not a booking.
        $otherRt = $this->makeRoomType(['code' => 'OTH'], 1, $this->other);
        $foreign = $this->payload();
        $foreign['rooms'][0]['room_type_id'] = $otherRt->public_id;
        $this->actingAs($this->owner)->postJson($this->api('/reservations'), $foreign)->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['rooms.0.room_type_id']]]);
        $this->assertSame(0, Reservation::acrossProperties()->count());
    }

    public function test_permissions(): void
    {
        $housekeeping = $this->member('housekeeping');
        $this->actingAs($housekeeping)->postJson($this->api('/reservations'), $this->payload())->assertForbidden();
        $this->actingAs($housekeeping)->get($this->page('/reservations'))->assertForbidden();
        $this->actingAs($housekeeping)->get($this->page('/guests'))->assertForbidden();

        $r = $this->book([$this->room($this->dlxBar, 0, 2)]);
        $sales = $this->member('sales_marketing'); // reservations.view only
        $this->actingAs($sales)->getJson($this->api('/reservations/'.$r->public_id))->assertOk()->assertJsonPath('reservation.actions.cancel', false);
        $this->actingAs($sales)->postJson($this->api('/reservations/'.$r->public_id.'/cancel'))->assertForbidden();
        $this->actingAs($sales)->postJson($this->api('/reservations/'.$r->public_id.'/check-in'))->assertForbidden();
        $this->actingAs($sales)->putJson($this->api('/reservations/'.$r->public_id), [])->assertForbidden();
    }

    public function test_other_property_reservation_is_404(): void
    {
        $otherRt = $this->makeRoomType(['code' => 'OTH'], 1, $this->other);
        $otherProduct = $this->product($otherRt, $this->bar($this->other), ['default_price' => '1000']);
        $foreign = $this->book([$this->room($otherProduct, 2, 3)], [], $this->other);

        foreach (['getJson' => '', 'postJson' => '/cancel'] as $method => $suffix) {
            $this->actingAs($this->owner)->{$method}($this->api('/reservations/'.$foreign->public_id.$suffix))->assertNotFound();
        }
        $this->actingAs($this->owner)->putJson($this->api('/reservations/'.$foreign->public_id), $this->payload())->assertNotFound();
        $this->actingAs($this->owner)->get($this->page('/reservations/'.$foreign->public_id))->assertNotFound();
        $this->actingAs($this->owner)->postJson($this->api('/reservations/'.$foreign->public_id.'/check-in'))->assertNotFound();
    }

    public function test_update_cancel_and_front_desk_flow(): void
    {
        $r = $this->book([$this->room($this->dlxBar, 0, 2)]);
        $room = ReservationRoom::acrossProperties()->where('reservation_id', $r->id)->first();
        $unit = $this->units($this->deluxe)->first();

        $this->actingAs($this->owner)->getJson($this->api('/reservations/units?'.http_build_query([
            'room_type_id' => $this->deluxe->public_id, 'check_in' => $this->day(0)->toDateString(), 'check_out' => $this->day(2)->toDateString(),
        ])))->assertOk()->assertJsonCount(3, 'units');

        $this->actingAs($this->owner)->postJson($this->api('/reservations/'.$r->public_id.'/check-in'))->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['unit_id']]]);
        $this->actingAs($this->owner)->postJson($this->api('/reservations/'.$r->public_id.'/assign'), ['room_id' => $room->public_id, 'unit_id' => $unit->public_id])
            ->assertOk()->assertJsonPath('reservation.rooms.0.unit.name', $unit->name);
        $this->actingAs($this->owner)->postJson($this->api('/reservations/'.$r->public_id.'/check-in'), ['guest' => ['id_type' => 'passport', 'id_number' => 'P1234567']])
            ->assertOk()->assertJsonPath('reservation.status', 'checked_in');
        $this->assertNotNull(DB::table('guests')->value('id_number_hash'));

        // Extend the in-house stay by a night.
        $this->actingAs($this->owner)->putJson($this->api('/reservations/'.$r->public_id), ['rooms' => [[
            'id' => $room->public_id, 'room_type_id' => $this->deluxe->public_id, 'rate_plan_id' => $this->bar()->public_id,
            'check_in' => $this->day(0)->toDateString(), 'check_out' => $this->day(3)->toDateString(), 'adults' => 2,
        ]]])->assertOk()->assertJsonPath('reservation.nights', 3);
        $this->assertInventoryConsistent();

        $this->actingAs($this->owner)->get($this->page('/front-desk?tab=in_house'))->assertOk()->assertSee($r->booking_ref);
        $this->actingAs($this->owner)->postJson($this->api('/reservations/'.$r->public_id.'/notes'), ['body' => 'Late checkout requested'])->assertCreated();
        // Open balance blocks check-out; after the payment it goes through.
        $this->actingAs($this->owner)->postJson($this->api('/reservations/'.$r->public_id.'/check-out'), ['note' => 'All good'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['balance']]]);
        $balance = app(\App\Domain\Billing\FolioService::class)->summary($r->fresh())['balance'];
        $this->actingAs($this->owner)->postJson($this->api('/reservations/'.$r->public_id.'/payments'), ['method' => 'cash', 'amount' => (string) $balance, 'idempotency_key' => 'test-pay-'.$r->public_id])
            ->assertSuccessful();
        $this->actingAs($this->owner)->postJson($this->api('/reservations/'.$r->public_id.'/check-out'), ['note' => 'All good'])
            ->assertOk()->assertJsonPath('reservation.status', 'checked_out');
        $this->assertInventoryConsistent();

        $c = $this->book([$this->room($this->steBar, 4, 6)]);
        $this->actingAs($this->owner)->postJson($this->api('/reservations/'.$c->public_id.'/cancel'), ['reason' => 'Plans changed'])
            ->assertOk()->assertJsonPath('reservation.status', 'cancelled');
        $this->actingAs($this->owner)->postJson($this->api('/reservations/'.$c->public_id.'/cancel'))->assertStatus(422);
        $this->assertInventoryConsistent();
    }

    public function test_availability_quote_and_search(): void
    {
        $this->actingAs($this->owner)->getJson($this->api('/reservations/availability?'.http_build_query([
            'check_in' => $this->day(3)->toDateString(), 'check_out' => $this->day(5)->toDateString(), 'adults' => 2,
        ])))->assertOk()->assertJsonPath('nights', 2)->assertJsonCount(2, 'rows')->assertJsonPath('rows.0.available', 3);
        $this->actingAs($this->owner)->getJson($this->api('/reservations/availability?check_in=x'))->assertStatus(422);

        $this->actingAs($this->owner)->postJson($this->api('/reservations/quote'), ['rooms' => $this->payload()['rooms']])
            ->assertOk()->assertJsonPath('room_total', '8000.00')->assertJsonPath('grand_total', '8400.00');

        $r = $this->book([$this->room($this->dlxBar, 1, 2)]);
        $this->actingAs($this->owner)->getJson($this->api('/search?q='.$r->booking_ref))->assertOk()->assertJsonPath('reservations.0.id', $r->public_id);
        $this->actingAs($this->owner)->getJson($this->api('/search?q=john.smith@'))->assertOk()->assertJsonCount(1, 'guests');
        $this->actingAs($this->owner)->getJson($this->api('/search?q=900123'))->assertOk()->assertJsonCount(1, 'guests')->assertJsonPath('reservations.0.ref', $r->booking_ref);
        // (FULLTEXT sees committed rows only, so the test searches the name snapshot prefix.)
        $this->actingAs($this->owner)->getJson($this->api('/search?q=John'))->assertOk()->assertJsonPath('reservations.0.ref', $r->booking_ref);
        $this->actingAs($this->otherOwner)->getJson('/web-api/p/'.$this->other->code.'/search?q='.$r->booking_ref)->assertOk()->assertJsonCount(0, 'reservations');
    }
}

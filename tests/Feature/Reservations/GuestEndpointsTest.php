<?php

namespace Tests\Feature\Reservations;

use App\Models\Guest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Guests JSON endpoints: success, validation, permission, other property → 404. */
class GuestEndpointsTest extends ReservationTestCase
{
    private function guest(): Guest
    {
        $r = $this->book([$this->room($this->dlxBar, 1, 2)]);

        return Guest::acrossProperties()->findOrFail($r->primary_guest_id);
    }

    public function test_profile_update_tags_notes_documents(): void
    {
        Storage::fake('local');
        $g = $this->guest();
        $this->actingAs($this->owner)->getJson($this->api('/guests/'.$g->public_id))->assertOk()
            ->assertJsonPath('guest.name', 'John Smith')->assertJsonPath('guest.status', 'upcoming')->assertJsonCount(1, 'guest.stay_history');

        $this->actingAs($this->owner)->putJson($this->api('/guests/'.$g->public_id), ['nationality_iso2' => 'FR', 'id_type' => 'passport', 'id_number' => 'X123 4567', 'is_vip' => true])
            ->assertOk()->assertJsonPath('guest.nationality', 'FR')->assertJsonPath('guest.id_number', 'X123 4567');
        $this->assertNotSame('X123 4567', DB::table('guests')->where('id', $g->id)->value('id_number_enc'), 'encrypted at rest');

        // Users without guests.update see the ID number masked.
        $sales = $this->member('sales_marketing');
        DB::table('role_permissions')->insert(['role_id' => DB::table('roles')->whereNull('property_id')->where('code', 'sales_marketing')->value('id'),
            'permission_id' => DB::table('permissions')->where('key', 'guests.view')->value('id')]);
        $this->actingAs($sales->fresh())->getJson($this->api('/guests/'.$g->public_id))->assertOk()->assertJsonPath('guest.id_number', '•••••4567');

        $this->actingAs($this->owner)->putJson($this->api('/guests/'.$g->public_id.'/tags'), ['tags' => ['VIP', 'vip', ' Business ']])->assertOk()->assertJsonPath('tags', ['VIP', 'Business']);
        $this->actingAs($this->owner)->postJson($this->api('/guests/'.$g->public_id.'/notes'), ['body' => 'Prefers a quiet room'])->assertCreated();
        $doc = $this->actingAs($this->owner)->postJson($this->api('/guests/'.$g->public_id.'/documents'), [
            'type' => 'passport', 'file' => UploadedFile::fake()->create('passport.pdf', 120, 'application/pdf'),
        ])->assertCreated()->json('document.id');
        $this->actingAs($this->owner)->get($this->api('/guests/'.$g->public_id.'/documents/'.$doc))->assertOk();
        $this->actingAs($this->owner)->getJson($this->api('/guests/'.$g->public_id))->assertJsonCount(1, 'guest.notes')->assertJsonCount(1, 'guest.documents');
    }

    public function test_create_dedupe_and_validation(): void
    {
        $this->actingAs($this->owner)->postJson($this->api('/guests'), ['first_name' => 'Ana', 'email' => 'ana@example.com', 'phone' => '+33 6 12 34 56 78'])->assertCreated();
        $this->actingAs($this->owner)->postJson($this->api('/guests'), ['first_name' => 'Ana B', 'email' => 'ANA@example.com'])->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['email']]]);
        $this->actingAs($this->owner)->postJson($this->api('/guests'), ['first_name' => 'Other', 'phone' => '+33612345678'])->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['phone']]]);
        $this->actingAs($this->owner)->postJson($this->api('/guests'), ['email' => 'not-an-email'])->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['first_name', 'email']]]);
        $this->actingAs($this->owner)->putJson($this->api('/guests/'.Guest::acrossProperties()->first()->public_id.'/tags'), ['tags' => array_map(fn ($i) => "t$i", range(1, 13))])->assertStatus(422);
        $this->actingAs($this->owner)->getJson($this->api('/guest-lookup?q=ana@'))->assertOk()->assertJsonCount(1, 'guests');
        $this->actingAs($this->owner)->get($this->page('/guests?q=ana@example'))->assertOk()->assertSee('G-000001');
        $this->actingAs($this->owner)->get($this->api('/guests/export'))->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_permissions_and_tenancy(): void
    {
        $g = $this->guest();
        $housekeeping = $this->member('housekeeping');
        $this->actingAs($housekeeping)->getJson($this->api('/guests/'.$g->public_id))->assertForbidden();
        $guestRelations = $this->member('accounts'); // guests.view, no guests.update
        $this->actingAs($guestRelations)->getJson($this->api('/guests/'.$g->public_id))->assertOk();
        $this->actingAs($guestRelations)->putJson($this->api('/guests/'.$g->public_id), ['first_name' => 'X'])->assertForbidden();
        $this->actingAs($guestRelations)->postJson($this->api('/guests'), ['first_name' => 'X'])->assertForbidden();

        $otherRt = $this->makeRoomType(['code' => 'OTH'], 1, $this->other);
        $otherProduct = $this->product($otherRt, $this->bar($this->other), ['default_price' => '1000']);
        $foreign = Guest::acrossProperties()->findOrFail($this->book([$this->room($otherProduct, 2, 3)], [], $this->other)->primary_guest_id);
        $this->actingAs($this->owner)->getJson($this->api('/guests/'.$foreign->public_id))->assertNotFound();
        $this->actingAs($this->owner)->putJson($this->api('/guests/'.$foreign->public_id), ['first_name' => 'X'])->assertNotFound();
        $this->actingAs($this->owner)->postJson($this->api('/guests/'.$foreign->public_id.'/notes'), ['body' => 'x'])->assertNotFound();
        $this->actingAs($this->owner)->get($this->page('/guests'))->assertOk()->assertDontSee($foreign->public_id);
    }
}

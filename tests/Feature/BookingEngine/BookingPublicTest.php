<?php

namespace Tests\Feature\BookingEngine;

use App\Models\Reservation;
use App\Notifications\BookingReceivedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/** Public booking engine over HTTP: no login, property code only, separate from the PMS. */
class BookingPublicTest extends BookingEngineTestCase
{
    private function base(): string
    {
        return '/book/'.$this->property->code;
    }

    private function bookBody(array $extra = []): array
    {
        $search = $this->getJson($this->base().'/api/search?'.http_build_query($this->stayQuery()))->json();
        $room = collect($search['room_types'])->firstWhere('name', 'Deluxe Room');

        return array_merge($this->stayQuery(), [
            'room_type_id' => $room['id'], 'rate_plan_id' => $room['rates'][0]['rate_plan_id'], 'quoted_total' => $room['rates'][0]['grand_total'],
            'idempotency_key' => 'web-1', 'guest' => $this->guest(), 'accept_terms' => true,
        ], $extra);
    }

    public function test_pages_are_public_and_separate_from_the_pms(): void
    {
        $this->assertGuest();
        $html = $this->get($this->base())->assertOk()->assertSee($this->property->name)->getContent();
        $this->assertStringContainsString('layout-booking', $html);
        $this->assertStringNotContainsString('noindex', $html, 'hotel booking pages can be found by search engines');
        $this->assertStringNotContainsString('"reservations":{', $html, 'no PMS wording on the public page');
        $this->get('/book/P9999')->assertNotFound();
        $this->get('/book/'.strtolower($this->property->code))->assertOk();

        // The PMS still needs a login.
        $this->get('/p/'.$this->property->code.'/reservations')->assertRedirect('/login');

        $this->engine()->saveSettings($this->property, ['enabled' => false]);
        $this->get($this->base())->assertNotFound();
        $this->getJson($this->base().'/api/search?'.http_build_query($this->stayQuery()))->assertNotFound();
    }

    public function test_search_endpoint(): void
    {
        $res = $this->getJson($this->base().'/api/search?'.http_build_query($this->stayQuery()))->assertOk()
            ->assertJsonPath('nights', 2)->assertJsonPath('currency', 'INR')->assertJsonCount(2, 'room_types');
        $this->assertSame(26, strlen($res->json('room_types.0.id')), 'public ids only');
        $this->getJson($this->base().'/api/search?'.http_build_query($this->stayQuery(5, 5)))->assertStatus(422);
        $this->getJson($this->base().'/api/search?check_in=x')->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['check_in', 'check_out', 'adults']]]);
        $this->getJson($this->base().'/api/search?'.http_build_query($this->stayQuery(5, 7, ['promo_code' => 'NOPE'])))->assertOk()
            ->assertJsonPath('promo.status', 'unknown');
    }

    public function test_book_sends_the_confirmation_and_the_signed_page_shows_the_booking(): void
    {
        Notification::fake();
        $res = $this->postJson($this->base().'/api/book', $this->bookBody())->assertCreated()
            ->assertJsonPath('booking.status', 'confirmed')->assertJsonPath('payment', null);
        $r = Reservation::acrossProperties()->firstOrFail();
        $this->assertSame('booking_engine', DB::table('booking_sources')->where('id', $r->source_id)->value('code'));
        $this->assertSame('maya@example.com', $r->primaryGuest->email);
        Notification::assertSentOnDemand(BookingReceivedNotification::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'maya@example.com');

        $url = $res->json('confirmation_url');
        $this->get($url)->assertOk()->assertSee($r->booking_ref);
        $this->get(strtok($url, '?'))->assertForbidden();
        $this->get(strtok($url, '?').'?signature=forged')->assertForbidden();

        // It appears in the hotel's own reservation list (PMS) with the booking engine as source.
        $this->actingAs($this->owner)->getJson('/web-api/p/'.$this->property->code.'/reservations/'.$r->public_id)->assertOk()
            ->assertJsonPath('reservation.source.code', 'booking_engine');
    }

    public function test_book_validation_honeypot_terms_and_price_change(): void
    {
        $this->postJson($this->base().'/api/book', $this->bookBody(['accept_terms' => false]))->assertStatus(422)
            ->assertJsonPath('error.fields.accept_terms.0', __('booking.checkout.terms_required'));
        $this->postJson($this->base().'/api/book', $this->bookBody(['website' => 'http://spam']))->assertStatus(422);
        $this->postJson($this->base().'/api/book', $this->bookBody(['guest' => ['first_name' => 'A']]))->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['guest.last_name', 'guest.email', 'guest.phone']]]);
        $this->postJson($this->base().'/api/book', $this->bookBody(['quoted_total' => '1.00']))->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['quoted_total']]]);
        $this->assertSame(0, Reservation::acrossProperties()->count());
    }

    public function test_checkout_page_needs_an_available_selection(): void
    {
        $body = $this->bookBody();
        $q = $this->stayQuery() + ['room_type' => $body['room_type_id'], 'rate_plan' => $body['rate_plan_id']];
        $this->get($this->base().'/checkout?'.http_build_query($q))->assertOk()->assertSee('Deluxe Room');
        $this->get($this->base().'/checkout?'.http_build_query(['rate_plan' => str_repeat('A', 26)] + $q))->assertRedirect();
        $this->get($this->base().'/checkout')->assertRedirect();
    }

    public function test_expired_holds_are_released_and_paid_ones_confirmed(): void
    {
        DB::table('rate_plans')->where('id', $this->bar()->id)->update(['payment_type' => 'prepay_full']);
        $a = $this->engine()->book($this->property, $this->stayQuery(5, 7, ['room_type_id' => $this->deluxe->public_id, 'rate_plan_id' => $this->bar()->public_id, 'guest' => $this->guest()]))['reservation'];
        $b = $this->engine()->book($this->property, $this->stayQuery(5, 7, ['room_type_id' => $this->deluxe->public_id, 'rate_plan_id' => $this->bar()->public_id, 'guest' => $this->guest(['email' => 'b@example.com'])]))['reservation'];
        DB::table('reservations')->whereIn('id', [$a->id, $b->id])->update(['hold_expires_at' => now()->subMinute()]);
        // b's payment came in through the gateway webhook meanwhile.
        DB::table('payments')->insert(['public_id' => strtoupper((string) \Illuminate\Support\Str::ulid()), 'property_id' => $this->property->id, 'reservation_id' => $b->id,
            'folio_id' => DB::table('folios')->where('reservation_id', $b->id)->value('id'), 'kind' => 'payment', 'method' => 'gateway', 'gateway' => 'razorpay',
            'amount' => $b->grand_total, 'currency_code' => 'INR', 'status' => 'captured', 'created_at' => now(), 'updated_at' => now()]);

        $this->artisan('booking:expire-holds')->expectsOutput('confirmed=1 released=1')->assertSuccessful();
        $this->assertSame('cancelled', $a->fresh()->status);
        $this->assertSame('confirmed', $b->fresh()->status);
        $this->assertSame([1, 1], $this->sold($this->deluxe, 5, 7));
        $this->assertInventoryConsistent();
    }
}

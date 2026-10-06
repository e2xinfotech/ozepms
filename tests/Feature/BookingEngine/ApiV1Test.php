<?php

namespace Tests\Feature\BookingEngine;

use App\Domain\Api\ApiKeyService;
use App\Models\ApiKey;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\SubscriptionPlan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/** Versioned API /api/v1 with property API keys, and key management in Settings. */
class ApiV1Test extends BookingEngineTestCase
{
    private function key(array $abilities = ApiKey::ABILITIES, ?Property $property = null): string
    {
        return app(ApiKeyService::class)->create($property ?? $this->property, 'Website', $abilities)[1];
    }

    private function auth(string $key): array
    {
        return ['Authorization' => 'Bearer '.$key, 'Accept' => 'application/json'];
    }

    private function body(string $key, array $extra = []): array
    {
        $search = $this->getJson('/api/v1/availability?'.http_build_query($this->stayQuery()), $this->auth($key))->assertOk()->json('data');
        $room = collect($search['room_types'])->firstWhere('name', 'Deluxe Room');

        return array_merge($this->stayQuery(), [
            'room_type_id' => $room['id'], 'rate_plan_id' => $room['rates'][0]['rate_plan_id'], 'quoted_total' => $room['rates'][0]['grand_total'],
            'guest' => $this->guest(), 'external_ref' => 'WEB-77',
        ], $extra);
    }

    public function test_authentication_and_abilities(): void
    {
        $this->getJson('/api/v1/property')->assertUnauthorized()->assertJsonStructure(['error' => ['message']]);
        $this->getJson('/api/v1/property', $this->auth('ozk_aaaaaaaaaaaa_'.str_repeat('x', 40)))->assertUnauthorized();
        $this->getJson('/api/v1/property', $this->auth('nonsense'))->assertUnauthorized();

        $read = $this->key(['availability']);
        $this->getJson('/api/v1/property', $this->auth($read))->assertOk()->assertJsonPath('data.code', $this->property->code)->assertJsonStructure(['data' => ['room_types', 'booking_url']]);
        $this->getJson('/api/v1/property', ['X-Api-Key' => $read])->assertOk();
        $this->postJson('/api/v1/reservations', [], $this->auth($read))->assertForbidden();
        $this->getJson('/api/v1/reservations/XYZ', $this->auth($read))->assertForbidden();

        $k = ApiKey::acrossProperties()->latest('id')->first();
        $this->assertNotNull($k->last_used_at);
        $this->assertNotSame($read, $k->key_hash);
        $this->assertSame(hash('sha256', $read), $k->key_hash);
    }

    public function test_availability_applies_the_booking_rules(): void
    {
        $key = $this->key();
        $data = $this->getJson('/api/v1/availability?'.http_build_query($this->stayQuery()), $this->auth($key))->assertOk()->json('data');
        $this->assertSame(2, $data['nights']);
        $room = collect($data['room_types'])->firstWhere('name', 'Deluxe Room');
        $this->assertNotNull($room);
        $this->assertArrayHasKey('tax_lines', $room['rates'][0]);
        $this->assertArrayHasKey('refundable', $room['rates'][0]);

        $this->getJson('/api/v1/availability?'.http_build_query($this->stayQuery(7, 5)), $this->auth($key))->assertStatus(422)->assertJsonStructure(['error' => ['fields']]);
        $none = $this->getJson('/api/v1/availability?'.http_build_query($this->stayQuery(5, 7, ['adults' => 9])), $this->auth($key))->assertOk()->json('data.room_types');
        $this->assertSame([], $none);
    }

    public function test_create_and_read_a_reservation(): void
    {
        Notification::fake();
        $key = $this->key();
        $res = $this->postJson('/api/v1/reservations', $this->body($key), $this->auth($key) + ['Idempotency-Key' => 'api-1'])->assertCreated()
            ->assertJsonPath('data.status', 'confirmed')->assertJsonStructure(['data' => ['ref', 'confirmation_url', 'payment' => ['due_now']]]);
        $ref = $res->json('data.ref');
        $r = Reservation::query()->where('booking_ref', $ref)->firstOrFail();
        $this->assertSame('booking_engine', $r->source->code);

        // Same idempotency key: no second booking.
        $this->postJson('/api/v1/reservations', $this->body($key), $this->auth($key) + ['Idempotency-Key' => 'api-1'])->assertSuccessful();
        $this->assertSame(1, Reservation::query()->where('property_id', $this->property->id)->where('source_id', $r->source_id)->count());

        $this->getJson('/api/v1/reservations/'.$ref, $this->auth($key))->assertOk()->assertJsonPath('data.ref', $ref);
        $this->getJson('/api/v1/reservations/NOPE123', $this->auth($key))->assertNotFound();
        $this->postJson('/api/v1/reservations', $this->body($key, ['quoted_total' => '1.00']), $this->auth($key))->assertStatus(422);
        $this->postJson('/api/v1/reservations', $this->body($key, ['guest' => ['first_name' => 'A']]), $this->auth($key))->assertStatus(422);
    }

    public function test_keys_are_isolated_per_property_and_revocable(): void
    {
        Notification::fake();
        $key = $this->key();
        $ref = $this->postJson('/api/v1/reservations', $this->body($key), $this->auth($key))->assertCreated()->json('data.ref');

        $pro = SubscriptionPlan::query()->where('code', 'professional')->firstOrFail();
        DB::table('subscriptions')->where('property_id', $this->other->id)->update(['plan_id' => $pro->id]);
        $otherKey = $this->key(ApiKey::ABILITIES, $this->other);
        $this->getJson('/api/v1/property', $this->auth($otherKey))->assertOk()->assertJsonPath('data.code', $this->other->code);
        $this->getJson('/api/v1/reservations/'.$ref, $this->auth($otherKey))->assertNotFound();

        app(ApiKeyService::class)->revoke(ApiKey::acrossProperties()->where('property_id', $this->property->id)->firstOrFail());
        $this->getJson('/api/v1/property', $this->auth($key))->assertUnauthorized();
    }

    public function test_property_without_the_feature_or_inactive_is_refused(): void
    {
        $key = $this->key();
        $basic = SubscriptionPlan::query()->where('code', '!=', 'professional')->orderBy('id')->get()
            ->first(fn ($p) => ! (bool) (((array) ($p->features ?? []))['booking_engine'] ?? false));
        $this->assertNotNull($basic);
        if ($basic) {
            DB::table('subscriptions')->where('property_id', $this->property->id)->update(['plan_id' => $basic->id]);
            $this->getJson('/api/v1/property', $this->auth($key))->assertForbidden();
            $pro = SubscriptionPlan::query()->where('code', 'professional')->firstOrFail();
            DB::table('subscriptions')->where('property_id', $this->property->id)->update(['plan_id' => $pro->id]);
        }
        $this->getJson('/api/v1/property', $this->auth($key))->assertOk();
        DB::table('properties')->where('id', $this->property->id)->update(['status' => 'suspended']);
        $this->getJson('/api/v1/property', $this->auth($key))->assertForbidden();
    }

    public function test_key_management_in_settings(): void
    {
        $url = '/web-api/p/'.$this->property->code.'/settings/api-keys';
        $this->actingAs($this->owner)->getJson($url)->assertOk()->assertJsonPath('keys', []);
        $res = $this->actingAs($this->owner)->postJson($url, ['name' => 'Hotel website', 'abilities' => ['availability', 'reservations.create']])->assertCreated();
        $plain = $res->json('plain');
        $this->assertMatchesRegularExpression('/^ozk_[a-z0-9]{12}_[A-Za-z0-9]{40}$/', $plain);
        $this->actingAs($this->owner)->getJson($url)->assertOk()->assertJsonCount(1, 'keys')->assertJsonMissing(['plain' => $plain]);
        $this->assertStringNotContainsString($plain, json_encode($this->actingAs($this->owner)->getJson($url)->json()));

        $this->actingAs($this->owner)->postJson($url, ['name' => 'X', 'abilities' => []])->assertStatus(422);
        $this->actingAs($this->owner)->postJson($url, ['name' => 'X', 'abilities' => ['admin']])->assertStatus(422);
        $this->actingAs($this->actingMember('front_desk'))->postJson($url, ['name' => 'X', 'abilities' => ['availability']])->assertForbidden();
        $this->actingAs($this->otherOwner)->getJson($url)->assertNotFound();

        $id = $res->json('key.id');
        $this->actingAs($this->owner)->deleteJson($url.'/'.$id)->assertOk();
        $this->actingAs($this->owner)->getJson($url)->assertOk()->assertJsonCount(0, 'keys');
        auth()->logout();
        $this->getJson('/api/v1/property', $this->auth($plain))->assertUnauthorized();
        $this->assertDatabaseHas('audit_logs', ['action' => 'api_key.created', 'property_id' => $this->property->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'api_key.revoked', 'property_id' => $this->property->id]);
    }
}

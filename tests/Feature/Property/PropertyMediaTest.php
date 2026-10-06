<?php

namespace Tests\Feature\Property;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Property logo and cover photo upload. */
class PropertyMediaTest extends TestCase
{
    public function test_owner_uploads_and_removes_the_logo(): void
    {
        Storage::fake('public');
        [$property, $owner] = $this->createPropertyWithOwner();

        $res = $this->actingAs($owner)->post("/web-api/p/{$property->code}/settings/media/logo",
            ['image' => UploadedFile::fake()->image('my logo.png', 200, 200)], ['Accept' => 'application/json'])->assertOk();

        $path = $property->refresh()->logo_path;
        $this->assertNotNull($path);
        $this->assertStringStartsWith("properties/{$property->code}/logo-", $path);
        $this->assertStringNotContainsString('my logo', $path);
        Storage::disk('public')->assertExists($path);
        $this->assertNotNull($res->json('property.logo'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'property.media_updated', 'property_id' => $property->id]);

        $this->actingAs($owner)->deleteJson("/web-api/p/{$property->code}/settings/media/logo")->assertOk();
        $this->assertNull($property->refresh()->logo_path);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_only_images_are_accepted(): void
    {
        Storage::fake('public');
        [$property, $owner] = $this->createPropertyWithOwner();

        $this->actingAs($owner)->post("/web-api/p/{$property->code}/settings/media/cover",
            ['image' => UploadedFile::fake()->create('script.php', 10, 'application/x-php')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['image']]]);
        $this->actingAs($owner)->post("/web-api/p/{$property->code}/settings/media/banner",
            ['image' => UploadedFile::fake()->image('a.png', 200, 200)], ['Accept' => 'application/json'])->assertNotFound();
        $this->assertNull($property->refresh()->cover_image_path);
    }

    public function test_permissions_and_tenancy(): void
    {
        Storage::fake('public');
        [$property] = $this->createPropertyWithOwner();
        [, $otherOwner] = $this->createPropertyWithOwner();
        $frontDesk = $this->addMember($property, 'front_desk');
        $file = fn () => ['image' => UploadedFile::fake()->image('a.png', 200, 200)];

        $this->actingAs($frontDesk)->post("/web-api/p/{$property->code}/settings/media/logo", $file(), ['Accept' => 'application/json'])->assertForbidden();
        $this->actingAs($otherOwner)->post("/web-api/p/{$property->code}/settings/media/logo", $file(), ['Accept' => 'application/json'])->assertNotFound();

        $admin = $this->makePlatformAdmin();
        $this->actingAs($admin)->post("/web-api/admin/properties/{$property->code}/media/cover", $file(), ['Accept' => 'application/json'])->assertOk();
        $this->assertNotNull($property->refresh()->cover_image_path);
    }
}

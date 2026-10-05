<?php

namespace Tests\Feature\Accommodation;

use App\Models\TaxRule;

class TaxesTest extends AccommodationTestCase
{
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Tourism Fee', 'code' => 'tf', 'kind' => 'fee', 'calc_type' => 'fixed_per_night', 'rate' => 150,
            'apply_to' => ['room_charges'], 'description' => 'Municipal tourism fee.',
        ], $overrides);
    }

    private function rule(string $code, $property = null): TaxRule
    {
        return TaxRule::query()->where('property_id', ($property ?? $this->property)->id)->where('code', $code)->firstOrFail();
    }

    public function test_new_indian_property_gets_gst_slabs(): void
    {
        $this->assertSame(['GST-ROOM-0', 'GST-ROOM-18', 'GST-ROOM-5'], TaxRule::query()->where('property_id', $this->property->id)->orderBy('code')->pluck('code')->all());
    }

    public function test_list_page_renders_and_requires_permission(): void
    {
        $this->actingAs($this->owner)->get($this->page('/taxes'))->assertOk()
            ->assertSee($this->pageName('property/taxes/index'), false)->assertSee('GST-ROOM-5', false);
        $this->actingAs($this->owner)->get($this->page('/taxes?tab=fee'))->assertOk()->assertDontSee('"code":"GST-ROOM-5"', false);
        $this->actingAs($this->member('front_desk'))->get($this->page('/taxes'))->assertForbidden();
        $this->actingAs($this->member('accounts'))->get($this->page('/taxes'))->assertOk();
    }

    public function test_store_update_and_show(): void
    {
        $roomType = $this->makeRoomType([], 1);
        $id = $this->actingAs($this->owner)->postJson($this->api('/taxes'), $this->payload(['room_types' => [$roomType->public_id]]))
            ->assertCreated()->assertJsonPath('tax.code', 'TF')->assertJsonPath('tax.rate', '150.0000')
            ->assertJsonPath('tax.room_types', [$roomType->public_id])->json('tax.id');

        $this->actingAs($this->owner)->putJson($this->api('/taxes/'.$id), ['rate' => 175.5, 'apply_to' => ['room_charges', 'add_ons'], 'room_types' => []])
            ->assertOk()->assertJsonPath('tax.rate', '175.5000')->assertJsonPath('tax.apply_to', ['room_charges', 'add_ons'])->assertJsonPath('tax.room_types', []);

        $this->actingAs($this->owner)->getJson($this->api('/taxes/'.$id))->assertOk()->assertJsonPath('tax.name', 'Tourism Fee')->assertJsonStructure(['tax' => ['history']]);
    }

    public function test_store_validates_input(): void
    {
        $this->actingAs($this->owner)->postJson($this->api('/taxes'), $this->payload(['name' => '', 'apply_to' => []]))
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['name', 'apply_to']]]);
        $this->actingAs($this->owner)->postJson($this->api('/taxes'), $this->payload(['calc_type' => 'percent', 'rate' => 120]))
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['rate']]]);
        $this->actingAs($this->owner)->postJson($this->api('/taxes'), $this->payload(['code' => 'GST-ROOM-5']))
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['code']]]);
        $this->actingAs($this->owner)->postJson($this->api('/taxes'), $this->payload(['kind' => 'fee', 'component_mode' => 'gst_split']))
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['component_mode']]]);
        $this->actingAs($this->owner)->postJson($this->api('/taxes'), $this->payload(['slab_min' => 5000, 'slab_max' => 100]))
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['slab_max']]]);
    }

    public function test_writes_are_forbidden_without_permission(): void
    {
        $rule = $this->rule('GST-ROOM-5');
        $frontDesk = $this->member('front_desk');

        $this->actingAs($frontDesk)->postJson($this->api('/taxes'), $this->payload())->assertForbidden();
        $this->actingAs($frontDesk)->putJson($this->api('/taxes/'.$rule->public_id), ['rate' => 1])->assertForbidden();
        $this->actingAs($frontDesk)->deleteJson($this->api('/taxes/'.$rule->public_id))->assertForbidden();
    }

    public function test_rules_of_another_property_and_templates_are_not_found(): void
    {
        $foreign = $this->rule('GST-ROOM-5', $this->other);
        $template = TaxRule::query()->whereNull('property_id')->where('code', 'GST-ROOM-5')->firstOrFail();

        foreach ([$foreign, $template] as $rule) {
            $this->actingAs($this->owner)->getJson($this->api('/taxes/'.$rule->public_id))->assertNotFound();
            $this->actingAs($this->owner)->putJson($this->api('/taxes/'.$rule->public_id), ['rate' => 1])->assertNotFound();
            $this->actingAs($this->owner)->postJson($this->api('/taxes/'.$rule->public_id.'/status'), ['is_active' => false])->assertNotFound();
            $this->actingAs($this->owner)->deleteJson($this->api('/taxes/'.$rule->public_id))->assertNotFound();
        }
        $this->assertSame('5.0000', $foreign->fresh()->rate);
    }

    public function test_status_default_and_delete(): void
    {
        $rule = $this->rule('GST-ROOM-18');

        $this->actingAs($this->owner)->postJson($this->api('/taxes/'.$rule->public_id.'/status'), ['is_active' => false])->assertOk()->assertJsonPath('is_active', false);
        $this->actingAs($this->owner)->postJson($this->api('/taxes/'.$rule->public_id.'/default'), ['default' => true])->assertOk()->assertJsonPath('is_default_for_new_room_types', true);
        $this->actingAs($this->owner)->postJson($this->api('/taxes/'.$rule->public_id.'/default'), [])->assertStatus(422);
        $this->actingAs($this->owner)->deleteJson($this->api('/taxes/'.$rule->public_id))->assertOk();
        $this->assertNull(TaxRule::query()->find($rule->id));
    }

    public function test_copy_templates_is_idempotent(): void
    {
        TaxRule::query()->where('property_id', $this->property->id)->delete();

        $this->actingAs($this->owner)->postJson($this->api('/taxes/copy-templates'))->assertOk()->assertJsonPath('copied', 3);
        $this->actingAs($this->owner)->postJson($this->api('/taxes/copy-templates'))->assertOk()->assertJsonPath('copied', 0);
    }

    public function test_preview_applies_gst_slab(): void
    {
        $this->actingAs($this->owner)->postJson($this->api('/taxes/preview'), ['tariff' => 4500, 'nights' => 2])->assertOk()
            ->assertJsonPath('taxable', '9000.00')
            ->assertJsonPath('tax_total', '450.00')
            ->assertJsonPath('by_component.CGST', '225.00')
            ->assertJsonPath('by_component.SGST', '225.00');

        $this->actingAs($this->owner)->postJson($this->api('/taxes/preview'), ['tariff' => -1, 'nights' => 0])->assertStatus(422);
        $this->actingAs($this->member('front_desk'))->postJson($this->api('/taxes/preview'), ['tariff' => 100, 'nights' => 1])->assertForbidden();
    }
}

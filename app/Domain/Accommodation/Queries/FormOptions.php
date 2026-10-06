<?php

namespace App\Domain\Accommodation\Queries;

use App\Domain\Accommodation\PlanLimits;
use App\Models\Amenity;
use App\Models\BedType;
use App\Models\CancellationPolicy;
use App\Models\MealPlan;
use App\Models\PhysicalUnit;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Models\TaxRule;
use App\Models\UnitBlock;
use App\Support\PropertyContext;
use Illuminate\Support\Facades\DB;

/**
 * Option lists for the room type, PMS room, rate plan and tax forms of the current property.
 * Labels are translated here so pages only render them.
 */
class FormOptions
{
    public function __construct(
        private readonly PropertyContext $context,
        private readonly PlanLimits $limits,
    ) {}

    /** @return list<array{value: string, label: string}> */
    public function categories(): array
    {
        return array_map(fn (string $c) => ['value' => $c, 'label' => __('rooms.categories.'.$c)], RoomType::CATEGORIES);
    }

    /** @return list<array{value: string, label: string, sleeps: int}> */
    public function bedTypes(): array
    {
        return BedType::query()->orderBy('id')->get()
            ->map(fn (BedType $b) => ['value' => $b->code, 'label' => $b->label(), 'sleeps' => $b->sleeps])->all();
    }

    /** Active global + custom amenities, grouped by category on the page. */
    public function amenities(): array
    {
        return Amenity::query()->visibleToProperty($this->context->id())->where('is_active', true)
            ->orderBy('category')->orderBy('id')->get()
            ->map(fn (Amenity $a) => ['value' => $a->code, 'label' => $a->label(), 'category' => $a->category, 'icon' => $a->icon, 'custom' => $a->isCustom()])
            ->all();
    }

    public function amenityCategories(): array
    {
        return array_map(fn (string $c) => ['value' => $c, 'label' => __('amenities.categories.'.$c)], Amenity::CATEGORIES);
    }

    /** Room types for selects (id = public id). */
    public function roomTypes(bool $activeOnly = false): array
    {
        return RoomType::query()->when($activeOnly, fn ($q) => $q->where('is_active', true))
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'public_id', 'code', 'name', 'base_adults', 'max_adults', 'max_children', 'max_infants', 'max_occupancy', 'is_active'])
            ->map(fn (RoomType $r) => [
                'value' => $r->public_id, 'label' => $r->name.' ('.$r->code.')', 'code' => $r->code, 'name' => $r->name,
                'base_adults' => $r->base_adults, 'max_adults' => $r->max_adults, 'max_children' => $r->max_children,
                'max_infants' => $r->max_infants, 'is_active' => $r->is_active,
            ])->all();
    }

    /** Amenity codes of each room type, keyed by room type public id (new rooms start from these). */
    public function roomTypeAmenities(): array
    {
        return \Illuminate\Support\Facades\DB::table('room_types')
            ->join('room_type_amenities', 'room_type_amenities.room_type_id', '=', 'room_types.id')
            ->join('amenities', 'amenities.id', '=', 'room_type_amenities.amenity_id')
            ->where('room_types.property_id', $this->context->id())
            ->get(['room_types.public_id', 'amenities.code'])
            ->groupBy('public_id')
            ->map(fn ($rows) => $rows->pluck('code')->values()->all())
            ->all();
    }

    public function ratePlans(bool $activeOnly = false): array
    {
        return RatePlan::query()->with('mealPlan')->when($activeOnly, fn ($q) => $q->where('is_active', true))
            ->orderBy('sort_order')->orderBy('name')->get()
            ->map(fn (RatePlan $p) => [
                'value' => $p->public_id, 'label' => $p->name.' ('.$p->code.')', 'code' => $p->code, 'name' => $p->name,
                'meal_plan' => $p->mealPlan?->code, 'is_active' => $p->is_active, 'is_default' => $p->is_default,
            ])->all();
    }

    public function mealPlans(): array
    {
        return MealPlan::query()->visibleToProperty($this->context->id())->where('is_active', true)->orderBy('id')->get()
            ->map(fn (MealPlan $m) => [
                'value' => $m->code, 'label' => $m->label().' ('.$m->code.')', 'name' => $m->label(),
                'breakfast' => $m->includes_breakfast, 'lunch' => $m->includes_lunch, 'dinner' => $m->includes_dinner,
            ])->all();
    }

    public function cancellationPolicies(): array
    {
        return CancellationPolicy::query()->with('rules')->where('is_active', true)->orderBy('name')->get()
            ->map(fn (CancellationPolicy $p) => [
                'value' => $p->code, 'label' => $p->name, 'refundable' => $p->is_refundable, 'description' => $p->description,
                'rules' => $p->rules->map(fn ($r) => [
                    'applies_to' => $r->applies_to, 'hours_before_arrival' => $r->hours_before_arrival,
                    'charge_type' => $r->charge_type, 'charge_value' => $r->charge_value,
                ])->all(),
            ])->all();
    }

    public function ageBands(): array
    {
        return DB::table('property_age_bands')->where('property_id', $this->context->id())->orderBy('min_age')
            ->get(['code', 'min_age', 'max_age'])
            ->map(fn ($b) => ['value' => $b->code, 'label' => __('rates.age_bands.'.$b->code, ['min' => $b->min_age, 'max' => $b->max_age])])
            ->all();
    }

    /** Distinct floors of the property's rooms (Rooms filter). */
    public function floors(): array
    {
        return PhysicalUnit::query()->whereNotNull('floor')->distinct()->orderBy('floor')->pluck('floor')
            ->map(fn ($f) => ['value' => (string) $f, 'label' => (string) $f])->all();
    }

    public function enumOptions(string $group, array $values): array
    {
        return array_map(fn (string $v) => ['value' => $v, 'label' => __($group.'.'.$v)], $values);
    }

    public function blockTypes(): array
    {
        return $this->enumOptions('rooms.block_types', UnitBlock::TYPES);
    }

    public function paymentTypes(): array
    {
        return $this->enumOptions('rates.payment_types', RatePlan::PAYMENT_TYPES);
    }

    public function taxOptions(): array
    {
        return [
            'kinds' => $this->enumOptions('taxes.kinds', TaxRule::KINDS),
            'tax_types' => $this->enumOptions('taxes.tax_types', TaxRule::TAX_TYPES),
            'apply_to' => $this->enumOptions('taxes.apply_to', TaxRule::APPLY_TO),
            'methods' => $this->enumOptions('taxes.methods', ['percent', 'fixed']),
            'bases' => $this->enumOptions('taxes.bases', ['per_room_night', 'per_person_night', 'per_stay', 'per_booking']),
            'component_modes' => $this->enumOptions('taxes.component_modes', ['single', 'gst_split']),
            'room_types' => $this->roomTypes(),
            'rate_plans' => $this->ratePlans(),
        ];
    }

    /** Plan limits shown on the room type and room forms. */
    public function usage(): array
    {
        return $this->limits->usage($this->context->property());
    }
}

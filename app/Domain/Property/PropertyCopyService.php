<?php

namespace App\Domain\Property;

use App\Domain\Accommodation\Events\ProductChanged;
use App\Domain\Accommodation\Events\RoomTypeUnitsChanged;
use App\Domain\Audit\AuditLogger;
use App\Infrastructure\Database\Tx;
use App\Models\Property;
use App\Models\PropertyUser;
use App\Models\User;
use App\Support\PropertyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * "Copy Property": creates a new property with the source's settings and set-up —
 * languages, age bands, custom roles, amenities, meal plans, cancellation policies,
 * rate plans, room types (beds, amenities), room type × rate plan products with
 * occupancy pricing, taxes and fees, extra services and (optionally) the PMS rooms.
 *
 * Never copied: reservations, guests, folios, payments, invoices, inventory and rates
 * per date, photos, users other than the owner, audit history and counters.
 */
class PropertyCopyService
{
    /** Property columns that are not carried over to the copy. */
    private const SKIP_COLUMNS = [
        'id', 'code', 'slug', 'status', 'onboarding_step', 'created_by', 'created_at', 'updated_at', 'deleted_at',
        'business_date', 'ari_version', 'logo_path', 'cover_image_path',
    ];

    /** @var array<string, array<int, int>> old id => new id, per table */
    private array $map = [];

    public function __construct(
        private readonly PropertyService $properties,
        private readonly AuditLogger $audit,
        private readonly PropertyContext $context,
    ) {}

    /**
     * @param  array{name: string, include_rooms?: bool}  $options
     */
    public function copy(Property $source, array $options, User $by): Property
    {
        // The request runs in the source property; the new records belong to the copy, so the
        // tenant context is switched for the duration of the copy and restored afterwards.
        $previous = [$this->context->has() ? $this->context->property() : null, $this->context->membership(), $this->context->isSupportMode()];
        $this->context->clear();

        try {
            return $this->copyInContext($source, $options, $by);
        } finally {
            $previous[0] ? $this->context->set($previous[0], $previous[1], $previous[2]) : $this->context->clear();
        }
    }

    /** @param  array{name: string, include_rooms?: bool}  $options */
    private function copyInContext(Property $source, array $options, User $by): Property
    {
        return Tx::run(function () use ($source, $options, $by) {
            $this->map = [];

            $data = collect($source->getAttributes())->except(self::SKIP_COLUMNS)->all();
            $data['name'] = $options['name'];
            $data['status'] = 'onboarding';

            $owner = $this->ownerOf($source) ?? $by;
            $plan = $source->currentSubscription?->plan;

            $copy = $this->properties->create($data, $owner, $plan, $by, $source->id);
            $this->context->set($copy, null, true);
            $this->properties->syncLanguages($copy, $this->properties->languages($source));

            $this->copySetup($source, $copy, (bool) ($options['include_rooms'] ?? false));
            $this->keepAccess($source, $copy, $by, $owner);

            $this->audit->log('property.copied', $copy, ['after' => [
                'source' => $source->code,
                'include_rooms' => (bool) ($options['include_rooms'] ?? false),
                'rate_plans' => count($this->map['rate_plans'] ?? []),
                'room_types' => count($this->map['room_types'] ?? []),
            ]], $copy->id);

            return $copy->refresh();
        });
    }

    private function copySetup(Property $from, Property $to, bool $includeRooms): void
    {
        // Age bands replace the defaults created with the property.
        DB::table('property_age_bands')->where('property_id', $to->id)->delete();
        $this->rows('property_age_bands', $from, $to);
        $this->rows('property_settings', $from, $to, mapped: false);

        $this->rows('roles', $from, $to);
        foreach ($this->map['roles'] ?? [] as $old => $new) {
            DB::table('role_permissions')->insert(DB::table('role_permissions')->where('role_id', $old)->get()
                ->map(fn ($r) => ['role_id' => $new, 'permission_id' => $r->permission_id])->all());
        }

        $this->rows('amenities', $from, $to);
        $this->rows('property_amenities', $from, $to, ['amenity_id' => 'amenities'], mapped: false);
        $this->rows('meal_plans', $from, $to);
        $this->rows('booking_sources', $from, $to);
        $this->rows('cancellation_policies', $from, $to);
        $this->children('cancellation_policy_rules', 'policy_id', 'cancellation_policies');

        $this->rows('rate_plans', $from, $to, [
            'meal_plan_id' => 'meal_plans', 'cancellation_policy_id' => 'cancellation_policies',
        ], publicId: true, softDeletes: true);

        $this->rows('room_types', $from, $to, [], publicId: true, softDeletes: true);
        $this->children('room_type_beds', 'room_type_id', 'room_types', keyed: false);
        $this->children('room_type_amenities', 'room_type_id', 'room_types', ['amenity_id' => 'amenities'], keyed: false);

        $this->products($from, $to);
        $this->children('product_occupancy_rules', 'product_id', 'room_type_rate_plans', ['age_band_id' => 'property_age_bands']);

        $this->rows('tax_rules', $from, $to, [], publicId: true);
        $this->children('tax_rule_scopes', 'tax_rule_id', 'tax_rules', ['room_type_id' => 'room_types', 'rate_plan_id' => 'rate_plans']);
        $this->rows('services', $from, $to);

        $this->translations($from, $to);

        if ($includeRooms) {
            $this->rows('physical_units', $from, $to, ['room_type_id' => 'room_types'], publicId: true, softDeletes: true,
                overrides: ['housekeeping_status' => 'clean', 'last_cleaned_at' => null, 'notes' => null]);
            $this->children('physical_unit_amenities', 'unit_id', 'physical_units', ['amenity_id' => 'amenities'], keyed: false);
        }

        // Inventory and rate calendars are rebuilt from the copied rooms and products.
        foreach ($this->map['room_types'] ?? [] as $roomTypeId) {
            RoomTypeUnitsChanged::dispatch($to->id, $roomTypeId);
        }
        foreach ($this->map['room_type_rate_plans'] ?? [] as $productId) {
            ProductChanged::dispatch($to->id, $productId);
        }
    }

    /**
     * Copies the property's rows of $table to the new property.
     *
     * @param  array<string, string>  $remap  column => table whose id map applies
     * @param  array<int, string>  $except  columns left empty on the copy
     * @param  array<string, mixed>  $overrides  fixed values on the copy
     */
    private function rows(string $table, Property $from, Property $to, array $remap = [], bool $mapped = true,
        bool $publicId = false, bool $softDeletes = false, array $except = [], array $overrides = []): void
    {
        $query = DB::table($table)->where('property_id', $from->id);
        if ($softDeletes) {
            $query->whereNull('deleted_at');
        }
        if ($mapped) {
            $query->orderBy('id');
        }

        foreach ($query->get() as $row) {
            $values = (array) $row;
            $oldId = $values['id'] ?? null;
            unset($values['id']);
            $values['property_id'] = $to->id;
            foreach ($remap as $column => $mapTable) {
                $values[$column] = $this->remap($mapTable, $values[$column] ?? null);
            }
            foreach ($except as $column) {
                $values[$column] = null;
            }
            if ($publicId) {
                $values['public_id'] = (string) Str::ulid();
            }
            foreach (['created_at', 'updated_at'] as $ts) {
                if (array_key_exists($ts, $values)) {
                    $values[$ts] = now();
                }
            }
            $values = $overrides + $values;

            if ($mapped && $oldId !== null) {
                $this->map[$table][$oldId] = (int) DB::table($table)->insertGetId($values);
            } else {
                DB::table($table)->insertOrIgnore($values);
            }
        }
    }

    /** Products: a derived product needs its parent product first, so parents are copied before children. */
    private function products(Property $from, Property $to): void
    {
        $pending = DB::table('room_type_rate_plans')->where('property_id', $from->id)->orderBy('id')->get()->keyBy('id');
        while ($pending->isNotEmpty()) {
            $ready = $pending->filter(fn ($p) => $p->parent_product_id === null || isset($this->map['room_type_rate_plans'][$p->parent_product_id]));
            if ($ready->isEmpty()) {
                break; // parent outside the property: cannot be linked, so those products are not copied
            }
            foreach ($ready as $row) {
                $values = (array) $row;
                unset($values['id']);
                $values = [
                    'public_id' => (string) Str::ulid(),
                    'property_id' => $to->id,
                    'room_type_id' => $this->remap('room_types', $row->room_type_id),
                    'rate_plan_id' => $this->remap('rate_plans', $row->rate_plan_id),
                    'parent_product_id' => $row->parent_product_id === null ? null : $this->map['room_type_rate_plans'][$row->parent_product_id],
                    'created_at' => now(),
                    'updated_at' => now(),
                ] + $values;
                $this->map['room_type_rate_plans'][$row->id] = (int) DB::table('room_type_rate_plans')->insertGetId($values);
                $pending->forget($row->id);
            }
        }
    }

    /**
     * Copies child rows that hang off an already copied parent table.
     *
     * @param  array<string, string>  $remap
     */
    private function children(string $table, string $parentColumn, string $parentTable, array $remap = [], bool $keyed = true): void
    {
        $parents = $this->map[$parentTable] ?? [];
        if ($parents === []) {
            return;
        }

        foreach (DB::table($table)->whereIn($parentColumn, array_keys($parents))->get() as $row) {
            $values = (array) $row;
            $oldId = $values['id'] ?? null;
            unset($values['id']);
            $values[$parentColumn] = $parents[$values[$parentColumn]];
            foreach ($remap as $column => $mapTable) {
                $values[$column] = $this->remap($mapTable, $values[$column] ?? null);
            }

            if ($keyed && $oldId !== null) {
                $this->map[$table][$oldId] = (int) DB::table($table)->insertGetId($values);
            } else {
                DB::table($table)->insertOrIgnore($values);
            }
        }
    }

    /** Ids of property-owned rows are translated; shared reference rows keep their id. */
    private function remap(string $table, mixed $id): mixed
    {
        if ($id === null) {
            return null;
        }

        return $this->map[$table][$id] ?? $id;
    }

    /** Translated names and descriptions of the copied room types, rate plans and amenities. */
    private function translations(Property $from, Property $to): void
    {
        $types = ['room_type' => 'room_types', 'rate_plan' => 'rate_plans', 'amenity' => 'amenities', 'tax_rule' => 'tax_rules'];
        foreach (DB::table('content_translations')->where('property_id', $from->id)->get() as $row) {
            $mapTable = $types[$row->entity_type] ?? null;
            $newId = $mapTable ? ($this->map[$mapTable][$row->entity_id] ?? null) : null;
            if ($newId === null) {
                continue;
            }
            $values = (array) $row;
            unset($values['id']);
            DB::table('content_translations')->insertOrIgnore(['property_id' => $to->id, 'entity_id' => $newId] + $values);
        }
    }

    /** A team member who copies a property keeps the same role on the copy, so they can open it. */
    private function keepAccess(Property $source, Property $copy, User $by, User $owner): void
    {
        if ($by->is($owner)) {
            return;
        }
        $membership = PropertyUser::query()->withoutGlobalScope('property')
            ->where('property_id', $source->id)->where('user_id', $by->id)->where('status', 'active')->first();
        if (! $membership) {
            return;
        }
        $roleId = $this->map['roles'][$membership->role_id] ?? $membership->role_id;
        PropertyUser::query()->withoutGlobalScope('property')->create([
            'property_id' => $copy->id, 'user_id' => $by->id, 'role_id' => $roleId,
            'is_owner' => false, 'status' => 'active', 'invited_by' => $by->id, 'joined_at' => now(),
        ]);
    }

    private function ownerOf(Property $property): ?User
    {
        $membership = PropertyUser::query()->withoutGlobalScope('property')
            ->where('property_id', $property->id)->where('is_owner', true)->where('status', 'active')->first();

        return $membership ? User::query()->find($membership->user_id) : null;
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use App\Support\PropertyContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A tax, service charge or fee. Rows with property_id NULL are country templates;
 * a property uses its own rules and falls back to the templates only when it has none.
 * Not tenant-scoped by the global scope (templates must stay readable); property
 * code always goes through forProperty().
 */
class TaxRule extends Model
{
    use HasPublicId;

    public const KINDS = ['tax', 'service_charge', 'fee'];

    public const TAX_TYPES = ['gst', 'vat', 'sales', 'tourism', 'city', 'local', 'service_charge', 'other'];

    public const CALC_TYPES = ['percent', 'fixed_per_night', 'fixed_per_person_night', 'fixed_per_stay', 'fixed_per_booking'];

    public const APPLY_TO = ['room_charges', 'add_ons', 'fnb', 'events', 'beverage', 'liquor'];

    protected $table = 'tax_rules';

    protected $guarded = ['id'];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:4',
            'slab_min' => 'decimal:2',
            'slab_max' => 'decimal:2',
            'is_inclusive' => 'boolean',
            'is_compound' => 'boolean',
            'is_default_for_new_room_types' => 'boolean',
            'include_in_displayed_rate' => 'boolean',
            'effective_from' => 'immutable_date',
            'effective_to' => 'immutable_date',
            'is_active' => 'boolean',
            'priority' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(TaxCategory::class, 'tax_category_id');
    }

    public function scopes(): HasMany
    {
        return $this->hasMany(TaxRuleScope::class);
    }

    public function scopeForProperty(Builder $query, ?int $propertyId = null): Builder
    {
        return $query->where('property_id', $propertyId ?? app(PropertyContext::class)->id());
    }

    public function scopeTemplatesFor(Builder $query, string $countryIso2): Builder
    {
        return $query->whereNull('property_id')->where('country_iso2', $countryIso2);
    }

    /** @return list<string> */
    public function applyToList(): array
    {
        return array_values(array_filter(explode(',', (string) $this->apply_to)));
    }

    public function isFixed(): bool
    {
        return $this->calc_type !== 'percent';
    }
}

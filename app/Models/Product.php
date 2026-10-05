<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProperty;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A sellable combination of room type and rate plan (table room_type_rate_plans).
 * Manual products carry their own price; derived products follow a parent product
 * with a percent / fixed / per-person adjustment.
 */
class Product extends Model
{
    use BelongsToProperty, HasPublicId;

    public const ADJUST_TYPES = ['percent', 'fixed', 'fixed_per_person'];

    protected $table = 'room_type_rate_plans';

    protected $guarded = ['id'];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return [
            'adjust_value' => 'decimal:4',
            'inherit_restrictions' => 'boolean',
            'default_price' => 'decimal:2',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class)->withTrashed();
    }

    public function ratePlan(): BelongsTo
    {
        return $this->belongsTo(RatePlan::class)->withTrashed();
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_product_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_product_id');
    }

    public function occupancyRules(): HasMany
    {
        return $this->hasMany(ProductOccupancyRule::class, 'product_id');
    }

    public function isDerived(): bool
    {
        return $this->pricing_mode === 'derived';
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}

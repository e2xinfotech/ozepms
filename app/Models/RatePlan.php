<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProperty;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Commercial terms (meal plan, cancellation, payment, stay rules) independent of room types. */
class RatePlan extends Model
{
    use BelongsToProperty, HasPublicId, SoftDeletes;

    public const PAYMENT_TYPES = ['pay_at_property', 'prepay_full', 'deposit_percent', 'deposit_nights'];

    protected $table = 'rate_plans';

    protected $guarded = ['id'];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return [
            'deposit_value' => 'decimal:4',
            'sell_on_pms' => 'boolean',
            'sell_on_booking_engine' => 'boolean',
            'sell_on_channels' => 'boolean',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'default_min_los' => 'integer',
            'default_max_los' => 'integer',
            'min_advance_days' => 'integer',
            'max_advance_days' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function mealPlan(): BelongsTo
    {
        return $this->belongsTo(MealPlan::class);
    }

    public function cancellationPolicy(): BelongsTo
    {
        return $this->belongsTo(CancellationPolicy::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function label(): string
    {
        return $this->name.' ('.$this->code.')';
    }
}

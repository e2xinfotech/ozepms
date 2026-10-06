<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProperty;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Table: offers (Phase 5). Rules are evaluated by App\Domain\Offers\OfferService.
 * weekdays: bitmask Mon=1 … Sun=64 (127 = every day). promo_code NULL = automatic offer.
 */
class Offer extends Model
{
    use BelongsToProperty, HasPublicId, SoftDeletes;

    public const TYPES = ['room_discount', 'package', 'early_bird', 'last_minute', 'long_stay', 'other'];

    public const DISCOUNT_TYPES = ['percent', 'fixed_per_night', 'fixed_per_stay', 'free_nights'];

    public const ALL_DAYS = 127;

    protected $table = 'offers';

    protected $guarded = ['id'];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return [
            'discount_value' => 'decimal:4',
            'min_amount' => 'decimal:2',
            'booking_from' => 'date',
            'booking_to' => 'date',
            'stay_from' => 'date',
            'stay_to' => 'date',
            'is_stackable' => 'boolean',
            'on_pms' => 'boolean',
            'on_booking_engine' => 'boolean',
            'is_active' => 'boolean',
            'weekdays' => 'integer',
            'priority' => 'integer',
            'min_nights' => 'integer',
            'max_nights' => 'integer',
            'min_advance_days' => 'integer',
            'max_advance_days' => 'integer',
            'max_redemptions' => 'integer',
            'redemptions' => 'integer',
        ];
    }

    public function scopes(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(OfferScope::class, 'offer_id');
    }

    public function conditions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(OfferCondition::class, 'offer_id');
    }

    public function applications(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(OfferApplication::class, 'offer_id');
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProperty;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Sellable accommodation category (Deluxe Room, Suite …). Its inventory is the number
 * of active PMS rooms (physical_units); its prices live on products (room type ↔ rate plan).
 */
class RoomType extends Model
{
    use BelongsToProperty, HasPublicId, SoftDeletes;

    /** Categories offered in the room type form and list filter. */
    public const CATEGORIES = ['standard', 'deluxe', 'superior', 'executive', 'suite', 'family', 'villa', 'apartment', 'dormitory', 'other'];

    protected $table = 'room_types';

    protected $guarded = ['id'];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return [
            'size_value' => 'decimal:2',
            'is_active' => 'boolean',
            'extra_bed_allowed' => 'boolean',
            'base_adults' => 'integer',
            'max_adults' => 'integer',
            'max_children' => 'integer',
            'max_infants' => 'integer',
            'max_occupancy' => 'integer',
            'max_extra_beds' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function units(): HasMany
    {
        return $this->hasMany(PhysicalUnit::class)->orderBy('sort_order')->orderBy('name');
    }

    public function activeUnits(): HasMany
    {
        return $this->units()->where('is_active', true);
    }

    public function images(): HasMany
    {
        return $this->hasMany(RoomTypeImage::class)->orderBy('sort_order')->orderBy('id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class)->orderBy('sort_order');
    }

    public function amenities(): BelongsToMany
    {
        return $this->belongsToMany(Amenity::class, 'room_type_amenities');
    }

    public function beds(): BelongsToMany
    {
        return $this->belongsToMany(BedType::class, 'room_type_beds')->withPivot('quantity');
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

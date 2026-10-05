<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProperty;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A PMS room (101, Villa A …). Belongs to one room type. */
class PhysicalUnit extends Model
{
    use BelongsToProperty, HasPublicId, SoftDeletes;

    public const HOUSEKEEPING = ['clean', 'dirty', 'inspected'];

    protected $table = 'physical_units';

    protected $guarded = ['id'];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_cleaned_at' => 'datetime',
            'sort_order' => 'integer',
        ];
    }

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class)->withTrashed();
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(UnitBlock::class, 'unit_id');
    }

    public function amenities(): BelongsToMany
    {
        return $this->belongsToMany(Amenity::class, 'physical_unit_amenities', 'unit_id')->withPivot('mode');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}

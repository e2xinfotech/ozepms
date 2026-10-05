<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    protected $fillable = ['property_id', 'scope', 'code', 'name', 'description', 'color', 'is_system'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions');
    }

    /** System roles plus the custom roles of one property. */
    public function scopeAvailableTo(Builder $query, int $propertyId): Builder
    {
        return $query->where('scope', 'property')
            ->where(fn (Builder $q) => $q->whereNull('property_id')->orWhere('property_id', $propertyId));
    }
}

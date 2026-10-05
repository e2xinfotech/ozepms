<?php

namespace App\Models;

use App\Support\PropertyContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Global amenities (property_id NULL, translated through label_key) plus custom
 * amenities a property adds for itself. Not tenant-scoped by the global scope
 * because global rows must stay visible; use visibleToProperty() instead.
 */
class Amenity extends Model
{
    public const CATEGORIES = ['room', 'bathroom', 'media', 'kitchen', 'property', 'service', 'other'];

    protected $table = 'amenities';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /** Global amenities and the current property's custom ones. */
    public function scopeVisibleToProperty(Builder $query, ?int $propertyId = null): Builder
    {
        $propertyId ??= app(PropertyContext::class)->id();

        return $query->where(fn (Builder $q) => $q->whereNull('property_id')->orWhere('property_id', $propertyId));
    }

    public function isCustom(): bool
    {
        return $this->property_id !== null;
    }

    public function label(): string
    {
        return $this->label_key ? __($this->label_key) : $this->name;
    }
}

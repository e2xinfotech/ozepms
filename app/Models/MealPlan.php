<?php

namespace App\Models;

use App\Support\PropertyContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Global meal plans (RO, BB, HB …, with OpenTravel MPT codes) plus property custom ones. */
class MealPlan extends Model
{
    protected $table = 'meal_plans';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'includes_breakfast' => 'boolean',
            'includes_lunch' => 'boolean',
            'includes_dinner' => 'boolean',
            'is_all_inclusive' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function scopeVisibleToProperty(Builder $query, ?int $propertyId = null): Builder
    {
        $propertyId ??= app(PropertyContext::class)->id();

        return $query->where(fn (Builder $q) => $q->whereNull('property_id')->orWhere('property_id', $propertyId));
    }

    /** Global plans are translated by code; custom plans keep the name the property typed. */
    public function label(): string
    {
        if ($this->property_id === null) {
            $key = 'rates.meal_plans.'.$this->code;
            $text = __($key);

            return $text === $key ? $this->name : $text;
        }

        return $this->name;
    }
}

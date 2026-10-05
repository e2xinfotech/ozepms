<?php

namespace App\Models\Concerns;

use App\Models\Property;
use App\Support\PropertyContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Tenant scoping for every property-owned model.
 *
 * - Queries are limited to the property selected for the request.
 * - New records automatically receive that property's id.
 * - Creating a record without a selected property is refused.
 */
trait BelongsToProperty
{
    public static function bootBelongsToProperty(): void
    {
        static::addGlobalScope('property', function (Builder $builder) {
            $context = app(PropertyContext::class);
            if ($context->has()) {
                $builder->where($builder->qualifyColumn('property_id'), $context->id());
            }
        });

        static::creating(function ($model) {
            $context = app(PropertyContext::class);
            if (empty($model->property_id)) {
                if (! $context->has()) {
                    throw new LogicException(static::class.' cannot be created without a property.');
                }
                $model->property_id = $context->id();
            } elseif ($context->has() && (int) $model->property_id !== $context->id()) {
                throw new LogicException(static::class.' belongs to a different property.');
            }
        });
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /** Explicitly query across properties (platform features only). */
    public static function acrossProperties(): Builder
    {
        return static::withoutGlobalScope('property');
    }
}

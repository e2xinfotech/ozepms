<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProperty;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Guest-facing names/descriptions of room types, rate plans and amenities in other languages. */
class ContentTranslation extends Model
{
    use BelongsToProperty;

    public const ENTITY_TYPES = ['room_type', 'rate_plan', 'amenity'];

    protected $table = 'content_translations';

    public $timestamps = false;

    protected $guarded = ['id'];

    public function scopeFor(Builder $query, string $entityType, int $entityId): Builder
    {
        return $query->where('entity_type', $entityType)->where('entity_id', $entityId);
    }
}

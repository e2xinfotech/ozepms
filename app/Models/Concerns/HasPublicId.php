<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/**
 * Exposes a ULID instead of the numeric primary key in URLs and API responses.
 */
trait HasPublicId
{
    public static function bootHasPublicId(): void
    {
        static::creating(function ($model) {
            if (empty($model->public_id)) {
                $model->public_id = (string) Str::ulid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}

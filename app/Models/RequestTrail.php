<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RequestTrail extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'request_trail';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['route_params' => 'array', 'field_names' => 'array', 'created_at' => 'datetime'];
    }
}

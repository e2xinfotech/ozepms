<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProperty;
use Illuminate\Database\Eloquent\Model;

class PropertySetting extends Model
{
    use BelongsToProperty;

    public const CREATED_AT = null;

    public $incrementing = false;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    protected $fillable = ['property_id', 'key', 'value'];

    protected function casts(): array
    {
        return ['value' => 'json'];
    }
}

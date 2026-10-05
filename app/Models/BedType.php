<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Global bed types (king, queen, twin …). */
class BedType extends Model
{
    protected $table = 'bed_types';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['sleeps' => 'integer'];
    }

    public function label(): string
    {
        return __($this->label_key);
    }
}

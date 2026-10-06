<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProperty;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fixed price of a product on one date for a given number of adults (table ari_daily_occupancy);
 * replaces base price + adult occupancy rules for that occupancy.
 */
class AriDayOccupancy extends Model
{
    use BelongsToProperty;

    protected $table = 'ari_daily_occupancy';

    /** Composite primary key (product_id, stay_date, adults): write through the query builder, not save(). */
    protected $primaryKey = null;

    public $incrementing = false;

    public const CREATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'stay_date' => 'immutable_date',
            'adults' => 'integer',
            'price' => 'decimal:2',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}

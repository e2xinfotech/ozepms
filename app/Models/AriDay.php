<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProperty;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Price and restrictions of one product on one stay date (table ari_daily). price is NULL on
 * derived products (computed from the parent). Writes go through App\Domain\Inventory\AriService.
 */
class AriDay extends Model
{
    use BelongsToProperty;

    protected $table = 'ari_daily';

    /** Composite primary key (product_id, stay_date): write through the query builder, not save(). */
    protected $primaryKey = null;

    public $incrementing = false;

    public const CREATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'stay_date' => 'immutable_date',
            'price' => 'decimal:2',
            'min_los' => 'integer',
            'max_los' => 'integer',
            'min_los_arrival' => 'integer',
            'cutoff_days' => 'integer',
            'max_advance_days' => 'integer',
            'cta' => 'boolean',
            'ctd' => 'boolean',
            'stop_sell' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}

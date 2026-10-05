<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Price adjustment by occupancy for one product.
 *   adult, guest_count 1          → single occupancy adjustment
 *   adult, guest_count n > base   → charge for each adult from the n-th on (extra adult)
 *   child, guest_count n, band    → charge for the n-th child in that age band
 */
class ProductOccupancyRule extends Model
{
    public const GUEST_TYPES = ['adult', 'child', 'infant'];

    protected $table = 'product_occupancy_rules';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'adjust_value' => 'decimal:4',
            'guest_count' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}

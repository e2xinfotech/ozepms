<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** PMS product (room type × rate plan) ↔ rate on the channel, with an optional markup. */
class ChannelRateMapping extends Model
{
    public const MARKUPS = ['none', 'percent', 'fixed'];

    protected $table = 'channel_rate_plan_mappings';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['markup_value' => 'decimal:4'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withoutGlobalScopes();
    }
}

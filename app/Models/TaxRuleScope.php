<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Limits a tax rule to a room type and/or rate plan. No scope rows = applies to everything. */
class TaxRuleScope extends Model
{
    protected $table = 'tax_rule_scopes';

    public $timestamps = false;

    protected $guarded = ['id'];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(TaxRule::class, 'tax_rule_id');
    }

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class)->withTrashed();
    }

    public function ratePlan(): BelongsTo
    {
        return $this->belongsTo(RatePlan::class)->withTrashed();
    }
}

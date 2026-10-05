<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One window of a cancellation policy: when less than hours_before_arrival remain,
 * the charge applies (none, first night, N nights, percent, fixed amount, full stay).
 */
class CancellationPolicyRule extends Model
{
    public const CHARGE_TYPES = ['none', 'first_night', 'nights', 'percent', 'fixed', 'full'];

    protected $table = 'cancellation_policy_rules';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'charge_value' => 'decimal:4',
            'hours_before_arrival' => 'integer',
        ];
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(CancellationPolicy::class, 'policy_id');
    }
}

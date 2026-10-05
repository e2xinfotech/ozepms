<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Table: payment_gateway_events. Owned by Phase 4 (see docs/04-development-guide.md).
 * Relationships and behaviour are added by the owning module.
 */
class PaymentGatewayEvent extends Model
{
    protected $table = 'payment_gateway_events';

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'signature_valid' => 'boolean',
            'payload' => 'array',
            'processed_at' => 'date',
        ];
    }
}

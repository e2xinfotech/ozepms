<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Every webhook call received from a payment gateway, valid or not (platform-level table,
 * no tenant scope: the property is only known after the payload is read).
 * (gateway, event_id) is unique, so a replayed event is stored and processed once.
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
            'processed_at' => 'datetime',
        ];
    }
}

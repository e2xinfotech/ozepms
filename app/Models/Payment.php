<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProperty;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Money received for (kind payment) or paid back on (kind refund, parent_payment_id → payment)
 * a reservation. Only captured rows count towards the folio. Written by PaymentService.
 */
class Payment extends Model
{
    use BelongsToProperty, HasPublicId;

    public const METHODS = ['cash', 'card', 'upi', 'bank_transfer', 'gateway', 'other'];

    /** Methods staff can record by hand (gateway payments come from the gateway). */
    public const MANUAL_METHODS = ['cash', 'card', 'upi', 'bank_transfer', 'other'];

    protected $table = 'payments';

    protected $guarded = ['id'];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'refunded_amount' => 'decimal:2',
            'is_deposit' => 'boolean',
            'received_at' => 'datetime',
        ];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function folio(): BelongsTo
    {
        return $this->belongsTo(Folio::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'parent_payment_id');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Payment::class, 'parent_payment_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function isRefund(): bool
    {
        return $this->kind === 'refund';
    }
}

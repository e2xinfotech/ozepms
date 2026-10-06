<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProperty;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A GST tax invoice or credit note. Immutable once issued: everything printed is frozen in
 * `snapshot`; corrections are made with a credit note (invoice_type credit_note,
 * original_invoice_id → invoice). Written only by App\Domain\Billing\InvoiceService.
 */
class Invoice extends Model
{
    use BelongsToProperty, HasPublicId;

    protected $table = 'invoices';

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'immutable_date',
            'taxable_total' => 'decimal:2',
            'cgst_total' => 'decimal:2',
            'sgst_total' => 'decimal:2',
            'igst_total' => 'decimal:2',
            'other_tax_total' => 'decimal:2',
            'round_off' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'snapshot' => 'array',
            'cancelled_at' => 'datetime',
        ];
    }

    public function folio(): BelongsTo
    {
        return $this->belongsTo(Folio::class);
    }

    public function original(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'original_invoice_id');
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(Invoice::class, 'original_invoice_id');
    }

    public function isCreditNote(): bool
    {
        return $this->invoice_type === 'credit_note';
    }
}

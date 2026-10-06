<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProperty;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One charge on a folio. amount = taxable value, tax_amount = sum of its folio_line_taxes.
 * Lines are never deleted: a void marks the line (is_void) and posts a reversal line
 * (void_of_line_id → original) on the current business date, so revenue per day stays right.
 */
class FolioLine extends Model
{
    use BelongsToProperty, HasPublicId;

    public const TYPES = ['room', 'service', 'discount', 'adjustment', 'cancellation_fee'];

    protected $table = 'folio_lines';

    public const UPDATED_AT = null;

    protected $guarded = ['id', 'live_key'];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return [
            'business_date' => 'immutable_date',
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:4',
            'amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'is_void' => 'boolean',
        ];
    }

    public function folio(): BelongsTo
    {
        return $this->belongsTo(Folio::class);
    }

    public function taxes(): HasMany
    {
        return $this->hasMany(FolioLineTax::class)->orderBy('id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function isReversal(): bool
    {
        return $this->void_of_line_id !== null;
    }
}

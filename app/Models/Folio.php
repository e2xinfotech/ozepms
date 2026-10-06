<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProperty;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The bill of a reservation: charge lines (room nights, services, discounts, fees) and the
 * payments against it. Written only through App\Domain\Billing\FolioService.
 * charges_total / tax_total / payments_total are running totals of the lines and payments.
 */
class Folio extends Model
{
    use BelongsToProperty;

    protected $table = 'folios';

    protected $guarded = ['id'];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return [
            'charges_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'payments_total' => 'decimal:2',
            'closed_at' => 'datetime',
        ];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(FolioLine::class)->orderBy('business_date')->orderBy('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class)->orderBy('id');
    }

    public function billToGuest(): BelongsTo
    {
        return $this->belongsTo(Guest::class, 'bill_to_guest_id');
    }
}

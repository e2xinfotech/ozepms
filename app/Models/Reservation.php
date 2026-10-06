<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProperty;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A booking: one or more reservation rooms for one primary guest.
 * Written only through App\Domain\Reservations\ReservationService.
 */
class Reservation extends Model
{
    use BelongsToProperty, HasPublicId;

    /** Statuses that hold inventory (inventory_daily.sold) for their active nights. */
    public const HOLDING = ['hold', 'pending', 'confirmed', 'checked_in', 'checked_out'];

    /** Statuses that can still be changed (dates, rooms, guests). */
    public const OPEN = ['inquiry', 'hold', 'pending', 'confirmed', 'checked_in'];

    public const STATUSES = ['inquiry', 'hold', 'pending', 'confirmed', 'checked_in', 'checked_out', 'cancelled', 'no_show'];

    protected $table = 'reservations';

    protected $guarded = ['id'];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return [
            'check_in' => 'immutable_date',
            'check_out' => 'immutable_date',
            'room_total' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'extras_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'paid_total' => 'decimal:2',
            'balance_due' => 'decimal:2',
            'hold_expires_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'cancellation_fee' => 'decimal:2',
            'confirmed_at' => 'datetime',
        ];
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(ReservationRoom::class)->orderBy('sort_order')->orderBy('id');
    }

    public function primaryGuest(): BelongsTo
    {
        return $this->belongsTo(Guest::class, 'primary_guest_id');
    }

    public function guests(): BelongsToMany
    {
        return $this->belongsToMany(Guest::class, 'reservation_guests')->withPivot(['reservation_room_id', 'is_primary']);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(BookingSource::class, 'source_id');
    }

    public function history(): HasMany
    {
        return $this->hasMany(ReservationStatusHistory::class)->orderByDesc('created_at')->orderByDesc('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function holdsInventory(): bool
    {
        return in_array($this->status, self::HOLDING, true);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProperty;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One room of a reservation (room type × rate plan for a stay). The PMS room is in unit_nights. */
class ReservationRoom extends Model
{
    use BelongsToProperty;

    protected $table = 'reservation_rooms';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'check_in' => 'immutable_date',
            'check_out' => 'immutable_date',
            'child_ages' => 'array',
            'rate_snapshot' => 'array',
            'room_total' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'checked_in_at' => 'datetime',
            'checked_out_at' => 'datetime',
        ];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class)->withTrashed();
    }

    public function ratePlan(): BelongsTo
    {
        return $this->belongsTo(RatePlan::class)->withTrashed();
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function nights(): HasMany
    {
        return $this->hasMany(ReservationRoomNight::class)->orderBy('stay_date');
    }

    public function unitNights(): HasMany
    {
        return $this->hasMany(UnitNight::class)->orderBy('stay_date');
    }

    public function nightCount(): int
    {
        return (int) $this->check_in->diffInDays($this->check_out);
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProperty;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Guest profile of one property. Written through App\Domain\Guests\GuestService. */
class Guest extends Model
{
    use BelongsToProperty, HasPublicId;

    public const TYPES = ['individual', 'corporate', 'group', 'travel_agent'];

    public const ID_TYPES = ['passport', 'national_id', 'driving_licence', 'voter_id', 'other'];

    public const TITLES = ['mr', 'mrs', 'ms', 'miss', 'dr'];

    protected $table = 'guests';

    protected $guarded = ['id'];

    protected $hidden = ['id', 'id_number_enc', 'id_number_hash'];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'immutable_date',
            'id_expiry' => 'immutable_date',
            'is_vip' => 'boolean',
            'marketing_consent' => 'boolean',
            'anonymized_at' => 'datetime',
            'id_number_enc' => 'encrypted',
            'tags' => 'array',
        ];
    }

    public function reservations(): BelongsToMany
    {
        return $this->belongsToMany(Reservation::class, 'reservation_guests')->withPivot(['reservation_room_id', 'is_primary']);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(GuestDocument::class)->orderByDesc('created_at');
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.($this->last_name ?? ''));
    }

    public function number(): string
    {
        return 'G-'.str_pad((string) ($this->guest_no ?? 0), 6, '0', STR_PAD_LEFT);
    }

    /** "XXXX-1234": the ID number is shown masked; the full value only to guests.update holders. */
    public function maskedIdNumber(): ?string
    {
        $value = $this->id_number_enc;
        if ($value === null || $value === '') {
            return null;
        }

        return str_repeat('•', max(0, mb_strlen($value) - 4)).mb_substr($value, -4);
    }
}

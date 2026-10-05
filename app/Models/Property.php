<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Property extends Model
{
    use SoftDeletes;

    protected $guarded = ['id', 'code', 'ari_version', 'created_by'];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'business_date' => 'date',
            'star_rating' => 'integer',
            'week_start' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(PropertyType::class, 'property_type_id');
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'country_iso2', 'iso2');
    }

    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(PropertyUser::class)->withoutGlobalScope('property');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function currentSubscription(): HasOne
    {
        return $this->hasOne(Subscription::class)
            ->whereNot('status', 'cancelled')
            ->latestOfMany('ends_on');
    }

    public function owner(): HasOne
    {
        return $this->hasOne(PropertyUser::class)->withoutGlobalScope('property')->where('is_owner', true);
    }

    public function locationLabel(): string
    {
        return collect([$this->city, $this->country?->name])->filter()->implode(', ');
    }
}

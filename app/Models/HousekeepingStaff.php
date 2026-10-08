<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProperty;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A person who cleans rooms; receives the cleaning e-mails for the rooms assigned to them. */
class HousekeepingStaff extends Model
{
    use BelongsToProperty, HasPublicId, SoftDeletes;

    protected $table = 'housekeeping_staff';

    protected $guarded = ['id'];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(PhysicalUnit::class, 'housekeeping_staff_id');
    }
}

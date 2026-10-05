<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProperty;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PropertyUser extends Model
{
    use BelongsToProperty;

    protected $fillable = ['property_id', 'user_id', 'role_id', 'is_owner', 'status', 'invited_by', 'joined_at'];

    protected function casts(): array
    {
        return ['is_owner' => 'boolean', 'joined_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}

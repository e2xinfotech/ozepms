<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApprovalRequest extends Model
{
    use HasPublicId;

    public const UPDATED_AT = null;

    public const CREATED_AT = 'requested_at';

    public const TYPES = ['property_registration', 'plan', 'channel_connection'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['details' => 'array', 'requested_at' => 'datetime', 'decided_at' => 'datetime'];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}

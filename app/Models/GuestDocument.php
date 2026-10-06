<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProperty;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Uploaded guest document (ID scan, registration card); the file is on the private disk. */
class GuestDocument extends Model
{
    use BelongsToProperty, HasPublicId;

    public const TYPES = ['id_front', 'id_back', 'passport', 'visa', 'registration_card', 'other'];

    public const UPDATED_AT = null;

    protected $table = 'guest_documents';

    protected $guarded = ['id'];

    protected $hidden = ['id', 'path'];

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProperty;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An extra the property sells (extra bed, airport pickup, laundry…), posted to folios. */
class Service extends Model
{
    use BelongsToProperty, HasPublicId;

    public const POSTING_RULES = ['once', 'per_night', 'per_person', 'per_person_night'];

    protected $table = 'services';

    protected $guarded = ['id'];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function taxCategory(): BelongsTo
    {
        return $this->belongsTo(TaxCategory::class);
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProperty;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CancellationPolicy extends Model
{
    use BelongsToProperty;

    protected $table = 'cancellation_policies';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_refundable' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function rules(): HasMany
    {
        return $this->hasMany(CancellationPolicyRule::class, 'policy_id')
            ->orderBy('applies_to')->orderByDesc('hours_before_arrival');
    }

    public function ratePlans(): HasMany
    {
        return $this->hasMany(RatePlan::class);
    }
}

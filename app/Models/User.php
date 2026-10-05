<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasPublicId, Notifiable;

    protected $fillable = [
        'name', 'job_title', 'email', 'phone_e164', 'password', 'locale', 'status', 'is_platform_user',
    ];

    protected $hidden = [
        'id', 'password', 'remember_token', 'two_factor_secret', 'two_factor_recovery', 'last_login_ip',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_platform_user' => 'boolean',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'locked_until' => 'datetime',
            'password_changed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'email_verified_at' => 'datetime',
        ];
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new \App\Notifications\PasswordLinkNotification($token, $this->status === 'invited'));
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(PropertyUser::class);
    }

    public function platformRoles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'platform_user_roles');
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null && $this->two_factor_secret !== null;
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['active', 'invited'], true);
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    public function initials(): string
    {
        $parts = preg_split('/\s+/', trim($this->name)) ?: [];
        $first = mb_substr($parts[0] ?? '', 0, 1);
        $last = count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '';

        return mb_strtoupper($first.$last);
    }
}

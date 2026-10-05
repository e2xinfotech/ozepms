<?php

namespace App\Domain\Access;

use App\Models\Permission;
use App\Models\PropertyUser;
use App\Models\User;
use App\Support\PropertyContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Answers "may this user do X?" for platform and property permissions.
 * Results are cached per request; role permission sets are cached in the
 * application cache and cleared whenever a role changes.
 */
class AccessService
{
    /** @var array<string, array<int, string>> */
    private array $memo = [];

    public function __construct(private readonly PropertyContext $context) {}

    public function allows(User $user, string $permission): bool
    {
        if (str_starts_with($permission, 'platform.')) {
            return in_array($permission, $this->platformPermissions($user), true);
        }

        if (! $this->context->has()) {
            return false;
        }

        return in_array($permission, $this->propertyPermissions($user), true);
    }

    /** @return array<int, string> */
    public function platformPermissions(User $user): array
    {
        if (! $user->is_platform_user || $user->status !== 'active') {
            return [];
        }

        return $this->memo['platform:'.$user->id] ??= DB::table('platform_user_roles')
            ->join('role_permissions', 'role_permissions.role_id', '=', 'platform_user_roles.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('platform_user_roles.user_id', $user->id)
            ->distinct()
            ->pluck('permissions.key')
            ->all();
    }

    /** @return array<int, string> Permissions in the property selected for this request. */
    public function propertyPermissions(User $user): array
    {
        $key = 'property:'.$user->id.':'.$this->context->id();

        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        // E2X staff viewing a property they are not a member of (support mode).
        if ($this->context->isSupportMode()) {
            return $this->memo[$key] = in_array('platform.properties.manage', $this->platformPermissions($user), true)
                ? $this->allPropertyPermissions()
                : [];
        }

        $membership = $this->context->membership();
        if (! $membership instanceof PropertyUser || $membership->status !== 'active') {
            return $this->memo[$key] = [];
        }

        return $this->memo[$key] = $this->rolePermissions($membership->role_id);
    }

    /** @return array<int, string> */
    public function rolePermissions(int $roleId): array
    {
        return Cache::rememberForever("role_permissions:$roleId", fn () => DB::table('role_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('role_permissions.role_id', $roleId)
            ->pluck('permissions.key')
            ->all());
    }

    public function forgetRole(int $roleId): void
    {
        Cache::forget("role_permissions:$roleId");
        $this->memo = [];
    }

    /** @return array<int, string> */
    public function allPropertyPermissions(): array
    {
        return Cache::rememberForever('permissions:property', fn () => Permission::query()
            ->where('scope', 'property')->pluck('key')->all());
    }
}

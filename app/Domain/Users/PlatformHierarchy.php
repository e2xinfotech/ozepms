<?php

namespace App\Domain\Users;

use App\Domain\Access\AccessService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Who may change whom on the platform.
 *
 * Super Admin > Admin > IT Support > property users. A person can only manage accounts below their own
 * level. Super Admin accounts are created from the server console and are never changed in the screens.
 */
class PlatformHierarchy
{
    public const SUPER_ADMIN = 'super_admin';

    public const ADMIN = 'admin';

    private const LEVELS = ['super_admin' => 3, 'admin' => 2, 'it_support' => 1];

    /** Platform roles an actor may hand out in the screens. */
    private const ASSIGNABLE = [3 => ['admin', 'it_support'], 2 => ['it_support']];

    public function __construct(private readonly AccessService $access) {}

    /** 3 Super Admin, 2 Admin, 1 IT Support, 0 everybody else. */
    public function level(User $user): int
    {
        if (! $user->is_platform_user) {
            return 0;
        }
        $codes = $user->relationLoaded('platformRoles')
            ? $user->platformRoles->pluck('code')->all()
            : DB::table('platform_user_roles')->join('roles', 'roles.id', '=', 'platform_user_roles.role_id')
                ->where('platform_user_roles.user_id', $user->id)->pluck('roles.code')->all();

        return (int) max(array_map(fn ($c) => self::LEVELS[$c] ?? 0, $codes) ?: [0]);
    }

    public function isSuperAdmin(User $user): bool
    {
        return $this->level($user) === 3;
    }

    /**
     * Accounts the viewer must not see at all: Super Admins are invisible below the Super Admin level
     * (users list, details, audit and activity rows).
     *
     * @return array<int, int>
     */
    public function hiddenUserIds(?User $viewer): array
    {
        if ($viewer !== null && $this->isSuperAdmin($viewer)) {
            return [];
        }

        return DB::table('platform_user_roles')->join('roles', 'roles.id', '=', 'platform_user_roles.role_id')
            ->where('roles.code', self::SUPER_ADMIN)->pluck('platform_user_roles.user_id')->map(fn ($id) => (int) $id)->all();
    }

    /** @return array<int, string> */
    public function assignableRoles(User $actor): array
    {
        return self::ASSIGNABLE[$this->level($actor)] ?? [];
    }

    /** May the actor edit, disable or reset the password of the target? Property users are open to any user manager. */
    public function canManage(User $actor, User $target): bool
    {
        if ($actor->id === $target->id) {
            return true;
        }
        $targetLevel = $this->level($target);
        if ($targetLevel === 0) {
            return true;
        }

        return $targetLevel < $this->level($actor) && $targetLevel < 3
            && ($targetLevel < 2 || $this->access->allows($actor, 'platform.admins.manage'));
    }

    public function assertCanManage(User $actor, User $target): void
    {
        if (! $this->canManage($actor, $target)) {
            throw ValidationException::withMessages(['user' => __('users.cannot_manage_level')]);
        }
    }

    /** @param  array<int, string>  $roleCodes */
    public function assertCanAssign(User $actor, array $roleCodes): void
    {
        if (array_diff($roleCodes, $this->assignableRoles($actor)) !== []) {
            throw ValidationException::withMessages(['roles' => __('users.cannot_assign_role')]);
        }
    }

    /** The last active Super Admin can neither be disabled nor lose the role. */
    public function assertNotLastSuperAdmin(User $target): void
    {
        if ($this->level($target) !== 3) {
            return;
        }
        $others = DB::table('platform_user_roles')
            ->join('roles', 'roles.id', '=', 'platform_user_roles.role_id')
            ->join('users', 'users.id', '=', 'platform_user_roles.user_id')
            ->where('roles.code', self::SUPER_ADMIN)->where('users.status', 'active')
            ->where('users.id', '!=', $target->id)->exists();
        if (! $others) {
            throw ValidationException::withMessages(['user' => __('users.last_super_admin')]);
        }
    }
}

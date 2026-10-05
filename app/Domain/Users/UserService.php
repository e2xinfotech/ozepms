<?php

namespace App\Domain\Users;

use App\Domain\Access\AccessService;
use App\Domain\Audit\AuditLogger;
use App\Infrastructure\Database\Tx;
use App\Models\Property;
use App\Models\PropertyUser;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UserService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AccessService $access,
    ) {}

    /**
     * Finds the user by e-mail or creates an invited account, then sends a
     * "set your password" link. Existing users keep their password.
     *
     * @param  array{name: string, email: string, job_title?: ?string, phone_e164?: ?string, locale?: ?string}  $data
     */
    public function findOrInvite(array $data, bool $platformUser = false): User
    {
        $email = Str::lower(trim($data['email']));
        $user = User::query()->where('email', $email)->first();

        if ($user) {
            return $user;
        }

        $user = User::query()->create([
            'name' => $data['name'],
            'email' => $email,
            'job_title' => $data['job_title'] ?? null,
            'phone_e164' => $data['phone_e164'] ?? null,
            'locale' => $data['locale'] ?? config('ozepms.locales.default'),
            'password' => Str::password(40),
            'status' => 'invited',
            'is_platform_user' => $platformUser,
        ]);

        $this->audit->log('user.created', $user, ['after' => $user->only(['name', 'email', 'job_title'])]);
        $this->sendPasswordLink($user);

        return $user;
    }

    /** Adds a user to the property selected in the request context. */
    public function addToProperty(Property $property, array $data, Role $role, User $by): PropertyUser
    {
        $this->assertRoleUsable($role, $property);

        return Tx::run(function () use ($property, $data, $role, $by) {
            $user = $this->findOrInvite($data);

            $exists = PropertyUser::query()->withoutGlobalScope('property')
                ->where('property_id', $property->id)->where('user_id', $user->id)->exists();
            if ($exists) {
                throw ValidationException::withMessages(['email' => __('users.already_member')]);
            }

            $membership = PropertyUser::query()->withoutGlobalScope('property')->create([
                'property_id' => $property->id,
                'user_id' => $user->id,
                'role_id' => $role->id,
                'status' => 'active',
                'invited_by' => $by->id,
                'joined_at' => now(),
            ]);

            $this->audit->log('property_user.added', $membership, ['after' => ['user' => $user->email, 'role' => $role->code]], $property->id);

            return $membership;
        });
    }

    public function updateMembership(PropertyUser $membership, array $userData, ?Role $role, ?string $status, ?User $by = null): PropertyUser
    {
        if ($by && $membership->user_id === $by->id && $status && $status !== $membership->status) {
            throw ValidationException::withMessages(['status' => __('users.cannot_change_self')]);
        }

        return Tx::run(function () use ($membership, $userData, $role, $status) {
            $user = $membership->user;
            $user->fill(array_filter($userData, fn ($v) => $v !== null));
            if ($user->isDirty()) {
                $diff = $this->audit->diff($user);
                $user->save();
                $this->audit->log('user.updated', $user, $diff, $membership->property_id);
            }

            if ($role && $role->id !== $membership->role_id) {
                $this->assertRoleUsable($role, $membership->property);
                if ($membership->is_owner) {
                    throw ValidationException::withMessages(['role' => __('users.owner_role_fixed')]);
                }
                $before = $membership->role?->code;
                $membership->role_id = $role->id;
                $this->audit->log('property_user.role_changed', $membership, ['before' => ['role' => $before], 'after' => ['role' => $role->code]], $membership->property_id);
            }

            if ($status && $status !== $membership->status) {
                if ($membership->is_owner && $status !== 'active') {
                    throw ValidationException::withMessages(['status' => __('users.owner_cannot_disable')]);
                }
                $this->audit->log('property_user.status_changed', $membership, ['before' => ['status' => $membership->status], 'after' => ['status' => $status]], $membership->property_id);
                $membership->status = $status;
            }

            $membership->save();

            return $membership->refresh();
        });
    }

    /** Removes access to one property. The account itself and its history remain. */
    public function removeFromProperty(PropertyUser $membership, ?User $by = null): void
    {
        if ($by && $membership->user_id === $by->id) {
            throw ValidationException::withMessages(['user' => __('users.cannot_remove_self')]);
        }
        if ($membership->is_owner) {
            throw ValidationException::withMessages(['user' => __('users.owner_cannot_remove')]);
        }
        $this->audit->log('property_user.removed', $membership, ['before' => ['user_id' => $membership->user_id, 'role_id' => $membership->role_id]], $membership->property_id);
        $membership->delete();
    }

    public function setAccountStatus(User $user, string $status, User $by): void
    {
        if ($user->id === $by->id) {
            throw ValidationException::withMessages(['status' => __('users.cannot_change_self')]);
        }
        $before = $user->status;
        $user->forceFill(['status' => $status])->save();
        if ($status === 'disabled') {
            DB::table('sessions')->where('user_id', $user->id)->delete();
        }
        $this->audit->log('user.status_changed', $user, ['before' => ['status' => $before], 'after' => ['status' => $status]]);
    }

    public function sendPasswordLink(User $user): void
    {
        Password::broker()->sendResetLink(['email' => $user->email]);
        $this->audit->log('user.password_link_sent', $user);
    }

    /** @param  array<int, int>  $roleIds */
    public function syncPlatformRoles(User $user, array $roleIds): void
    {
        $user->platformRoles()->sync($roleIds);
        $user->forceFill(['is_platform_user' => count($roleIds) > 0])->save();
        $this->audit->log('user.platform_roles_changed', $user, ['after' => ['roles' => $roleIds]]);
    }

    /**
     * Creates (or promotes) an E2X platform user with the given platform roles.
     *
     * @param  array{name: string, email: string, job_title?: ?string, phone_e164?: ?string, locale?: ?string}  $data
     * @param  array<int, string>  $roleCodes
     */
    public function createPlatformUser(array $data, array $roleCodes): User
    {
        return Tx::run(function () use ($data, $roleCodes) {
            $user = $this->findOrInvite($data, true);
            $this->syncPlatformRoles($user, $this->platformRoleIds($roleCodes));

            return $user;
        });
    }

    /**
     * @param  array{name?: ?string, job_title?: ?string, phone_e164?: ?string, locale?: ?string}  $data
     * @param  array<int, string>|null  $roleCodes  null leaves the roles unchanged
     */
    public function updatePlatformUser(User $user, array $data, ?array $roleCodes, User $by): User
    {
        return Tx::run(function () use ($user, $data, $roleCodes, $by) {
            $this->updateProfile($user, $data);

            if ($roleCodes !== null) {
                $ids = $this->platformRoleIds($roleCodes);
                if ($user->id === $by->id && $ids === []) {
                    throw ValidationException::withMessages(['roles' => __('users.cannot_change_self')]);
                }
                $this->syncPlatformRoles($user, $ids);
            }

            return $user->refresh();
        });
    }

    /**
     * Name, contact details and language of an account.
     *
     * @param  array{name?: ?string, job_title?: ?string, phone_e164?: ?string, locale?: ?string}  $data
     */
    public function updateProfile(User $user, array $data): User
    {
        $user->fill(array_intersect_key($data, array_flip(['name', 'job_title', 'phone_e164', 'locale'])));
        if ($user->isDirty()) {
            $diff = $this->audit->diff($user);
            $user->save();
            $this->audit->log('user.updated', $user, $diff);
        }

        return $user;
    }

    /**
     * @param  array<int, string>  $codes
     * @return array<int, int>
     */
    private function platformRoleIds(array $codes): array
    {
        return Role::query()->whereNull('property_id')->where('scope', 'platform')
            ->whereIn('code', $codes)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function assertRoleUsable(Role $role, Property $property): void
    {
        if ($role->scope !== 'property' || ($role->property_id !== null && $role->property_id !== $property->id)) {
            throw ValidationException::withMessages(['role' => __('validation.exists', ['attribute' => 'role'])]);
        }
        if ($role->code === 'owner') {
            throw ValidationException::withMessages(['role' => __('users.owner_role_reserved')]);
        }
    }
}

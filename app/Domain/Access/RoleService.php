<?php

namespace App\Domain\Access;

use App\Domain\Audit\AuditLogger;
use App\Infrastructure\Database\Tx;
use App\Models\Permission;
use App\Models\Property;
use App\Models\Role;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Custom roles per property. System roles are read-only for tenants.
 */
class RoleService
{
    public function __construct(private readonly AuditLogger $audit, private readonly AccessService $access) {}

    /** @param  array<int, string>  $permissionKeys */
    public function create(Property $property, string $name, ?string $description, string $color, array $permissionKeys): Role
    {
        return Tx::run(function () use ($property, $name, $description, $color, $permissionKeys) {
            $role = Role::query()->create([
                'property_id' => $property->id,
                'scope' => 'property',
                'code' => $this->uniqueCode($property, $name),
                'name' => $name,
                'description' => $description,
                'color' => $color,
                'is_system' => false,
            ]);
            $this->syncPermissions($role, $permissionKeys);
            $this->audit->log('role.created', $role, ['after' => ['name' => $name, 'permissions' => $permissionKeys]], $property->id);

            return $role;
        });
    }

    /** @param  array<int, string>  $permissionKeys */
    public function update(Role $role, string $name, ?string $description, string $color, array $permissionKeys): Role
    {
        if ($role->is_system) {
            throw ValidationException::withMessages(['role' => __('roles.system_read_only')]);
        }

        return Tx::run(function () use ($role, $name, $description, $color, $permissionKeys) {
            $before = ['name' => $role->name, 'permissions' => $role->permissions()->pluck('key')->all()];
            $role->update(['name' => $name, 'description' => $description, 'color' => $color]);
            $this->syncPermissions($role, $permissionKeys);
            $this->audit->log('role.updated', $role, ['before' => $before, 'after' => ['name' => $name, 'permissions' => $permissionKeys]], $role->property_id);

            return $role;
        });
    }

    public function delete(Role $role): void
    {
        if ($role->is_system) {
            throw ValidationException::withMessages(['role' => __('roles.system_read_only')]);
        }
        if (\App\Models\PropertyUser::query()->withoutGlobalScope('property')->where('role_id', $role->id)->exists()) {
            throw ValidationException::withMessages(['role' => __('roles.in_use')]);
        }
        $this->audit->log('role.deleted', $role, ['before' => ['name' => $role->name]], $role->property_id);
        $role->delete();
        $this->access->forgetRole($role->id);
    }

    /** @param  array<int, string>  $keys */
    private function syncPermissions(Role $role, array $keys): void
    {
        $ids = Permission::query()->where('scope', $role->scope)->whereIn('key', $keys)->pluck('id')->all();
        $role->permissions()->sync($ids);
        $this->access->forgetRole($role->id);
    }

    private function uniqueCode(Property $property, string $name): string
    {
        $base = Str::limit(Str::snake(Str::ascii($name)), 30, '') ?: 'role';
        $code = $base;
        $i = 2;
        while (Role::query()->where('property_id', $property->id)->where('code', $code)->exists()
            || Role::query()->whereNull('property_id')->where('code', $code)->exists()) {
            $code = $base.'_'.$i++;
        }

        return $code;
    }
}

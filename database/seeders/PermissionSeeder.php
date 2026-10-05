<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;

/**
 * Syncs config/permissions.php into the database: permissions and system roles.
 * Custom roles created by properties are never touched.
 */
class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $catalogue = config('permissions');

        foreach (['platform', 'property'] as $scope) {
            foreach ($catalogue[$scope] as $module => $keys) {
                foreach ($keys as $key) {
                    Permission::query()->updateOrCreate(['key' => $key], ['scope' => $scope, 'module' => $module]);
                }
            }
        }

        foreach ($catalogue['roles'] as $scope => $roles) {
            $all = Permission::query()->where('scope', $scope)->pluck('id', 'key');

            foreach ($roles as $code => $definition) {
                $role = Role::query()->updateOrCreate(
                    ['property_id' => null, 'code' => $code],
                    ['scope' => $scope, 'name' => $definition['name'], 'color' => $definition['color'], 'is_system' => true,
                        'description' => 'roles.descriptions.'.$code],
                );

                $ids = $definition['permissions'] === ['*']
                    ? $all->values()->all()
                    : $all->only($definition['permissions'])->values()->all();

                $role->permissions()->sync($ids);
                Cache::forget('role_permissions:'.$role->id);
            }
        }

        Cache::forget('permissions:property');
    }
}

<?php

namespace App\Domain\Access;

use App\Models\Role;
use Illuminate\Support\Facades\DB;

/**
 * Permissions grouped by module with translated labels, for the role editor
 * and permission views. The source of truth is config/permissions.php.
 */
final class PermissionCatalogue
{
    /** @return array<int, array{module: string, label: string, permissions: array<int, array{key: string, label: string}>}> */
    public static function grouped(string $scope = 'property'): array
    {
        $labels = trans('permissions.keys');
        $modules = trans('permissions.modules');

        return collect(config('permissions.'.$scope))
            ->map(fn (array $keys, string $module) => [
                'module' => $module,
                'label' => is_array($modules) ? ($modules[$module] ?? $module) : $module,
                'permissions' => collect($keys)->map(fn (string $key) => [
                    'key' => $key,
                    // Keys contain dots, so read the translated array instead of using __().
                    'label' => is_array($labels) ? ($labels[$key] ?? $key) : $key,
                ])->all(),
            ])->values()->all();
    }

    /**
     * Roles a property can assign, with their permission keys and user counts.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function rolesFor(int $propertyId): array
    {
        $counts = DB::table('property_users')->where('property_id', $propertyId)
            ->select('role_id', DB::raw('count(*) as total'))->groupBy('role_id')->pluck('total', 'role_id');

        return Role::query()->availableTo($propertyId)
            ->with('permissions:id,key')
            ->orderByDesc('is_system')->orderBy('name')
            ->get()
            ->map(fn (Role $role) => self::role($role, (int) ($counts[$role->id] ?? 0)))
            ->all();
    }

    /** @return array<string, mixed> */
    public static function role(Role $role, ?int $usersCount = null): array
    {
        $description = $role->description;
        if ($description && str_starts_with($description, 'roles.descriptions.')) {
            $description = __($description);
        }

        return [
            'code' => $role->code,
            'name' => $role->name,
            'description' => $description,
            'color' => $role->color,
            'is_system' => $role->is_system,
            // The owner role is granted when a property is registered and never assigned by hand.
            'assignable' => $role->code !== 'owner',
            'users' => $usersCount,
            'permissions' => $role->permissions->pluck('key')->values()->all(),
        ];
    }
}

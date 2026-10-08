<?php

namespace App\Domain\Users\Queries;

use App\Models\Role;
use App\Models\User;
use App\Support\Listing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Every account on the platform (Super Admin → Users & Roles).
 * Reads across properties on purpose: this list is only reachable with platform.users.manage.
 */
class PlatformUserQuery
{
    public const STATUSES = ['active', 'invited', 'disabled', 'locked'];

    /** @return array{rows: array, meta: array, counts: array, kpis: array} */
    public function list(Request $request): array
    {
        $filters = Listing::filters($request, ['q', 'role', 'property', 'status', 'kind']);

        $hidden = app(\App\Domain\Users\PlatformHierarchy::class)->hiddenUserIds($request->user());

        $filtered = User::query()
            ->when($hidden !== [], fn (Builder $q) => $q->whereNotIn('users.id', $hidden))
            ->when($filters['q'] !== '', function (Builder $q) use ($filters) {
                $term = '%'.$filters['q'].'%';
                $q->where(fn (Builder $w) => $w->where('users.name', 'like', $term)
                    ->orWhere('users.email', 'like', $term)
                    ->orWhere('users.job_title', 'like', $term));
            })
            ->when($filters['kind'] === 'platform', fn (Builder $q) => $q->where('is_platform_user', true))
            ->when($filters['kind'] === 'property', fn (Builder $q) => $q->where('is_platform_user', false))
            ->when($filters['role'] !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->whereIn('users.id', DB::table('platform_user_roles')->join('roles', 'roles.id', '=', 'platform_user_roles.role_id')
                    ->where('roles.code', $filters['role'])->select('platform_user_roles.user_id'))
                ->orWhereIn('users.id', DB::table('property_users')->join('roles', 'roles.id', '=', 'property_users.role_id')
                    ->where('roles.code', $filters['role'])->select('property_users.user_id'))))
            ->when($filters['property'] !== '', fn (Builder $q) => $q->whereIn('users.id', DB::table('property_users')
                ->join('properties', 'properties.id', '=', 'property_users.property_id')
                ->where('properties.code', $filters['property'])->select('property_users.user_id')));

        $byStatus = (clone $filtered)->select('status', DB::raw('count(*) as total'))->groupBy('status')->pluck('total', 'status');

        $rows = (clone $filtered)
            ->when(in_array($filters['status'], self::STATUSES, true), fn (Builder $q) => $q->where('status', $filters['status']))
            ->with(['platformRoles:id,code,name,color'])
            ->withCount(['memberships as properties_count' => fn ($q) => $q->withoutGlobalScope('property')]);

        Listing::sort($rows, $request, [
            'name' => 'users.name', 'email' => 'users.email', 'status' => 'users.status', 'last_login' => 'users.last_login_at',
        ], 'users.name');

        $page = Listing::paginate($rows, $request);
        $users = collect($page['rows']);
        $memberships = $this->firstMemberships($users->pluck('id')->all());

        $page['rows'] = $users->map(function (User $u) use ($memberships) {
            $platformRole = $u->platformRoles->first();
            $membership = $memberships[$u->id] ?? null;

            return [
                'id' => $u->public_id,
                'name' => $u->name,
                'email' => $u->email,
                'job_title' => $u->job_title,
                'is_platform' => $u->is_platform_user,
                'role' => $platformRole?->code ?? $membership?->role_code,
                'role_name' => \App\Support\RoleLabel::name($platformRole?->code, $platformRole?->name) ?? \App\Support\RoleLabel::name($membership?->role_code, $membership?->role_name),
                'role_color' => $platformRole?->color ?? $membership?->role_color ?? 'slate',
                'property_access' => $u->is_platform_user ? 'all' : ($membership?->property_name),
                'properties_count' => (int) $u->properties_count,
                'status' => $u->status,
                'two_factor' => $u->hasTwoFactorEnabled(),
                'last_login_at' => $u->last_login_at?->toIso8601String(),
            ];
        })->all();

        $total = (int) $byStatus->sum();

        return $page + [
            'counts' => ['all' => $total] + collect(self::STATUSES)->mapWithKeys(fn ($s) => [$s => (int) ($byStatus[$s] ?? 0)])->all(),
            'kpis' => [
                'total' => User::query()->count(),
                'active' => User::query()->where('status', 'active')->count(),
                'inactive' => User::query()->whereIn('status', ['disabled', 'locked'])->count(),
                'roles' => Role::query()->whereNull('property_id')->count(),
            ],
        ];
    }

    /**
     * First property membership (by property name) of each user, for the "Property Access" column.
     *
     * @param  array<int, int>  $userIds
     * @return array<int, object>
     */
    private function firstMemberships(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        return DB::table('property_users')
            ->join('properties', 'properties.id', '=', 'property_users.property_id')
            ->join('roles', 'roles.id', '=', 'property_users.role_id')
            ->whereIn('property_users.user_id', $userIds)
            ->orderBy('properties.name')
            ->get(['property_users.user_id', 'properties.name as property_name', 'roles.code as role_code', 'roles.name as role_name', 'roles.color as role_color'])
            ->groupBy('user_id')
            ->map(fn ($group) => $group->first())
            ->all();
    }
}

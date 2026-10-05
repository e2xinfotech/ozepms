<?php

namespace App\Domain\Users\Queries;

use App\Models\PropertyUser;
use App\Models\Role;
use App\Support\Listing;
use App\Support\PropertyContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Users of the property selected for the request (Users & Roles list).
 */
class PropertyUserQuery
{
    public function __construct(private readonly PropertyContext $context) {}

    /** @return array{rows: array, meta: array, counts: array, kpis: array} */
    public function list(Request $request): array
    {
        $filters = Listing::filters($request, ['q', 'role', 'status']);

        $base = PropertyUser::query()
            ->join('users', 'users.id', '=', 'property_users.user_id')
            ->join('roles', 'roles.id', '=', 'property_users.role_id');

        $filtered = (clone $base)
            ->when($filters['q'] !== '', function (Builder $q) use ($filters) {
                $term = '%'.$filters['q'].'%';
                $q->where(fn (Builder $w) => $w->where('users.name', 'like', $term)
                    ->orWhere('users.email', 'like', $term)
                    ->orWhere('roles.name', 'like', $term)
                    ->orWhere('users.job_title', 'like', $term));
            })
            ->when($filters['role'] !== '', fn (Builder $q) => $q->where('roles.code', $filters['role']));

        $byStatus = (clone $filtered)->select('property_users.status', DB::raw('count(*) as total'))
            ->groupBy('property_users.status')->pluck('total', 'status');

        $rows = (clone $filtered)
            ->when(in_array($filters['status'], ['active', 'disabled', 'invited'], true), fn (Builder $q) => $q->where('property_users.status', $filters['status']))
            ->select([
                'property_users.*', 'users.public_id as user_public_id', 'users.name as user_name', 'users.email as user_email',
                'users.job_title as user_job_title', 'users.avatar_path as user_avatar', 'users.last_login_at as user_last_login_at',
                'users.status as account_status', 'roles.code as role_code', 'roles.name as role_name', 'roles.color as role_color',
            ]);

        Listing::sort($rows, $request, [
            'name' => 'users.name', 'email' => 'users.email', 'role' => 'roles.name',
            'status' => 'property_users.status', 'last_login' => 'users.last_login_at',
        ], 'users.name');

        $total = (int) $byStatus->sum();

        return Listing::paginate($rows, $request, fn (PropertyUser $m) => [
            'id' => $m->user_public_id,
            'name' => $m->user_name,
            'email' => $m->user_email,
            'job_title' => $m->user_job_title,
            'role' => $m->role_code,
            'role_name' => $m->role_name,
            'role_color' => $m->role_color,
            'is_owner' => $m->is_owner,
            'status' => $m->account_status === 'disabled' ? 'disabled' : $m->status,
            'invited' => $m->account_status === 'invited',
            'last_login_at' => $m->user_last_login_at ? \Illuminate\Support\Carbon::parse($m->user_last_login_at)->toIso8601String() : null,
        ]) + [
            'counts' => [
                'all' => $total,
                'active' => (int) ($byStatus['active'] ?? 0),
                'disabled' => (int) ($byStatus['disabled'] ?? 0),
                'invited' => (int) ($byStatus['invited'] ?? 0),
            ],
            'kpis' => [
                'total' => (int) (clone $base)->count(),
                'active' => (int) (clone $base)->where('property_users.status', 'active')->count(),
                'inactive' => (int) (clone $base)->where('property_users.status', '!=', 'active')->count(),
                'roles' => Role::query()->availableTo($this->context->id())->count(),
            ],
        ];
    }
}

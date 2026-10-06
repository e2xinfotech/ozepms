<?php

namespace App\Http\Resources;

use App\Domain\Access\AccessService;
use App\Domain\Audit\Queries\AuditQuery;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * An account as seen by E2X platform staff: platform roles, every property
 * membership and recent activity.
 *
 * @mixin User
 */
class PlatformUserResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;
        $user->loadMissing('platformRoles:id,code,name,color,description');
        $role = $user->platformRoles->first();
        $description = $role?->description;
        if ($description && str_starts_with($description, 'roles.descriptions.')) {
            $description = __($description);
        }

        return [
            'id' => $user->public_id,
            'name' => $user->name,
            'email' => $user->email,
            'phone_e164' => $user->phone_e164,
            'job_title' => $user->job_title,
            'locale' => $user->locale,
            'avatar' => $user->avatar_path ? Storage::url($user->avatar_path) : null,
            'is_platform' => $user->is_platform_user,
            'roles' => $user->platformRoles->pluck('code')->values()->all(),
            'role_name' => \App\Support\RoleLabel::name($role?->code, $role?->name),
            'role_color' => $role?->color,
            'role_description' => $description,
            'status' => $user->status,
            'two_factor' => $user->hasTwoFactorEnabled(),
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'created_at' => $user->created_at?->toIso8601String(),
            'permissions' => app(AccessService::class)->platformPermissions($user),
            'properties' => DB::table('property_users')
                ->join('properties', 'properties.id', '=', 'property_users.property_id')
                ->join('roles', 'roles.id', '=', 'property_users.role_id')
                ->where('property_users.user_id', $user->id)
                ->whereNull('properties.deleted_at')
                ->orderBy('properties.name')
                ->get(['properties.code', 'properties.name', 'properties.city', 'roles.code as role_code', 'roles.name as role_name', 'property_users.status', 'property_users.is_owner'])
                ->map(fn ($p) => [
                    'code' => $p->code, 'name' => $p->name, 'city' => $p->city, 'role_name' => \App\Support\RoleLabel::name($p->role_code, $p->role_name),
                    'status' => $p->status, 'is_owner' => (bool) $p->is_owner,
                ])->all(),
            'activity' => app(AuditQuery::class)->forUser($user->id, null, 15),
        ];
    }
}

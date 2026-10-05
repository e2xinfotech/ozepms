<?php

namespace App\Http\Resources;

use App\Domain\Access\AccessService;
use App\Domain\Audit\Queries\AuditQuery;
use App\Models\PropertyUser;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * One user as seen from a property: account details, role, permissions,
 * the properties shared with the viewer and recent activity in this property.
 *
 * @mixin PropertyUser
 */
class PropertyUserResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        /** @var PropertyUser $m */
        $m = $this->resource;
        $user = $m->user;
        $role = $m->role;
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
            'role' => $role?->code,
            'role_name' => $role?->name,
            'role_color' => $role?->color,
            'role_description' => $description,
            'is_owner' => $m->is_owner,
            'status' => $user->status === 'disabled' ? 'disabled' : $m->status,
            'account_status' => $user->status,
            'two_factor' => $user->hasTwoFactorEnabled(),
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'created_at' => $user->created_at?->toIso8601String(),
            'joined_at' => $m->joined_at?->toIso8601String(),
            'permissions' => $role ? app(AccessService::class)->rolePermissions($role->id) : [],
            'properties' => $this->sharedProperties($m, (int) $request->user()?->id),
            'activity' => app(AuditQuery::class)->forUser($user->id, $m->property_id, 15),
        ];
    }

    /**
     * Properties this user can open that the viewer can also open; other
     * tenants' names are never revealed.
     */
    private function sharedProperties(PropertyUser $m, int $viewerId): array
    {
        return DB::table('property_users as target')
            ->join('property_users as viewer', function ($join) use ($viewerId) {
                $join->on('viewer.property_id', '=', 'target.property_id')->where('viewer.user_id', $viewerId);
            })
            ->join('properties', 'properties.id', '=', 'target.property_id')
            ->join('roles', 'roles.id', '=', 'target.role_id')
            ->where('target.user_id', $m->user_id)
            ->whereNull('properties.deleted_at')
            ->orderBy('properties.name')
            ->get(['properties.code', 'properties.name', 'properties.city', 'roles.name as role_name', 'target.status'])
            ->map(fn ($p) => ['code' => $p->code, 'name' => $p->name, 'city' => $p->city, 'role_name' => $p->role_name, 'status' => $p->status])
            ->all();
    }
}

<?php

namespace App\Domain\Audit\Queries;

use App\Models\AuditLog;
use App\Support\Listing;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Audit trail search for the platform Audit Log screen and per-user activity tabs.
 */
class AuditQuery
{
    /** @return array{rows: array, meta: array} */
    public function list(Request $request): array
    {
        $filters = Listing::filters($request, ['action', 'user', 'property', 'from', 'to', 'via']);

        $hidden = app(\App\Domain\Users\PlatformHierarchy::class)->hiddenUserIds($request->user());

        $query = AuditLog::query()
            ->with(['user:id,public_id,name,email', 'impersonator:id,public_id,name,email', 'property:id,code,name'])
            ->when($hidden !== [], fn (Builder $q) => $q->where(fn ($w) => $w->whereNull('user_id')->orWhereNotIn('user_id', $hidden))
                ->where(fn ($w) => $w->whereNull('impersonator_id')->orWhereNotIn('impersonator_id', $hidden))
                ->whereNotIn('action', ['user.super_admin_created_console']))
            ->when($filters['action'] !== '', fn (Builder $q) => $q->where('action', 'like', '%'.$filters['action'].'%'))
            ->when($filters['user'] !== '', fn (Builder $q) => $q->whereIn('user_id', DB::table('users')
                ->where('email', 'like', '%'.$filters['user'].'%')->orWhere('name', 'like', '%'.$filters['user'].'%')->select('id')))
            ->when($filters['via'] === 'acting', fn (Builder $q) => $q->whereNotNull('impersonator_id'))
            ->when($filters['via'] !== '' && $filters['via'] !== 'acting', fn (Builder $q) => $q->whereIn('impersonator_id', DB::table('users')
                ->where('email', 'like', '%'.$filters['via'].'%')->orWhere('name', 'like', '%'.$filters['via'].'%')->select('id')))
            ->when($filters['property'] !== '', fn (Builder $q) => $q->whereIn('property_id', DB::table('properties')
                ->where('code', $filters['property'])->select('id')))
            ->when($this->date($filters['from']), fn (Builder $q, CarbonImmutable $d) => $q->where('created_at', '>=', $d->startOfDay()))
            ->when($this->date($filters['to']), fn (Builder $q, CarbonImmutable $d) => $q->where('created_at', '<=', $d->endOfDay()))
            ->orderByDesc('id');

        return Listing::paginate($query, $request, fn (AuditLog $log) => $this->row($log));
    }

    /**
     * Latest entries written by one user, optionally limited to one property.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forUser(int $userId, ?int $propertyId = null, int $limit = 20): array
    {
        return AuditLog::query()
            ->with(['property:id,code,name', 'impersonator:id,public_id,name,email'])
            ->where('user_id', $userId)
            ->when($propertyId, fn (Builder $q) => $q->where('property_id', $propertyId))
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (AuditLog $log) => $this->row($log))
            ->all();
    }

    private function row(AuditLog $log): array
    {
        return [
            'id' => substr(sha1($log->id.'|'.$log->request_id), 0, 16),
            'action' => $log->action,
            'action_label' => self::actionLabel($log->action),
            'user' => $log->user?->name,
            'user_email' => $log->user?->email,
            'impersonator' => $this->visible($log->impersonator_id) ? $log->impersonator?->name : null,
            'impersonator_email' => $this->visible($log->impersonator_id) ? $log->impersonator?->email : null,
            'property' => $log->property?->name,
            'property_code' => $log->property?->code,
            'entity' => $log->entity_type ? self::entityLabel($log->entity_type) : null,
            'ip' => $log->ip,
            'ref' => $log->request_id ? substr($log->request_id, -8) : null,
            'changes' => $log->changes,
            'at' => $log->created_at?->toIso8601String(),
        ];
    }

    /** Super Admins are not named to viewers below that level. */
    private function visible(?int $userId): bool
    {
        return $userId === null || ! in_array($userId, app(\App\Domain\Users\PlatformHierarchy::class)->hiddenUserIds(request()->user()), true);
    }

    /** Action keys contain dots, so they are looked up in the translated array directly. */
    public static function actionLabel(string $action): string
    {
        $labels = trans('admin.actions');

        if (is_array($labels) && isset($labels[$action])) {
            return (string) $labels[$action];
        }

        // Readable fallback for actions added later ("room_type.created" → "Room type created").
        return \Illuminate\Support\Str::ucfirst(str_replace(['.', '_'], ' ', $action));
    }

    public static function entityLabel(string $type): string
    {
        $labels = trans('admin.entities');

        return is_array($labels) && isset($labels[$type]) ? (string) $labels[$type] : \Illuminate\Support\Str::ucfirst(str_replace('_', ' ', $type));
    }

    private function date(string $value): ?CarbonImmutable
    {
        if ($value === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        try {
            return CarbonImmutable::createFromFormat('Y-m-d', $value);
        } catch (\Throwable) {
            return null;
        }
    }
}

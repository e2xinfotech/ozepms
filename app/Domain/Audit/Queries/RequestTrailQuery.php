<?php

namespace App\Domain\Audit\Queries;

use App\Models\RequestTrail;
use App\Support\Listing;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** The per-request change trail for the platform Audit Log screen. */
class RequestTrailQuery
{
    public const FILTERS = ['user', 'property', 'from', 'to', 'via', 'method'];

    /** @return array{rows: array, meta: array} */
    public function list(Request $request): array
    {
        $f = Listing::filters($request, self::FILTERS);
        $people = fn (string $term) => DB::table('users')->where('email', 'like', '%'.$term.'%')->orWhere('name', 'like', '%'.$term.'%')->select('id');

        $hidden = app(\App\Domain\Users\PlatformHierarchy::class)->hiddenUserIds($request->user());

        $query = RequestTrail::query()
            ->when($hidden !== [], fn (Builder $q) => $q->where(fn ($w) => $w->whereNull('user_id')->orWhereNotIn('user_id', $hidden))
                ->where(fn ($w) => $w->whereNull('impersonator_id')->orWhereNotIn('impersonator_id', $hidden)))
            ->when($f['user'] !== '', fn (Builder $q) => $q->whereIn('user_id', $people($f['user'])))
            ->when($f['via'] === 'acting', fn (Builder $q) => $q->whereNotNull('impersonator_id'))
            ->when($f['via'] !== '' && $f['via'] !== 'acting', fn (Builder $q) => $q->whereIn('impersonator_id', $people($f['via'])))
            ->when($f['property'] !== '', fn (Builder $q) => $q->whereIn('property_id', DB::table('properties')->where('code', $f['property'])->select('id')))
            ->when(in_array($f['method'], ['POST', 'PUT', 'PATCH', 'DELETE'], true), fn (Builder $q) => $q->where('method', $f['method']))
            ->when($this->date($f['from']), fn (Builder $q, CarbonImmutable $d) => $q->where('created_at', '>=', $d->startOfDay()))
            ->when($this->date($f['to']), fn (Builder $q, CarbonImmutable $d) => $q->where('created_at', '<=', $d->endOfDay()))
            ->orderByDesc('id');

        $page = Listing::paginate($query, $request);
        $rows = collect($page['rows']);
        $names = DB::table('users')->whereIn('id', $rows->pluck('user_id')->merge($rows->pluck('impersonator_id'))->filter()->unique()->all())
            ->get(['id', 'name', 'email'])->keyBy('id');
        $properties = DB::table('properties')->whereIn('id', $rows->pluck('property_id')->filter()->unique()->all())->pluck('code', 'id');

        $page['rows'] = $rows->map(fn (RequestTrail $r) => [
            'id' => (string) $r->id,
            'method' => $r->method,
            'route' => $r->route_name ?? $r->route_uri,
            'uri' => $r->route_uri,
            'params' => $r->route_params,
            'fields' => $r->field_names ?? [],
            'status' => $r->status,
            'user' => $names[$r->user_id]->name ?? null,
            'user_email' => $names[$r->user_id]->email ?? null,
            'impersonator' => $names[$r->impersonator_id]->name ?? null,
            'impersonator_email' => $names[$r->impersonator_id]->email ?? null,
            'property_code' => $properties[$r->property_id] ?? null,
            'ip' => $r->ip,
            'ref' => $r->request_id ? substr($r->request_id, -8) : null,
            'at' => $r->created_at?->toIso8601String(),
        ])->all();

        return $page;
    }

    private function date(string $value): ?CarbonImmutable
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        try {
            return CarbonImmutable::createFromFormat('Y-m-d', $value);
        } catch (\Throwable) {
            return null;
        }
    }
}

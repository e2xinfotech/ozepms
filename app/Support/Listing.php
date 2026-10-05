<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;

/**
 * Server-side pagination in the shape every list page expects:
 *   ['rows' => [...], 'meta' => ['page', 'per_page', 'total', 'last_page']]
 */
final class Listing
{
    public static function perPage(Request $request): int
    {
        $options = config('ozepms.pagination.options');
        $value = (int) $request->query('per_page', config('ozepms.pagination.default'));

        return in_array($value, $options, true) ? $value : (int) config('ozepms.pagination.default');
    }

    /**
     * @param  callable|null  $map  transforms each row (model or stdClass) into an array
     */
    public static function paginate(EloquentBuilder|QueryBuilder $query, Request $request, ?callable $map = null): array
    {
        $perPage = self::perPage($request);
        $page = max(1, (int) $request->query('page', 1));
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        if ($paginator->isEmpty() && $page > 1 && $paginator->lastPage() < $page) {
            $paginator = $query->paginate($perPage, ['*'], 'page', $paginator->lastPage());
        }

        $rows = collect($paginator->items());

        return [
            'rows' => ($map ? $rows->map($map) : $rows)->values()->all(),
            'meta' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => max(1, $paginator->lastPage()),
            ],
        ];
    }

    /** Applies ?sort=&dir= when the column is in the allow-list. */
    public static function sort(EloquentBuilder|QueryBuilder $query, Request $request, array $allowed, string $default, string $defaultDir = 'asc'): void
    {
        $sort = (string) $request->query('sort', '');
        $dir = strtolower((string) $request->query('dir', $defaultDir)) === 'desc' ? 'desc' : 'asc';
        $column = $allowed[$sort] ?? null;

        if ($column) {
            $query->orderBy($column, $dir);
        } else {
            $query->orderBy($default, $defaultDir);
        }
    }

    /** Current filter values from the query string, limited to the given keys. */
    public static function filters(Request $request, array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = (string) $request->query($key, '');
        }

        return $out;
    }
}

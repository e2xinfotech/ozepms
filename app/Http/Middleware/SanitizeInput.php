<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * First line of defence for everything users send (forms, JSON, query strings):
 *  - control characters (null bytes …) are removed from every text, so they never reach the database;
 *  - text that is not valid UTF-8 is refused (it would make the database reject the row);
 *  - input nested deeper than 8 levels or with more than 5,000 values is refused;
 *  - on page and list requests (GET) the query string only carries single values: arrays are dropped.
 * Uploaded files and the raw body (needed for webhook signatures) are not touched here.
 */
class SanitizeInput
{
    private const MAX_DEPTH = 8;

    private const MAX_VALUES = 5000;

    /** Forms and JSON are small; only file uploads may be large (their own size limits apply). */
    private const MAX_BODY_BYTES = 1_048_576;

    public function handle(Request $request, Closure $next): Response
    {
        if ((int) $request->headers->get('Content-Length', 0) > self::MAX_BODY_BYTES && ! str_contains((string) $request->headers->get('Content-Type'), 'multipart/form-data')
            && ! $request->is('hooks/*')) {
            abort(413);
        }
        $count = 0;
        $get = $request->isMethod('GET') || $request->isMethod('HEAD');

        $query = $request->query->all();
        if ($get) {
            $query = array_filter($query, fn ($v) => ! is_array($v));
        }
        $request->query->replace($this->clean($query, 0, $count));

        $request->request->replace($this->clean($request->request->all(), 0, $count));
        if ($request->isJson()) {
            $request->json()->replace($this->clean($request->json()->all(), 0, $count));
        }

        return $next($request);
    }

    private function clean(array $data, int $depth, int &$count): array
    {
        if ($depth > self::MAX_DEPTH) {
            $this->refuse();
        }
        $out = [];
        foreach ($data as $key => $value) {
            if (++$count > self::MAX_VALUES) {
                $this->refuse();
            }
            $key = is_string($key) ? $this->text($key) : $key;
            $out[$key] = is_array($value) ? $this->clean($value, $depth + 1, $count) : (is_string($value) ? $this->text($value) : $value);
        }

        return $out;
    }

    private function text(string $value): string
    {
        if ($value !== '' && ! mb_check_encoding($value, 'UTF-8')) {
            $this->refuse();
        }

        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value) ?? '';
    }

    private function refuse(): never
    {
        abort(422, __('errors.invalid_input'));
    }
}

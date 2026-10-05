<?php

namespace App\Domain\Platform;

use App\Domain\Audit\AuditLogger;
use App\Models\SystemErrorEvent;
use App\Support\Listing;
use Illuminate\Http\Request;

/**
 * Grouped application errors for the System Health screen.
 */
class SystemHealthService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @return array{rows: array, meta: array, counts: array} */
    public function list(Request $request): array
    {
        $filters = Listing::filters($request, ['status', 'level', 'source', 'q']);

        $query = SystemErrorEvent::query()
            ->when($filters['status'] === 'resolved', fn ($q) => $q->whereNotNull('resolved_at'))
            ->when($filters['status'] !== 'resolved' && $filters['status'] !== 'all', fn ($q) => $q->whereNull('resolved_at'))
            ->when(in_array($filters['level'], ['warning', 'error', 'critical'], true), fn ($q) => $q->where('level', $filters['level']))
            ->when(in_array($filters['source'], ['server', 'client', 'queue', 'scheduler'], true), fn ($q) => $q->where('source', $filters['source']))
            ->when($filters['q'] !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('message', 'like', '%'.$filters['q'].'%')
                ->orWhere('exception_class', 'like', '%'.$filters['q'].'%')
                ->orWhere('last_request_id', 'like', '%'.$filters['q'].'%')));

        Listing::sort($query, $request, ['count' => 'occurrences', 'last_seen' => 'last_seen_at'], 'last_seen_at', 'desc');

        return Listing::paginate($query, $request, fn (SystemErrorEvent $e) => [
            'id' => $e->fingerprint,
            'level' => $e->level,
            'source' => $e->source,
            'class' => $e->exception_class ? class_basename($e->exception_class) : null,
            'message' => $e->message,
            'location' => $e->location,
            'count' => (int) $e->occurrences,
            'first_seen' => $e->first_seen_at?->toIso8601String(),
            'last_seen' => $e->last_seen_at?->toIso8601String(),
            'ref' => $e->last_request_id ? substr($e->last_request_id, -8) : null,
            'resolved_at' => $e->resolved_at?->toIso8601String(),
        ]) + ['counts' => [
            'open' => SystemErrorEvent::query()->whereNull('resolved_at')->count(),
            'resolved' => SystemErrorEvent::query()->whereNotNull('resolved_at')->count(),
            'all' => SystemErrorEvent::query()->count(),
        ]];
    }

    public function resolve(SystemErrorEvent $event): void
    {
        if ($event->resolved_at !== null) {
            return;
        }
        $event->forceFill(['resolved_at' => now()])->save();
        $this->audit->log('system.error_resolved', $event, ['message' => mb_substr($event->message, 0, 200)]);
    }
}

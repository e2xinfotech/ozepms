<?php

namespace App\Domain\Accommodation\Queries;

use App\Models\AuditLog;
use App\Support\PropertyContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** "History" tab of room, room type, rate plan and tax panels: audit entries of one record. */
class HistoryQuery
{
    public function __construct(private readonly PropertyContext $context) {}

    /** @return list<array<string, mixed>> */
    public function for(Model $entity, int $limit = 15): array
    {
        return AuditLog::query()
            ->with('user:id,name')
            ->where('entity_type', Str::snake(class_basename($entity)))
            ->where('entity_id', $entity->getKey())
            ->where('property_id', $this->context->id())
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (AuditLog $log) => [
                'action' => $log->action,
                'label' => $this->label($log->action),
                'user' => $log->user?->name,
                'at' => $log->created_at?->toIso8601String(),
            ])->all();
    }

    private function label(string $action): string
    {
        $key = str_replace('.', '_', $action);
        $text = __('rooms.history.'.$key);

        return $text === 'rooms.history.'.$key ? Str::headline(str_replace('.', ' ', $action)) : $text;
    }
}

<?php

namespace App\Domain\Audit;

use App\Infrastructure\Logging\Redactor;
use App\Models\AuditLog;
use App\Support\PropertyContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes the audit trail. Call it from services after every important change.
 */
class AuditLogger
{
    public function __construct(private readonly PropertyContext $context) {}

    public function log(string $action, ?Model $entity = null, array $changes = [], ?int $propertyId = null, ?int $userId = null): void
    {
        $request = app()->bound('request') ? request() : null;

        AuditLog::query()->create([
            'property_id' => $propertyId ?? $this->resolvePropertyId($entity),
            'user_id' => $userId ?? auth()->id(),
            'impersonator_id' => $this->impersonatorId($request),
            'action' => $action,
            'entity_type' => $entity ? $this->entityName($entity) : null,
            'entity_id' => $entity?->getKey(),
            'request_id' => $request?->attributes->get('request_id'),
            'ip' => $request?->ip(),
            'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 255) : null,
            'changes' => $changes ? Redactor::clean($changes) : null,
            'created_at' => now(),
        ]);
    }

    /** The platform user behind the session when someone is acting as another user, else null. */
    private function impersonatorId(?\Illuminate\Http\Request $request): ?int
    {
        if ($request === null || ! $request->hasSession()) {
            return null;
        }
        $state = $request->session()->get('impersonation');

        return is_array($state) && (int) ($state['target_id'] ?? 0) === (int) auth()->id() ? (int) $state['actor_id'] : null;
    }

    /** Before/after diff of a model that is about to be saved. */
    public function diff(Model $model): array
    {
        $after = $model->getDirty();
        $before = array_intersect_key($model->getOriginal(), $after);
        unset($after['updated_at'], $before['updated_at']);

        return ['before' => $before, 'after' => $after];
    }

    private function resolvePropertyId(?Model $entity): ?int
    {
        if ($entity && isset($entity->property_id)) {
            return (int) $entity->property_id;
        }
        if ($entity instanceof \App\Models\Property) {
            return $entity->id;
        }

        return $this->context->idOrNull();
    }

    private function entityName(Model $entity): string
    {
        return \Illuminate\Support\Str::snake(class_basename($entity));
    }
}

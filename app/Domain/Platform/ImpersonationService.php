<?php

namespace App\Domain\Platform;

use App\Domain\Access\AccessService;
use App\Domain\Audit\AuditLogger;
use App\Domain\Users\PlatformHierarchy;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * "Log in as": platform staff act as another user, for support and on their behalf.
 *
 * Super Admin may act as Admins, IT Support and every property user; Admin may act as property users.
 * Nobody may act as a Super Admin, as themselves, or while already acting as someone. The original
 * user stays in the session, every change made meanwhile is audited with both names, and the session
 * ends on its own after config('ozepms.security.impersonation_minutes').
 */
class ImpersonationService
{
    public const SESSION_KEY = 'impersonation';

    public function __construct(
        private readonly AccessService $access,
        private readonly PlatformHierarchy $hierarchy,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array{actor_id: int, target_id: int, reason: string, started_at: int, expires_at: int}|null */
    public function state(Request $request): ?array
    {
        if (! $request->hasSession()) {
            return null;
        }
        $state = $request->session()->get(self::SESSION_KEY);

        return is_array($state) && (int) ($state['target_id'] ?? 0) === (int) $request->user()?->id ? $state : null;
    }

    public function canImpersonate(User $actor, User $target): bool
    {
        if ($actor->id === $target->id || ! $target->isActive()) {
            return false;
        }
        if (! $this->access->allows($actor, 'platform.impersonate')) {
            return false;
        }
        $targetLevel = $this->hierarchy->level($target);
        if ($targetLevel === 3) {
            return false;
        }

        return $targetLevel < $this->hierarchy->level($actor);
    }

    public function start(Request $request, User $target, string $reason): void
    {
        $actor = $request->user();
        $reason = trim($reason);

        if ($this->state($request) !== null) {
            throw ValidationException::withMessages(['user' => __('impersonation.already_active')]);
        }
        if (mb_strlen($reason) < (int) config('ozepms.security.impersonation_reason_min')) {
            throw ValidationException::withMessages(['reason' => __('impersonation.reason_required', ['min' => config('ozepms.security.impersonation_reason_min')])]);
        }
        if (! $this->canImpersonate($actor, $target)) {
            throw ValidationException::withMessages(['user' => __('impersonation.not_allowed')]);
        }

        $this->audit->log('impersonation.started', $target, ['reason' => $reason, 'actor' => $actor->email, 'target' => $target->email], null, $actor->id);

        Auth::login($target);
        $request->session()->put(self::SESSION_KEY, [
            'actor_id' => $actor->id,
            'target_id' => $target->id,
            'reason' => $reason,
            'started_at' => now()->timestamp,
            'expires_at' => now()->addMinutes((int) config('ozepms.security.impersonation_minutes'))->timestamp,
        ]);
        $request->session()->forget('url.intended');
    }

    /** Returns to the original user. $action is 'impersonation.stopped' or 'impersonation.expired'. */
    public function stop(Request $request, string $action = 'impersonation.stopped'): ?User
    {
        $state = $this->state($request);
        if ($state === null) {
            $request->session()->forget(self::SESSION_KEY);

            return null;
        }

        $target = $request->user();
        $actor = User::query()->find($state['actor_id']);

        // Ended first, so this entry belongs to the real person alone (the target is only the record concerned).
        $request->session()->forget(self::SESSION_KEY);
        $this->audit->log($action, $target, [
            'actor' => $actor?->email, 'target' => $target?->email, 'minutes' => (int) round((now()->timestamp - $state['started_at']) / 60),
        ], null, $actor?->id);

        if ($actor === null || ! $actor->isActive()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return null;
        }

        Auth::login($actor);

        return $actor;
    }
}

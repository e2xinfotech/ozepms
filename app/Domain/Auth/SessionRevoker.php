<?php

namespace App\Domain\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Ends a person's other sign-ins (after a password change or reset, so a stolen session stops working). */
final class SessionRevoker
{
    public static function forUser(User $user, ?string $exceptSessionId = null): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }
        DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)
            ->when($exceptSessionId !== null, fn ($q) => $q->where('id', '!=', $exceptSessionId))->delete();
    }
}

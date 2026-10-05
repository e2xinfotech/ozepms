<?php

namespace App\Infrastructure\Auth;

use Illuminate\Auth\SessionGuard as BaseSessionGuard;

/**
 * Session guard whose "keep me signed in" cookie has a neutral name. The stock name
 * ("remember_<guard>_<hash>") identifies the server framework to anyone looking at cookies.
 */
class SessionGuard extends BaseSessionGuard
{
    public function getRecallerName()
    {
        return (string) config('ozepms.security.remember_cookie', 'oz_rm');
    }
}

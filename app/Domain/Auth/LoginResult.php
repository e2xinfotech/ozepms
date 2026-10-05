<?php

namespace App\Domain\Auth;

enum LoginResult: string
{
    case Success = 'success';
    case TwoFactorRequired = 'two_factor_required';
    case InvalidCredentials = 'invalid_credentials';
    case Locked = 'locked';
    case Disabled = 'disabled';
    case Throttled = 'throttled';
}

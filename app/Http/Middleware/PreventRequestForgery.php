<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery as Base;

/**
 * CSRF protection without the framework's XSRF-TOKEN cookie: pages send the
 * token from the csrf-token meta tag (lib/http.ts), so the cookie is not needed
 * and would only reveal which framework runs the site.
 */
class PreventRequestForgery extends Base
{
    protected $addHttpCookie = false;
}

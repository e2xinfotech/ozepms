<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureSubscriptionActive;
use App\Http\Middleware\EnsureTwoFactorEnrolled;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\PreventRequestForgery;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\ResolveProperty;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use App\Infrastructure\Logging\ErrorRecorder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        then: function () {
            Route::middleware('web')->prefix('web-api')->name('webapi.')->group(base_path('routes/web-api.php'));

            // Uptime check for the load balancer / monitoring: plain JSON, no session, no framework page.
            Route::get('/up', function () {
                try {
                    \Illuminate\Support\Facades\DB::select('select 1');

                    return response()->json(['status' => 'ok']);
                } catch (\Throwable) {
                    return response()->json(['status' => 'unavailable'], 503);
                }
            })->name('health');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AssignRequestId::class);
        $middleware->append(SecurityHeaders::class);
        $middleware->web(
            append: [SetLocale::class, EnsureUserIsActive::class],
            replace: [\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class => PreventRequestForgery::class],
        );

        $middleware->alias([
            'property' => ResolveProperty::class,
            'subscription' => EnsureSubscriptionActive::class,
            'two-factor' => EnsureTwoFactorEnrolled::class,
            'can.do' => RequirePermission::class,
        ]);

        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('home'));
        $middleware->trustProxies(at: env('TRUSTED_PROXIES', '127.0.0.1'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Every unexpected error is logged and grouped for the System health screen.
        $exceptions->report(function (Throwable $e) {
            ErrorRecorder::exception($e);
        });

        $exceptions->dontReportDuplicates();

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*', 'web-api/*') || $request->expectsJson(),
        );

        // One JSON error format everywhere: { error: { code, message, fields?, ref } }
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! ($request->is('api/*', 'web-api/*') || $request->expectsJson())) {
                return null;
            }

            $ref = $request->attributes->get('request_id');

            [$status, $code, $message, $fields] = match (true) {
                $e instanceof ValidationException => [422, 'VALIDATION_FAILED', __('errors.validation'), $e->errors()],
                $e instanceof AuthenticationException => [401, 'UNAUTHENTICATED', __('errors.401'), null],
                $e instanceof AuthorizationException => [403, 'FORBIDDEN', __('errors.403'), null],
                $e instanceof TokenMismatchException => [419, 'SESSION_EXPIRED', __('errors.419'), null],
                $e instanceof HttpExceptionInterface && $e->getStatusCode() === 419 => [419, 'SESSION_EXPIRED', __('errors.419'), null],
                // Only messages the application passed to abort() are shown; framework messages
                // (unknown route, missing record, wrong method) would reveal internal names.
                $e instanceof HttpExceptionInterface => [
                    $e->getStatusCode(),
                    'HTTP_'.$e->getStatusCode(),
                    $e->getStatusCode() < 500 && $e::class === HttpException::class && $e->getPrevious() === null && $e->getMessage() !== ''
                        ? $e->getMessage()
                        : (Lang::has('errors.'.$e->getStatusCode()) ? __('errors.'.$e->getStatusCode()) : __('errors.400')),
                    null,
                ],
                default => [500, 'SERVER_ERROR', __('errors.500'), null],
            };

            return response()->json(['error' => array_filter([
                'code' => $code,
                'message' => $message,
                'fields' => $fields,
                'ref' => $ref ? substr($ref, -8) : null,
            ], fn ($v) => $v !== null)], $status);
        });
    })->create();

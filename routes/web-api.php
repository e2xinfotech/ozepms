<?php

use App\Http\Controllers\WebApi;
use Illuminate\Support\Facades\Route;

/*
| JSON endpoints used by pages (session cookie + CSRF token).
| Prefix: /web-api   Name prefix: webapi.
*/

Route::post('/locale', WebApi\LocaleController::class)->name('locale');
Route::post('/client-errors', WebApi\ClientErrorController::class)->middleware('throttle:client-errors')->name('client-errors');

Route::middleware(['guest', 'throttle:auth'])->prefix('auth')->name('auth.')->group(function () {
    Route::post('/login', [WebApi\AuthController::class, 'login'])->name('login');
    Route::post('/two-factor', [WebApi\AuthController::class, 'twoFactor'])->name('two-factor');
    Route::post('/forgot-password', [WebApi\AuthController::class, 'forgotPassword'])->name('forgot-password');
    Route::post('/reset-password', [WebApi\AuthController::class, 'resetPassword'])->name('reset-password');
});

Route::middleware('auth')->group(function () {
    Route::post('/auth/logout', [WebApi\AuthController::class, 'logout'])->name('auth.logout');
    Route::post('/impersonation/stop', [WebApi\ImpersonationController::class, 'stop'])->name('impersonation.stop');

    Route::prefix('account')->name('account.')->group(function () {
        Route::post('/two-factor/begin', [WebApi\AccountController::class, 'beginTwoFactor'])->name('two-factor.begin');
        Route::post('/two-factor/confirm', [WebApi\AccountController::class, 'confirmTwoFactor'])->name('two-factor.confirm');
        Route::post('/two-factor/disable', [WebApi\AccountController::class, 'disableTwoFactor'])->name('two-factor.disable');
        Route::put('/password', [WebApi\AccountController::class, 'updatePassword'])->name('password');
        Route::put('/profile', [WebApi\AccountController::class, 'updateProfile'])->name('profile');
    });

    Route::middleware('two-factor')->group(function () {
        Route::get('/lookups/states', [WebApi\LookupController::class, 'states'])->name('lookups.states');
        Route::post('/properties', [WebApi\OnboardingController::class, 'store'])->name('properties.store');

        Route::prefix('p/{property}')->middleware(['property', 'subscription'])->name('property.')->group(function () {
            Route::get('/properties/{code}', [WebApi\Property\PropertyController::class, 'show'])->middleware('can.do:property.view')->name('properties.show');
            Route::put('/settings', [WebApi\Property\PropertyController::class, 'update'])->middleware('can.do:property.update')->name('settings.update');
            Route::put('/settings/age-bands', WebApi\Property\AgeBandsController::class)->middleware('can.do:property.update')->name('settings.age-bands');
            Route::middleware('can.do:property.update')->group(function () {
                Route::put('/settings/email', [WebApi\Property\EmailSettingsController::class, 'update'])->name('settings.email');
                Route::post('/settings/email/test', [WebApi\Property\EmailSettingsController::class, 'test'])->middleware('throttle:10,1')->name('settings.email.test');
                Route::get('/settings/email/templates', [WebApi\Property\EmailTemplatesController::class, 'index'])->name('settings.email.templates');
                Route::put('/settings/email/templates/{event}', [WebApi\Property\EmailTemplatesController::class, 'update'])->name('settings.email.templates.update');
                Route::delete('/settings/email/templates/{event}', [WebApi\Property\EmailTemplatesController::class, 'reset'])->name('settings.email.templates.reset');
                Route::post('/settings/email/templates/{event}/preview', [WebApi\Property\EmailTemplatesController::class, 'preview'])->middleware('throttle:60,1')->name('settings.email.templates.preview');
                Route::get('/settings/email/logs', [WebApi\Property\EmailSettingsController::class, 'logs'])->name('settings.email.logs');
                Route::post('/settings/email/logs/{log}/resend', [WebApi\Property\EmailSettingsController::class, 'resend'])->middleware('throttle:20,1')->name('settings.email.resend');
            });
            Route::put('/settings/booking-engine', WebApi\Property\BookingEngineSettingsController::class)->middleware('can.do:property.update')->name('settings.booking-engine');
            Route::middleware('can.do:property.update')->group(function () {
                Route::get('/settings/api-keys', [WebApi\Property\ApiKeysController::class, 'index'])->name('settings.api-keys.index');
                Route::post('/settings/api-keys', [WebApi\Property\ApiKeysController::class, 'store'])->middleware('throttle:20,1')->name('settings.api-keys.store');
                Route::delete('/settings/api-keys/{key}', [WebApi\Property\ApiKeysController::class, 'destroy'])->name('settings.api-keys.destroy');
            });
            Route::post('/settings/media/{kind}', [WebApi\Property\PropertyController::class, 'uploadMedia'])->whereIn('kind', ['logo', 'cover'])->middleware('can.do:property.update')->name('settings.media.store');
            Route::delete('/settings/media/{kind}', [WebApi\Property\PropertyController::class, 'removeMedia'])->whereIn('kind', ['logo', 'cover'])->middleware('can.do:property.update')->name('settings.media.destroy');
            Route::post('/copy', [WebApi\Property\PropertyController::class, 'copy'])->middleware(['can.do:property.update', 'throttle:20,1'])->name('copy');

            Route::middleware('can.do:users.manage')->group(function () {
                Route::get('/users/{user}', [WebApi\Property\UsersController::class, 'show'])->name('users.show');
                Route::post('/users', [WebApi\Property\UsersController::class, 'store'])->name('users.store');
                Route::put('/users/{user}', [WebApi\Property\UsersController::class, 'update'])->name('users.update');
                Route::delete('/users/{user}', [WebApi\Property\UsersController::class, 'destroy'])->name('users.destroy');
                Route::post('/users/{user}/password-link', [WebApi\Property\UsersController::class, 'passwordLink'])->name('users.password-link');

                Route::get('/roles', [WebApi\Property\RolesController::class, 'index'])->name('roles.index');
                Route::post('/roles', [WebApi\Property\RolesController::class, 'store'])->name('roles.store');
                Route::put('/roles/{role}', [WebApi\Property\RolesController::class, 'update'])->name('roles.update');
                Route::delete('/roles/{role}', [WebApi\Property\RolesController::class, 'destroy'])->name('roles.destroy');
            });

            // Module JSON routes: one file per module in routes/property-api/*.php
            foreach (glob(base_path('routes/property-api/*.php')) as $file) {
                require $file;
            }
        });

        Route::prefix('admin')->name('admin.')->group(function () {
            Route::middleware('can.do:platform.properties.manage')->group(function () {
                Route::get('/properties/{code}', [WebApi\Admin\PropertiesController::class, 'show'])->name('properties.show');
                Route::post('/properties', [WebApi\Admin\PropertiesController::class, 'store'])->name('properties.store');
                Route::put('/properties/{code}', [WebApi\Admin\PropertiesController::class, 'update'])->name('properties.update');
                Route::post('/properties/bulk-status', [WebApi\Admin\PropertiesController::class, 'bulkStatus'])->name('properties.bulk-status');
                Route::post('/properties/{code}/status', [WebApi\Admin\PropertiesController::class, 'status'])->name('properties.status');
                Route::post('/properties/{code}/media/{kind}', [WebApi\Admin\PropertiesController::class, 'uploadMedia'])->whereIn('kind', ['logo', 'cover'])->name('properties.media.store');
                Route::delete('/properties/{code}/media/{kind}', [WebApi\Admin\PropertiesController::class, 'removeMedia'])->whereIn('kind', ['logo', 'cover'])->name('properties.media.destroy');
                Route::post('/properties/{code}/owner', [WebApi\Admin\PropertiesController::class, 'owner'])->name('properties.owner');
                Route::post('/properties/{code}/copy', [WebApi\Admin\PropertiesController::class, 'copy'])->name('properties.copy');
            });
            Route::post('/properties/{code}/subscription', [WebApi\Admin\PropertiesController::class, 'subscription'])
                ->middleware('can.do:platform.subscriptions.manage')->name('properties.subscription');

            Route::middleware('can.do:platform.users.manage')->group(function () {
                Route::get('/users/{user}', [WebApi\Admin\UsersController::class, 'show'])->name('users.show');
                Route::post('/users', [WebApi\Admin\UsersController::class, 'store'])->name('users.store');
                Route::post('/owners', [WebApi\Admin\UsersController::class, 'storeOwner'])->name('owners.store');
                Route::put('/users/{user}', [WebApi\Admin\UsersController::class, 'update'])->name('users.update');
                Route::post('/users/{user}/status', [WebApi\Admin\UsersController::class, 'status'])->name('users.status');
                Route::post('/users/{user}/password-link', [WebApi\Admin\UsersController::class, 'passwordLink'])->name('users.password-link');
            });

            Route::post('/users/{user}/impersonate', [WebApi\ImpersonationController::class, 'start'])
                ->middleware(['can.do:platform.impersonate', 'throttle:20,1'])->name('users.impersonate');

            Route::post('/approvals/{approval}/decide', [WebApi\Admin\ApprovalsController::class, 'decide'])
                ->middleware('can.do:platform.approvals.manage')->name('approvals.decide');

            Route::middleware('can.do:platform.plans.manage')->group(function () {
                Route::post('/plans', [WebApi\Admin\PlansController::class, 'store'])->name('plans.store');
                Route::put('/plans/{plan}', [WebApi\Admin\PlansController::class, 'update'])->name('plans.update');
            });

            Route::middleware('can.do:platform.settings.manage')->group(function () {
                Route::put('/email', [WebApi\Admin\EmailSettingsController::class, 'update'])->name('email.update');
                Route::post('/email/test', [WebApi\Admin\EmailSettingsController::class, 'test'])->middleware('throttle:10,1')->name('email.test');
            });

            Route::post('/system/errors/{event}/resolve', [WebApi\Admin\SystemController::class, 'resolve'])
                ->middleware('can.do:platform.system.view')->name('system.errors.resolve');
        });
    });
});

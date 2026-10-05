<?php

use App\Http\Controllers\Web\AccountController;
use App\Http\Controllers\Web\Admin;
use App\Http\Controllers\Web\Auth\LoginController;
use App\Http\Controllers\Web\Auth\PasswordResetController;
use App\Http\Controllers\Web\Auth\TwoFactorChallengeController;
use App\Http\Controllers\Web\HomeController;
use App\Http\Controllers\Web\OnboardingController;
use App\Http\Controllers\Web\Property;
use Illuminate\Support\Facades\Route;

/*
| Page routes. Every page is a full server-rendered document with its own React entry.
| Actions inside pages call the JSON endpoints in routes/web-api.php.
*/

Route::get('/', [HomeController::class, 'root'])->name('root');

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::get('/two-factor', [TwoFactorChallengeController::class, 'show'])->name('two-factor.challenge');
    Route::get('/forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'reset'])->name('password.reset');
});

Route::middleware('auth')->group(function () {
    Route::get('/account/security', [AccountController::class, 'security'])->name('account.security');

    Route::middleware('two-factor')->group(function () {
        Route::get('/home', [HomeController::class, 'home'])->name('home');
        Route::get('/account/profile', [AccountController::class, 'profile'])->name('account.profile');
        Route::get('/properties', [OnboardingController::class, 'picker'])->name('properties.index');
        Route::get('/properties/new', [OnboardingController::class, 'create'])->name('properties.create');

        // Property workspace: /p/P1001/...
        Route::prefix('p/{property}')
            ->middleware('property')
            ->name('property.')
            ->group(function () {
                Route::get('/', fn ($property) => redirect()->route('property.dashboard', $property));
                Route::get('/dashboard', Property\DashboardController::class)->middleware('can.do:property.view')->name('dashboard');
                Route::get('/properties', [Property\PropertiesController::class, 'index'])->middleware('can.do:property.view')->name('properties');
                Route::get('/settings', [Property\SettingsController::class, 'edit'])->middleware('can.do:property.view')->name('settings');
                Route::get('/users', [Property\UsersController::class, 'index'])->middleware('can.do:users.manage')->name('users');

                // Module page routes: one file per module in routes/property/*.php
                foreach (glob(base_path('routes/property/*.php')) as $file) {
                    require $file;
                }
            });

        // E2X platform administration
        Route::prefix('admin')
            ->middleware('can.do:platform.dashboard')
            ->name('admin.')
            ->group(function () {
                Route::get('/', Admin\DashboardController::class)->name('dashboard');
                Route::get('/properties', [Admin\PropertiesController::class, 'index'])->middleware('can.do:platform.properties.manage')->name('properties');
                Route::get('/properties/new', [Admin\PropertiesController::class, 'create'])->middleware('can.do:platform.properties.manage')->name('properties.create');
                Route::get('/properties/{code}/edit', [Admin\PropertiesController::class, 'edit'])->middleware('can.do:platform.properties.manage')->name('properties.edit');
                Route::get('/users', [Admin\UsersController::class, 'index'])->middleware('can.do:platform.users.manage')->name('users');
                Route::get('/plans', [Admin\PlansController::class, 'index'])->middleware('can.do:platform.plans.manage')->name('plans');
                Route::get('/audit', [Admin\AuditController::class, 'index'])->middleware('can.do:platform.audit.view')->name('audit');
                Route::get('/system', [Admin\SystemController::class, 'index'])->middleware('can.do:platform.system.view')->name('system');

                foreach (glob(base_path('routes/admin/*.php')) as $file) {
                    require $file;
                }
            });
    });
});

<?php

namespace App\Providers;

use App\Domain\Access\AccessService;
use App\Infrastructure\Logging\ErrorRecorder;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use App\Models\User;
use App\Support\PropertyContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Fresh for every request / queued job.
        $this->app->scoped(PropertyContext::class);
        $this->app->scoped(AccessService::class);
    }

    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());
        Model::automaticallyEagerLoadRelationships();

        if ($this->app->isProduction()) {
            URL::forceScheme('https');
            if (config('app.debug')) {
                // Never expose stack traces in production, whatever the .env says.
                config(['app.debug' => false]);
                Log::critical('APP_DEBUG was enabled in production and has been forced off.');
            }
        }

        Password::defaults(fn () => Password::min(config('ozepms.security.password_min_length'))
            ->mixedCase()
            ->numbers()
            ->when($this->app->isProduction(), fn ($rule) => $rule->uncompromised()));

        // Permission keys (e.g. "reservations.create") work with @can / Gate::allows / $user->can().
        Gate::before(function (User $user, string $ability) {
            if (! str_contains($ability, '.')) {
                return null;
            }

            return app(AccessService::class)->allows($user, $ability);
        });

        $this->configureRateLimiting();
        $this->tagErrorSources();

        $threshold = (int) config('ozepms.logging.slow_query_ms');
        DB::listen(function (QueryExecuted $query) use ($threshold) {
            if ($query->time >= $threshold) {
                Log::channel('performance')->warning('Slow query', [
                    'ms' => $query->time,
                    'sql' => $query->sql,
                    'connection' => $query->connectionName,
                ]);
            }
        });
    }

    /** Errors raised inside queued jobs and scheduled tasks are labelled as such on System Health. */
    private function tagErrorSources(): void
    {
        Event::listen(JobProcessing::class, fn () => ErrorRecorder::runningIn('queue'));
        Event::listen([JobProcessed::class, JobFailed::class], fn () => ErrorRecorder::runningIn('server'));
        Event::listen(ScheduledTaskStarting::class, fn () => ErrorRecorder::runningIn('scheduler'));
        Event::listen([ScheduledTaskFinished::class, ScheduledTaskFailed::class], fn () => ErrorRecorder::runningIn('server'));
    }

    private function configureRateLimiting(): void
    {
        // Sign-in, two-factor and password reset endpoints, per client IP.
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(
            (int) config('ozepms.security.auth_requests_per_minute'),
        )->by('auth|'.$request->ip()));

        // Browser error reports: enough to diagnose a failure, never enough to flood the logs.
        RateLimiter::for('client-errors', fn (Request $request) => Limit::perMinute(
            (int) config('ozepms.logging.client_errors_per_minute'),
        )->by('client-errors|'.$request->ip()));
    }
}

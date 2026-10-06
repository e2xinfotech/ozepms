<?php

namespace Tests\Feature\Admin;

use App\Infrastructure\Logging\ErrorRecorder;
use App\Models\SystemErrorEvent;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/** Errors are grouped on System Health with where they happened: server, browser, queue or scheduler. */
class ErrorSourcesTest extends TestCase
{
    public function test_errors_inside_a_job_are_labelled_queue(): void
    {
        $job = \Mockery::mock(\Illuminate\Contracts\Queue\Job::class)->shouldIgnoreMissing();
        Event::dispatch(new JobProcessing('database', $job));
        app(ExceptionHandler::class)->report(new \RuntimeException('Job blew up'));
        Event::dispatch(new JobProcessed('database', $job));
        app(ExceptionHandler::class)->report(new \LogicException('Request blew up'));

        $this->assertSame('queue', SystemErrorEvent::query()->where('message', 'Job blew up')->value('source'));
        $this->assertSame('server', SystemErrorEvent::query()->where('message', 'Request blew up')->value('source'));
        $this->assertSame('server', ErrorRecorder::currentSource());
    }

    public function test_browser_errors_are_recorded_as_client(): void
    {
        $this->postJson('/web-api/client-errors', ['message' => 'TypeError: x is undefined', 'source' => 'boot.tsx', 'line' => 10, 'url' => '/p/P1001/dashboard'])
            ->assertSuccessful();

        $this->assertDatabaseHas('system_error_events', ['source' => 'client']);
    }

    public function test_users_never_see_internal_details(): void
    {
        config(['app.debug' => false]);
        \Illuminate\Support\Facades\Route::middleware('web')->get('/__boom', fn () => throw new \RuntimeException('SQLSTATE secret /var/www/app'));

        $this->getJson('/__boom')->assertStatus(500)
            ->assertJsonPath('error.code', 'SERVER_ERROR')
            ->assertDontSee('SQLSTATE')->assertDontSee('/var/www');
        $this->get('/__boom')->assertStatus(500)->assertDontSee('SQLSTATE')->assertDontSee('RuntimeException');
    }
}

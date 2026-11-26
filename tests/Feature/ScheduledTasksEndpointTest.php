<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Tests\Feature;

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Gate;
use LogicException;
use Nwidart\Actuator\Actuator;
use Nwidart\Actuator\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

final class ScheduledTasksEndpointTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('actuator.exposure.include', ['scheduledtasks']);
    }

    #[Test]
    public function it_lists_cron_and_sub_minute_tasks(): void
    {
        Carbon::setTestNow('2026-01-01 10:30:00 UTC');
        $schedule = $this->app->make(Schedule::class);
        $schedule->command('inspire')->dailyAt('12:00')->timezone('Europe/Brussels');
        $schedule->call(fn () => null)->everyTenSeconds()->description('Poll');

        $this->getJson('/actuator/scheduledtasks')->assertOk()->assertExactJson([
            'cron' => [[
                'runnable' => ['target' => 'php artisan inspire'],
                'expression' => '0 12 * * *',
                'timezone' => 'Europe/Brussels',
                'nextExecution' => ['time' => '2026-01-01T12:00:00+01:00'],
            ]],
            'fixedDelay' => [],
            'fixedRate' => [[
                'runnable' => ['target' => 'Poll'],
                'initialDelay' => 0,
                'interval' => 10000,
            ]],
            'custom' => [],
        ]);
    }

    #[Test]
    public function it_returns_empty_lists_without_tasks(): void
    {
        $this->getJson('/actuator/scheduledtasks')
            ->assertOk()
            ->assertExactJson(['cron' => [], 'fixedDelay' => [], 'fixedRate' => [], 'custom' => []]);
    }

    #[Test]
    public function it_reports_the_last_execution_in_the_spring_boot_shape(): void
    {
        Carbon::setTestNow('2026-01-01 10:30:00 UTC');
        $schedule = $this->app->make(Schedule::class);
        $report = $schedule->command('inspire')->dailyAt('12:00');
        $poll = $schedule->call(fn () => null)->everyTenSeconds()->description('Poll');
        $schedule->command('migrate')->daily();

        event(new ScheduledTaskStarting($report));
        Carbon::setTestNow('2026-01-01 10:30:05 UTC');
        event(new ScheduledTaskStarting($poll));

        $this->getJson('/actuator/scheduledtasks')->assertOk()->assertExactJson([
            'cron' => [
                [
                    'runnable' => ['target' => 'php artisan inspire'],
                    'expression' => '0 12 * * *',
                    'timezone' => 'UTC',
                    'nextExecution' => ['time' => '2026-01-01T12:00:00+00:00'],
                    'lastExecution' => ['time' => '2026-01-01T10:30:00+00:00', 'status' => 'STARTED'],
                ],
                [
                    'runnable' => ['target' => 'php artisan migrate'],
                    'expression' => '0 0 * * *',
                    'timezone' => 'UTC',
                    'nextExecution' => ['time' => '2026-01-02T00:00:00+00:00'],
                ],
            ],
            'fixedDelay' => [],
            'fixedRate' => [[
                'runnable' => ['target' => 'Poll'],
                'initialDelay' => 0,
                'interval' => 10000,
                'lastExecution' => ['time' => '2026-01-01T10:30:05+00:00', 'status' => 'STARTED'],
            ]],
            'custom' => [],
        ]);
    }

    #[Test]
    public function it_hides_the_exception_message_by_default(): void
    {
        $this->failTask();

        $this->getJson('/actuator/scheduledtasks')
            ->assertJsonPath('cron.0.lastExecution.status', 'ERROR')
            ->assertJsonPath('cron.0.lastExecution.exception', ['type' => LogicException::class]);
    }

    #[Test]
    public function it_shows_the_exception_message_when_allowed(): void
    {
        config()->set('actuator.scheduledtasks.show_exceptions', 'always');
        $this->failTask();

        $this->getJson('/actuator/scheduledtasks')
            ->assertJsonPath('cron.0.lastExecution.exception', ['type' => LogicException::class, 'message' => 'token=secret']);
    }

    #[Test]
    public function it_shows_the_exception_message_to_authorized_users(): void
    {
        config()->set('actuator.scheduledtasks.show_exceptions', 'when_authorized');
        Gate::define('viewActuator', fn (User $user): bool => true);
        $this->failTask();

        $this->getJson('/actuator/scheduledtasks')->assertJsonPath('cron.0.lastExecution.exception', ['type' => LogicException::class]);
        $this->actingAs(new User)->getJson('/actuator/scheduledtasks')
            ->assertJsonPath('cron.0.lastExecution.exception', ['type' => LogicException::class, 'message' => 'token=secret']);
    }

    #[Test]
    public function a_message_that_is_not_valid_utf8_is_answered(): void
    {
        config()->set('actuator.scheduledtasks.show_exceptions', 'always');
        $task = $this->app->make(Schedule::class)->command('report:send')->daily();
        event(new ScheduledTaskFailed($task, new LogicException("Invalid byte \xB1 here")));

        $this->getJson('/actuator/scheduledtasks')
            ->assertOk()
            ->assertJsonPath('cron.0.lastExecution.exception.message', "Invalid byte \u{FFFD} here");
    }

    #[Test]
    public function tasks_with_the_same_target_share_a_record(): void
    {
        $schedule = $this->app->make(Schedule::class);
        $first = $schedule->command('inspire')->hourly();
        $schedule->command('inspire')->daily();

        event(new ScheduledTaskStarting($first));

        $response = $this->getJson('/actuator/scheduledtasks');
        $this->assertSame('STARTED', $response->json('cron.0.lastExecution.status'));
        $this->assertSame('STARTED', $response->json('cron.1.lastExecution.status'));
    }

    #[Test]
    public function it_leaves_the_last_execution_out_when_recording_is_off(): void
    {
        $this->failTask();
        config()->set('actuator.scheduler.record', false);

        $this->getJson('/actuator/scheduledtasks')->assertOk()->assertJsonMissingPath('cron.0.lastExecution');
    }

    #[Test]
    public function it_adds_the_tags_of_the_callbacks_to_each_task(): void
    {
        Exceptions::fake();
        $schedule = $this->app->make(Schedule::class);
        $schedule->command('blog:digest')->hourly();
        $schedule->call(fn () => null)->everyTenSeconds()->description('Poll');
        $schedule->command('inspire')->daily();

        Actuator::tagScheduledTasksUsing(static fn (Event $event): array => $event->description === 'Poll'
            ? ['module' => 'Shipping']
            : ['module' => str_contains((string) $event->command, 'blog:') ? 'Blog' : null, '' => 'dropped', 'list' => ['dropped']]);
        Actuator::tagScheduledTasksUsing(static fn (): array => throw new RuntimeException('broken'));
        Actuator::tagScheduledTasksUsing(static fn (Event $event): array => $event->description === 'Poll' ? ['module' => 'Tracking', 'weight' => 2] : []);

        $response = $this->getJson('/actuator/scheduledtasks')->assertOk();

        $this->assertSame(['module' => 'Blog'], $response->json('cron.0.tags'));
        $this->assertSame(['module' => 'Tracking', 'weight' => 2], $response->json('fixedRate.0.tags'));
        $response->assertJsonMissingPath('cron.1.tags');
        Exceptions::assertReported(RuntimeException::class);
    }

    #[Test]
    public function it_drops_tags_that_are_not_finite_numbers(): void
    {
        $this->app->make(Schedule::class)->command('inspire')->daily();

        Actuator::tagScheduledTasksUsing(static fn (): array => ['module' => 'Blog', 'inf' => INF, 'negative' => -INF, 'nan' => NAN, 'ratio' => 0.5]);

        $this->getJson('/actuator/scheduledtasks')->assertOk()->assertJsonPath('cron.0.tags', ['module' => 'Blog', 'ratio' => 0.5]);
    }

    #[Test]
    public function a_failing_reporter_does_not_fail_the_endpoint(): void
    {
        $this->app->make(Schedule::class)->command('inspire')->daily();
        Exceptions::reportable(static fn (RuntimeException $exception) => throw new LogicException('reporter down'));

        Actuator::tagScheduledTasksUsing(static fn (): array => throw new RuntimeException('broken'));
        Actuator::tagScheduledTasksUsing(static fn (): array => ['module' => 'Blog']);

        $this->getJson('/actuator/scheduledtasks')->assertOk()->assertJsonPath('cron.0.tags', ['module' => 'Blog']);
    }

    private function failTask(): void
    {
        $task = $this->app->make(Schedule::class)->command('report:send')->daily();

        event(new ScheduledTaskFailed($task, new LogicException('token=secret')));
    }
}

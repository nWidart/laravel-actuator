<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Nwidart\Actuator\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

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
}

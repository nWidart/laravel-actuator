<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Nwidart\Actuator\Support\ScheduledTaskTarget;
use Nwidart\Actuator\Tests\Fixtures\SucceedingJob;
use Nwidart\Actuator\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class ScheduledTaskTargetTest extends TestCase
{
    #[Test]
    public function the_name_of_a_task_leaves_out_the_arguments_of_its_command(): void
    {
        $schedule = $this->app->make(Schedule::class);

        $this->assertSame('report:send', ScheduledTaskTarget::name($schedule->command('report:send --token=secret --date=2026-01-01')));
        $this->assertSame('backup.sh', ScheduledTaskTarget::name($schedule->exec('TOKEN=secret backup.sh --all')));
        $this->assertSame('/usr/bin/sync', ScheduledTaskTarget::name($schedule->exec("'/usr/bin/sync' --password=secret")));
        $this->assertSame('Nightly report', ScheduledTaskTarget::name($schedule->command('report:send --token=secret')->description('Nightly report')));
        $this->assertSame(SucceedingJob::class, ScheduledTaskTarget::name($schedule->job(SucceedingJob::class)));
        $closure = $schedule->call(fn () => null);
        $this->assertSame($closure->getSummaryForDisplay(), ScheduledTaskTarget::name($closure));
    }

    #[Test]
    public function the_target_of_a_task_is_its_whole_command(): void
    {
        $schedule = $this->app->make(Schedule::class);

        $this->assertSame('php artisan report:send --date=2026-01-01', ScheduledTaskTarget::of($schedule->command('report:send --date=2026-01-01')));
    }
}

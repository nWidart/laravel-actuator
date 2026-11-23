<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Tests\Feature\Indicators;

use Illuminate\Cache\ArrayStore;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Nwidart\Actuator\Health\Indicators\SchedulerHealthIndicator;
use Nwidart\Actuator\Health\Status;
use Nwidart\Actuator\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

final class SchedulerHealthIndicatorTest extends TestCase
{
    /**
     * @return iterable<string, array{string, Status}>
     */
    public static function ages(): iterable
    {
        yield 'just ticked' => ['10:30:00', Status::Up];
        yield 'at the warning threshold' => ['10:32:00', Status::Up];
        yield 'past the warning threshold' => ['10:32:01', Status::Warning];
        yield 'at the down threshold' => ['10:35:00', Status::Warning];
        yield 'past the down threshold' => ['10:35:01', Status::Down];
    }

    #[Test]
    #[DataProvider('ages')]
    public function the_status_follows_the_time_since_the_last_tick(string $now, Status $status): void
    {
        $this->tick('2026-01-01 10:30:00 UTC');
        Carbon::setTestNow("2026-01-01 {$now} UTC");

        $health = (new SchedulerHealthIndicator)->health();

        $this->assertSame($status, $health->status);
        $this->assertSame(['lastTick' => '2026-01-01T10:30:00+00:00', 'downAfter' => 5, 'warningAfter' => 2], $health->details);
    }

    #[Test]
    public function it_is_down_when_the_scheduler_never_ticked(): void
    {
        $health = (new SchedulerHealthIndicator)->health();

        $this->assertSame(Status::Down, $health->status);
        $this->assertNull($health->details['lastTick']);
    }

    #[Test]
    public function the_thresholds_are_configurable(): void
    {
        $this->tick('2026-01-01 10:30:00 UTC');
        Carbon::setTestNow('2026-01-01 10:40:00 UTC');

        $this->assertSame(Status::Up, (new SchedulerHealthIndicator(downAfter: 15, warningAfter: null))->health()->status);
        $this->assertSame(Status::Warning, (new SchedulerHealthIndicator(downAfter: 15, warningAfter: 5))->health()->status);
        $this->assertSame(Status::Down, (new SchedulerHealthIndicator(downAfter: 9))->health()->status);
    }

    #[Test]
    public function it_rejects_a_threshold_under_a_minute(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SchedulerHealthIndicator(downAfter: 0);
    }

    #[Test]
    public function it_is_unknown_when_recording_is_off(): void
    {
        $this->tick('2026-01-01 10:30:00 UTC');
        config()->set('actuator.scheduler.record', false);

        $health = (new SchedulerHealthIndicator)->health();

        $this->assertSame(Status::Unknown, $health->status);
        $this->assertSame('Scheduler recording is off: set [actuator.scheduler.record] to true.', $health->details['error']);
    }

    #[Test]
    public function the_health_endpoint_reports_a_failing_store_as_down(): void
    {
        config()->set('cache.stores.broken', ['driver' => 'broken']);
        config()->set('actuator.scheduler.store', 'broken');
        Cache::extend('broken', fn () => Cache::repository(new class extends ArrayStore
        {
            public function get($key): mixed
            {
                throw new RuntimeException('Connection refused');
            }
        }));
        config()->set('actuator.health.indicators', ['scheduler' => SchedulerHealthIndicator::class]);
        config()->set('actuator.health.show_details', 'always');

        $this->getJson('/actuator/health')
            ->assertStatus(503)
            ->assertJsonPath('components.scheduler.status', 'DOWN')
            ->assertJsonPath('components.scheduler.details.error', 'RuntimeException: Connection refused');
    }

    #[Test]
    public function the_health_command_checks_the_scheduler(): void
    {
        config()->set('actuator.health.indicators', ['scheduler' => SchedulerHealthIndicator::class]);

        $this->artisan('actuator:health', ['path' => 'scheduler'])->expectsOutput('DOWN')->assertExitCode(1);

        $this->tick('2026-01-01 10:30:00 UTC');
        $this->artisan('actuator:health', ['path' => 'scheduler'])->expectsOutput('UP')->assertExitCode(0);
    }

    private function tick(string $time): void
    {
        Carbon::setTestNow($time);
        event(new CommandStarting('schedule:run', new ArrayInput([]), new NullOutput));
    }
}

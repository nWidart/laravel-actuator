<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Tests\Feature\Indicators;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Queue\Failed\FileFailedJobProvider;
use Illuminate\Queue\Failed\NullFailedJobProvider;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use Nwidart\Actuator\Health\Indicators\FailedJobsHealthIndicator;
use Nwidart\Actuator\Health\Status;
use Nwidart\Actuator\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

final class FailedJobsHealthIndicatorTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('queue.failed.database', 'testing');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('failed_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid')->nullable()->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
    }

    #[Test]
    public function no_failed_job_is_up(): void
    {
        $health = $this->indicator()->health();

        $this->assertSame(Status::Up, $health->status);
        $this->assertSame(['count' => 0, 'warningThreshold' => 1], $health->details);
    }

    #[Test]
    public function one_failed_job_is_a_warning_by_default(): void
    {
        $this->failJob('redis', 'default');

        $health = $this->indicator()->health();

        $this->assertSame(Status::Warning, $health->status);
        $this->assertSame(1, $health->details['count']);
    }

    #[Test]
    public function it_is_down_from_the_down_threshold(): void
    {
        $this->failJob('redis', 'default', times: 2);

        $status = fn (?int $warning, ?int $down): Status => $this->indicator(['warningThreshold' => $warning, 'downThreshold' => $down])->health()->status;

        $this->assertSame(Status::Down, $status(1, 2));
        $this->assertSame(Status::Warning, $status(1, 3));
        $this->assertSame(Status::Up, $status(null, 3));
        $this->assertSame(Status::Up, $status(null, null));
    }

    #[Test]
    public function it_counts_the_jobs_of_one_connection_and_queue(): void
    {
        $this->failJob('redis', 'default');
        $this->failJob('redis', 'emails', times: 2);
        $this->failJob('sqs', 'emails');

        $health = $this->indicator(['connection' => 'redis', 'queue' => 'emails'])->health();

        $this->assertSame(['connection' => 'redis', 'queue' => 'emails', 'count' => 2, 'warningThreshold' => 1], $health->details);
    }

    #[Test]
    public function it_counts_the_jobs_that_failed_within_the_window(): void
    {
        Date::setTestNow('2026-11-23 10:00:00');
        $this->failJob('redis', 'default', at: '2026-11-23 08:59:59');
        $this->failJob('redis', 'default', at: '2026-11-23 09:00:00');
        $this->failJob('redis', 'emails', at: '2026-11-23 09:30:00');
        $this->failJob('sqs', 'emails', at: '2026-11-23 09:45:00');

        $all = $this->indicator(['minutes' => 60])->health();
        $emails = $this->indicator(['minutes' => 60, 'connection' => 'redis', 'queue' => 'emails'])->health();

        $this->assertSame(['minutes' => 60, 'count' => 3, 'warningThreshold' => 1], $all->details);
        $this->assertSame(1, $emails->details['count']);
    }

    #[Test]
    public function the_window_works_with_the_database_failer_without_uuids(): void
    {
        config()->set('queue.failed.driver', 'database');
        $this->app->forgetInstance('queue.failer');
        Date::setTestNow('2026-11-23 10:00:00');
        $this->failJob('redis', 'default', at: '2026-11-23 09:59:00');

        $this->assertSame(1, $this->indicator(['minutes' => 5])->health()->details['count']);
    }

    #[Test]
    public function a_failer_that_cannot_count_is_unknown(): void
    {
        $health = $this->indicator(['failer' => Mockery::mock(FailedJobProviderInterface::class)])->health();

        $this->assertSame(Status::Unknown, $health->status);
        $this->assertStringEndsWith(' cannot count failed jobs', $health->details['error']);
    }

    #[Test]
    public function a_failer_other_than_the_database_cannot_count_within_a_window(): void
    {
        $health = $this->indicator(['failer' => new FileFailedJobProvider(sys_get_temp_dir().'/actuator-failed-jobs.json'), 'minutes' => 60])->health();

        $this->assertSame(Status::Unknown, $health->status);
        $this->assertSame(['minutes' => 60, 'error' => FileFailedJobProvider::class.' cannot count failed jobs within a window'], $health->details);
    }

    #[Test]
    public function a_countable_failer_counts_without_a_window(): void
    {
        $this->assertSame(Status::Up, $this->indicator(['failer' => new NullFailedJobProvider])->health()->status);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function indicator(array $arguments = []): FailedJobsHealthIndicator
    {
        return $this->app->make(FailedJobsHealthIndicator::class, $arguments);
    }

    private function failJob(string $connection, string $queue, int $times = 1, ?string $at = null): void
    {
        $now = Date::now();
        if ($at !== null) {
            Date::setTestNow($at);
        }

        for ($i = 0; $i < $times; $i++) {
            $this->app->make('queue.failer')->log($connection, $queue, '{"uuid":"'.Str::uuid().'"}', new RuntimeException('Failed'));
        }

        Date::setTestNow($now);
    }
}

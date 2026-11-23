<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Health\Indicators;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Queue\Failed\CountableFailedJobProvider;
use Illuminate\Queue\Failed\DatabaseFailedJobProvider;
use Illuminate\Queue\Failed\DatabaseUuidFailedJobProvider;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\Facades\Date;
use Nwidart\Actuator\Health\Health;
use Nwidart\Actuator\Health\HealthIndicator;

/**
 * Counts the failed jobs, of one connection or queue when given, and within the last $minutes
 * when set. WARNING from the warning threshold, DOWN from the down threshold. UNKNOWN when the
 * failer cannot count them: only the database failers count within a window.
 */
final readonly class FailedJobsHealthIndicator implements HealthIndicator
{
    /**
     * @param  int|null  $warningThreshold  count at which the jobs are WARNING. Null never is.
     * @param  int|null  $downThreshold  count at which the jobs are DOWN. Null never is.
     * @param  int|null  $minutes  count only the jobs that failed within this many minutes. Null counts them all.
     */
    public function __construct(
        private FailedJobProviderInterface $failer,
        private ConnectionResolverInterface $db,
        private Repository $config,
        private ?int $warningThreshold = 1,
        private ?int $downThreshold = null,
        private ?int $minutes = null,
        private ?string $connection = null,
        private ?string $queue = null,
    ) {}

    public function health(): Health
    {
        $details = array_filter([
            'connection' => $this->connection,
            'queue' => $this->queue,
            'minutes' => $this->minutes,
        ], static fn (mixed $value): bool => $value !== null);

        $count = $this->count();
        if ($count === null) {
            $reason = $this->minutes === null ? 'cannot count failed jobs' : 'cannot count failed jobs within a window';

            return Health::unknown([...$details, 'error' => $this->failer::class." {$reason}"]);
        }

        $details = [...$details, 'count' => $count] + array_filter([
            'warningThreshold' => $this->warningThreshold,
            'downThreshold' => $this->downThreshold,
        ], static fn (mixed $value): bool => $value !== null);

        return match (true) {
            $this->downThreshold !== null && $count >= $this->downThreshold => Health::down($details),
            $this->warningThreshold !== null && $count >= $this->warningThreshold => Health::warning($details),
            default => Health::up($details),
        };
    }

    /**
     * The failed jobs, or null when the failer cannot count them.
     */
    private function count(): ?int
    {
        if ($this->minutes === null) {
            return $this->failer instanceof CountableFailedJobProvider ? (int) $this->failer->count($this->connection, $this->queue) : null;
        }

        if (! $this->failer instanceof DatabaseFailedJobProvider && ! $this->failer instanceof DatabaseUuidFailedJobProvider) {
            return null;
        }

        // The failer's own getTable() is protected on early Laravel 12 releases.
        $database = $this->config->get('queue.failed.database');
        $table = $this->config->get('queue.failed.table');

        return $this->db->connection(is_string($database) ? $database : null)->table(is_string($table) ? $table : 'failed_jobs')
            ->where('failed_at', '>=', Date::now()->subMinutes($this->minutes))
            ->when($this->connection !== null, fn ($query) => $query->where('connection', $this->connection))
            ->when($this->queue !== null, fn ($query) => $query->where('queue', $this->queue))
            ->count();
    }
}

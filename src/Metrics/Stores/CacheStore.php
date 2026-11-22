<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Metrics\Stores;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Nwidart\Actuator\Metrics\MetricsStore;
use RuntimeException;

/**
 * Keeps every series in one cache entry, rewritten under a lock.
 *
 * It works with any Laravel cache store that supports locks, so it needs no
 * setup. Writers wait for each other: use the Redis store under heavy load.
 */
final readonly class CacheStore implements MetricsStore
{
    private const KEY = 'actuator:metrics';

    private const LOCK = 'actuator:metrics:lock';

    /**
     * @param  int  $attempts  how often a writer tries to get the lock before it drops its measurements
     * @param  int  $wait  time between two attempts, in milliseconds
     */
    public function __construct(private Repository $cache, private int $attempts = 10, private int $wait = 5) {}

    public function write(array $increments, array $values): void
    {
        $store = $this->cache->getStore();

        if (! $store instanceof LockProvider) {
            throw new RuntimeException('The cache store used for metrics must support locks. Use another cache store, or the "redis" metrics store.');
        }

        // The lock expires on its own, in case its owner dies before the release.
        $lock = $store->lock(self::LOCK, 5);

        for ($attempt = 1; ! $lock->get(); $attempt++) {
            if ($attempt >= $this->attempts) {
                // Losing a few measurements is better than keeping a worker busy.
                return;
            }

            usleep($this->wait * 1000);
        }

        try {
            $series = $this->read();

            foreach ($increments as $key => $amount) {
                $series[$key] = ($series[$key] ?? 0.0) + $amount;
            }

            // Some stores refuse an entry without throwing: memcached does above 1 MB.
            if (! $this->cache->forever(self::KEY, [...$series, ...$values])) {
                throw new RuntimeException('The cache store did not accept the metrics. Use another cache store, or the "redis" metrics store.');
            }
        } finally {
            $lock->release();
        }
    }

    public function read(): array
    {
        $series = [];
        $stored = $this->cache->get(self::KEY);

        foreach (is_array($stored) ? $stored : [] as $key => $value) {
            if (is_string($key) && (is_float($value) || is_int($value))) {
                $series[$key] = (float) $value;
            }
        }

        return $series;
    }

    public function clear(): void
    {
        $this->cache->forget(self::KEY);
    }
}

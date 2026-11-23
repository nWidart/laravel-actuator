<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Tests\Fixtures;

use Nwidart\Actuator\Health\Health;
use Nwidart\Actuator\Health\HealthIndicator;
use Nwidart\Actuator\Health\Status;

/**
 * Counts how many times it runs, so tests can tell a cache hit from a real run.
 */
final class CountingHealthIndicator implements HealthIndicator
{
    public static int $runs = 0;

    public function __construct(private readonly string $status = 'UP') {}

    public function health(): Health
    {
        self::$runs++;

        return new Health(Status::from($this->status), ['run' => self::$runs]);
    }
}

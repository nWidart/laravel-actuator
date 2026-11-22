<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Tests\Fixtures;

use Nwidart\Actuator\Metrics\MetricsStore;
use RuntimeException;

final class FailingMetricsStore implements MetricsStore
{
    public function write(array $increments, array $values): void
    {
        throw new RuntimeException('The store is down.');
    }

    public function read(): array
    {
        throw new RuntimeException('The store is down.');
    }

    public function clear(): void
    {
        throw new RuntimeException('The store is down.');
    }
}

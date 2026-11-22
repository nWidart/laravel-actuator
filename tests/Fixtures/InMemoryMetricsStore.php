<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Tests\Fixtures;

use Nwidart\Actuator\Metrics\MetricsStore;

final class InMemoryMetricsStore implements MetricsStore
{
    /** @var array<string, float> */
    public array $series = [];

    public int $writes = 0;

    public function write(array $increments, array $values): void
    {
        $this->writes++;

        foreach ($increments as $key => $amount) {
            $this->series[$key] = ($this->series[$key] ?? 0.0) + $amount;
        }

        $this->series = [...$this->series, ...$values];
    }

    public function read(): array
    {
        return $this->series;
    }

    public function clear(): void
    {
        $this->series = [];
    }
}

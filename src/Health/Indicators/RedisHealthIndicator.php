<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Health\Indicators;

use Illuminate\Redis\RedisManager;
use Nwidart\Actuator\Health\Health;
use Nwidart\Actuator\Health\HealthIndicator;

/**
 * Sends PING to a Redis connection (the "default" one when null).
 */
final readonly class RedisHealthIndicator implements HealthIndicator
{
    public function __construct(private RedisManager $redis, private ?string $connection = null) {}

    public function health(): Health
    {
        $connection = $this->redis->connection($this->connection);
        $connection->command('ping');

        return Health::up(['connection' => $connection->getName()]);
    }
}

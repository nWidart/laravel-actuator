<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Tests\Feature\Indicators;

use Illuminate\Database\QueryException;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\RedisManager;
use Mockery;
use Nwidart\Actuator\Health\Indicators\CacheHealthIndicator;
use Nwidart\Actuator\Health\Indicators\DatabaseHealthIndicator;
use Nwidart\Actuator\Health\Indicators\DiskSpaceHealthIndicator;
use Nwidart\Actuator\Health\Indicators\PingHealthIndicator;
use Nwidart\Actuator\Health\Indicators\ReadinessStateHealthIndicator;
use Nwidart\Actuator\Health\Indicators\RedisHealthIndicator;
use Nwidart\Actuator\Health\Status;
use Nwidart\Actuator\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class HealthIndicatorsTest extends TestCase
{
    #[Test]
    public function ping_is_up(): void
    {
        $this->assertSame(Status::Up, (new PingHealthIndicator)->health()->status);
    }

    #[Test]
    public function readiness_is_out_of_service_in_maintenance_mode(): void
    {
        $indicator = new ReadinessStateHealthIndicator($this->app);
        $this->assertSame(Status::Up, $indicator->health()->status);

        $this->app->maintenanceMode()->activate([]);

        try {
            $this->assertSame(Status::OutOfService, $indicator->health()->status);
        } finally {
            $this->app->maintenanceMode()->deactivate();
        }
    }

    #[Test]
    public function disk_space_is_up_above_the_threshold(): void
    {
        $health = (new DiskSpaceHealthIndicator(__DIR__, threshold: 1))->health();

        $this->assertSame(Status::Up, $health->status);
        $this->assertSame(__DIR__, $health->details['path']);
        $this->assertTrue($health->details['exists']);
        $this->assertGreaterThan(0, $health->details['free']);
        $this->assertGreaterThanOrEqual($health->details['free'], $health->details['total']);
    }

    #[Test]
    public function disk_space_is_down_below_the_threshold(): void
    {
        $this->assertSame(Status::Down, (new DiskSpaceHealthIndicator(__DIR__, threshold: PHP_INT_MAX))->health()->status);
    }

    #[Test]
    public function disk_space_is_down_for_a_missing_path(): void
    {
        $health = (new DiskSpaceHealthIndicator('/does/not/exist'))->health();

        $this->assertSame(Status::Down, $health->status);
        $this->assertFalse($health->details['exists']);
    }

    #[Test]
    public function disk_space_defaults_to_the_base_path(): void
    {
        $this->assertSame(base_path(), (new DiskSpaceHealthIndicator)->health()->details['path']);
    }

    #[Test]
    public function database_is_up_when_the_query_succeeds(): void
    {
        $health = $this->app->make(DatabaseHealthIndicator::class)->health();

        $this->assertSame(Status::Up, $health->status);
        $this->assertSame(['connection' => 'testing', 'database' => 'SQLite', 'validationQuery' => 'select 1'], $health->details);
    }

    #[Test]
    public function database_throws_when_the_connection_fails(): void
    {
        config()->set('database.connections.broken', ['driver' => 'sqlite', 'database' => '/does/not/exist.sqlite']);

        $this->expectException(QueryException::class);

        $this->app->make(DatabaseHealthIndicator::class, ['connection' => 'broken'])->health();
    }

    #[Test]
    public function cache_is_up_when_a_value_round_trips(): void
    {
        $health = $this->app->make(CacheHealthIndicator::class)->health();

        $this->assertSame(Status::Up, $health->status);
        $this->assertSame(['store' => 'array'], $health->details);
    }

    #[Test]
    public function cache_is_down_when_the_value_is_not_stored(): void
    {
        config()->set('cache.stores.null', ['driver' => 'null']);

        $health = $this->app->make(CacheHealthIndicator::class, ['store' => 'null'])->health();

        $this->assertSame(Status::Down, $health->status);
        $this->assertSame('null', $health->details['store']);
    }

    #[Test]
    public function redis_is_up_when_ping_succeeds(): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->expects('command')->with('ping')->andReturn(true);
        $connection->expects('getName')->andReturn('default');
        $redis = Mockery::mock(RedisManager::class);
        $redis->expects('connection')->with(null)->andReturn($connection);

        $health = (new RedisHealthIndicator($redis))->health();

        $this->assertSame(Status::Up, $health->status);
        $this->assertSame(['connection' => 'default'], $health->details);
    }
}

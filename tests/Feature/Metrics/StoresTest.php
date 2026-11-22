<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Tests\Feature\Metrics;

use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Mockery;
use Nwidart\Actuator\Metrics\MetricsStore;
use Nwidart\Actuator\Metrics\Stores\CacheStore;
use Nwidart\Actuator\Metrics\Stores\RedisStore;
use Nwidart\Actuator\Tests\Fixtures\InMemoryMetricsStore;
use Nwidart\Actuator\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use stdClass;

final class StoresTest extends TestCase
{
    #[Test]
    public function the_cache_store_adds_increments_and_overwrites_values(): void
    {
        $store = new CacheStore(Cache::store('array'));

        $store->write(['requests' => 1.0, 'seconds' => 0.25], ['queue.size' => 4.0]);
        $store->write(['requests' => 2.0], ['queue.size' => 1.0]);

        $this->assertSame(['requests' => 3.0, 'seconds' => 0.25, 'queue.size' => 1.0], $store->read());

        $store->clear();

        $this->assertSame([], $store->read());
    }

    #[Test]
    public function the_cache_store_shares_series_between_instances(): void
    {
        (new CacheStore(Cache::store('array')))->write(['requests' => 1.0], []);

        $this->assertSame(['requests' => 1.0], (new CacheStore(Cache::store('array')))->read());
    }

    #[Test]
    public function the_cache_store_drops_a_write_when_the_lock_stays_taken(): void
    {
        $store = new CacheStore(Cache::store('array'), attempts: 2, wait: 1);
        $lock = Cache::store('array')->lock('actuator:metrics:lock', 5);
        $this->assertTrue($lock->get());

        $store->write(['requests' => 1.0], []);
        $this->assertSame([], $store->read());

        $lock->release();
        $store->write(['requests' => 1.0], []);
        $this->assertSame(['requests' => 1.0], $store->read());
    }

    #[Test]
    public function the_cache_store_releases_its_lock(): void
    {
        (new CacheStore(Cache::store('array')))->write(['requests' => 1.0], []);

        $this->assertTrue(Cache::store('array')->lock('actuator:metrics:lock', 5)->get());
    }

    #[Test]
    public function the_cache_store_clears_under_the_lock_of_the_writers(): void
    {
        $store = new CacheStore(Cache::store('array'), attempts: 1, wait: 1);
        $store->write(['requests' => 1.0], []);

        // A writer holds the lock: it has read the entry and will write it back.
        $lock = Cache::store('array')->lock('actuator:metrics:lock', 5);
        $this->assertTrue($lock->get());

        try {
            $store->clear();
            $this->fail('A clear that a writer would undo must not report success.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('the store is busy', $e->getMessage());
        }

        $this->assertSame(['requests' => 1.0], $store->read());

        $lock->release();
        $store->clear();

        $this->assertSame([], $store->read());
        $this->assertTrue(Cache::store('array')->lock('actuator:metrics:lock', 5)->get());
    }

    #[Test]
    public function the_cache_store_needs_a_cache_with_locks(): void
    {
        $cache = Mockery::mock(Repository::class);
        $cache->expects('getStore')->andReturn(Mockery::mock(Store::class));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must support locks');

        (new CacheStore($cache))->write(['requests' => 1.0], []);
    }

    #[Test]
    public function the_cache_store_fails_when_the_cache_refuses_the_entry(): void
    {
        $cache = Mockery::mock(Repository::class);
        $cache->expects('getStore')->andReturn(new ArrayStore);
        $cache->expects('get')->andReturn(null);
        $cache->expects('forever')->andReturn(false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('did not accept');

        (new CacheStore($cache))->write(['requests' => 1.0], []);
    }

    #[Test]
    public function the_cache_store_ignores_a_cache_entry_it_did_not_write(): void
    {
        Cache::store('array')->forever('actuator:metrics', ['requests' => 1, 'text' => 'no', 3 => 2.0]);

        $this->assertSame(['requests' => 1.0], (new CacheStore(Cache::store('array')))->read());
    }

    #[Test]
    public function the_redis_store_writes_with_one_script(): void
    {
        $predis = Mockery::mock(Connection::class);
        $predis->expects('command')->withArgs(function (string $method, array $parameters): bool {
            [$script, $keys, $key, $increments, $values] = $parameters;

            return $method === 'eval'
                && str_contains($script, 'HINCRBYFLOAT')
                && $keys === 1
                && $key === 'actuator:metrics'
                && $increments === '{"requests":1,"seconds":0.25}'
                && $values === '{}';
        })->andReturn(1);

        (new RedisStore($predis))->write(['requests' => 1.0, 'seconds' => 0.25], []);

        $phpredis = Mockery::mock(PhpRedisConnection::class);
        $phpredis->expects('command')->withArgs(function (string $method, array $parameters): bool {
            [, $arguments, $keys] = $parameters;

            return $method === 'eval' && $keys === 1 && $arguments === ['actuator:metrics', '{}', '{"queue.size":4}'];
        })->andReturn(1);

        (new RedisStore($phpredis))->write([], ['queue.size' => 4.0]);
    }

    #[Test]
    public function the_redis_store_reads_and_clears_its_hash(): void
    {
        $redis = Mockery::mock(Connection::class);
        $redis->expects('command')->with('hgetall', ['actuator:metrics'])->andReturn(['requests' => '3', 'seconds' => '0.25', 'broken' => 'x']);
        $redis->expects('command')->with('del', ['actuator:metrics']);

        $store = new RedisStore($redis);

        $this->assertSame(['requests' => 3.0, 'seconds' => 0.25], $store->read());
        $store->clear();
    }

    #[Test]
    public function the_redis_store_fails_when_redis_refuses_a_command(): void
    {
        // phpredis answers false where predis throws.
        $redis = Mockery::mock(PhpRedisConnection::class);
        $redis->allows('command')->andReturn(false);
        $store = new RedisStore($redis);

        try {
            $store->read();
            $this->fail('A refused read must not look like an empty store.');
        } catch (RuntimeException $e) {
            $this->assertSame('Redis did not return the metrics.', $e->getMessage());
        }

        $this->expectExceptionMessage('Redis did not write the metrics.');

        $store->write(['requests' => 1.0], []);
    }

    #[Test]
    public function the_cache_store_is_the_default(): void
    {
        $this->assertInstanceOf(CacheStore::class, $this->app->make(MetricsStore::class));
    }

    #[Test]
    public function the_redis_store_uses_the_configured_connection(): void
    {
        config()->set('actuator.metrics.store', 'redis');
        config()->set('actuator.metrics.redis.connection', 'metrics');

        $redis = Mockery::mock(RedisManager::class);
        $redis->expects('connection')->with('metrics')->andReturn(Mockery::mock(Connection::class));
        $this->app->instance(RedisManager::class, $redis);

        $this->assertInstanceOf(RedisStore::class, $this->app->make(MetricsStore::class));
    }

    #[Test]
    public function a_class_name_selects_a_custom_store(): void
    {
        config()->set('actuator.metrics.store', InMemoryMetricsStore::class);

        $this->assertInstanceOf(InMemoryMetricsStore::class, $this->app->make(MetricsStore::class));
    }

    #[Test]
    public function a_custom_store_must_implement_the_contract(): void
    {
        config()->set('actuator.metrics.store', stdClass::class);

        $this->expectException(InvalidArgumentException::class);

        $this->app->make(MetricsStore::class);
    }
}

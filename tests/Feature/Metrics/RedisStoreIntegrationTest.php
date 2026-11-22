<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Tests\Feature\Metrics;

use Illuminate\Redis\RedisManager;
use Nwidart\Actuator\Metrics\Stores\RedisStore;
use Nwidart\Actuator\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Predis\Client;
use Throwable;

/**
 * Runs against a real Redis server, with each client that is installed.
 * Skipped when no server answers on REDIS_HOST and REDIS_PORT.
 */
final class RedisStoreIntegrationTest extends TestCase
{
    private function store(string $client): RedisStore
    {
        if ($client === 'phpredis' ? ! extension_loaded('redis') : ! class_exists(Client::class)) {
            $this->markTestSkipped("The {$client} client is not installed.");
        }

        config()->set('database.redis.client', $client);
        config()->set('database.redis.options.prefix', 'actuator-test:');
        config()->set('database.redis.default.host', env('REDIS_HOST', '127.0.0.1'));
        config()->set('database.redis.default.port', env('REDIS_PORT', 6379));
        config()->set('database.redis.default.timeout', 1);

        $store = new RedisStore($this->app->make(RedisManager::class)->connection());

        try {
            $store->clear();
        } catch (Throwable $e) {
            $this->markTestSkipped('No Redis server: '.$e->getMessage());
        }

        return $store;
    }

    #[Test]
    #[DataProvider('clients')]
    public function it_adds_increments_and_overwrites_values(string $client): void
    {
        $store = $this->store($client);
        $series = '["timer","http.server.requests","seconds",{"uri":"/users/{user}"},"sum"]';

        $store->write(['requests' => 1.0, $series => 0.25, 'hits' => 1234567890123456.0], ['queue.size' => 4.0, 'ratio' => 0.1]);
        $store->write(['requests' => 2.0, $series => 1.0E-5, 'hits' => 1.0], ['queue.size' => 1.5]);
        $store->write([], []);

        $stored = $store->read();
        ksort($stored);

        $this->assertSame(
            [$series => 0.25001, 'hits' => 1234567890123457.0, 'queue.size' => 1.5, 'ratio' => 0.1, 'requests' => 3.0],
            $stored,
        );

        $store->clear();

        $this->assertSame([], $store->read());
    }

    #[Test]
    #[DataProvider('clients')]
    public function it_fails_when_redis_refuses_the_command(string $client): void
    {
        $store = $this->store($client);
        $this->app->make(RedisManager::class)->connection()->command('set', ['actuator:metrics', 'not a hash']);

        try {
            $store->read();
            $this->fail('A refused read must not look like an empty store.');
        } catch (Throwable) {
            $this->addToAssertionCount(1);
        }

        try {
            $store->write(['requests' => 1.0], []);
            $this->fail('A refused write must not look like a success.');
        } catch (Throwable) {
            $this->addToAssertionCount(1);
        } finally {
            $store->clear();
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function clients(): iterable
    {
        yield 'phpredis' => ['phpredis'];
        yield 'predis' => ['predis'];
    }
}

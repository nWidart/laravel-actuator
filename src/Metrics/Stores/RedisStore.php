<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Metrics\Stores;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Nwidart\Actuator\Metrics\MetricsStore;

/**
 * Keeps every series as a field of one Redis hash. Writers never wait for
 * each other: a write is one script, which Redis runs atomically.
 */
final readonly class RedisStore implements MetricsStore
{
    private const KEY = 'actuator:metrics';

    // Redis passes a Lua number to a command with 17 significant digits, so nothing is rounded.
    private const WRITE = <<<'LUA'
        for field, amount in pairs(cjson.decode(ARGV[1])) do
            redis.call('HINCRBYFLOAT', KEYS[1], field, amount)
        end
        for field, value in pairs(cjson.decode(ARGV[2])) do
            redis.call('HSET', KEYS[1], field, value)
        end
        LUA;

    public function __construct(private Connection $redis) {}

    public function write(array $increments, array $values): void
    {
        $increments = (string) json_encode((object) $increments);
        $values = (string) json_encode((object) $values);

        // phpredis and predis order the arguments of EVAL differently.
        $this->redis->command('eval', $this->redis instanceof PhpRedisConnection
            ? [self::WRITE, [self::KEY, $increments, $values], 1]
            : [self::WRITE, 1, self::KEY, $increments, $values]);
    }

    public function read(): array
    {
        $series = [];
        $stored = $this->redis->command('hgetall', [self::KEY]);

        foreach (is_array($stored) ? $stored : [] as $key => $value) {
            if (is_string($key) && is_numeric($value)) {
                $series[$key] = (float) $value;
            }
        }

        return $series;
    }

    public function clear(): void
    {
        $this->redis->command('del', [self::KEY]);
    }
}

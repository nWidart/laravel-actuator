<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Health\Indicators;

use Illuminate\Cache\CacheManager;
use Illuminate\Support\Str;
use Nwidart\Actuator\Health\Health;
use Nwidart\Actuator\Health\HealthIndicator;

/**
 * Writes, reads and deletes a key on a cache store (the default one when null).
 */
final readonly class CacheHealthIndicator implements HealthIndicator
{
    public function __construct(private CacheManager $cache, private ?string $store = null) {}

    public function health(): Health
    {
        $store = $this->store ?? $this->cache->getDefaultDriver();
        $repository = $this->cache->store($store);
        $key = 'actuator:health:'.Str::random(16);
        $value = Str::random(16);

        $repository->put($key, $value, 10);
        $read = $repository->get($key);
        $repository->forget($key);

        $health = $read === $value ? Health::up() : Health::down(['error' => 'Value read back differs from value written']);

        return $health->withDetail('store', $store);
    }
}

<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Health\Indicators;

use Illuminate\Container\Container;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Nwidart\Actuator\Health\Health;
use Nwidart\Actuator\Health\HealthIndicator;

/**
 * The status of the Horizon master supervisors, as `php artisan horizon:status` reports it: DOWN
 * when none runs (inactive), WARNING when one is paused, UP when they run. UNKNOWN when
 * laravel/horizon is not installed.
 */
final readonly class HorizonHealthIndicator implements HealthIndicator
{
    public function health(): Health
    {
        if (! interface_exists(MasterSupervisorRepository::class)) {
            return Health::unknown(['error' => 'laravel/horizon is not installed']);
        }

        $repository = Container::getInstance()->make(MasterSupervisorRepository::class);
        $masters = $repository->all();

        $paused = array_filter($masters, static fn (mixed $master): bool => is_object($master) && ($master->status ?? null) === 'paused');

        return match (true) {
            $masters === [] => Health::down(['status' => 'inactive', 'masters' => 0]),
            $paused !== [] => Health::warning(['status' => 'paused', 'masters' => count($masters)]),
            default => Health::up(['status' => 'running', 'masters' => count($masters)]),
        };
    }
}

<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Health\Indicators;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Migrations\Migrator;
use Nwidart\Actuator\Health\Health;
use Nwidart\Actuator\Health\HealthIndicator;
use Throwable;

/**
 * OUT_OF_SERVICE while there are migrations that `php artisan migrate` would run, from the same
 * paths: the code expects a schema that the database does not have yet. The details list them.
 * UNKNOWN when the database cannot be read, which the "db" indicator reports.
 */
final readonly class MigrationsHealthIndicator implements HealthIndicator
{
    public function __construct(private Application $app) {}

    public function health(): Health
    {
        $migrator = $this->app->make(Migrator::class);
        $files = $migrator->getMigrationFiles([...$migrator->paths(), $this->app->databasePath('migrations')]);

        try {
            // Without the migrations table, nothing has run.
            $ran = $migrator->repositoryExists() ? $migrator->getRepository()->getRan() : [];
        } catch (Throwable $exception) {
            return Health::unknown()->withException($exception);
        }

        $pending = array_values(array_diff(array_keys($files), $ran));

        return $pending === [] ? Health::up() : Health::outOfService(['pending' => $pending]);
    }
}

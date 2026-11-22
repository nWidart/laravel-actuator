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
 * Migrations that migrate skips, with Migrator::withoutMigrations() or a shouldRun() that returns
 * false, are not pending. UNKNOWN when the database cannot be read, which the "db" indicator
 * reports.
 */
final readonly class MigrationsHealthIndicator implements HealthIndicator
{
    public function __construct(private Application $app) {}

    public function health(): Health
    {
        // Laravel 12.0 binds the migrator by name only.
        /** @var Migrator $migrator */
        $migrator = $this->app->make('migrator');
        $files = $migrator->getMigrationFiles([...$migrator->paths(), $this->app->databasePath('migrations')]);

        try {
            // Without the migrations table, nothing has run.
            $ran = $migrator->repositoryExists() ? $migrator->getRepository()->getRan() : [];
        } catch (Throwable $exception) {
            return Health::unknown()->withException($exception);
        }

        // What migrate runs: Laravel's own filter leaves out the migrations that have run and those
        // of withoutMigrations(). shouldSkip() reads shouldRun(), since Laravel 12.x.
        /** @var list<string> $paths */
        $paths = (fn (): array => $this->pendingMigrations($files, $ran))->call($migrator);
        $pending = [];
        foreach ($paths as $path) {
            // @phpstan-ignore function.alreadyNarrowedType (Laravel 12.0 has no shouldSkip())
            if (! method_exists($migrator, 'shouldSkip') || ! $migrator->shouldSkip($path)) {
                $pending[] = $migrator->getMigrationName($path);
            }
        }

        return $pending === [] ? Health::up() : Health::outOfService(['pending' => $pending]);
    }
}

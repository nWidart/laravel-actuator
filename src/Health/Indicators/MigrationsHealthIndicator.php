<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Health\Indicators;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Migrations\Migration;
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

        $pending = array_map($migrator->getMigrationName(...), self::pending($migrator, $files, $ran));

        return $pending === [] ? Health::up() : Health::outOfService(['pending' => $pending]);
    }

    /**
     * What migrate runs, with Laravel's own filter: without the migrations that have run, those of
     * withoutMigrations(), and those whose shouldRun() returns false (Laravel 12.x and later).
     *
     * @param  array<string, string>  $files  name => path
     * @param  array<string>  $ran
     * @return list<string> paths
     */
    private static function pending(Migrator $migrator, array $files, array $ran): array
    {
        return (fn (): array => array_values(array_filter(
            $this->pendingMigrations($files, $ran),
            fn (string $path): bool => ! (($migration = $this->resolvePath($path)) instanceof Migration
                // @phpstan-ignore function.alreadyNarrowedType (Laravel 12.0 has no shouldRun())
                && method_exists($migration, 'shouldRun') && ! $migration->shouldRun()),
        )))->call($migrator);
    }
}

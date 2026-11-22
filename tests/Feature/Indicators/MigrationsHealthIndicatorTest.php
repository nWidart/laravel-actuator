<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Tests\Feature\Indicators;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Database\QueryException;
use Nwidart\Actuator\Health\Indicators\MigrationsHealthIndicator;
use Nwidart\Actuator\Health\Status;
use Nwidart\Actuator\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class MigrationsHealthIndicatorTest extends TestCase
{
    #[Test]
    public function it_is_out_of_service_until_the_migrations_have_run(): void
    {
        $this->app->make('migrator')->path(dirname(__DIR__, 2).'/Fixtures/migrations');
        $indicator = $this->app->make(MigrationsHealthIndicator::class);

        $health = $indicator->health();
        $this->assertSame(Status::OutOfService, $health->status);
        $this->assertSame(['pending' => ['2026_01_01_000000_create_orders_table']], $health->details);

        $this->artisan('migrate')->assertSuccessful();

        $this->assertSame(Status::Up, $indicator->health()->status);
    }

    #[Test]
    public function migrations_that_migrate_skips_are_not_pending(): void
    {
        $migrator = $this->app->make('migrator');
        $migrator->path(dirname(__DIR__, 2).'/Fixtures/migrations');
        $migrator->path(dirname(__DIR__, 2).'/Fixtures/skipped-migrations');
        Migrator::withoutMigrations(['2026_01_01_000000_create_orders_table']);

        try {
            $this->assertSame(Status::Up, $this->app->make(MigrationsHealthIndicator::class)->health()->status);
        } finally {
            Migrator::withoutMigrations([]);
        }
    }

    #[Test]
    public function it_finds_the_migrator_by_its_name_as_laravel_12_0_binds_it(): void
    {
        unset($this->app[Migrator::class]);

        $this->assertSame(Status::Up, $this->app->make(MigrationsHealthIndicator::class)->health()->status);
    }

    #[Test]
    public function it_is_unknown_when_the_database_cannot_be_read(): void
    {
        config()->set('database.connections.broken', ['driver' => 'sqlite', 'database' => '/does/not/exist.sqlite']);
        config()->set('database.default', 'broken');

        $health = $this->app->make(MigrationsHealthIndicator::class)->health();

        $this->assertSame(Status::Unknown, $health->status);
        $this->assertStringStartsWith(QueryException::class, $health->details['error']);
    }

    #[Test]
    public function pending_migrations_take_the_readiness_group_out_of_service(): void
    {
        $this->getJson('/actuator/health/readiness')->assertOk()->assertExactJson(['status' => 'UP']);

        $this->app->make('migrator')->path(dirname(__DIR__, 2).'/Fixtures/migrations');

        $this->getJson('/actuator/health/readiness')->assertStatus(503)->assertExactJson(['status' => 'OUT_OF_SERVICE']);
        $this->getJson('/actuator/health/liveness')->assertOk();
    }
}

<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Tests\Feature\Indicators;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;
use Mockery;
use Nwidart\Actuator\Health\Indicators\DatabaseHealthIndicator;
use Nwidart\Actuator\Health\Status;
use Nwidart\Actuator\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

final class DatabaseHealthIndicatorTest extends TestCase
{
    #[Test]
    public function a_server_database_shows_its_open_and_maximum_connections(): void
    {
        $health = (new DatabaseHealthIndicator($this->manager($this->mysql(open: 40, max: 151))))->health();

        $this->assertSame(Status::Up, $health->status);
        $this->assertSame([
            'connection' => 'mysql',
            'database' => 'MySQL',
            'validationQuery' => 'select 1',
            'connections' => 40,
            'maxConnections' => 151,
        ], $health->details);
    }

    #[Test]
    public function it_is_down_once_the_open_connections_reach_the_maximum_usage(): void
    {
        $below = (new DatabaseHealthIndicator($this->manager($this->mysql(open: 89, max: 100)), maxConnectionUsage: 0.9))->health();
        $reached = (new DatabaseHealthIndicator($this->manager($this->mysql(open: 90, max: 100)), maxConnectionUsage: 0.9))->health();

        $this->assertSame(Status::Up, $below->status);
        $this->assertSame(Status::Down, $reached->status);
        $this->assertSame(['connections' => 90, 'maxConnections' => 100, 'maxConnectionUsage' => 0.9], array_slice($reached->details, 3));
    }

    #[Test]
    public function the_maximum_usage_is_reached_at_exactly_its_share(): void
    {
        $health = (new DatabaseHealthIndicator($this->manager($this->mysql(open: 55, max: 100)), maxConnectionUsage: 0.55))->health();

        $this->assertSame(Status::Down, $health->status);
    }

    #[Test]
    public function postgresql_counts_only_client_connections(): void
    {
        $connection = new class extends PostgresConnection
        {
            public function __construct()
            {
                parent::__construct(fn () => throw new RuntimeException('not connected'), 'shop', '', ['driver' => 'pgsql', 'name' => 'pgsql']);
            }

            public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
            {
                return [(object) ['1' => 1]];
            }

            public function scalar($query, $bindings = [], $useReadPdo = true)
            {
                return match ($query) {
                    "select count(*) from pg_stat_activity where backend_type = 'client backend'" => 12,
                    'show max_connections' => '100',
                    default => throw new RuntimeException("Unexpected query [{$query}]."),
                };
            }
        };

        $health = (new DatabaseHealthIndicator($this->manager($connection)))->health();

        $this->assertSame(['connections' => 12, 'maxConnections' => 100], array_slice($health->details, 3));
    }

    #[Test]
    public function connections_the_user_may_not_read_are_left_out(): void
    {
        $connection = $this->mysql(open: 0, max: 0, scalar: fn () => throw new RuntimeException('SELECT command denied'));

        $health = (new DatabaseHealthIndicator($this->manager($connection), maxConnectionUsage: 0.9))->health();

        $this->assertSame(Status::Up, $health->status);
        $this->assertArrayNotHasKey('connections', $health->details);
    }

    #[Test]
    public function sqlite_has_no_connection_details(): void
    {
        $health = $this->app->make(DatabaseHealthIndicator::class, ['maxConnectionUsage' => 0.9])->health();

        $this->assertSame(Status::Up, $health->status);
        $this->assertSame(['connection' => 'testing', 'database' => 'SQLite', 'validationQuery' => 'select 1'], $health->details);
    }

    /**
     * A MySQL connection that answers the validation query, threadCount() and the limit.
     *
     * @param  (Closure(string): mixed)|null  $scalar
     */
    private function mysql(int $open, int $max, ?Closure $scalar = null): MySqlConnection
    {
        $scalar ??= fn (string $query): string|int => $query === 'select @@max_connections' ? (string) $max : $open;

        return new class($scalar) extends MySqlConnection
        {
            public function __construct(private readonly Closure $answer)
            {
                parent::__construct(fn () => throw new RuntimeException('not connected'), 'shop', '', ['driver' => 'mysql', 'name' => 'mysql']);
            }

            public function isMaria()
            {
                return false;
            }

            public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
            {
                return [(object) ['1' => 1]];
            }

            public function scalar($query, $bindings = [], $useReadPdo = true)
            {
                return ($this->answer)($query);
            }
        };
    }

    private function manager(Connection $connection): DatabaseManager
    {
        $manager = Mockery::mock(DatabaseManager::class);
        $manager->allows('connection')->with(null)->andReturn($connection);

        return $manager;
    }
}

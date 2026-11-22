<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Health\Indicators;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Nwidart\Actuator\Health\Health;
use Nwidart\Actuator\Health\HealthIndicator;
use Throwable;

/**
 * Runs a validation query on a database connection (the default one when null). On MySQL,
 * MariaDB and PostgreSQL, the details also hold the client connections open on the server, from
 * every client, and the most it accepts. With a maximum connection usage, such as 0.9, the
 * database is DOWN once the open connections reach that share of the server's limit.
 */
final readonly class DatabaseHealthIndicator implements HealthIndicator
{
    private const VALIDATION_QUERY = 'select 1';

    /** Laravel driver name => the query that reads the server's connection limit. */
    private const MAX_CONNECTIONS = [
        'mysql' => 'select @@max_connections',
        'mariadb' => 'select @@max_connections',
        'pgsql' => 'show max_connections',
    ];

    /**
     * Laravel driver name => the query that counts the client connections, where threadCount()
     * counts more: on PostgreSQL, it also counts the background processes.
     */
    private const OPEN_CONNECTIONS = [
        'pgsql' => "select count(*) from pg_stat_activity where backend_type = 'client backend'",
    ];

    /**
     * @param  float|null  $maxConnectionUsage  share of the server's connection limit, from 0 to 1, at which the database is DOWN. Null never is.
     */
    public function __construct(private DatabaseManager $db, private ?string $connection = null, private ?float $maxConnectionUsage = null) {}

    public function health(): Health
    {
        $connection = $this->db->connection($this->connection);
        $connection->select(self::VALIDATION_QUERY);

        $details = [
            'connection' => $connection->getName(),
            'database' => $connection->getDriverTitle(),
            'validationQuery' => self::VALIDATION_QUERY,
        ];

        [$open, $max] = $this->connections($connection);
        if ($open === null || $max === null) {
            return Health::up($details);
        }

        $details['connections'] = $open;
        $details['maxConnections'] = $max;
        if ($this->maxConnectionUsage === null) {
            return Health::up($details);
        }

        $details['maxConnectionUsage'] = $this->maxConnectionUsage;

        return $open / $max >= $this->maxConnectionUsage ? Health::down($details) : Health::up($details);
    }

    /**
     * The connections open on the server and its limit. Null when the driver has none, or when
     * the user may not read them: the database still answers queries.
     *
     * @return array{?int, ?int}
     */
    private function connections(Connection $connection): array
    {
        $driver = $connection->getDriverName();
        if (! isset(self::MAX_CONNECTIONS[$driver])) {
            return [null, null];
        }

        try {
            $open = isset(self::OPEN_CONNECTIONS[$driver]) ? $connection->scalar(self::OPEN_CONNECTIONS[$driver]) : $connection->threadCount();
            $max = $connection->scalar(self::MAX_CONNECTIONS[$driver]);
        } catch (Throwable) {
            return [null, null];
        }

        return [is_numeric($open) ? (int) $open : null, is_numeric($max) && (int) $max > 0 ? (int) $max : null];
    }
}

<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Health\Indicators;

use Illuminate\Database\DatabaseManager;
use Nwidart\Actuator\Health\Health;
use Nwidart\Actuator\Health\HealthIndicator;

/**
 * Runs a validation query on a database connection (the default one when null).
 */
final readonly class DatabaseHealthIndicator implements HealthIndicator
{
    private const VALIDATION_QUERY = 'select 1';

    public function __construct(private DatabaseManager $db, private ?string $connection = null) {}

    public function health(): Health
    {
        $connection = $this->db->connection($this->connection);
        $connection->select(self::VALIDATION_QUERY);

        return Health::up([
            'connection' => $connection->getName(),
            'database' => $connection->getDriverTitle(),
            'validationQuery' => self::VALIDATION_QUERY,
        ]);
    }
}

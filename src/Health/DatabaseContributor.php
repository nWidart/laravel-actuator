<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Health;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use PDOException;

class DatabaseContributor implements HealthContributor
{
    /**
     * @var Repository
     */
    private $config;
    /**
     * @var DatabaseManager
     */
    private $db;

    public function __construct(Repository $config, DatabaseManager $db)
    {
        $this->config = $config;
        $this->db = $db;
    }

    public function run()
    {
        try {
            $this->db->reconnect()->getPdo();
        } catch (PDOException $e) {
            return 'Not connected';
        }
        return [
            'database' => $this->config->get('database.default'),
        ];
    }
}

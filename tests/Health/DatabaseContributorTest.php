<?php

namespace Nwidart\Actuator\Tests\Health;

use Exception;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Mockery;
use Nwidart\Actuator\Health\DatabaseContributor;
use Orchestra\Testbench\TestCase;
use PDOException;

final class DatabaseContributorTest extends TestCase
{
    /**
     * @var Repository|Mockery\LegacyMockInterface|Mockery\MockInterface
     */
    private $config;
    /**
     * @var DatabaseManager|Mockery\LegacyMockInterface|Mockery\MockInterface
     */
    private $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->config = Mockery::mock(Repository::class);
        $this->db = Mockery::mock(DatabaseManager::class);
    }

    /** @test */
    public function it_returns_message_when_db_is_not_configured(): void
    {
        $pdoException = new PDOException('123', 123, new Exception('', 123));
        $this->db->shouldReceive('reconnect', 'getPdo')->andThrowExceptions([$pdoException]);
        $databaseContributor = new DatabaseContributor($this->config, $this->db);

        $this->assertEquals('Not connected', $databaseContributor->run());
    }

    /** @test */
    public function it_returns_ok_when_db_is_connected(): void
    {
        $this->db->shouldReceive('getPdo');
        $connection = Mockery::mock(new Connection($this->db->getPdo()));
        $this->db->shouldReceive('reconnect')->andReturn($connection);
        $this->config->shouldReceive('get')->withArgs(['database.default'])->andReturn('mysql');

        $databaseContributor = new DatabaseContributor($this->config, $this->db);

        $this->assertEquals(['database' => 'mysql'], $databaseContributor->run());
    }

}

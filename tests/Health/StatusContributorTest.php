<?php

namespace Nwidart\Actuator\Tests\Health;

use Illuminate\Filesystem\Filesystem;
use Nwidart\Actuator\Health\StatusContributor;
use Orchestra\Testbench\TestCase;

final class StatusContributorTest extends TestCase
{
    /**
     * @var Filesystem
     */
    private $finder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->finder = $this->app['files'];
    }

    /** @test */
    public function it_returns_up(): void
    {
        $statusContributor = new StatusContributor($this->app);

        $this->assertEquals('UP', $statusContributor->run());
    }

    /** @test */
    public function it_returns_down_when_app_is_in_maintenance(): void
    {
        $this->finder->put($this->app->storagePath() . '/framework/down', 'down');
        $statusContributor = new StatusContributor($this->app);

        $this->assertEquals('DOWN', $statusContributor->run());
        $this->finder->delete($this->app->storagePath() . '/framework/down');
    }
}

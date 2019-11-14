<?php

namespace Nwidart\Actuator\Tests\Health;

use Nwidart\Actuator\Health\HealthBuilder;
use Nwidart\Actuator\Health\HealthContributorRegistryBuilder;
use Nwidart\Actuator\Health\StatusContributor;
use Orchestra\Testbench\TestCase;

final class HealthBuilderTest extends TestCase
{
    /** @test */
    public function it_does_not_touch_health_dto_if_no_contributors(): void
    {
        $this->app['config']->set('actuator.health.contributors', []);
        $builder = new HealthBuilder((new HealthContributorRegistryBuilder())->build());

        $health = $builder->build();

        $this->assertEquals([], $health->details);
        $this->assertNull($health->status);
    }

    /** @test */
    public function it_sets_the_status(): void
    {
        $this->app['config']->set('actuator.health.contributors', [
            'status' => StatusContributor::class,
        ]);
        $builder = new HealthBuilder((new HealthContributorRegistryBuilder())->build());

        $health = $builder->build();

        $this->assertEquals([], $health->details);
        $this->assertEquals('UP', $health->status);
    }

    /** @test */
    public function it_can_set_additional_contributors_in_details(): void
    {
        $this->app['config']->set('actuator.health.contributors', [
            'status' => StatusContributor::class,
            'dummy' => DummyHealthContributor::class,
        ]);
        $builder = new HealthBuilder((new HealthContributorRegistryBuilder())->build());

        $health = $builder->build();

        $this->assertEquals(['dummy' => 'dummy-data'], $health->details);
    }
}

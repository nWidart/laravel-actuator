<?php

namespace Nwidart\Actuator\Tests\Health;

use Nwidart\Actuator\Health\HealthContributorRegistry;
use PHPUnit\Framework\TestCase;

class HealthContributorRegistryTest extends TestCase
{
    /** @test */
    public function it_can_register_contributors(): void
    {
        $registry = new HealthContributorRegistry();
        $registry->register('dummy', new DummyHealthContributor());

        $this->assertCount(1, $registry->getAll());
    }

    /** @test */
    public function it_returns_a_collection_of_contributors(): void
    {
        $registry = new HealthContributorRegistry();
        $registry->register('dummy', new DummyHealthContributor());

        $this->assertInstanceOf(DummyHealthContributor::class, $registry->getAll()->first());
    }

    /** @test */
    public function it_can_find_a_contributor_by_name(): void
    {
        $registry = new HealthContributorRegistry();
        $registry->register('dummy', new DummyHealthContributor());

        $this->assertInstanceOf(DummyHealthContributor::class, $registry->findByName('dummy'));
    }

    /** @test */
    public function it_returns_null_if_name_does_not_exist(): void
    {
        $registry = new HealthContributorRegistry();

        $this->assertNull($registry->findByName('dummy'));
    }
}

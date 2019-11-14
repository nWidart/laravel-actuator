<?php

namespace Nwidart\Actuator\Tests\Health;

use Nwidart\Actuator\Health\StatusContributor;
use PHPUnit\Framework\TestCase;

final class StatusContributorTest extends TestCase
{
    /** @test */
    public function it_returns_up(): void
    {
        $statusContributor = new StatusContributor();

        $this->assertEquals('UP', $statusContributor->run());
    }
}

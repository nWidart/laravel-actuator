<?php

namespace Nwidart\Actuator\Tests\Health;

use Nwidart\Actuator\Health\DiskSpaceContributor;
use PHPUnit\Framework\TestCase;

final class DiskSpaceContributorTest extends TestCase
{
    /** @test */
    public function it_returns_array_of_used_and_total_disk_space(): void
    {
        $diskSpaceContributor = new DiskSpaceContributor();

        $this->assertArrayHasKey('free', $diskSpaceContributor->run());
        $this->assertArrayHasKey('total', $diskSpaceContributor->run());
    }
}

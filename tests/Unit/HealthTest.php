<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Tests\Unit;

use Nwidart\Actuator\Health\Health;
use Nwidart\Actuator\Health\Status;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class HealthTest extends TestCase
{
    #[Test]
    public function it_adds_details_without_mutating(): void
    {
        $health = Health::up(['a' => 1]);
        $withDetail = $health->withDetail('b', 2);

        $this->assertSame(['a' => 1], $health->details);
        $this->assertSame(['a' => 1, 'b' => 2], $withDetail->details);
        $this->assertSame(Status::Up, $withDetail->status);
    }

    #[Test]
    public function it_records_an_exception_as_error_detail(): void
    {
        $health = Health::down()->withException(new RuntimeException('Boom'));

        $this->assertSame(['error' => 'RuntimeException: Boom'], $health->details);
    }
}

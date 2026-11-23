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
    public function it_builds_each_status(): void
    {
        $this->assertSame(Status::Up, Health::up()->status);
        $this->assertSame(Status::Down, Health::down()->status);
        $this->assertSame(Status::OutOfService, Health::outOfService()->status);
        $this->assertSame(Status::Unknown, Health::unknown()->status);

        $warning = Health::warning(['usage' => 0.8]);
        $this->assertSame(Status::Warning, $warning->status);
        $this->assertSame(['usage' => 0.8], $warning->details);
    }

    #[Test]
    public function it_records_an_exception_as_error_detail(): void
    {
        $health = Health::down()->withException(new RuntimeException('Boom'));

        $this->assertSame(['error' => 'RuntimeException: Boom'], $health->details);
    }
}

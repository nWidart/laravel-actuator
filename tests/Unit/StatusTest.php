<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Tests\Unit;

use Nwidart\Actuator\Health\Status;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StatusTest extends TestCase
{
    #[Test]
    public function it_aggregates_to_the_most_severe_status(): void
    {
        $this->assertSame(Status::Down, Status::aggregate([Status::Up, Status::Down, Status::OutOfService]));
        $this->assertSame(Status::OutOfService, Status::aggregate([Status::Up, Status::OutOfService, Status::Unknown]));
        $this->assertSame(Status::Up, Status::aggregate([Status::Unknown, Status::Up]));
        $this->assertSame(Status::Unknown, Status::aggregate([]));
    }

    #[Test]
    public function it_maps_statuses_to_http_codes(): void
    {
        $this->assertSame(200, Status::Up->httpStatus());
        $this->assertSame(200, Status::Unknown->httpStatus());
        $this->assertSame(503, Status::Down->httpStatus());
        $this->assertSame(503, Status::OutOfService->httpStatus());
    }
}

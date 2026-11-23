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
    public function warning_sits_between_out_of_service_and_up(): void
    {
        $this->assertSame(Status::Warning, Status::aggregate([Status::Up, Status::Warning, Status::Unknown]));
        $this->assertSame(Status::OutOfService, Status::aggregate([Status::Warning, Status::OutOfService]));
    }

    #[Test]
    public function it_aggregates_with_a_custom_order(): void
    {
        $order = [Status::Warning, Status::Down];

        $this->assertSame(Status::Warning, Status::aggregate([Status::Down, Status::Warning], $order));
        $this->assertSame(Status::Down, Status::aggregate([Status::Up, Status::Down], $order));
        // Statuses missing from the order sort after the listed ones, in default order.
        $this->assertSame(Status::OutOfService, Status::aggregate([Status::Up, Status::OutOfService], $order));
        $this->assertSame(Status::Unknown, Status::aggregate([], $order));
    }

    #[Test]
    public function it_maps_statuses_to_http_codes(): void
    {
        $this->assertSame(200, Status::Up->httpStatus());
        $this->assertSame(200, Status::Unknown->httpStatus());
        $this->assertSame(200, Status::Warning->httpStatus());
        $this->assertSame(503, Status::Down->httpStatus());
        $this->assertSame(503, Status::OutOfService->httpStatus());
    }
}

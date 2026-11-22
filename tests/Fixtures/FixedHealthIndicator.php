<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Tests\Fixtures;

use Nwidart\Actuator\Health\Health;
use Nwidart\Actuator\Health\HealthIndicator;
use Nwidart\Actuator\Health\Status;
use RuntimeException;

final readonly class FixedHealthIndicator implements HealthIndicator
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(private string $status = 'UP', private array $details = ['key' => 'value']) {}

    public function health(): Health
    {
        if ($this->status === 'THROW') {
            throw new RuntimeException('Boom');
        }

        return new Health(Status::from($this->status), $this->details);
    }
}

<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Tests\Fixtures\Endpoints;

final class NoOperationEndpoint
{
    public function read(): string
    {
        return 'no attribute';
    }
}

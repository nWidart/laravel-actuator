<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Tests\Fixtures\Endpoints;

use Nwidart\Actuator\Endpoints\Attributes\ReadOperation;

final class ScalarParameterEndpoint
{
    #[ReadOperation]
    public function read(int $limit): void {}
}

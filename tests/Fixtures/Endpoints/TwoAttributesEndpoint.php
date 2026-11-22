<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Tests\Fixtures\Endpoints;

use Nwidart\Actuator\Endpoints\Attributes\ReadOperation;
use Nwidart\Actuator\Endpoints\Attributes\WriteOperation;

final class TwoAttributesEndpoint
{
    #[ReadOperation]
    #[WriteOperation]
    public function both(): void {}
}

<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Tests\Fixtures\Endpoints;

use Nwidart\Actuator\Endpoints\Attributes\ReadOperation;
use Nwidart\Actuator\Endpoints\Attributes\Selector;

final class IntegerSelectorEndpoint
{
    #[ReadOperation]
    public function read(#[Selector] int $id): void {}
}

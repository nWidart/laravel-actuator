<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Tests\Fixtures\Endpoints;

use Nwidart\Actuator\Endpoints\Attributes\ReadOperation;
use Nwidart\Actuator\Endpoints\Attributes\Selector;

final class SamePathEndpoint
{
    #[ReadOperation]
    public function first(#[Selector] string $name): void {}

    #[ReadOperation]
    public function second(#[Selector] string $slug): void {}
}

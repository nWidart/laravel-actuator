<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Endpoints\Attributes;

use Attribute;

/**
 * Binds an operation parameter to a path segment after the endpoint id.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class Selector
{
    /**
     * @param  bool  $remaining  Match the whole remaining path, slashes included. Only for the last selector.
     */
    public function __construct(public readonly bool $remaining = false) {}
}

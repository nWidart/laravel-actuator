<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Endpoints\Attributes;

use Attribute;

/**
 * Marks an endpoint method as a read operation, served with GET.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class ReadOperation {}

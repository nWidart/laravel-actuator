<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Endpoints\Attributes;

use Attribute;

/**
 * Marks an endpoint method as a write operation, served with POST.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class WriteOperation {}

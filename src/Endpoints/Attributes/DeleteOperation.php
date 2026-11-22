<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Endpoints\Attributes;

use Attribute;

/**
 * Marks an endpoint method as a delete operation, served with DELETE.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class DeleteOperation {}

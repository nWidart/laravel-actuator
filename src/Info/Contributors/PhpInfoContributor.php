<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Info\Contributors;

use Nwidart\Actuator\Info\InfoContributor;

final class PhpInfoContributor implements InfoContributor
{
    public function contribute(): array
    {
        return [
            'version' => PHP_VERSION,
            'sapi' => PHP_SAPI,
        ];
    }
}

<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Info\Contributors;

use Nwidart\Actuator\Info\InfoContributor;

/**
 * Memory figures are in bytes. A limit of -1 means unlimited.
 */
final class ProcessInfoContributor implements InfoContributor
{
    public function contribute(): array
    {
        return [
            'pid' => getmypid(),
            'memory' => [
                'usage' => memory_get_usage(true),
                'peak' => memory_get_peak_usage(true),
                'limit' => ini_parse_quantity(ini_get('memory_limit')),
            ],
        ];
    }
}

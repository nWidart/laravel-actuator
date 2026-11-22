<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Info\Contributors;

use Nwidart\Actuator\Info\InfoContributor;

final class OsInfoContributor implements InfoContributor
{
    public function contribute(): array
    {
        return [
            'name' => PHP_OS_FAMILY,
            'version' => php_uname('r'),
            'arch' => php_uname('m'),
        ];
    }
}

<?php

use Nwidart\Actuator\Health\DiskSpaceContributor;
use Nwidart\Actuator\Health\MemoryContributor;
use Nwidart\Actuator\Health\StatusContributor;

return [
    'health' => [
        'contributors' => [
            'status' => StatusContributor::class,
            'disk' => DiskSpaceContributor::class,
            'memory' => MemoryContributor::class,
        ],
    ],
];

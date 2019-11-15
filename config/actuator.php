<?php

use Nwidart\Actuator\Health\DatabaseContributor;
use Nwidart\Actuator\Health\DiskSpaceContributor;
use Nwidart\Actuator\Health\MemoryContributor;
use Nwidart\Actuator\Health\StatusContributor;

return [
    'health' => [
        'show-details' => false,
        'contributors' => [
            'status' => StatusContributor::class,
            'disk' => DiskSpaceContributor::class,
            'memory' => MemoryContributor::class,
            'db' => DatabaseContributor::class,
        ],
    ],
];

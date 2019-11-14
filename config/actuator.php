<?php

use Nwidart\Actuator\Health\DiskSpaceContributor;
use Nwidart\Actuator\Health\StatusContributor;

return [
    'health' => [
        'contributors' => [
            'status' => StatusContributor::class,
            'disk' => DiskSpaceContributor::class,
        ],
    ],
];

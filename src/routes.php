<?php

use Nwidart\Actuator\Health\HealthBuilder;

Route::get('/actuator/health', static function (HealthBuilder $healthBuilder) {
    return response()->json($healthBuilder->build());
});

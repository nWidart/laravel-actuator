<?php

use Illuminate\Support\Facades\Route;
use Nwidart\Actuator\Endpoint;
use Nwidart\Actuator\Http\Controllers\DiscoveryController;

Route::prefix(config()->string('actuator.base_path'))->name('actuator.')->group(function (): void {
    Route::get('/', DiscoveryController::class)
        ->middleware(Endpoint::sharedMiddleware())
        ->name('discovery');

    foreach (Endpoint::exposed() as $endpoint) {
        $route = Route::get($endpoint === Endpoint::Health ? 'health/{path?}' : $endpoint->value, $endpoint->controller())
            ->middleware($endpoint->middleware())
            ->name($endpoint->value);

        if ($endpoint === Endpoint::Health) {
            $route->where('path', '.*');
        }
    }
});

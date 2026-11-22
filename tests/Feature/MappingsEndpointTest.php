<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Nwidart\Actuator\Http\Controllers\HealthController;
use Nwidart\Actuator\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class MappingsEndpointTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('actuator.exposure.include', ['health', 'mappings']);
    }

    #[Test]
    public function it_lists_routes(): void
    {
        Route::post('orders/{order}', fn () => 'ok')->name('orders.update')->middleware('auth')->domain('shop.test');

        $routes = collect($this->getJson('/actuator/mappings')->assertOk()->json('routes'))->keyBy('uri');

        $this->assertSame([
            'methods' => ['POST'],
            'uri' => 'orders/{order}',
            'name' => 'orders.update',
            'action' => 'Closure',
            'middleware' => ['auth'],
            'domain' => 'shop.test',
        ], $routes['orders/{order}']);
        $this->assertSame(HealthController::class, $routes['actuator/health/{path?}']['action']);
    }
}

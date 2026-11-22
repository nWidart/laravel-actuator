<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Nwidart\Actuator\Tests\TestCase;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\Test;

final class EndpointsTest extends TestCase
{
    protected function exposeAll($app): void
    {
        $app['config']->set('actuator.exposure.include', ['*']);
    }

    protected function exposeAllButEnv($app): void
    {
        $app['config']->set('actuator.exposure.include', ['*']);
        $app['config']->set('actuator.exposure.exclude', ['env']);
    }

    protected function useCustomBasePathAndMiddleware($app): void
    {
        $app['config']->set('actuator.base_path', 'manage');
        $app['config']->set('actuator.exposure.include', ['health', 'env']);
        $app['config']->set('actuator.env.middleware', ['auth']);
    }

    #[Test]
    public function only_health_is_exposed_by_default(): void
    {
        $this->getJson('/actuator')->assertOk()->assertExactJson([
            '_links' => [
                'self' => ['href' => 'http://localhost/actuator', 'templated' => false],
                'health' => ['href' => 'http://localhost/actuator/health', 'templated' => false],
                'health-path' => ['href' => 'http://localhost/actuator/health/{*path}', 'templated' => true],
            ],
        ]);

        foreach (['info', 'env', 'mappings', 'scheduledtasks'] as $endpoint) {
            $this->getJson("/actuator/{$endpoint}")->assertNotFound();
        }
    }

    #[Test]
    #[DefineEnvironment('exposeAll')]
    public function a_wildcard_exposes_every_endpoint(): void
    {
        $this->getJson('/actuator')->assertOk()->assertJsonStructure([
            '_links' => ['self', 'health', 'health-path', 'info', 'env', 'mappings', 'scheduledtasks'],
        ]);

        foreach (['health', 'info', 'env', 'mappings', 'scheduledtasks'] as $endpoint) {
            $this->getJson("/actuator/{$endpoint}")->assertOk();
        }
    }

    #[Test]
    #[DefineEnvironment('exposeAllButEnv')]
    public function exclusion_wins_over_inclusion(): void
    {
        $this->getJson('/actuator/env')->assertNotFound();
        $this->getJson('/actuator/info')->assertOk();
    }

    #[Test]
    #[DefineEnvironment('useCustomBasePathAndMiddleware')]
    public function base_path_and_endpoint_middleware_are_configurable(): void
    {
        Route::get('login', fn () => 'login')->name('login');

        $this->getJson('/manage/health')->assertOk();
        $this->getJson('/manage/env')->assertUnauthorized();
        $this->getJson('/actuator/health')->assertNotFound();
    }
}

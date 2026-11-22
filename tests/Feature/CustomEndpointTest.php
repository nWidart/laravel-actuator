<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Nwidart\Actuator\Actuator;
use Nwidart\Actuator\Tests\Fixtures\Endpoints\WidgetsEndpoint;
use Nwidart\Actuator\Tests\TestCase;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\Test;

final class CustomEndpointTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        Actuator::endpoint('widgets', WidgetsEndpoint::class);
    }

    protected function exposeWidgets($app): void
    {
        $app['config']->set('actuator.exposure.include', ['health', 'widgets']);
    }

    protected function exposeAll($app): void
    {
        $app['config']->set('actuator.exposure.include', ['*']);
    }

    protected function exposeAllButWidgets($app): void
    {
        $app['config']->set('actuator.exposure.include', ['*']);
        $app['config']->set('actuator.exposure.exclude', ['widgets']);
    }

    protected function useCustomBasePathAndMiddleware($app): void
    {
        $app['config']->set('actuator.base_path', 'manage');
        $app['config']->set('actuator.exposure.include', ['health', 'widgets']);
        $app['config']->set('actuator.widgets.middleware', ['auth']);
    }

    #[Test]
    public function it_is_not_exposed_by_default(): void
    {
        $this->getJson('/actuator/widgets')->assertNotFound();
        $this->getJson('/actuator')->assertOk()->assertJsonMissingPath('_links.widgets');
    }

    #[Test]
    #[DefineEnvironment('exposeWidgets')]
    public function it_is_exposed_by_its_id_and_listed_in_the_discovery_response(): void
    {
        $this->getJson('/actuator/widgets')->assertOk()->assertExactJson(['widgets' => ['alpha']]);

        $this->getJson('/actuator')->assertOk()->assertExactJson([
            '_links' => [
                'self' => ['href' => 'http://localhost/actuator', 'templated' => false],
                'health' => ['href' => 'http://localhost/actuator/health', 'templated' => false],
                'health-path' => ['href' => 'http://localhost/actuator/health/{*path}', 'templated' => true],
                'widgets' => ['href' => 'http://localhost/actuator/widgets', 'templated' => false],
                'widgets-name' => ['href' => 'http://localhost/actuator/widgets/{name}', 'templated' => true],
                'widgets-name-part' => ['href' => 'http://localhost/actuator/widgets/{name}/{part}', 'templated' => true],
            ],
        ]);
    }

    #[Test]
    #[DefineEnvironment('exposeAll')]
    public function a_wildcard_exposes_it(): void
    {
        $this->getJson('/actuator/widgets')->assertOk();
    }

    #[Test]
    #[DefineEnvironment('exposeAllButWidgets')]
    public function exclusion_wins_over_inclusion(): void
    {
        $this->getJson('/actuator/widgets')->assertNotFound();
        $this->getJson('/actuator/info')->assertOk();
    }

    #[Test]
    #[DefineEnvironment('useCustomBasePathAndMiddleware')]
    public function it_uses_the_base_path_and_its_own_middleware(): void
    {
        Route::get('login', fn () => 'login')->name('login');

        $this->getJson('/manage/health')->assertOk();
        $this->getJson('/manage/widgets')->assertUnauthorized();
        $this->postJson('/manage/widgets/alpha')->assertUnauthorized();
        $this->getJson('/actuator/widgets')->assertNotFound();
    }

    #[Test]
    #[DefineEnvironment('exposeWidgets')]
    public function selectors_are_path_segments(): void
    {
        $this->getJson('/actuator/widgets/alpha')->assertOk()->assertExactJson(['name' => 'alpha', 'parts' => ['lid']]);
        $this->getJson('/actuator/widgets/alpha/lid')->assertOk()->assertExactJson(['widget' => 'alpha', 'part' => 'lid']);
    }

    #[Test]
    #[DefineEnvironment('exposeWidgets')]
    public function a_read_operation_that_returns_null_is_not_found(): void
    {
        $this->getJson('/actuator/widgets/missing')->assertNotFound();
    }

    #[Test]
    #[DefineEnvironment('exposeWidgets')]
    public function a_write_operation_answers_post_and_receives_the_request(): void
    {
        $this->postJson('/actuator/widgets/alpha', ['enabled' => true])
            ->assertOk()
            ->assertExactJson(['name' => 'alpha', 'enabled' => true]);
    }

    #[Test]
    #[DefineEnvironment('exposeWidgets')]
    public function a_write_operation_that_returns_nothing_has_no_content(): void
    {
        $this->postJson('/actuator/widgets')->assertNoContent();
    }

    #[Test]
    #[DefineEnvironment('exposeWidgets')]
    public function a_delete_operation_answers_delete_and_can_return_its_own_response(): void
    {
        $this->delete('/actuator/widgets/alpha')
            ->assertStatus(202)
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertContent('removed alpha');
    }

    #[Test]
    #[DefineEnvironment('exposeWidgets')]
    public function a_path_answers_only_the_verbs_of_its_operations(): void
    {
        $this->deleteJson('/actuator/widgets')->assertMethodNotAllowed();
        $this->postJson('/actuator/widgets/alpha/lid')->assertMethodNotAllowed();
    }

    #[Test]
    #[DefineEnvironment('exposeWidgets')]
    public function its_routes_are_named(): void
    {
        $this->assertSame('http://localhost/actuator/widgets', route('actuator.widgets'));
        $this->assertSame('http://localhost/actuator/widgets/alpha', route('actuator.widgets-name', ['name' => 'alpha']));
        $this->assertSame('http://localhost/actuator/widgets/alpha', route('actuator.widgets-name.write', ['name' => 'alpha']));
        $this->assertSame('http://localhost/actuator/widgets/alpha', route('actuator.widgets-name.delete', ['name' => 'alpha']));
        $this->assertSame('http://localhost/actuator/health/liveness', route('actuator.health-path', ['path' => 'liveness']));
    }

    #[Test]
    #[DefineEnvironment('exposeWidgets')]
    public function its_routes_can_be_cached(): void
    {
        $router = $this->app['router'];
        $router->setCompiledRoutes($router->getRoutes()->compile());

        $this->getJson('/actuator/widgets/alpha')->assertOk()->assertJsonPath('name', 'alpha');
        $this->postJson('/actuator/widgets')->assertNoContent();
        $this->getJson('/actuator/health/liveness')->assertOk();
        $this->getJson('/actuator')->assertOk()->assertJsonPath('_links.widgets-name.templated', true);
    }
}

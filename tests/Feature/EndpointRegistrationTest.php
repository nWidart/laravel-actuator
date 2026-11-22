<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Tests\Feature;

use InvalidArgumentException;
use LogicException;
use Nwidart\Actuator\Actuator;
use Nwidart\Actuator\ActuatorServiceProvider;
use Nwidart\Actuator\Tests\Fixtures\Endpoints\RegistersEndpointInBoot;
use Nwidart\Actuator\Tests\Fixtures\Endpoints\RegistersEndpointInRegister;
use Nwidart\Actuator\Tests\Fixtures\Endpoints\WidgetsEndpoint;
use Nwidart\Actuator\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

final class EndpointRegistrationTest extends TestCase
{
    /**
     * @var list<class-string>
     */
    private array $providers = [ActuatorServiceProvider::class];

    /**
     * @var list<string>
     */
    private array $include = ['health', 'widgets'];

    protected function getPackageProviders($app): array
    {
        return $this->providers;
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('actuator.exposure.include', $this->include);
    }

    #[Test]
    public function a_package_registers_an_endpoint_before_the_actuator_provider_is_registered(): void
    {
        $this->providers = [RegistersEndpointInRegister::class, ActuatorServiceProvider::class];
        $this->refreshApplication();

        $this->getJson('/actuator/widgets')->assertOk();
        $this->getJson('/actuator')->assertOk()->assertJsonPath('_links.widgets.href', 'http://localhost/actuator/widgets');
    }

    #[Test]
    public function a_package_registers_an_endpoint_after_the_actuator_provider_is_registered(): void
    {
        $this->providers = [ActuatorServiceProvider::class, RegistersEndpointInRegister::class];
        $this->refreshApplication();

        $this->getJson('/actuator/widgets')->assertOk();
    }

    #[Test]
    public function a_package_registers_an_endpoint_while_it_boots_after_the_actuator_provider(): void
    {
        $this->providers = [ActuatorServiceProvider::class, RegistersEndpointInBoot::class];
        $this->refreshApplication();

        $this->getJson('/actuator/widgets/alpha')->assertOk();
        $this->getJson('/actuator')->assertOk()->assertJsonPath('_links.widgets-name.templated', true);
        $this->assertSame('http://localhost/actuator/widgets', route('actuator.widgets'));
    }

    #[Test]
    public function an_endpoint_registered_while_booting_is_not_exposed_by_default(): void
    {
        $this->providers = [ActuatorServiceProvider::class, RegistersEndpointInBoot::class];
        $this->include = ['health'];
        $this->refreshApplication();

        $this->getJson('/actuator/widgets')->assertNotFound();
    }

    #[Test]
    public function an_endpoint_cannot_be_registered_after_the_application_has_booted(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Actuator endpoint [widgets] is registered after the application has booted.');

        Actuator::endpoint('widgets', WidgetsEndpoint::class);
    }

    #[Test]
    #[DataProvider('invalidIds')]
    public function it_rejects_an_invalid_id(string $id, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        Actuator::endpoint($id, WidgetsEndpoint::class);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidIds(): iterable
    {
        yield 'empty' => ['', 'must start with a lowercase letter'];
        yield 'uppercase first letter' => ['Widgets', 'must start with a lowercase letter'];
        yield 'digit first' => ['1widgets', 'must start with a lowercase letter'];
        yield 'dash' => ['my-widgets', 'must start with a lowercase letter'];
        yield 'dot' => ['my.widgets', 'must start with a lowercase letter'];
        yield 'slash' => ['my/widgets', 'must start with a lowercase letter'];
        yield 'discovery route' => ['discovery', 'Actuator endpoint id [discovery] is reserved.'];
        yield 'middleware config key' => ['middleware', 'Actuator endpoint id [middleware] is reserved.'];
        yield 'exposure config key' => ['exposure', 'Actuator endpoint id [exposure] is reserved.'];
        yield 'built-in id' => ['health', 'Actuator endpoint id [health] is already registered as [health].'];
        yield 'built-in id in another case' => ['scheduledTasks', 'Actuator endpoint id [scheduledTasks] is already registered as [scheduledtasks].'];
    }
}

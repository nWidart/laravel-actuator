<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Tests\Feature;

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Nwidart\Actuator\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class EnvEndpointTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('actuator.exposure.include', ['env']);
        $app['config']->set('app.key', 'base64:secret');
        $_ENV['ACTUATOR_TEST_VARIABLE'] = 'visible';
    }

    protected function tearDown(): void
    {
        unset($_ENV['ACTUATOR_TEST_VARIABLE']);

        parent::tearDown();
    }

    #[Test]
    public function it_masks_values_by_default(): void
    {
        $response = $this->getJson('/actuator/env')
            ->assertOk()
            ->assertJsonPath('activeProfiles', ['testing'])
            ->assertJsonPath('propertySources.0.name', 'config')
            ->assertJsonPath('propertySources.1.name', 'environment');

        $this->assertSame(['value' => '******'], $response->json('propertySources.0.properties')['app.key']);
        $this->assertSame(['value' => '******'], $response->json('propertySources.1.properties')['ACTUATOR_TEST_VARIABLE']);
    }

    #[Test]
    public function it_shows_values_when_allowed(): void
    {
        config()->set('actuator.env.show_values', 'always');

        $response = $this->getJson('/actuator/env');

        $this->assertSame(['value' => 'base64:secret'], $response->json('propertySources.0.properties')['app.key']);
        $this->assertSame(['value' => 'visible'], $response->json('propertySources.1.properties')['ACTUATOR_TEST_VARIABLE']);
    }

    #[Test]
    public function it_shows_values_to_authorized_users(): void
    {
        config()->set('actuator.env.show_values', 'when_authorized');
        Gate::define('viewActuator', fn (User $user): bool => true);

        $this->assertSame('******', $this->appKey($this->getJson('/actuator/env')->json()));
        $this->assertSame('base64:secret', $this->appKey($this->actingAs(new User)->getJson('/actuator/env')->json()));
    }

    /**
     * @param  array<string, mixed>  $env
     */
    private function appKey(array $env): mixed
    {
        return $env['propertySources'][0]['properties']['app.key']['value'];
    }
}

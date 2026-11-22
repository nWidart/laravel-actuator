<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Tests\Feature;

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Nwidart\Actuator\Health\Indicators\PingHealthIndicator;
use Nwidart\Actuator\Health\Indicators\ReadinessStateHealthIndicator;
use Nwidart\Actuator\Tests\Fixtures\FixedHealthIndicator;
use Nwidart\Actuator\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class HealthEndpointTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('actuator.health.indicators', [
            'ping' => PingHealthIndicator::class,
            'fixed' => FixedHealthIndicator::class,
        ]);
        $app['config']->set('actuator.health.groups', [
            'liveness' => ['include' => ['ping']],
        ]);
    }

    #[Test]
    public function it_hides_components_and_details_by_default(): void
    {
        $this->getJson('/actuator/health')
            ->assertOk()
            ->assertExactJson(['status' => 'UP', 'groups' => ['liveness']]);
    }

    #[Test]
    public function it_shows_components_and_details_when_allowed(): void
    {
        config()->set('actuator.health.show_details', 'always');

        $this->getJson('/actuator/health')
            ->assertOk()
            ->assertExactJson([
                'status' => 'UP',
                'components' => [
                    'ping' => ['status' => 'UP'],
                    'fixed' => ['status' => 'UP', 'details' => ['key' => 'value']],
                ],
                'groups' => ['liveness'],
            ]);
    }

    #[Test]
    public function it_shows_components_without_details(): void
    {
        config()->set('actuator.health.show_components', 'always');

        $this->getJson('/actuator/health')
            ->assertOk()
            ->assertJsonPath('components.fixed', ['status' => 'UP']);
    }

    #[Test]
    public function it_shows_details_only_when_the_gate_allows(): void
    {
        config()->set('actuator.health.show_details', 'when_authorized');

        $this->getJson('/actuator/health')->assertJsonMissingPath('components');

        Gate::define('viewActuator', fn (?User $user): bool => $user?->getAuthIdentifier() === 1);
        $this->getJson('/actuator/health')->assertJsonMissingPath('components');

        $user = new User;
        $user->id = 1;
        $this->actingAs($user)->getJson('/actuator/health')->assertJsonPath('components.fixed.details', ['key' => 'value']);
    }

    #[Test]
    public function it_returns_503_with_the_most_severe_status(): void
    {
        config()->set('actuator.health.indicators.fixed', [FixedHealthIndicator::class, ['status' => 'OUT_OF_SERVICE']]);
        config()->set('actuator.health.indicators.down', [FixedHealthIndicator::class, ['status' => 'DOWN']]);

        $this->getJson('/actuator/health')
            ->assertStatus(503)
            ->assertJsonPath('status', 'DOWN');
    }

    #[Test]
    public function it_reports_a_throwing_indicator_as_down(): void
    {
        config()->set('actuator.health.show_details', 'always');
        config()->set('actuator.health.indicators.fixed', [FixedHealthIndicator::class, ['status' => 'THROW']]);

        $this->getJson('/actuator/health')
            ->assertStatus(503)
            ->assertJsonPath('components.fixed', ['status' => 'DOWN', 'details' => ['error' => 'RuntimeException: Boom']])
            ->assertJsonPath('components.ping.status', 'UP');
    }

    #[Test]
    public function it_reports_an_indicator_that_cannot_be_resolved_as_down(): void
    {
        config()->set('actuator.health.show_details', 'always');
        config()->set('actuator.health.indicators.broken', 'App\\Health\\DoesNotExist');

        $this->getJson('/actuator/health')
            ->assertStatus(503)
            ->assertJsonPath('components.broken', ['status' => 'DOWN', 'details' => [
                'error' => 'Illuminate\\Contracts\\Container\\BindingResolutionException: Target class [App\\Health\\DoesNotExist] does not exist.',
            ]])
            ->assertJsonPath('components.ping.status', 'UP');
    }

    #[Test]
    public function it_reports_unknown_without_indicators(): void
    {
        config()->set('actuator.health.indicators', []);
        config()->set('actuator.health.groups', []);

        $this->getJson('/actuator/health')->assertOk()->assertExactJson(['status' => 'UNKNOWN']);
    }

    #[Test]
    public function it_returns_a_group(): void
    {
        config()->set('actuator.health.indicators.fixed', [FixedHealthIndicator::class, ['status' => 'DOWN']]);

        $this->getJson('/actuator/health/liveness')
            ->assertOk()
            ->assertExactJson(['status' => 'UP']);
    }

    #[Test]
    public function a_group_overrides_visibility(): void
    {
        config()->set('actuator.health.groups.liveness.show_details', 'always');

        $this->getJson('/actuator/health/liveness')
            ->assertExactJson(['status' => 'UP', 'components' => ['ping' => ['status' => 'UP']]]);
        $this->getJson('/actuator/health')->assertJsonMissingPath('components');
    }

    #[Test]
    public function it_returns_a_component_when_components_are_shown(): void
    {
        $this->getJson('/actuator/health/fixed')->assertNotFound();

        config()->set('actuator.health.show_details', 'always');

        $this->getJson('/actuator/health/fixed')
            ->assertOk()
            ->assertExactJson(['status' => 'UP', 'details' => ['key' => 'value']]);
        $this->getJson('/actuator/health/liveness/ping')->assertOk()->assertExactJson(['status' => 'UP']);
    }

    #[Test]
    public function it_returns_404_for_unknown_paths(): void
    {
        config()->set('actuator.health.show_details', 'always');

        $this->getJson('/actuator/health/missing')->assertNotFound();
        $this->getJson('/actuator/health/liveness/fixed')->assertNotFound();
        $this->getJson('/actuator/health/fixed/extra')->assertNotFound();
    }

    #[Test]
    public function it_rejects_groups_with_unknown_indicators(): void
    {
        config()->set('actuator.health.groups.liveness.include', ['missing']);

        $this->withoutExceptionHandling();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Health group includes unknown indicator [missing].');

        $this->getJson('/actuator/health/liveness');
    }

    #[Test]
    public function it_answers_in_maintenance_mode_with_readiness_out_of_service(): void
    {
        config()->set('actuator.health.indicators.readinessState', ReadinessStateHealthIndicator::class);
        config()->set('actuator.health.groups.readiness', ['include' => ['readinessState']]);
        $this->app->maintenanceMode()->activate([]);

        try {
            $this->getJson('/actuator/health/readiness')->assertStatus(503)->assertExactJson(['status' => 'OUT_OF_SERVICE']);
            $this->getJson('/actuator/health/liveness')->assertOk()->assertExactJson(['status' => 'UP']);
        } finally {
            $this->app->maintenanceMode()->deactivate();
        }
    }
}

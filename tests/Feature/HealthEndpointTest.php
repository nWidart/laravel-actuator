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
use PHPUnit\Framework\Attributes\DataProvider;
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

        $response = $this->getJson('/actuator/health')->assertOk();

        $this->assertEquals([
            'status' => 'UP',
            'components' => [
                'ping' => ['status' => 'UP'],
                'fixed' => ['status' => 'UP', 'details' => ['key' => 'value']],
            ],
            'groups' => ['liveness'],
        ], $this->withoutDurations($response->json()));
        $this->assertMatchesRegularExpression('/^PT\d+(\.\d+)?S$/', $response->json('components.ping.details.duration'));
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
        $this->actingAs($user)->getJson('/actuator/health')->assertJsonPath('components.fixed.details.key', 'value');
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
            ->assertJsonPath('components.fixed.status', 'DOWN')
            ->assertJsonPath('components.fixed.details.error', 'RuntimeException: Boom')
            ->assertJsonPath('components.ping.status', 'UP');
    }

    #[Test]
    public function it_reports_an_indicator_that_cannot_be_resolved_as_down(): void
    {
        config()->set('actuator.health.show_details', 'always');
        config()->set('actuator.health.indicators.broken', 'App\\Health\\DoesNotExist');

        $this->getJson('/actuator/health')
            ->assertStatus(503)
            ->assertJsonPath('components.broken.status', 'DOWN')
            ->assertJsonPath('components.broken.details.error', 'Illuminate\\Contracts\\Container\\BindingResolutionException: Target class [App\\Health\\DoesNotExist] does not exist.')
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

        $this->assertEquals(
            ['status' => 'UP', 'components' => ['ping' => ['status' => 'UP']]],
            $this->withoutDurations($this->getJson('/actuator/health/liveness')->json()),
        );
        $this->getJson('/actuator/health')->assertJsonMissingPath('components');
    }

    #[Test]
    public function it_returns_a_component_when_components_are_shown(): void
    {
        $this->getJson('/actuator/health/fixed')->assertNotFound();

        config()->set('actuator.health.show_details', 'always');

        $this->assertEquals(['status' => 'UP', 'details' => ['key' => 'value']], $this->withoutDurations($this->getJson('/actuator/health/fixed')->assertOk()->json()));
        $this->assertEquals(['status' => 'UP'], $this->withoutDurations($this->getJson('/actuator/health/liveness/ping')->assertOk()->json()));
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

    #[Test]
    public function it_answers_200_with_a_warning(): void
    {
        config()->set('actuator.health.indicators.fixed', [FixedHealthIndicator::class, ['status' => 'WARNING']]);

        $this->getJson('/actuator/health')
            ->assertOk()
            ->assertJsonPath('status', 'WARNING');
    }

    #[Test]
    public function it_aggregates_with_a_custom_order(): void
    {
        config()->set('actuator.health.status.order', ['UP', 'WARNING']);
        config()->set('actuator.health.indicators.fixed', [FixedHealthIndicator::class, ['status' => 'WARNING']]);

        $this->getJson('/actuator/health')->assertOk()->assertJsonPath('status', 'UP');
    }

    #[Test]
    public function it_maps_statuses_to_custom_http_codes(): void
    {
        config()->set('actuator.health.status.http_mapping', ['WARNING' => 207, 'DOWN' => 500]);
        config()->set('actuator.health.indicators.fixed', [FixedHealthIndicator::class, ['status' => 'WARNING']]);

        $this->getJson('/actuator/health')->assertStatus(207)->assertJsonPath('status', 'WARNING');

        config()->set('actuator.health.indicators.fixed', [FixedHealthIndicator::class, ['status' => 'DOWN']]);
        $this->getJson('/actuator/health')->assertStatus(500);

        // Unmapped statuses keep their default code.
        config()->set('actuator.health.indicators.fixed', [FixedHealthIndicator::class, ['status' => 'OUT_OF_SERVICE']]);
        $this->getJson('/actuator/health')->assertStatus(503);
    }

    #[Test]
    public function a_group_overrides_the_status_order_and_http_mapping(): void
    {
        config()->set('actuator.health.status.http_mapping', ['WARNING' => 207]);
        config()->set('actuator.health.indicators.fixed', [FixedHealthIndicator::class, ['status' => 'WARNING']]);
        config()->set('actuator.health.groups.mixed', ['include' => ['ping', 'fixed'], 'status' => [
            'order' => ['UP'],
            'http_mapping' => ['UP' => 299],
        ]]);
        config()->set('actuator.health.groups.warn', ['include' => ['fixed'], 'status' => ['http_mapping' => ['WARNING' => 429]]]);

        $this->getJson('/actuator/health')->assertStatus(207)->assertJsonPath('status', 'WARNING');
        $this->getJson('/actuator/health/mixed')->assertStatus(299)->assertExactJson(['status' => 'UP']);
        $this->getJson('/actuator/health/warn')->assertStatus(429)->assertExactJson(['status' => 'WARNING']);
    }

    #[Test]
    public function a_component_of_a_group_uses_the_group_http_mapping(): void
    {
        config()->set('actuator.health.show_components', 'always');
        config()->set('actuator.health.indicators.fixed', [FixedHealthIndicator::class, ['status' => 'WARNING']]);
        config()->set('actuator.health.groups.warn', ['include' => ['fixed'], 'status' => ['http_mapping' => ['WARNING' => 429]]]);

        $this->getJson('/actuator/health/fixed')->assertOk();
        $this->getJson('/actuator/health/warn/fixed')->assertStatus(429);
    }

    #[Test]
    public function a_group_inherits_the_status_keys_it_does_not_set(): void
    {
        config()->set('actuator.health.status', ['order' => ['UP'], 'http_mapping' => ['DOWN' => 500]]);
        config()->set('actuator.health.indicators.fixed', [FixedHealthIndicator::class, ['status' => 'DOWN']]);
        config()->set('actuator.health.groups.ordered', ['include' => ['ping', 'fixed'], 'status' => ['order' => ['DOWN']]]);
        config()->set('actuator.health.groups.mapped', ['include' => ['ping', 'fixed'], 'status' => ['http_mapping' => ['UP' => 299]]]);

        // The group sets "order" and inherits the endpoint's "http_mapping".
        $this->getJson('/actuator/health/ordered')->assertStatus(500)->assertExactJson(['status' => 'DOWN']);
        // The group sets "http_mapping" and inherits the endpoint's "order".
        $this->getJson('/actuator/health/mapped')->assertStatus(299)->assertExactJson(['status' => 'UP']);
    }

    #[Test]
    public function a_group_http_mapping_replaces_the_endpoint_mapping(): void
    {
        config()->set('actuator.health.status.http_mapping', ['DOWN' => 500]);
        config()->set('actuator.health.indicators.fixed', [FixedHealthIndicator::class, ['status' => 'DOWN']]);
        config()->set('actuator.health.groups.warn', ['include' => ['fixed'], 'status' => ['http_mapping' => ['WARNING' => 429]]]);

        $this->getJson('/actuator/health')->assertStatus(500);
        // The endpoint's DOWN => 500 is not merged in: DOWN keeps its default code.
        $this->getJson('/actuator/health/warn')->assertStatus(503)->assertExactJson(['status' => 'DOWN']);
    }

    #[Test]
    public function it_accepts_status_codes_in_any_case(): void
    {
        config()->set('actuator.health.status', ['order' => ['up', 'Warning'], 'http_mapping' => ['up' => 299]]);
        config()->set('actuator.health.indicators.fixed', [FixedHealthIndicator::class, ['status' => 'WARNING']]);

        $this->getJson('/actuator/health')->assertStatus(299)->assertJsonPath('status', 'UP');
    }

    /**
     * @return iterable<string, array{0: string, 1: mixed, 2: string}>
     */
    public static function invalidStatusConfig(): iterable
    {
        yield 'unknown status in order' => ['order', ['DOWN', 'DEGRADED'], 'Health status setting [actuator.health.status.order] contains unknown status [DEGRADED].'];
        yield 'order not a list' => ['order', 'DOWN', 'Health status setting [actuator.health.status.order] must be a list of status codes.'];
        yield 'unknown status in mapping' => ['http_mapping', ['DEGRADED' => 200], 'Health status setting [actuator.health.status.http_mapping] contains unknown status [DEGRADED].'];
        yield 'non-int HTTP code' => ['http_mapping', ['WARNING' => '207'], 'Health status setting [actuator.health.status.http_mapping] must map [WARNING] to an integer HTTP status code from 200 to 599.'];
        yield 'HTTP code above 599' => ['http_mapping', ['DOWN' => 5000], 'Health status setting [actuator.health.status.http_mapping] must map [DOWN] to an integer HTTP status code from 200 to 599.'];
        yield 'informational HTTP code' => ['http_mapping', ['WARNING' => 103], 'Health status setting [actuator.health.status.http_mapping] must map [WARNING] to an integer HTTP status code from 200 to 599.'];
        yield 'HTTP code below 100' => ['http_mapping', ['DOWN' => 99], 'Health status setting [actuator.health.status.http_mapping] must map [DOWN] to an integer HTTP status code from 200 to 599.'];
        yield 'duplicate status in order' => ['order', ['down', 'UP', 'DOWN'], 'Health status setting [actuator.health.status.order] lists [DOWN] more than once.'];
        yield 'duplicate status in mapping' => ['http_mapping', ['down' => 500, 'DOWN' => 502], 'Health status setting [actuator.health.status.http_mapping] maps [DOWN] more than once.'];
        yield 'mapping not an array' => ['http_mapping', 207, 'Health status setting [actuator.health.status.http_mapping] must map status codes to HTTP status codes.'];
    }

    #[Test]
    #[DataProvider('invalidStatusConfig')]
    public function it_rejects_invalid_status_config(string $key, mixed $value, string $message): void
    {
        config()->set("actuator.health.status.{$key}", $value);

        $this->withoutExceptionHandling();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $this->getJson('/actuator/health');
    }

    #[Test]
    public function it_rejects_invalid_group_status_config(): void
    {
        config()->set('actuator.health.groups.liveness.status.order', ['NOPE']);

        $this->withoutExceptionHandling();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Health status setting [actuator.health.groups.liveness.status.order] contains unknown status [NOPE].');

        $this->getJson('/actuator/health/liveness');
    }

    /**
     * The health JSON without the "duration" detail, which differs on each run. Details left empty
     * are removed.
     *
     * @param  array<string, mixed>  $health
     * @return array<string, mixed>
     */
    private function withoutDurations(array $health): array
    {
        unset($health['details']['duration']);
        if (($health['details'] ?? null) === []) {
            unset($health['details']);
        }

        foreach ($health['components'] ?? [] as $name => $component) {
            $health['components'][$name] = $this->withoutDurations($component);
        }

        return $health;
    }
}

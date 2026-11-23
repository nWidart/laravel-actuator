<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Health;

use Illuminate\Contracts\Container\Container;
use Nwidart\Actuator\Metrics\MeterRegistry;
use Nwidart\Metrics\Metrics;
use Throwable;

/**
 * Exports the health status as callback gauges, read during the scrape. "laravel.health.status"
 * has one series per indicator ("component") and status, "laravel.health.overall.status" one per
 * status for the whole application. A series is 1 for the current status and 0 for the others, as
 * in kube-state-metrics, so no series vanishes when a status changes.
 *
 * The indicators run once per scrape, or not at all while their results are in the health cache.
 *
 * @internal
 */
final class HealthGauges
{
    public const STATUS_METER = 'laravel.health.status';

    public const OVERALL_METER = 'laravel.health.overall.status';

    /** @var array{status: Status, components: array<string, Status>}|Throwable|null */
    private array|Throwable|null $statuses = null;

    private function __construct(private readonly Container $container) {}

    /**
     * Registers the gauges. Nothing runs until the metrics are exported.
     */
    public static function register(Container $container, MeterRegistry $registry, Metrics $metrics): void
    {
        if (! $registry->enabled(self::STATUS_METER) && ! $registry->enabled(self::OVERALL_METER)) {
            return;
        }

        $gauges = new self($container);

        // Each export starts with a new evaluation. Export callbacks all run before any gauge is read.
        $metrics->onExport(static function () use ($gauges): void {
            $gauges->statuses = null;
        });

        $registry->describe(self::STATUS_METER, 'Health status of each indicator: 1 for its current status, 0 for the others.');
        $registry->describe(self::OVERALL_METER, 'Health status of the application: 1 for its current status, 0 for the others.');

        /** @var array<string, mixed> $indicators */
        $indicators = $container->make('config')->get('actuator.health.indicators', []);

        foreach (Status::cases() as $status) {
            $registry->gauge(self::OVERALL_METER, ['status' => $status->value], static fn (): int => (int) ($gauges->statuses()['status'] === $status));

            foreach (array_keys($indicators) as $name) {
                $registry->gauge(self::STATUS_METER, ['component' => $name, 'status' => $status->value], static function () use ($gauges, $name, $status): ?int {
                    // An indicator added to the configuration since the registration is left out.
                    $current = $gauges->statuses()['components'][$name] ?? null;

                    return $current === null ? null : (int) ($current === $status);
                });
            }
        }
    }

    /**
     * The statuses of this export. A failure is kept too, so that a broken configuration is not
     * evaluated again for each series; the registry reports it once and skips the series.
     *
     * @return array{status: Status, components: array<string, Status>}
     */
    private function statuses(): array
    {
        if ($this->statuses === null) {
            try {
                // Every component, whatever "show_details" and "show_components" say: the metrics
                // endpoint has its own protection. With the health cache on, no indicator runs again.
                $health = $this->container->make(HealthEndpoint::class)->health(showAll: true) ?? [];
                $components = [];
                foreach ($health['components'] ?? [] as $name => $component) {
                    if (is_array($component) && ($component['status'] ?? null) instanceof Status) {
                        $components[$name] = $component['status'];
                    }
                }

                $this->statuses = ['status' => $health['status'] ?? Status::Unknown, 'components' => $components];
            } catch (Throwable $exception) {
                $this->statuses = $exception;
            }
        }

        if ($this->statuses instanceof Throwable) {
            throw $this->statuses;
        }

        return $this->statuses;
    }
}

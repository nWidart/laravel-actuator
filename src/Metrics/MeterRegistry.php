<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Metrics;

use Closure;
use Nwidart\Metrics\AttributeContributors;
use Nwidart\Metrics\Metrics;
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\GaugeInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Metrics\ObserverInterface;
use Throwable;

/**
 * Creates meters, as Micrometer's MeterRegistry does, on top of nwidart/laravel-metrics: a
 * counter is an OpenTelemetry counter, a timer is a histogram in seconds, a summary is a
 * histogram in its own unit, and a gauge is a gauge. They are recorded in the request and merged
 * into the shared store when it ends. A counter or a gauge with a callback is read only when the
 * metrics are exported (a scrape), and is never stored.
 */
final class MeterRegistry
{
    private const SCOPE = 'nwidart/laravel-actuator';

    /** Micrometer base units => OpenTelemetry (UCUM) units. */
    private const UNITS = ['seconds' => 's', 'milliseconds' => 'ms', 'bytes' => 'By'];

    private ?MeterInterface $meter = null;

    /** @var array<string, CounterInterface> */
    private array $counters = [];

    /** @var array<string, GaugeInterface> */
    private array $gauges = [];

    /** @var array<string, HistogramInterface> */
    private array $histograms = [];

    /** @var array<string, string> */
    private array $descriptions = [];

    /** @var array<string, array{type: 'counter'|'gauge', unit: ?string, series: array<string, array{tags: array<non-empty-string, string|int|float|bool>, value: Closure(): (int|float|null)}>}> */
    private array $observed = [];

    /** @var array<string, list<float>> */
    private readonly array $buckets;

    private bool $failureReported = false;

    /**
     * @param  array<string, bool>  $enable  meter name prefix => enabled. The longest prefix wins.
     * @param  array<string, list<int|float>>  $buckets  timer or summary name => histogram upper bounds
     */
    public function __construct(
        private readonly Metrics $metrics,
        private readonly AttributeContributors $contributors,
        private readonly array $enable = [],
        array $buckets = [],
    ) {
        $this->buckets = array_map(static function (array $bounds): array {
            $bounds = array_map(floatval(...), $bounds);
            sort($bounds);

            return $bounds;
        }, $buckets);
    }

    /**
     * A value that only goes up. Pass a callback when another system already keeps the total: it
     * is called when the metrics are exported, and increment() then does nothing.
     *
     * @param  array<string, string|int|float|bool>  $tags
     * @param  (Closure(): (int|float|null))|null  $value
     */
    public function counter(string $name, array $tags = [], ?Closure $value = null): Counter
    {
        $tags = self::tags($tags);
        if (! $this->enabled($name)) {
            return new Counter($name, null, $tags);
        }
        if ($value !== null) {
            $this->observe('counter', $name, null, $tags, $value);

            return new Counter($name, null, $tags);
        }

        return new Counter($name, $this->counters[$name] ??= $this->meter()->createCounter($name, null, $this->descriptions[$name] ?? null), $tags);
    }

    /**
     * A value that goes up and down. Either pass a callback, which is called when the metrics are
     * exported, or call set() on the gauge.
     *
     * @param  array<string, string|int|float|bool>  $tags
     * @param  (Closure(): (int|float|null))|null  $value  null as a result leaves the gauge out
     */
    public function gauge(string $name, array $tags = [], ?Closure $value = null, string $unit = ''): Gauge
    {
        $tags = self::tags($tags);
        if (! $this->enabled($name)) {
            return new Gauge(null, $tags);
        }
        if ($value !== null) {
            $this->observe('gauge', $name, self::unit($unit), $tags, $value);

            return new Gauge(null, $tags);
        }

        return new Gauge($this->gauges[$name] ??= $this->meter()->createGauge($name, self::unit($unit), $this->descriptions[$name] ?? null), $tags);
    }

    /**
     * Counts events and the time they took, in seconds.
     *
     * @param  array<string, string|int|float|bool>  $tags
     */
    public function timer(string $name, array $tags = []): Timer
    {
        return new Timer($this->histogram($name, 's'), self::tags($tags));
    }

    /**
     * Counts events and the sum of an amount that is not a duration.
     *
     * @param  array<string, string|int|float|bool>  $tags
     */
    public function summary(string $name, array $tags = [], string $unit = ''): DistributionSummary
    {
        return new DistributionSummary($this->histogram($name, self::unit($unit)), self::tags($tags));
    }

    /**
     * Adds tags to a meter that someone else records, such as the built-in
     * "http.server.request.duration" of laravel-metrics. The callback receives what the
     * measurement is about and returns the tags to add. Keep the number of distinct values small.
     *
     * @template TContext of object
     *
     * @param  Closure(TContext): array<string, string|int|float|bool>  $callback
     */
    public function tagUsing(string $meter, Closure $callback): void
    {
        $this->metrics->attributes($meter, $callback);
    }

    /**
     * The tags contributed through tagUsing() for one measurement. Call this when you record a
     * meter that others may want to tag. A contributor that throws is skipped.
     *
     * @return array<non-empty-string, string|int|float|bool>
     */
    public function tagsFor(string $meter, object $context): array
    {
        return $this->contributors->for($meter, $context);
    }

    /**
     * Sets the help text shown next to a meter. Call it from a service provider, before the meter
     * is first used.
     */
    public function describe(string $name, string $description): void
    {
        $this->descriptions[$name] = $description;
    }

    /**
     * Whether the meter is recorded: no "enable" prefix turns it off.
     */
    public function enabled(string $name): bool
    {
        $match = null;
        $enabled = true;

        foreach ($this->enable as $prefix => $flag) {
            $matches = $name === $prefix || str_starts_with($name, "{$prefix}.");

            if ($matches && ($match === null || strlen($prefix) > strlen($match))) {
                $match = $prefix;
                $enabled = $flag;
            }
        }

        return $enabled;
    }

    /**
     * Merges what was recorded into the store now. The request, the command and each queued job
     * already do it when they end. A failure never reaches the caller.
     */
    public function flush(): void
    {
        $this->metrics->flush();
    }

    private function histogram(string $name, ?string $unit): ?HistogramInterface
    {
        if (! $this->enabled($name)) {
            return null;
        }

        // No configured bounds: one bucket, so the histogram keeps only its count and sum.
        return $this->histograms[$name] ??= $this->meter()->createHistogram($name, $unit, $this->descriptions[$name] ?? null, ['ExplicitBucketBoundaries' => $this->buckets[$name] ?? []]);
    }

    /**
     * @param  'counter'|'gauge'  $type
     * @param  array<non-empty-string, string|int|float|bool>  $tags
     * @param  Closure(): (int|float|null)  $value
     */
    private function observe(string $type, string $name, ?string $unit, array $tags, Closure $value): void
    {
        $first = $this->observed === [];
        $this->observed[$name] ??= ['type' => $type, 'unit' => $unit, 'series' => []];
        // The same meter and tags registered again replace the callback, as they do in Micrometer.
        $this->observed[$name]['series'][serialize($tags)] = ['tags' => $tags, 'value' => $value];

        if ($first) {
            $this->metrics->onExport(fn (MeterProviderInterface $provider) => $this->createObserved($provider));
        }
    }

    private function createObserved(MeterProviderInterface $provider): void
    {
        $meter = $provider->getMeter(self::SCOPE);

        foreach ($this->observed as $name => $observed) {
            $callback = function (ObserverInterface $observer) use ($observed): void {
                foreach ($observed['series'] as $series) {
                    try {
                        $value = ($series['value'])();
                    } catch (Throwable $e) {
                        $this->reportOnce($e);

                        continue;
                    }

                    if ((is_int($value) || is_float($value)) && is_finite($value)) {
                        $observer->observe($value, $series['tags']);
                    }
                }
            };

            $description = $this->descriptions[$name] ?? null;
            $observed['type'] === 'counter'
                ? $meter->createObservableCounter($name, $observed['unit'], $description, [], $callback)
                : $meter->createObservableGauge($name, $observed['unit'], $description, [], $callback);
        }
    }

    private function meter(): MeterInterface
    {
        return $this->meter ??= $this->metrics->meter(self::SCOPE);
    }

    /**
     * @param  array<string, string|int|float|bool>  $tags
     * @return array<non-empty-string, string|int|float|bool>
     */
    private static function tags(array $tags): array
    {
        $attributes = [];
        foreach ($tags as $name => $value) {
            $name = (string) $name;
            if ($name !== '') {
                $attributes[$name] = $value;
            }
        }

        return $attributes;
    }

    private static function unit(string $unit): ?string
    {
        return $unit === '' ? null : (self::UNITS[$unit] ?? $unit);
    }

    private function reportOnce(Throwable $e): void
    {
        if (! $this->failureReported) {
            $this->failureReported = true;
            report($e);
        }
    }
}

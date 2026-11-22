<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Metrics;

use Closure;
use Throwable;

/**
 * Creates meters and reads them back. Modelled on Micrometer's MeterRegistry.
 *
 * Measurements are buffered in this process and written to the store once,
 * by flush(), at the end of the request, job or command.
 */
final class MeterRegistry
{
    private readonly Buffer $buffer;

    private ?MetricsStore $store = null;

    private bool $failureReported = false;

    /** @var array<string, string> */
    private readonly array $commonTags;

    /** @var array<string, list<float>> */
    private readonly array $buckets;

    /** @var array<string, Closure(): (int|float|null)> */
    private array $callbacks = [];

    /** @var array<string, list<Closure(object): mixed>> */
    private array $contributors = [];

    /** @var array<string, string> */
    private array $descriptions = [];

    /** @var list<Closure(self): void> */
    private array $collecting = [];

    /**
     * @param  Closure(): MetricsStore  $resolveStore  called on the first write or read
     * @param  bool  $active  false turns every meter into a no-op
     * @param  array<string, string|int|float|bool>  $commonTags  added to every meter
     * @param  array<string, bool>  $enable  meter name prefix => enabled. The longest prefix wins.
     * @param  array<string, list<int|float>>  $buckets  timer or summary name => histogram upper bounds
     */
    public function __construct(
        private readonly Closure $resolveStore,
        private readonly bool $active = true,
        array $commonTags = [],
        private readonly array $enable = [],
        array $buckets = [],
    ) {
        $this->buffer = new Buffer;
        $this->commonTags = $this->normalize($commonTags);
        $this->buckets = array_map(static function (array $bounds): array {
            $bounds = array_map(floatval(...), $bounds);
            sort($bounds);

            return $bounds;
        }, $buckets);
    }

    /**
     * A value that only goes up. Pass a callback when another system already
     * keeps the total: it is called when the meters are read.
     *
     * @param  array<string, string|int|float|bool>  $tags
     * @param  (Closure(): (int|float|null))|null  $value
     */
    public function counter(string $name, array $tags = [], ?Closure $value = null): Counter
    {
        $id = $this->id(MeterType::Counter, $name, '', $tags);
        $this->sample($id->series('total'), $name, $value);

        return new Counter($this->bufferFor($name), $id);
    }

    /**
     * A value that goes up and down. Either pass a callback, which is called
     * when the meters are read, or call set() on the gauge.
     *
     * @param  array<string, string|int|float|bool>  $tags
     * @param  (Closure(): (int|float|null))|null  $value  null as a result leaves the gauge out
     */
    public function gauge(string $name, array $tags = [], ?Closure $value = null, string $unit = ''): Gauge
    {
        $id = $this->id(MeterType::Gauge, $name, $unit, $tags);
        $this->sample($id->series('value'), $name, $value);

        return new Gauge($this->bufferFor($name), $id);
    }

    /**
     * Counts events and the time they took, in seconds.
     *
     * @param  array<string, string|int|float|bool>  $tags
     */
    public function timer(string $name, array $tags = []): Timer
    {
        return new Timer($this->bufferFor($name), $this->id(MeterType::Timer, $name, 'seconds', $tags), $this->buckets[$name] ?? []);
    }

    /**
     * Counts events and the sum of an amount that is not a duration.
     *
     * @param  array<string, string|int|float|bool>  $tags
     */
    public function summary(string $name, array $tags = [], string $unit = ''): DistributionSummary
    {
        return new DistributionSummary($this->bufferFor($name), $this->id(MeterType::Summary, $name, $unit, $tags), $this->buckets[$name] ?? []);
    }

    /**
     * Adds tags to a meter that is recorded by someone else, such as the
     * built-in "http.server.requests". The callback receives the object the
     * measurement is about (see the documentation of each meter) and
     * returns the tags to add. Keep the number of distinct values small.
     *
     * @template TContext of object
     *
     * @param  Closure(TContext): array<string, string|int|float|bool>  $callback
     */
    public function tagUsing(string $meter, Closure $callback): void
    {
        /** @var Closure(object): mixed $callback */
        $this->contributors[$meter][] = $callback;
    }

    /**
     * The tags contributed through tagUsing() for one measurement. Call
     * this when you record a meter that others may want to tag.
     *
     * A contributor is code from elsewhere, and it runs inside the request,
     * job or query it describes. So nothing it does may fail that work: a
     * contributor that throws is skipped, and so is a value that is not a
     * string, a number or a boolean.
     *
     * @return array<string, string|int|float|bool>
     */
    public function tagsFor(string $meter, object $context): array
    {
        $tags = [];

        foreach ($this->contributors[$meter] ?? [] as $contributor) {
            try {
                $contributed = $contributor($context);
            } catch (Throwable $e) {
                $this->reportOnce($e);

                continue;
            }

            foreach (is_array($contributed) ? $contributed : [] as $tag => $value) {
                if (is_scalar($value)) {
                    $tags[(string) $tag] = $value;
                }
            }
        }

        return $tags;
    }

    /**
     * Sets the help text shown next to a meter. Call it from a service
     * provider, so that the process that answers the scrape knows it.
     */
    public function describe(string $name, string $description): void
    {
        $this->descriptions[$name] = $description;
    }

    /**
     * Whether the meter is recorded: metrics are active and no "enable" prefix turns it off.
     */
    public function enabled(string $name): bool
    {
        if (! $this->active) {
            return false;
        }

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
     * Registers a callback that runs each time the meters are read, before
     * the callbacks of gauges and counters. Use it for meters that are only
     * worth building for a scrape.
     *
     * @internal
     *
     * @param  Closure(self): void  $callback
     */
    public function collecting(Closure $callback): void
    {
        $this->collecting[] = $callback;
    }

    /**
     * Writes the buffered measurements to the store. A store failure is
     * reported once per process and never thrown: metrics must not break
     * the work they measure.
     */
    public function flush(): void
    {
        [$increments, $values] = $this->buffer->drain();

        if ($increments === [] && $values === []) {
            return;
        }

        try {
            $this->withoutRecording(fn () => $this->store()->write($increments, $values));
        } catch (Throwable $e) {
            $this->reportOnce($e);
        }
    }

    /**
     * Reads every enabled meter: the stored ones and the callbacks.
     *
     * @return list<MeterSnapshot>
     *
     * @throws Throwable when the store cannot be read
     */
    public function collect(): array
    {
        if (! $this->active) {
            return [];
        }

        foreach ($this->collecting as $callback) {
            try {
                $callback($this);
            } catch (Throwable $e) {
                // The other meters are still worth a scrape.
                $this->reportOnce($e);
            }
        }

        $series = $this->withoutRecording(fn (): array => $this->store()->read());

        foreach ($this->callbacks as $key => $callback) {
            try {
                $value = $callback();
            } catch (Throwable $e) {
                $this->reportOnce($e);

                continue;
            }

            if ($value !== null) {
                $series[$key] = (float) $value;
            }
        }

        $ids = [];
        $statistics = [];

        foreach ($series as $key => $value) {
            $parsed = MeterId::parse($key);

            if ($parsed === null || ! $this->enabled($parsed[0]->name)) {
                continue;
            }

            $meter = $parsed[0]->series('');
            $ids[$meter] = $parsed[0];
            $statistics[$meter][$parsed[1]] = $value;
        }

        ksort($ids);
        $snapshots = [];

        foreach ($ids as $meter => $id) {
            $snapshots[] = $this->snapshot($id, $statistics[$meter] ?? []);
        }

        return $snapshots;
    }

    /**
     * Removes every stored series, and the measurements still buffered.
     */
    public function clear(): void
    {
        $this->buffer->drain();
        $this->withoutRecording(fn () => $this->store()->clear());
    }

    /**
     * @param  array<string, float>  $statistics
     */
    private function snapshot(MeterId $id, array $statistics): MeterSnapshot
    {
        // Every configured bucket is listed, also the ones nothing fell into
        // yet: a histogram needs the same bounds on each of its series.
        $counts = [];

        foreach ($this->buckets[$id->name] ?? [] as $bound) {
            $counts["le:{$bound}"] = 0.0;
        }

        foreach ($statistics as $statistic => $value) {
            if (str_starts_with($statistic, 'le:')) {
                $counts[$statistic] = $value;
            }
        }

        $bounds = [];

        foreach ($counts as $statistic => $count) {
            $bounds[] = [(float) substr($statistic, 3), $count];
        }

        usort($bounds, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $buckets = [];
        $cumulative = 0.0;

        foreach ($bounds as [$bound, $count]) {
            $buckets[] = [$bound, $cumulative += $count];
        }

        return new MeterSnapshot(
            name: $id->name,
            type: $id->type,
            unit: $id->unit,
            tags: $id->tags,
            description: $this->descriptions[$id->name] ?? '',
            value: $statistics['total'] ?? $statistics['value'] ?? 0.0,
            count: $statistics['count'] ?? 0.0,
            sum: $statistics['sum'] ?? 0.0,
            buckets: $buckets,
        );
    }

    /**
     * @param  array<string, string|int|float|bool>  $tags
     */
    private function id(MeterType $type, string $name, string $unit, array $tags): MeterId
    {
        // A tag of the meter wins over a common tag of the same name.
        $tags = $this->normalize($tags) + $this->commonTags;
        ksort($tags);

        return new MeterId($type, $name, $unit, $tags);
    }

    /**
     * @param  array<string, string|int|float|bool>  $tags
     * @return array<string, string>
     */
    private function normalize(array $tags): array
    {
        return array_map(static fn (string|int|float|bool $value): string => is_bool($value) ? ($value ? 'true' : 'false') : (string) $value, $tags);
    }

    /**
     * @param  (Closure(): (int|float|null))|null  $value
     */
    private function sample(string $series, string $name, ?Closure $value): void
    {
        if ($value !== null && $this->enabled($name)) {
            $this->callbacks[$series] = $value;
        }
    }

    private function bufferFor(string $name): ?Buffer
    {
        return $this->enabled($name) ? $this->buffer : null;
    }

    private function store(): MetricsStore
    {
        return $this->store ??= ($this->resolveStore)();
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    private function withoutRecording(Closure $callback): mixed
    {
        $paused = $this->buffer->paused;
        $this->buffer->paused = true;

        try {
            return $callback();
        } finally {
            $this->buffer->paused = $paused;
        }
    }

    private function reportOnce(Throwable $e): void
    {
        if (! $this->failureReported) {
            $this->failureReported = true;
            report($e);
        }
    }
}

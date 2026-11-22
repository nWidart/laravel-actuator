<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Tests\Unit\Metrics;

use InvalidArgumentException;
use LogicException;
use Nwidart\Actuator\Metrics\MeterRegistry;
use Nwidart\Actuator\Metrics\MeterSnapshot;
use Nwidart\Actuator\Metrics\MeterType;
use Nwidart\Actuator\Metrics\MetricsStore;
use Nwidart\Actuator\Tests\Fixtures\InMemoryMetricsStore;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

final class MeterRegistryTest extends TestCase
{
    private InMemoryMetricsStore $store;

    protected function setUp(): void
    {
        $this->store = new InMemoryMetricsStore;
    }

    /**
     * @param  array<string, string|int|float|bool>  $commonTags
     * @param  array<string, bool>  $enable
     * @param  array<string, list<int|float>>  $buckets
     */
    private function registry(array $commonTags = [], array $enable = [], array $buckets = []): MeterRegistry
    {
        return new MeterRegistry(fn (): MetricsStore => $this->store, true, $commonTags, $enable, $buckets);
    }

    private function only(MeterRegistry $registry): MeterSnapshot
    {
        $meters = $registry->collect();
        $this->assertCount(1, $meters);

        return $meters[0];
    }

    #[Test]
    public function a_counter_adds_up_across_flushes(): void
    {
        $registry = $this->registry();

        $registry->counter('orders.placed', ['channel' => 'web'])->increment();
        $registry->flush();
        $registry->counter('orders.placed', ['channel' => 'web'])->increment(2);
        $registry->flush();

        $meter = $this->only($registry);
        $this->assertSame('orders.placed', $meter->name);
        $this->assertSame(MeterType::Counter, $meter->type);
        $this->assertSame(['channel' => 'web'], $meter->tags);
        $this->assertSame(3.0, $meter->value);
    }

    #[Test]
    public function measurements_reach_the_store_only_on_flush_and_in_one_write(): void
    {
        $registry = $this->registry();

        $registry->counter('a')->increment();
        $registry->counter('b')->increment();
        $this->assertSame([], $registry->collect());

        $registry->flush();
        $registry->flush();

        $this->assertSame(1, $this->store->writes);
        $this->assertCount(2, $registry->collect());
    }

    #[Test]
    public function a_counter_cannot_go_down(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->registry()->counter('orders.placed')->increment(-1);
    }

    #[Test]
    public function the_same_name_with_other_tags_is_another_meter(): void
    {
        $registry = $this->registry();

        $registry->counter('orders.placed', ['channel' => 'web'])->increment();
        $registry->counter('orders.placed', ['channel' => 'app'])->increment(5);
        $registry->flush();

        $this->assertSame(
            [['channel' => 'app'], ['channel' => 'web']],
            array_map(static fn (MeterSnapshot $meter): array => $meter->tags, $registry->collect()),
        );
    }

    #[Test]
    public function a_gauge_keeps_the_last_value(): void
    {
        $registry = $this->registry();

        $registry->gauge('backup.last.success', unit: 'seconds')->set(10);
        $registry->gauge('backup.last.success', unit: 'seconds')->set(20);
        $registry->flush();

        $meter = $this->only($registry);
        $this->assertSame(MeterType::Gauge, $meter->type);
        $this->assertSame('seconds', $meter->unit);
        $this->assertSame(20.0, $meter->value);
    }

    #[Test]
    public function a_callback_is_read_when_the_meters_are_collected(): void
    {
        $registry = $this->registry();
        $size = 3;

        $registry->gauge('queue.size', ['queue' => 'default'], function () use (&$size): int {
            return $size;
        });
        $registry->counter('cache.hits', value: static fn (): int => 40);
        $registry->gauge('absent', value: static fn (): ?int => null);

        $size = 7;
        $meters = $registry->collect();

        $this->assertSame(['cache.hits', 'queue.size'], array_map(static fn (MeterSnapshot $meter): string => $meter->name, $meters));
        $this->assertSame(40.0, $meters[0]->value);
        $this->assertSame(MeterType::Counter, $meters[0]->type);
        $this->assertSame(7.0, $meters[1]->value);
        $this->assertSame(0, $this->store->writes);
    }

    #[Test]
    public function a_timer_counts_and_sums_seconds(): void
    {
        $registry = $this->registry();

        $registry->timer('payment.charge')->record(0.25);
        $registry->timer('payment.charge')->record(0.75);
        $registry->timer('payment.charge')->record(-1);
        $registry->flush();

        $meter = $this->only($registry);
        $this->assertSame(MeterType::Timer, $meter->type);
        $this->assertSame('seconds', $meter->unit);
        $this->assertSame(3.0, $meter->count);
        $this->assertSame(1.0, $meter->sum);
        $this->assertSame([], $meter->buckets);
    }

    #[Test]
    public function a_timer_times_a_callback_and_returns_its_result(): void
    {
        $registry = $this->registry();

        $this->assertSame('charged', $registry->timer('payment.charge')->time(static fn (): string => 'charged'));

        try {
            $registry->timer('payment.charge')->time(static fn () => throw new LogicException('declined'));
        } catch (LogicException) {
            // The failed call is timed too.
        }

        $registry->flush();

        $this->assertSame(2.0, $this->only($registry)->count);
    }

    #[Test]
    public function configured_buckets_become_cumulative_counts(): void
    {
        $registry = $this->registry(buckets: ['payment.charge' => [1, 0.1, 0.5]]);

        foreach ([0.05, 0.1, 0.3, 0.3, 4] as $seconds) {
            $registry->timer('payment.charge')->record($seconds);
        }

        $registry->flush();
        $meter = $this->only($registry);

        $this->assertSame([[0.1, 2.0], [0.5, 4.0], [1.0, 4.0]], $meter->buckets);
        $this->assertSame(5.0, $meter->count);
    }

    #[Test]
    public function a_summary_records_amounts_in_its_own_unit(): void
    {
        $registry = $this->registry(buckets: ['upload.size' => [1024]]);

        $registry->summary('upload.size', ['disk' => 's3'], 'bytes')->record(512);
        $registry->summary('upload.size', ['disk' => 's3'], 'bytes')->record(4096);
        $registry->flush();

        $meter = $this->only($registry);
        $this->assertSame(MeterType::Summary, $meter->type);
        $this->assertSame('bytes', $meter->unit);
        $this->assertSame(2.0, $meter->count);
        $this->assertSame(4608.0, $meter->sum);
        $this->assertSame([[1024.0, 1.0]], $meter->buckets);
    }

    #[Test]
    public function common_tags_are_added_to_every_meter_and_a_meter_tag_wins(): void
    {
        $registry = $this->registry(commonTags: ['application' => 'shop', 'region' => 'eu', 'canary' => true]);

        $registry->counter('orders.placed', ['region' => 'us', 'attempt' => 2])->increment();
        $registry->flush();

        $this->assertSame(
            ['application' => 'shop', 'attempt' => '2', 'canary' => 'true', 'region' => 'us'],
            $this->only($registry)->tags,
        );
    }

    #[Test]
    public function meters_are_turned_off_by_the_longest_matching_prefix(): void
    {
        $registry = $this->registry(enable: ['db' => false, 'db.queries.slow' => true, 'http.server' => false]);

        $this->assertFalse($registry->enabled('db'));
        $this->assertFalse($registry->enabled('db.queries'));
        $this->assertTrue($registry->enabled('db.queries.slow'));
        $this->assertTrue($registry->enabled('dbal.queries'));
        $this->assertFalse($registry->enabled('http.server.requests'));
        $this->assertTrue($registry->enabled('http.client.requests'));

        $registry->counter('db.queries')->increment();
        $registry->gauge('db.pool', value: static fn (): int => 1);
        $registry->counter('db.queries.slow')->increment();
        $registry->flush();

        $this->assertSame('db.queries.slow', $this->only($registry)->name);
    }

    #[Test]
    public function a_meter_turned_off_later_is_hidden_even_when_it_is_stored(): void
    {
        $registry = $this->registry();
        $registry->counter('db.queries')->increment();
        $registry->flush();

        $this->assertCount(1, $registry->collect());
        $this->assertSame([], $this->registry(enable: ['db' => false])->collect());
    }

    #[Test]
    public function an_inactive_registry_records_nothing_and_never_touches_the_store(): void
    {
        $registry = new MeterRegistry(static fn () => throw new LogicException('The store must not be resolved.'), false);

        $registry->counter('orders.placed')->increment();
        $registry->gauge('queue.size', value: static fn (): int => 1);
        $registry->timer('payment.charge')->record(1);
        $registry->flush();

        $this->assertFalse($registry->enabled('orders.placed'));
        $this->assertSame([], $registry->collect());
    }

    #[Test]
    public function the_store_is_not_resolved_while_nothing_is_recorded(): void
    {
        $registry = new MeterRegistry(static fn () => throw new LogicException('The store must not be resolved.'));

        $registry->flush();

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function contributed_tags_are_merged_in_order(): void
    {
        $registry = $this->registry();
        $context = new stdClass;
        $context->module = 'Blog';

        $registry->tagUsing('http.server.requests', static fn (stdClass $context): array => ['module' => $context->module, 'tenant' => 'a']);
        $registry->tagUsing('http.server.requests', static fn (): array => ['tenant' => 'b']);

        $this->assertSame(['module' => 'Blog', 'tenant' => 'b'], $registry->tagsFor('http.server.requests', $context));
        $this->assertSame([], $registry->tagsFor('queue.jobs', $context));
    }

    #[Test]
    public function a_contributor_cannot_break_the_measurement_with_a_bad_value(): void
    {
        $registry = $this->registry();

        $registry->tagUsing('db.queries', static fn (): array => ['tenant' => null, 'module' => 'Blog', 'list' => ['a']]);
        $registry->tagUsing('db.queries', static fn (): ?array => null);
        $registry->tagUsing('db.queries', static fn (): string => 'not an array');

        $tags = $registry->tagsFor('db.queries', new stdClass);
        $registry->timer('db.queries', $tags)->record(0.1);
        $registry->flush();

        $this->assertSame(['module' => 'Blog'], $tags);
        $this->assertSame(['module' => 'Blog'], $this->only($registry)->tags);
    }

    #[Test]
    public function values_that_are_not_finite_are_ignored(): void
    {
        $registry = $this->registry(buckets: ['payment.charge' => [1]]);

        $registry->counter('orders.placed')->increment(NAN);
        $registry->counter('orders.placed')->increment(INF);
        $registry->gauge('queue.size')->set(NAN);
        $registry->timer('payment.charge')->record(INF);
        $registry->summary('upload.size')->record(NAN);
        $registry->flush();

        $this->assertSame([], $registry->collect());
        $this->assertSame(0, $this->store->writes);
    }

    #[Test]
    public function a_description_is_attached_to_the_meter(): void
    {
        $registry = $this->registry();

        $registry->describe('orders.placed', 'Orders placed.');
        $registry->counter('orders.placed')->increment();
        $registry->flush();

        $this->assertSame('Orders placed.', $this->only($registry)->description);
    }

    #[Test]
    public function work_done_by_the_store_itself_is_not_recorded(): void
    {
        $registry = null;
        $store = new class($registry) implements MetricsStore
        {
            /** @var array<string, float> */
            public array $series = [];

            public function __construct(private ?MeterRegistry &$registry) {}

            public function write(array $increments, array $values): void
            {
                $this->registry?->counter('db.queries')->increment();
                $this->series += $increments;
            }

            public function read(): array
            {
                $this->registry?->counter('db.queries')->increment();

                return $this->series;
            }

            public function clear(): void {}
        };
        $registry = new MeterRegistry(static fn (): MetricsStore => $store);

        $registry->counter('orders.placed')->increment();
        $registry->flush();
        $registry->collect();
        $registry->flush();

        $this->assertSame('orders.placed', $this->only($registry)->name);
    }

    #[Test]
    public function clear_empties_the_store_and_the_buffer(): void
    {
        $registry = $this->registry();

        $registry->counter('a')->increment();
        $registry->flush();
        $registry->counter('b')->increment();
        $registry->clear();
        $registry->flush();

        $this->assertSame([], $registry->collect());
    }

    #[Test]
    public function series_written_by_something_else_are_ignored(): void
    {
        $this->store->series = ['not json' => 1.0, '["counter","x"]' => 1.0, '["nope","x","",{},"total"]' => 1.0];

        $this->assertSame([], $this->registry()->collect());
    }
}

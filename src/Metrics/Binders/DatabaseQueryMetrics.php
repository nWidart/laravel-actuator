<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Metrics\Binders;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\QueryExecuted;
use Nwidart\Actuator\Metrics\MeterBinder;
use Nwidart\Actuator\Metrics\MeterRegistry;

/**
 * Times every database query. Tag contributors receive the QueryExecuted event.
 * The SQL is never a tag: it would create one series per statement.
 *
 * @internal
 */
final readonly class DatabaseQueryMetrics implements MeterBinder
{
    public const NAME = 'db.queries';

    public function __construct(private Dispatcher $events) {}

    public function bind(MeterRegistry $registry): void
    {
        $registry->describe(self::NAME, 'Duration of database queries.');

        if (! $registry->enabled(self::NAME)) {
            return;
        }

        $this->events->listen(QueryExecuted::class, static function (QueryExecuted $event) use ($registry): void {
            $registry->timer(self::NAME, [
                'connection' => $event->connectionName,
                ...$registry->tagsFor(self::NAME, $event),
            ])->record($event->time / 1000);
        });
    }
}

<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Support;

use Closure;
use Illuminate\Console\Scheduling\Event;
use Throwable;

/**
 * The callbacks that add tags to the entries of the scheduledtasks endpoint.
 *
 * @internal Register a callback with Nwidart\Actuator\Actuator::tagScheduledTasksUsing().
 */
final class ScheduledTaskTags
{
    /**
     * @var list<Closure(Event): array<mixed>>
     */
    private array $callbacks = [];

    /**
     * @param  Closure(Event): array<mixed>  $callback
     */
    public function add(Closure $callback): void
    {
        $this->callbacks[] = $callback;
    }

    /**
     * The tags of one task. A callback that throws is reported and skipped, and a failure of the
     * report itself is ignored. A tag with an empty name, or a value that is not a string, a finite
     * number or a boolean, is dropped. A later callback replaces a tag of an earlier one.
     *
     * @return array<non-empty-string, string|int|float|bool>
     */
    public function for(Event $event): array
    {
        $tags = [];

        foreach ($this->callbacks as $callback) {
            try {
                $contributed = $callback($event);
            } catch (Throwable $exception) {
                rescue(static fn () => report($exception), report: false);

                continue;
            }

            foreach ($contributed as $name => $value) {
                if (is_string($name) && $name !== '' && is_scalar($value) && (! is_float($value) || is_finite($value))) {
                    $tags[$name] = $value;
                }
            }
        }

        return $tags;
    }
}

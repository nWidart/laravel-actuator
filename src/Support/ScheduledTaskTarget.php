<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Support;

use Illuminate\Console\Scheduling\Event;

/**
 * @internal
 */
final class ScheduledTaskTarget
{
    /**
     * The name a scheduled task is shown under: its description, or its command.
     */
    public static function of(Event $event): string
    {
        if (is_string($event->description) || $event->command === null) {
            return $event->getSummaryForDisplay();
        }

        return Event::normalizeCommand($event->command);
    }
}

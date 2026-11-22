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

    /**
     * A short name for the task that leaves out the arguments of its command:
     * "php artisan report:send --token=secret" is "report:send". Arguments
     * can hold a secret, and can differ on each run.
     */
    public static function name(Event $event): string
    {
        if (is_string($event->description) || $event->command === null) {
            return $event->getSummaryForDisplay();
        }

        $words = preg_split('/\s+/', trim(Event::normalizeCommand($event->command)), flags: PREG_SPLIT_NO_EMPTY) ?: [];

        // A shell command can start with variable assignments, such as "TOKEN=secret backup.sh".
        while ($words !== [] && preg_match('/^\w+=/', $words[0]) === 1) {
            array_shift($words);
        }

        if (($words[0] ?? null) === 'php' && ($words[1] ?? null) === 'artisan') {
            $words = array_slice($words, 2);
        }

        return trim($words[0] ?? 'command', '\'"');
    }
}

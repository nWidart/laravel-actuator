<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Http\Controllers;

use DateTimeZone;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Date;

/**
 * Scheduled tasks, in the Spring Boot "scheduledtasks" format.
 * Sub-minute tasks are listed as "fixedRate" with an interval in milliseconds.
 */
final class ScheduledTasksController
{
    public function __invoke(Application $app): JsonResponse
    {
        // Schedules defined in routes/console.php or withSchedule() only load with the console kernel.
        $app->make(Kernel::class)->all();

        $cron = [];
        $fixedRate = [];

        foreach ($app->make(Schedule::class)->events() as $event) {
            $runnable = ['target' => $this->target($event)];

            if ($event->isRepeatable()) {
                $fixedRate[] = ['runnable' => $runnable, 'initialDelay' => 0, 'interval' => $event->repeatSeconds * 1000];

                continue;
            }

            $cron[] = [
                'runnable' => $runnable,
                'expression' => $event->getExpression(),
                'timezone' => $this->timezone($event),
                'nextExecution' => ['time' => $event->nextRunDate(Date::now())->toIso8601String()],
            ];
        }

        return new JsonResponse(['cron' => $cron, 'fixedDelay' => [], 'fixedRate' => $fixedRate, 'custom' => []]);
    }

    private function target(Event $event): string
    {
        if (is_string($event->description) || $event->command === null) {
            return $event->getSummaryForDisplay();
        }

        return Event::normalizeCommand($event->command);
    }

    private function timezone(Event $event): string
    {
        $timezone = $event->timezone ?? config()->string('app.timezone');

        return $timezone instanceof DateTimeZone ? $timezone->getName() : $timezone;
    }
}

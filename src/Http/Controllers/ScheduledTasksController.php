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
use Nwidart\Actuator\Endpoints\Attributes\ReadOperation;
use Nwidart\Actuator\Support\ScheduledTaskTarget;

/**
 * Scheduled tasks, in the Spring Boot "scheduledtasks" format.
 * Sub-minute tasks are listed as "fixedRate" with an interval in milliseconds.
 */
final class ScheduledTasksController
{
    #[ReadOperation]
    public function __invoke(Application $app): JsonResponse
    {
        // Schedules defined in routes/console.php or withSchedule() only load with the console kernel.
        $app->make(Kernel::class)->all();

        $cron = [];
        $fixedRate = [];

        foreach ($app->make(Schedule::class)->events() as $event) {
            $runnable = ['target' => ScheduledTaskTarget::of($event)];

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

    private function timezone(Event $event): string
    {
        $timezone = $event->timezone ?? config()->string('app.timezone');

        return $timezone instanceof DateTimeZone ? $timezone->getName() : $timezone;
    }
}

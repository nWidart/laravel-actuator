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
use Nwidart\Actuator\Support\ScheduledTaskTags;
use Nwidart\Actuator\Support\ScheduledTaskTarget;
use Nwidart\Actuator\Support\SchedulerStore;
use Nwidart\Actuator\Visibility;

/**
 * Scheduled tasks, in the Spring Boot "scheduledtasks" format.
 * Sub-minute tasks are listed as "fixedRate" with an interval in milliseconds.
 * A task's "lastExecution" comes from the SchedulerStore, and is left out when it has none.
 * A task's "tags" come from the callbacks of Actuator::tagScheduledTasksUsing(), and are left out when
 * they give none.
 *
 * @phpstan-import-type LastExecution from SchedulerStore
 */
final class ScheduledTasksController
{
    #[ReadOperation]
    public function __invoke(Application $app): JsonResponse
    {
        // Schedules defined in routes/console.php or withSchedule() only load with the console kernel.
        $app->make(Kernel::class)->all();

        $events = $app->make(Schedule::class)->events();
        $targets = array_map(ScheduledTaskTarget::of(...), $events);
        $executions = SchedulerStore::recording() ? SchedulerStore::lastExecutions(array_values($targets)) : [];
        $showMessage = Visibility::fromConfig(config('actuator.scheduledtasks.show_exceptions', 'never'))->isGranted();

        /**
         * @param  array<string, mixed>  $task
         * @return array<string, mixed>
         */
        $withLastExecution = static fn (array $task, string $target): array => isset($executions[$target])
            ? [...$task, 'lastExecution' => self::lastExecution($executions[$target], $showMessage)]
            : $task;
        $tags = $app->make(ScheduledTaskTags::class);

        /**
         * @param  array<string, mixed>  $task
         * @return array<string, mixed>
         */
        $withTags = static fn (array $task, Event $event): array => ($found = $tags->for($event)) !== []
            ? [...$task, 'tags' => $found]
            : $task;

        $cron = [];
        $fixedRate = [];

        foreach ($events as $index => $event) {
            $runnable = ['target' => $targets[$index]];

            if ($event->isRepeatable()) {
                $fixedRate[] = $withTags($withLastExecution(['runnable' => $runnable, 'initialDelay' => 0, 'interval' => $event->repeatSeconds * 1000], $targets[$index]), $event);

                continue;
            }

            $cron[] = $withTags($withLastExecution([
                'runnable' => $runnable,
                'expression' => $event->getExpression(),
                'timezone' => $this->timezone($event),
                'nextExecution' => ['time' => $event->nextRunDate(Date::now())->toIso8601String()],
            ], $targets[$index]), $event);
        }

        // A stored exception message, or a target, can hold bytes that are not UTF-8: they are
        // replaced, rather than failing the endpoint until the record expires.
        return new JsonResponse(['cron' => $cron, 'fixedDelay' => [], 'fixedRate' => $fixedRate, 'custom' => []], options: JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * The exception's type is always shown, its message only when "show_exceptions" allows it.
     *
     * @param  LastExecution  $execution
     * @return array<string, mixed>
     */
    private static function lastExecution(array $execution, bool $showMessage): array
    {
        if (isset($execution['exception']) && ! $showMessage) {
            unset($execution['exception']['message']);
        }

        return $execution;
    }

    private function timezone(Event $event): string
    {
        $timezone = $event->timezone ?? config()->string('app.timezone');

        return $timezone instanceof DateTimeZone ? $timezone->getName() : $timezone;
    }
}

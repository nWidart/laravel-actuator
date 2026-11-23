<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Support;

use Carbon\CarbonInterface;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * The last execution of each scheduled task and the time of the scheduler's last tick, kept in
 * the cache store set by "actuator.scheduler.store", shared by the scheduler and the web instances.
 *
 * A task is keyed by a hash of its target, never by the target itself: the target can hold a
 * secret. Two tasks with the same target share a record.
 *
 * Writes and endpoint reads never throw: a store that fails is logged once per kind and skipped,
 * so it never fails a scheduled task, "schedule:run" or the endpoint. After a write throws, the
 * process writes no more for the rest of its life: a store that hangs until its timeout would
 * otherwise delay each task. A new process tries again: "schedule:work" and cron start a new
 * "schedule:run" process every minute. A write the store rejects (put() returns false) is only
 * logged: the database store returns false on MySQL when another server wrote the same row.
 *
 * @internal
 *
 * @phpstan-type LastExecution array{time: string, status: string, exception?: array{type: string, message: string}}
 */
final class SchedulerStore
{
    public const STARTED = 'STARTED';

    public const SUCCESS = 'SUCCESS';

    public const ERROR = 'ERROR';

    /** Seconds a task's record is kept, so the records of removed tasks do not pile up. */
    public const TASK_TTL = 7 * 24 * 60 * 60;

    private const HEARTBEAT_KEY = 'actuator:scheduler:heartbeat';

    private const TASK_KEY = 'actuator:scheduler:task:';

    private const MESSAGE_LENGTH = 1000;

    /**
     * The kinds of problem already logged ("read", "write", "rejected"), once per process, like
     * HealthEndpoint. A logged "write", a write that threw, also turns writes off for the rest of
     * the process.
     *
     * @var array<string, true>
     */
    private static array $logged = [];

    /**
     * Whether the scheduler records its executions and ticks ("actuator.scheduler.record").
     */
    public static function recording(): bool
    {
        return filter_var(config('actuator.scheduler.record', true), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Records the last execution of a task: STARTED, SUCCESS or ERROR, with the exception on ERROR.
     *
     * @param  array{type: string, message: string}|null  $exception
     */
    public static function recordExecution(Event $task, string $status, ?array $exception = null): void
    {
        if (! self::recording()) {
            return;
        }

        $execution = ['time' => Date::now()->toIso8601String(), 'status' => $status];

        if ($exception !== null) {
            $execution['exception'] = ['type' => $exception['type'], 'message' => Str::limit($exception['message'], self::MESSAGE_LENGTH)];
        }

        self::write(self::taskKey(ScheduledTaskTarget::of($task)), $execution, self::TASK_TTL);
    }

    /**
     * Records that "schedule:run" started now. Kept without expiry.
     */
    public static function recordTick(): void
    {
        if (self::recording()) {
            self::write(self::HEARTBEAT_KEY, Date::now()->getTimestamp(), null);
        }
    }

    /**
     * The last execution of each target that has one, keyed by target, read in one call.
     * A store that fails is logged and gives no execution.
     *
     * @param  list<string>  $targets
     * @return array<string, LastExecution>
     */
    public static function lastExecutions(array $targets): array
    {
        if ($targets === []) {
            return [];
        }

        $keys = [];
        foreach ($targets as $target) {
            $keys[$target] = self::taskKey($target);
        }

        try {
            $entries = self::store()->many(array_values(array_unique($keys)));
        } catch (Throwable $exception) {
            self::warnOnce('read', "Scheduled task executions could not be read: {$exception->getMessage()}", $exception);

            return [];
        }

        $executions = [];
        foreach ($keys as $target => $key) {
            $execution = self::execution($entries[$key] ?? null);

            if ($execution !== null) {
                $executions[$target] = $execution;
            }
        }

        return $executions;
    }

    /**
     * The time "schedule:run" last started, or null when it never did. Throws when the store fails.
     * A store can read the timestamp back as a string: RedisStore keeps numbers unserialized.
     */
    public static function lastTick(): ?CarbonInterface
    {
        $timestamp = self::store()->get(self::HEARTBEAT_KEY);

        if (is_string($timestamp) && ctype_digit($timestamp)) {
            $timestamp = (int) $timestamp;
        }

        return is_int($timestamp) ? Date::now()->setTimestamp($timestamp) : null;
    }

    /**
     * An entry in the LastExecution shape, or null for a miss or an entry in another shape.
     *
     * @return LastExecution|null
     */
    private static function execution(mixed $entry): ?array
    {
        if (! is_array($entry) || ! is_string($entry['time'] ?? null) || ! in_array($entry['status'] ?? null, [self::STARTED, self::SUCCESS, self::ERROR], true)) {
            return null;
        }

        $execution = ['time' => $entry['time'], 'status' => $entry['status']];
        $exception = $entry['exception'] ?? null;

        if (is_array($exception) && is_string($exception['type'] ?? null) && is_string($exception['message'] ?? null)) {
            $execution['exception'] = ['type' => $exception['type'], 'message' => $exception['message']];
        }

        return $execution;
    }

    private static function write(string $key, mixed $value, ?int $ttl): void
    {
        if (isset(self::$logged['write'])) {
            return;
        }

        try {
            // A store can also reject an entry without throwing.
            if (! self::store()->put($key, $value, $ttl)) {
                self::warnOnce('rejected', 'Scheduler record could not be written: the store rejected the entry.');
            }
        } catch (Throwable $exception) {
            self::warnOnce('write', "Scheduler record could not be written: {$exception->getMessage()}. Recording stops for this process.", $exception);
        }
    }

    private static function taskKey(string $target): string
    {
        return self::TASK_KEY.hash('xxh128', $target);
    }

    /**
     * Resolved on each use, never captured at boot, so a long-lived worker (Octane) follows the
     * current container. An empty name is the application's default store.
     */
    private static function store(): Repository
    {
        $name = config('actuator.scheduler.store');

        if ($name !== null && ! is_string($name)) {
            throw new InvalidArgumentException('Scheduler setting [actuator.scheduler.store] must be a cache store name.');
        }

        return Container::getInstance()->make(CacheFactory::class)->store($name === '' ? null : $name);
    }

    private static function warnOnce(string $kind, string $message, ?Throwable $exception = null): void
    {
        if (isset(self::$logged[$kind])) {
            return;
        }

        self::$logged[$kind] = true;

        try {
            Log::warning($message, $exception === null ? [] : ['exception' => $exception]);
        } catch (Throwable) {
            // A logger that fails, such as on a full disk, must not fail the task or the endpoint either.
        }
    }
}

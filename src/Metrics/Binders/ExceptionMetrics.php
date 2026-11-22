<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Metrics\Binders;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Exceptions\Handler;
use Nwidart\Actuator\Metrics\MeterBinder;
use Nwidart\Actuator\Metrics\MeterRegistry;
use Throwable;

/**
 * Counts the exceptions the application reports. Exceptions Laravel does
 * not report, such as a 404 or a validation failure, are not counted.
 * Tag contributors receive the Throwable.
 *
 * @internal
 */
final readonly class ExceptionMetrics implements MeterBinder
{
    public const NAME = 'exceptions';

    public function __construct(private Application $app) {}

    public function bind(MeterRegistry $registry): void
    {
        $registry->describe(self::NAME, 'Exceptions reported by the application.');

        if (! $registry->enabled(self::NAME)) {
            return;
        }

        $count = static function (ExceptionHandler $handler) use ($registry): void {
            if ($handler instanceof Handler) {
                $handler->reportable(static function (Throwable $e) use ($registry): void {
                    $registry->counter(self::NAME, [
                        'exception' => self::name($e),
                        ...$registry->tagsFor(self::NAME, $e),
                    ])->increment();
                });
            }
        };

        // The handler is only built when something goes wrong: wait for it.
        $this->app->afterResolving(ExceptionHandler::class, $count);

        if ($this->app->resolved(ExceptionHandler::class)) {
            $count($this->app->make(ExceptionHandler::class));
        }
    }

    /**
     * The class name without its namespace, as Spring tags exceptions.
     */
    public static function name(Throwable $e): string
    {
        return str_contains($e::class, '@anonymous') ? 'anonymous' : class_basename($e);
    }
}

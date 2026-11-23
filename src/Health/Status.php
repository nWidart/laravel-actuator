<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Health;

enum Status: string
{
    case Down = 'DOWN';
    case OutOfService = 'OUT_OF_SERVICE';
    case Warning = 'WARNING';
    case Up = 'UP';
    case Unknown = 'UNKNOWN';

    /**
     * Pick the most severe status. Severity follows $order, then declaration order:
     * DOWN, OUT_OF_SERVICE, WARNING, UP, UNKNOWN. No status gives UNKNOWN.
     *
     * @param  iterable<Status>  $statuses
     * @param  list<Status>  $order  Most severe first. Statuses it omits sort after it.
     */
    public static function aggregate(iterable $statuses, array $order = []): self
    {
        $present = [];
        foreach ($statuses as $status) {
            $present[$status->value] = true;
        }

        foreach ([...$order, ...self::cases()] as $case) {
            if (isset($present[$case->value])) {
                return $case;
            }
        }

        return self::Unknown;
    }

    /**
     * The default HTTP status code: 503 for DOWN and OUT_OF_SERVICE, 200 otherwise.
     */
    public function httpStatus(): int
    {
        return match ($this) {
            self::Down, self::OutOfService => 503,
            self::Warning, self::Up, self::Unknown => 200,
        };
    }
}

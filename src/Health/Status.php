<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Health;

enum Status: string
{
    case Down = 'DOWN';
    case OutOfService = 'OUT_OF_SERVICE';
    case Up = 'UP';
    case Unknown = 'UNKNOWN';

    /**
     * Pick the most severe status. Severity follows declaration order:
     * DOWN, OUT_OF_SERVICE, UP, UNKNOWN. No status gives UNKNOWN.
     *
     * @param  iterable<Status>  $statuses
     */
    public static function aggregate(iterable $statuses): self
    {
        $present = [];
        foreach ($statuses as $status) {
            $present[$status->value] = true;
        }

        foreach (self::cases() as $case) {
            if (isset($present[$case->value])) {
                return $case;
            }
        }

        return self::Unknown;
    }

    public function httpStatus(): int
    {
        return match ($this) {
            self::Down, self::OutOfService => 503,
            self::Up, self::Unknown => 200,
        };
    }
}

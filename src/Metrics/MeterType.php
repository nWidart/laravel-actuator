<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Metrics;

enum MeterType: string
{
    case Counter = 'counter';
    case Gauge = 'gauge';
    case Timer = 'timer';
    case Summary = 'summary';
}

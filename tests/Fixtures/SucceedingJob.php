<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class SucceedingJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void {}
}

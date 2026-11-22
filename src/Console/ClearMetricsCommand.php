<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Console;

use Illuminate\Console\Command;
use Nwidart\MetricsStore\MetricStore;

final class ClearMetricsCommand extends Command
{
    protected $signature = 'actuator:metrics:clear';

    protected $description = 'Remove every stored metric, for example after routes or tags changed';

    public function handle(MetricStore $store): int
    {
        $store->clear();

        $this->components->info('The metrics store is empty.');

        return self::SUCCESS;
    }
}

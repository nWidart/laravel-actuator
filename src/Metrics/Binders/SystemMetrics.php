<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Metrics\Binders;

use Nwidart\Actuator\Metrics\MeterBinder;
use Nwidart\Actuator\Metrics\MeterRegistry;

/**
 * Load and disk space of the server that answers, under Spring's names.
 *
 * @internal
 */
final readonly class SystemMetrics implements MeterBinder
{
    public function bind(MeterRegistry $registry): void
    {
        $registry->describe('system.load.average.1m', 'System load average over the last minute.');
        $registry->describe('disk.free', 'Free space on the disk that holds the application.');
        $registry->describe('disk.total', 'Size of the disk that holds the application.');

        $registry->collecting(static function (MeterRegistry $registry): void {
            $registry->gauge('system.load.average.1m', value: static function (): ?float {
                $load = function_exists('sys_getloadavg') ? sys_getloadavg() : false;

                return $load === false ? null : $load[0];
            });

            // False means the disk cannot be read. Zero is a full disk, and is reported.
            $registry->gauge('disk.free', value: static function (): ?float {
                $free = disk_free_space(base_path());

                return $free === false ? null : $free;
            }, unit: 'bytes');

            $registry->gauge('disk.total', value: static function (): ?float {
                $total = disk_total_space(base_path());

                return $total === false ? null : $total;
            }, unit: 'bytes');
        });
    }
}

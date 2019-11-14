<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Health;

class DiskSpaceContributor implements HealthContributor
{
    public function run(): array
    {
        return [
            'free' => $this->formatBytes(disk_free_space('/')),
            'total' => $this->formatBytes(disk_total_space('/')),
        ];
    }

    /*
     * Source https://stackoverflow.com/questions/2510434/format-bytes-to-kilobytes-megabytes-gigabytes
     */
    private function formatBytes($bytes, $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));

        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}

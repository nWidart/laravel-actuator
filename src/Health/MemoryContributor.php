<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Health;

class MemoryContributor implements HealthContributor
{
    public function run(): array
    {
        return [
            'free' => $this->getMemoryFree(),
            'memory' => $this->getMemoryAllocated(),
        ];
    }

    /**
     * Return the memory free in kbytes
     */
    private function getMemoryFree(): int
    {
        return $this->getMemoryAllocated() - $this->getMemoryUsage();
    }

    /**
     * Return the maximum memory allocated to php in kbytes
     */
    private function getMemoryAllocated(): int
    {
        return ((int) ini_get('memory_limit')) * 1024;
    }

    /**
     * Return the memory usage in kbytes
     */
    private function getMemoryUsage(): int
    {
        return memory_get_usage(true) / 1024;
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

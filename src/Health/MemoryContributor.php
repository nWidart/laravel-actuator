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
}

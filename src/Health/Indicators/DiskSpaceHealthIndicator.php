<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Health\Indicators;

use Nwidart\Actuator\Health\Health;
use Nwidart\Actuator\Health\HealthIndicator;

/**
 * DOWN when the free space on the disk holding the path drops below the threshold (bytes).
 */
final readonly class DiskSpaceHealthIndicator implements HealthIndicator
{
    private string $path;

    public function __construct(?string $path = null, private int $threshold = 10 * 1024 * 1024)
    {
        $this->path = $path ?? base_path();
    }

    public function health(): Health
    {
        $free = file_exists($this->path) ? disk_free_space($this->path) : false;
        $total = file_exists($this->path) ? disk_total_space($this->path) : false;

        $details = [
            'total' => $total === false ? 0 : (int) $total,
            'free' => $free === false ? 0 : (int) $free,
            'threshold' => $this->threshold,
            'path' => $this->path,
            'exists' => $free !== false,
        ];

        return $free !== false && $free >= $this->threshold ? Health::up($details) : Health::down($details);
    }
}

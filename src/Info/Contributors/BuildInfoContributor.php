<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Info\Contributors;

use Nwidart\Actuator\Info\BuildInfo;
use Nwidart\Actuator\Info\InfoContributor;

/**
 * Shows the file written by "php artisan actuator:build-info" as Spring Boot's build section.
 */
final readonly class BuildInfoContributor implements InfoContributor
{
    private BuildInfo $file;

    public function __construct(?string $path = null)
    {
        $this->file = new BuildInfo($path);
    }

    public function contribute(): array
    {
        return $this->file->read();
    }
}

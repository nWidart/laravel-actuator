<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Info;

interface InfoContributor
{
    /**
     * Data shown under this contributor's key. An empty array omits the key.
     *
     * @return array<string, mixed>
     */
    public function contribute(): array;
}

<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Tests\Fixtures;

use Nwidart\Actuator\Info\InfoContributor;

final readonly class FixedInfoContributor implements InfoContributor
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(private array $data = ['key' => 'value']) {}

    public function contribute(): array
    {
        return $this->data;
    }
}

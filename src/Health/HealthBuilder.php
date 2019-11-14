<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Health;

class HealthBuilder
{
    /**
     * @var HealthContributorRegistry
     */
    private $registry;

    public function __construct(HealthContributorRegistry $registry)
    {
        $this->registry = $registry;
    }

    public function build(): Health
    {
        $health = new Health();

        $this->registry->getAll()->map(static function (HealthContributor $contributor, $name) use ($health) {
            if ($name === 'status') {
                $health->status = $contributor->run();

                return;
            }
            $health->details[$name] = $contributor->run();
        });

        return $health;
    }
}

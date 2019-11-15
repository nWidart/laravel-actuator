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

        $this->registry->getAll()->map(function (HealthContributor $contributor, $name) use ($health) {
            if ($name === 'status') {
                $health->status = $contributor->run();

                return;
            }
            if ($this->detailsAreEnabled()) {
                $health->details[$name] = $contributor->run();
            }
        });

        return $health;
    }

    private function detailsAreEnabled(): bool
    {
        return (bool) config('actuator.health.show-details', false);
    }
}

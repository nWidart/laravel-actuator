<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Tests\Feature\Indicators;

use Illuminate\Container\Container;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Mockery;
use Nwidart\Actuator\Health\Indicators\HorizonHealthIndicator;
use Nwidart\Actuator\Health\Status;
use Nwidart\Actuator\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class HorizonHealthIndicatorTest extends TestCase
{
    #[Test]
    public function no_master_supervisor_is_inactive_and_down(): void
    {
        $health = $this->health([]);

        $this->assertSame(Status::Down, $health['status']);
        $this->assertSame(['status' => 'inactive', 'masters' => 0], $health['details']);
    }

    #[Test]
    public function running_master_supervisors_are_up(): void
    {
        $health = $this->health(['running', 'running']);

        $this->assertSame(Status::Up, $health['status']);
        $this->assertSame(['status' => 'running', 'masters' => 2], $health['details']);
    }

    #[Test]
    public function a_paused_master_supervisor_is_a_warning(): void
    {
        $health = $this->health(['running', 'paused']);

        $this->assertSame(Status::Warning, $health['status']);
        $this->assertSame(['status' => 'paused', 'masters' => 2], $health['details']);
    }

    #[Test]
    public function without_horizon_it_is_unknown(): void
    {
        $indicator = new class extends HorizonHealthIndicator
        {
            protected function installed(): bool
            {
                return false;
            }
        };

        $health = $indicator->health();

        $this->assertSame(Status::Unknown, $health->status);
        $this->assertSame(['error' => 'laravel/horizon is not installed'], $health->details);
    }

    #[Test]
    public function the_repository_comes_from_the_current_container(): void
    {
        // Built in the worker's container, as under Octane, which only runs the indicator.
        $indicator = new HorizonHealthIndicator;
        $this->app->instance(MasterSupervisorRepository::class, $this->repository(['running']));

        // Octane runs each request in a sandbox: a clone of the application, set as the instance.
        $sandbox = clone $this->app;
        $sandbox->instance('app', $sandbox);
        $sandbox->instance(Container::class, $sandbox);
        $sandbox->instance(MasterSupervisorRepository::class, $this->repository(['paused']));
        Container::setInstance($sandbox);

        try {
            $health = $indicator->health();
        } finally {
            Container::setInstance($this->app);
        }

        $this->assertSame(Status::Warning, $health->status);
        $this->assertSame(['status' => 'paused', 'masters' => 1], $health->details);
    }

    /**
     * @param  list<string>  $statuses  the status of each master supervisor
     * @return array{status: Status, details: array<string, mixed>}
     */
    private function health(array $statuses): array
    {
        $this->app->instance(MasterSupervisorRepository::class, $this->repository($statuses));

        $health = (new HorizonHealthIndicator)->health();

        return ['status' => $health->status, 'details' => $health->details];
    }

    /**
     * @param  list<string>  $statuses  the status of each master supervisor
     */
    private function repository(array $statuses): MasterSupervisorRepository
    {
        $masters = array_map(fn (string $status): object => (object) ['name' => 'host-'.uniqid(), 'status' => $status], $statuses);
        $repository = Mockery::mock(MasterSupervisorRepository::class);
        $repository->allows('all')->andReturn($masters);

        return $repository;
    }
}

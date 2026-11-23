<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Console;

use Illuminate\Console\Command;
use Nwidart\Actuator\Health\HealthEndpoint;
use Nwidart\Actuator\Health\Status;

/**
 * Checks the health from the shell, for exec probes on pods without an HTTP server. Shows
 * everything whatever the visibility settings, since whoever runs it has shell access, and
 * never uses the result cache.
 */
final class HealthCommand extends Command
{
    protected $signature = 'actuator:health
        {path? : A group, a component, or a component of a group, such as readiness/db}
        {--details : Show the components and their details}
        {--json : Print the same JSON as the health endpoint}';

    protected $description = 'Check the health of the application; exits 0 when healthy, 1 when not, 2 for an unknown path';

    public function handle(HealthEndpoint $endpoint): int
    {
        $argument = $this->argument('path');
        $path = array_values(array_filter(explode('/', is_string($argument) ? $argument : ''), static fn (string $segment): bool => $segment !== ''));

        $health = $endpoint->health($path, cache: false, showAll: true);

        if ($health === null) {
            $this->components->error('No health for path ['.implode('/', $path).'].');

            return self::INVALID;
        }

        if ($this->option('json')) {
            $this->line(json_encode($health, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->line($health['status']->value);

            if ($this->option('details')) {
                $this->details($health['details'] ?? [], 1);

                foreach ($health['components'] ?? [] as $name => $component) {
                    /** @var array{status: Status, details?: array<string, mixed>} $component */
                    $this->line("  {$name}: {$component['status']->value}");
                    $this->details($component['details'] ?? [], 2);
                }
            }
        }

        $httpStatus = $endpoint->httpStatus($path, $health['status']);

        return $httpStatus >= 200 && $httpStatus < 300 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function details(array $details, int $depth): void
    {
        $indent = str_repeat('  ', $depth);

        foreach ($details as $key => $value) {
            $text = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
            $this->line("{$indent}{$key}: {$text}");
        }
    }
}

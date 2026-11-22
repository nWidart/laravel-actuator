<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Http\Controllers;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Nwidart\Actuator\Visibility;

/**
 * Configuration and environment variables, in the Spring Boot "env" format.
 */
final class EnvController
{
    private const MASK = '******';

    public function __invoke(Application $app, Repository $config): JsonResponse
    {
        $showValues = Visibility::fromConfig($config->get('actuator.env.show_values', 'never'))->isGranted();

        return new JsonResponse([
            'activeProfiles' => [$app->environment()],
            'propertySources' => [
                $this->source('config', Arr::dot($config->all()), $showValues),
                $this->source('environment', [...getenv(), ...$_ENV], $showValues),
            ],
        ]);
    }

    /**
     * @param  array<array-key, mixed>  $properties
     * @return array{name: string, properties: object}
     */
    private function source(string $name, array $properties, bool $showValues): array
    {
        ksort($properties);

        return [
            'name' => $name,
            'properties' => (object) array_map(
                static fn (mixed $value): array => ['value' => $showValues ? $value : self::MASK],
                $properties,
            ),
        ];
    }
}

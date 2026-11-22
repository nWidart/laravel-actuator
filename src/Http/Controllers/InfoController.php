<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Http\Controllers;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;
use Nwidart\Actuator\Info\InfoContributor;

final class InfoController
{
    public function __invoke(Container $container, Repository $config): JsonResponse
    {
        $info = [];

        /** @var array<string, string|array{0: class-string, 1?: array<string, mixed>}> $contributors */
        $contributors = $config->get('actuator.info.contributors', []);

        foreach ($contributors as $name => $definition) {
            $contributor = $container->make(...(array) $definition);

            if (! $contributor instanceof InfoContributor) {
                throw new InvalidArgumentException("Info contributor [{$name}] must implement ".InfoContributor::class.'.');
            }

            if (($data = $contributor->contribute()) !== []) {
                $info[$name] = $data;
            }
        }

        return new JsonResponse((object) $info);
    }
}

<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Nwidart\Actuator\Endpoint;

final class DiscoveryController
{
    public function __invoke(): JsonResponse
    {
        $links = ['self' => $this->link(route('actuator.discovery'))];

        foreach (Endpoint::exposed() as $endpoint) {
            $links[$endpoint->value] = $this->link(route("actuator.{$endpoint->value}"));

            if ($endpoint === Endpoint::Health) {
                $links['health-path'] = $this->link(route('actuator.health').'/{*path}', templated: true);
            }
        }

        return new JsonResponse(['_links' => $links]);
    }

    /**
     * @return array{href: string, templated: bool}
     */
    private function link(string $href, bool $templated = false): array
    {
        return ['href' => $href, 'templated' => $templated];
    }
}

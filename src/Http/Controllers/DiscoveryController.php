<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Nwidart\Actuator\Endpoints\EndpointRegistry;
use Nwidart\Actuator\Endpoints\Operation;

final class DiscoveryController
{
    public function __invoke(EndpointRegistry $registry): JsonResponse
    {
        $self = route('actuator.discovery');
        $links = ['self' => $this->link($self)];

        foreach ($registry->exposed() as $id => $class) {
            foreach (Operation::of($id, $class) as $operation) {
                // Operations on the same path share one link.
                $links[$operation->key()] ??= $this->link(
                    rtrim($self, '/').'/'.$operation->path(template: true),
                    templated: $operation->selectors !== [],
                );
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

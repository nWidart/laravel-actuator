<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Tests\Fixtures\Endpoints;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Nwidart\Actuator\Endpoints\Attributes\DeleteOperation;
use Nwidart\Actuator\Endpoints\Attributes\ReadOperation;
use Nwidart\Actuator\Endpoints\Attributes\Selector;
use Nwidart\Actuator\Endpoints\Attributes\WriteOperation;

final class WidgetsEndpoint
{
    private const WIDGETS = ['alpha' => ['name' => 'alpha', 'parts' => ['lid']]];

    /**
     * @return array{widgets: list<string>}
     */
    #[ReadOperation]
    public function widgets(): array
    {
        return ['widgets' => array_keys(self::WIDGETS)];
    }

    /**
     * @return array{name: string, parts: list<string>}|null
     */
    #[ReadOperation]
    public function widget(#[Selector] string $name): ?array
    {
        return self::WIDGETS[$name] ?? null;
    }

    /**
     * @return array{widget: string, part: string}
     */
    #[ReadOperation]
    public function part(#[Selector] string $name, #[Selector] string $part): array
    {
        return ['widget' => $name, 'part' => $part];
    }

    /**
     * @return array{name: string, enabled: bool}
     */
    #[WriteOperation]
    public function update(Request $request, #[Selector] string $name): array
    {
        return ['name' => $name, 'enabled' => $request->boolean('enabled')];
    }

    #[WriteOperation]
    public function reset(): void {}

    #[DeleteOperation]
    public function remove(#[Selector] string $name): Response
    {
        return new Response("removed {$name}", 202, ['Content-Type' => 'text/plain']);
    }

    public function notAnOperation(): string
    {
        return 'ignored';
    }
}

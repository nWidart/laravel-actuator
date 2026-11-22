<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Endpoints;

use InvalidArgumentException;
use Nwidart\Actuator\Endpoints\Attributes\DeleteOperation;
use Nwidart\Actuator\Endpoints\Attributes\ReadOperation;
use Nwidart\Actuator\Endpoints\Attributes\Selector;
use Nwidart\Actuator\Endpoints\Attributes\WriteOperation;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * One operation of an endpoint: a method served on one HTTP verb and path.
 *
 * @internal
 */
final readonly class Operation
{
    private const VERBS = [
        ReadOperation::class => 'GET',
        WriteOperation::class => 'POST',
        DeleteOperation::class => 'DELETE',
    ];

    private const ROUTE_NAME_SUFFIXES = ['GET' => '', 'POST' => '.write', 'DELETE' => '.delete'];

    /**
     * @param  'GET'|'POST'|'DELETE'  $verb
     * @param  list<string>  $selectors  The selector parameter names, in path order.
     * @param  string|null  $remaining  The selector that matches the whole remaining path, if any.
     */
    private function __construct(
        public string $endpoint,
        public string $method,
        public string $verb,
        public array $selectors,
        public ?string $remaining,
    ) {}

    /**
     * The operations declared by an endpoint class.
     *
     * @return non-empty-list<self>
     */
    public static function of(string $endpoint, string $class): array
    {
        if (! class_exists($class)) {
            throw new InvalidArgumentException("Actuator endpoint [{$endpoint}] class [{$class}] does not exist.");
        }

        $operations = [];

        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $verbs = array_values(array_filter(
                self::VERBS,
                static fn (string $attribute): bool => $method->getAttributes($attribute) !== [],
                ARRAY_FILTER_USE_KEY,
            ));

            if ($verbs === []) {
                continue;
            }

            $name = "{$class}::{$method->getName()}()";

            if (count($verbs) > 1) {
                throw new InvalidArgumentException("Actuator operation [{$name}] must have exactly one operation attribute.");
            }

            [$selectors, $remaining] = self::selectors($method, $name);
            $shape = $verbs[0].' '.count($selectors);

            if (isset($operations[$shape])) {
                throw new InvalidArgumentException("Actuator operation [{$name}] has the same HTTP verb and path as [{$class}::{$operations[$shape]->method}()].");
            }

            $operations[$shape] = new self($endpoint, $method->getName(), $verbs[0], $selectors, $remaining);
        }

        if ($operations === []) {
            throw new InvalidArgumentException("Actuator endpoint [{$endpoint}] class [{$class}] declares no operation.");
        }

        return array_values($operations);
    }

    /**
     * The path below the base path. A template marks a remaining-path selector as "{*name}".
     */
    public function path(bool $template = false): string
    {
        $segments = array_map(
            fn (string $selector): string => $template && $selector === $this->remaining ? "{*{$selector}}" : "{{$selector}}",
            $this->selectors,
        );

        return implode('/', [$this->endpoint, ...$segments]);
    }

    /**
     * The discovery link key, shared by the operations on the same path.
     */
    public function key(): string
    {
        return implode('-', [$this->endpoint, ...$this->selectors]);
    }

    /**
     * The route name, without the "actuator." prefix.
     */
    public function routeName(): string
    {
        return $this->key().self::ROUTE_NAME_SUFFIXES[$this->verb];
    }

    /**
     * @return array{list<string>, string|null}
     */
    private static function selectors(ReflectionMethod $method, string $name): array
    {
        $selectors = [];
        $remaining = null;

        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();
            $selector = $parameter->getAttributes(Selector::class)[0] ?? null;

            if ($selector === null) {
                if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                    throw new InvalidArgumentException("Parameter [\${$parameter->getName()}] of actuator operation [{$name}] must be a selector or have a class type.");
                }

                continue;
            }

            if ($remaining !== null) {
                throw new InvalidArgumentException("A selector that matches the remaining path must be the last selector of actuator operation [{$name}].");
            }

            if ($type !== null && ! ($type instanceof ReflectionNamedType && $type->getName() === 'string')) {
                throw new InvalidArgumentException("Selector [\${$parameter->getName()}] of actuator operation [{$name}] must be a string.");
            }

            $selectors[] = $parameter->getName();
            $remaining = $selector->newInstance()->remaining ? $parameter->getName() : null;
        }

        return [$selectors, $remaining];
    }
}

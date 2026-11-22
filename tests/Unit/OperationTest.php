<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Tests\Unit;

use InvalidArgumentException;
use Nwidart\Actuator\Endpoints\Operation;
use Nwidart\Actuator\Http\Controllers\HealthController;
use Nwidart\Actuator\Tests\Fixtures\Endpoints\IntegerSelectorEndpoint;
use Nwidart\Actuator\Tests\Fixtures\Endpoints\NoOperationEndpoint;
use Nwidart\Actuator\Tests\Fixtures\Endpoints\RemainingNotLastEndpoint;
use Nwidart\Actuator\Tests\Fixtures\Endpoints\SamePathEndpoint;
use Nwidart\Actuator\Tests\Fixtures\Endpoints\ScalarParameterEndpoint;
use Nwidart\Actuator\Tests\Fixtures\Endpoints\TwoAttributesEndpoint;
use Nwidart\Actuator\Tests\Fixtures\Endpoints\WidgetsEndpoint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OperationTest extends TestCase
{
    #[Test]
    public function it_reads_the_operations_of_an_endpoint_class(): void
    {
        $operations = array_map(
            static fn (Operation $operation): array => [$operation->method, $operation->verb, $operation->path(), $operation->key(), $operation->routeName()],
            Operation::of('widgets', WidgetsEndpoint::class),
        );

        $this->assertSame([
            ['widgets', 'GET', 'widgets', 'widgets', 'widgets'],
            ['widget', 'GET', 'widgets/{name}', 'widgets-name', 'widgets-name'],
            ['part', 'GET', 'widgets/{name}/{part}', 'widgets-name-part', 'widgets-name-part'],
            ['update', 'POST', 'widgets/{name}', 'widgets-name', 'widgets-name.write'],
            ['reset', 'POST', 'widgets', 'widgets', 'widgets.write'],
            ['remove', 'DELETE', 'widgets/{name}', 'widgets-name', 'widgets-name.delete'],
        ], $operations);
    }

    #[Test]
    public function a_selector_can_match_the_remaining_path(): void
    {
        [, $forPath] = Operation::of('health', HealthController::class);

        $this->assertSame('path', $forPath->remaining);
        $this->assertSame('health/{path}', $forPath->path());
        $this->assertSame('health/{*path}', $forPath->path(template: true));
    }

    #[Test]
    #[DataProvider('invalidEndpoints')]
    public function it_rejects_an_invalid_endpoint_class(string $class, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        Operation::of('invalid', $class);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidEndpoints(): iterable
    {
        yield 'missing class' => ['App\\Missing', 'class [App\\Missing] does not exist'];
        yield 'no operation' => [NoOperationEndpoint::class, 'declares no operation'];
        yield 'two operation attributes' => [TwoAttributesEndpoint::class, 'must have exactly one operation attribute'];
        yield 'same verb and path' => [SamePathEndpoint::class, 'has the same HTTP verb and path as ['.SamePathEndpoint::class.'::first()]'];
        yield 'scalar parameter' => [ScalarParameterEndpoint::class, 'Parameter [$limit] of actuator operation ['.ScalarParameterEndpoint::class.'::read()] must be a selector or have a class type.'];
        yield 'integer selector' => [IntegerSelectorEndpoint::class, 'Selector [$id] of actuator operation ['.IntegerSelectorEndpoint::class.'::read()] must be a string.'];
        yield 'remaining selector not last' => [RemainingNotLastEndpoint::class, 'must be the last selector'];
    }
}

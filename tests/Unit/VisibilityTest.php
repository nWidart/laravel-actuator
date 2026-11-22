<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Tests\Unit;

use InvalidArgumentException;
use Nwidart\Actuator\Visibility;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class VisibilityTest extends TestCase
{
    #[Test]
    public function it_reads_config_values(): void
    {
        $this->assertSame(Visibility::WhenAuthorized, Visibility::fromConfig('when_authorized'));
    }

    #[Test]
    #[DataProvider('invalidValues')]
    public function it_rejects_invalid_config_values(mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        Visibility::fromConfig($value);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidValues(): iterable
    {
        yield 'unknown string' => ['sometimes'];
        yield 'boolean' => [true];
    }
}

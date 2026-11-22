<?php

declare(strict_types=1);

namespace Nwidart\Actuator;

use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

enum Visibility: string
{
    /**
     * Gate checked for "when_authorized". Define it in your application:
     * Gate::define('viewActuator', fn (User $user) => $user->isAdmin());
     */
    public const GATE = 'viewActuator';

    case Never = 'never';
    case WhenAuthorized = 'when_authorized';
    case Always = 'always';

    public static function fromConfig(mixed $value): self
    {
        $visibility = is_string($value) ? self::tryFrom($value) : null;

        return $visibility ?? throw new InvalidArgumentException('Visibility must be "never", "when_authorized" or "always".');
    }

    public function isGranted(): bool
    {
        return match ($this) {
            self::Never => false,
            self::Always => true,
            self::WhenAuthorized => Gate::has(self::GATE) && Gate::allows(self::GATE),
        };
    }
}

<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Health;

use Throwable;

final readonly class Health
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public Status $status,
        public array $details = [],
    ) {}

    /**
     * @param  array<string, mixed>  $details
     */
    public static function up(array $details = []): self
    {
        return new self(Status::Up, $details);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public static function down(array $details = []): self
    {
        return new self(Status::Down, $details);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public static function outOfService(array $details = []): self
    {
        return new self(Status::OutOfService, $details);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public static function unknown(array $details = []): self
    {
        return new self(Status::Unknown, $details);
    }

    public function withDetail(string $key, mixed $value): self
    {
        return new self($this->status, [...$this->details, $key => $value]);
    }

    public function withException(Throwable $exception): self
    {
        return $this->withDetail('error', $exception::class.': '.$exception->getMessage());
    }
}

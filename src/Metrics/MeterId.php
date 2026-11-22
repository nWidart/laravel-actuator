<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Metrics;

/**
 * Identity of a meter: its type, dotted name, base unit and tags.
 *
 * A meter is stored as one or more series. Each series holds one statistic
 * of the meter ("total", "value", "count", "sum" or "le:{bound}") under a
 * key that carries the whole identity, so a store only maps keys to numbers.
 *
 * @internal
 */
final readonly class MeterId
{
    /**
     * @param  array<string, string>  $tags  sorted by name
     */
    public function __construct(
        public MeterType $type,
        public string $name,
        public string $unit,
        public array $tags,
    ) {}

    public function series(string $statistic): string
    {
        return (string) json_encode(
            [$this->type->value, $this->name, $this->unit, (object) $this->tags, $statistic],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        );
    }

    /**
     * @return array{self, string}|null the meter and the statistic, or null for a key this class did not write
     */
    public static function parse(string $series): ?array
    {
        $parts = json_decode($series, true);

        if (! is_array($parts) || count($parts) !== 5 || ! array_is_list($parts)) {
            return null;
        }

        [$type, $name, $unit, $tags, $statistic] = $parts;

        if (! is_string($type) || ! is_string($name) || ! is_string($unit) || ! is_array($tags) || ! is_string($statistic)) {
            return null;
        }

        $type = MeterType::tryFrom($type);

        if ($type === null) {
            return null;
        }

        $labels = [];

        foreach ($tags as $tag => $value) {
            if (! is_scalar($value)) {
                return null;
            }

            $labels[(string) $tag] = (string) $value;
        }

        return [new self($type, $name, $unit, $labels), $statistic];
    }
}

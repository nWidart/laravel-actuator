<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Metrics\Export;

use Nwidart\Actuator\Metrics\MeterSnapshot;
use Nwidart\Actuator\Metrics\MeterType;

/**
 * Renders meters in the Prometheus text exposition format, version 0.0.4.
 *
 * Names follow Micrometer's Prometheus convention: dots become underscores,
 * the base unit is appended, and counters end in "_total".
 */
final class PrometheusTextFormat
{
    public const CONTENT_TYPE = 'text/plain; version=0.0.4; charset=utf-8';

    /**
     * @param  list<MeterSnapshot>  $meters
     */
    public function render(array $meters): string
    {
        $families = [];

        foreach ($meters as $meter) {
            $families[$this->family($meter)][] = $meter;
        }

        ksort($families);
        $output = '';

        foreach ($families as $family => $members) {
            $distribution = in_array($members[0]->type, [MeterType::Timer, MeterType::Summary], true);
            $histogram = $distribution && array_filter($members, static fn (MeterSnapshot $meter): bool => $meter->buckets !== []) !== [];

            if ($members[0]->description !== '') {
                $output .= "# HELP {$family} ".strtr($members[0]->description, ['\\' => '\\\\', "\n" => '\\n'])."\n";
            }

            $output .= "# TYPE {$family} ".match (true) {
                $histogram => 'histogram',
                $distribution => 'summary',
                $members[0]->type === MeterType::Counter => 'counter',
                default => 'gauge',
            }."\n";

            $lines = [];

            foreach ($members as $meter) {
                if (! $distribution) {
                    $lines[] = $this->sample($family, $meter->tags, $meter->value);

                    continue;
                }

                if ($histogram) {
                    foreach ($meter->buckets as [$bound, $count]) {
                        $lines[] = $this->sample("{$family}_bucket", [...$meter->tags, 'le' => $this->number($bound)], $count);
                    }

                    $lines[] = $this->sample("{$family}_bucket", [...$meter->tags, 'le' => '+Inf'], $meter->count);
                }

                $lines[] = $this->sample("{$family}_count", $meter->tags, $meter->count);
                $lines[] = $this->sample("{$family}_sum", $meter->tags, $meter->sum);
            }

            $output .= implode('', $lines);
        }

        return $output;
    }

    private function family(MeterSnapshot $meter): string
    {
        $name = $this->name($meter->name);
        $unit = $this->name($meter->unit);
        $counter = $meter->type === MeterType::Counter;

        // "requests.total" and "requests" are the same counter: "_total" goes last, once.
        if ($counter && str_ends_with($name, '_total')) {
            $name = substr($name, 0, -6);
        }

        if ($unit !== '' && ! str_ends_with($name, "_{$unit}")) {
            $name .= "_{$unit}";
        }

        if ($counter) {
            $name .= '_total';
        }

        return $name;
    }

    /**
     * @param  array<string, string>  $tags
     */
    private function sample(string $name, array $tags, float $value): string
    {
        $labels = [];

        // Keyed by the label name: two tags that become the same name must not both be written.
        foreach ($tags as $tag => $tagValue) {
            $label = $this->name((string) $tag);
            $labels[$label] = $label.'="'.strtr($tagValue, ['\\' => '\\\\', '"' => '\\"', "\n" => '\\n']).'"';
        }

        return $name.($labels === [] ? '' : '{'.implode(',', $labels).'}').' '.$this->number($value)."\n";
    }

    private function name(string $name): string
    {
        $name = (string) preg_replace('/[^a-zA-Z0-9_]/', '_', $name);

        return preg_match('/^[0-9]/', $name) === 1 ? "_{$name}" : $name;
    }

    private function number(float $value): string
    {
        return match (true) {
            is_nan($value) => 'NaN',
            is_infinite($value) => $value > 0 ? '+Inf' : '-Inf',
            // A whole number is written without a fraction. Anything else is written
            // with every digit it has: a cast to string would keep only 14.
            floor($value) === $value && abs($value) < 1e15 => sprintf('%d', $value),
            default => (string) json_encode($value),
        };
    }
}

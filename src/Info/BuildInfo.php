<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Info;

/**
 * The build info file written by "php artisan actuator:build-info" and read by the build and git contributors.
 */
final readonly class BuildInfo
{
    private const KEYS = ['version', 'time', 'commit', 'branch'];

    public string $path;

    public function __construct(?string $path = null)
    {
        $configured = config('actuator.info.build.path');

        $this->path = $path ?? (is_string($configured) ? $configured : base_path('bootstrap/cache/actuator-build.json'));
    }

    /**
     * The non-empty values of the file. Empty when the file is absent or not valid JSON.
     *
     * @return array<'version'|'time'|'commit'|'branch', string>
     */
    public function read(): array
    {
        $contents = is_file($this->path) ? file_get_contents($this->path) : false;
        $data = $contents === false ? null : json_decode($contents, true);

        if (! is_array($data)) {
            return [];
        }

        $values = [];

        foreach (self::KEYS as $key) {
            if (is_string($data[$key] ?? null) && $data[$key] !== '') {
                $values[$key] = $data[$key];
            }
        }

        return $values;
    }

    /**
     * @param  array<'version'|'time'|'commit'|'branch', string|null>  $values
     */
    public function write(array $values): bool
    {
        $directory = dirname($this->path);

        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            return false;
        }

        return @file_put_contents($this->path, json_encode($values, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL) !== false;
    }
}

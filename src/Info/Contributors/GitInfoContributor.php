<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Info\Contributors;

use Nwidart\Actuator\Info\InfoContributor;

/**
 * Reads the current branch and commit from the .git directory, when deployed with one.
 */
final readonly class GitInfoContributor implements InfoContributor
{
    private string $directory;

    public function __construct(?string $directory = null)
    {
        $this->directory = $directory ?? base_path('.git');
    }

    public function contribute(): array
    {
        $head = $this->read('HEAD');

        if ($head === null) {
            return [];
        }

        if (! str_starts_with($head, 'ref: ')) {
            return ['commit' => ['id' => substr($head, 0, 7)]];
        }

        $ref = substr($head, 5);
        $commit = $this->read($ref) ?? $this->packedRef($ref);

        return array_filter([
            'branch' => str_starts_with($ref, 'refs/heads/') ? substr($ref, 11) : $ref,
            'commit' => $commit === null ? null : ['id' => substr($commit, 0, 7)],
        ]);
    }

    private function packedRef(string $ref): ?string
    {
        foreach (explode("\n", $this->read('packed-refs') ?? '') as $line) {
            if (str_ends_with($line, ' '.$ref)) {
                return strtok($line, ' ') ?: null;
            }
        }

        return null;
    }

    private function read(string $file): ?string
    {
        $path = $this->directory.'/'.$file;
        $contents = is_file($path) ? file_get_contents($path) : false;

        return $contents === false || trim($contents) === '' ? null : trim($contents);
    }
}

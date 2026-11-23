<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Process;
use Nwidart\Actuator\Info\BuildInfo;

final class BuildInfoCommand extends Command
{
    protected $signature = 'actuator:build-info
        {--build-version= : The version. Defaults to "git describe --tags --always"}
        {--time= : The build time. Defaults to now, in ISO-8601 UTC}
        {--commit= : The commit. Defaults to the current git commit}
        {--branch= : The branch. Defaults to the current git branch}
        {--path= : The file to write. Defaults to actuator.info.build.path}';

    protected $description = 'Write the build info shown by the info endpoint, at build time';

    public function handle(): int
    {
        $file = new BuildInfo($this->stringOption('path'));

        $branch = $this->stringOption('branch') ?? $this->git('rev-parse --abbrev-ref HEAD');

        $written = $file->write([
            'version' => $this->stringOption('build-version') ?? $this->git('describe --tags --always'),
            'time' => $this->stringOption('time') ?? Date::now()->utc()->toIso8601ZuluString(),
            'commit' => $this->stringOption('commit') ?? $this->git('rev-parse HEAD'),
            // A detached HEAD has no branch.
            'branch' => $branch === 'HEAD' ? null : $branch,
        ]);

        if (! $written) {
            $this->components->error("Could not write {$file->path}.");

            return self::FAILURE;
        }

        $this->components->info("Build info written to {$file->path}.");

        return self::SUCCESS;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The output of a git command, or null when git or the repository is not available.
     */
    private function git(string $arguments): ?string
    {
        $result = Process::path(base_path())->run('git '.$arguments);
        $output = trim($result->output());

        return $result->successful() && $output !== '' ? $output : null;
    }
}

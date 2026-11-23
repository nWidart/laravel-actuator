<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Process;
use Nwidart\Actuator\Info\Contributors\BuildInfoContributor;
use Nwidart\Actuator\Info\Contributors\GitInfoContributor;
use Nwidart\Actuator\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

final class BuildInfoTest extends TestCase
{
    private string $directory;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/actuator-build-'.uniqid();
        $this->path = $this->directory.'/actuator-build.json';
        config()->set('actuator.info.build.path', $this->path);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->directory);

        parent::tearDown();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('actuator.exposure.include', ['info']);
    }

    #[Test]
    public function the_command_writes_the_values_given_as_options(): void
    {
        Process::fake();

        $this->artisan('actuator:build-info', [
            '--build-version' => 'v1.4.2',
            '--time' => '2026-11-22T09:12:44Z',
            '--commit' => 'ae06613b0c',
            '--branch' => 'main',
        ])->assertSuccessful();

        $this->assertSame(
            ['version' => 'v1.4.2', 'time' => '2026-11-22T09:12:44Z', 'commit' => 'ae06613b0c', 'branch' => 'main'],
            json_decode((string) file_get_contents($this->path), true),
        );
        Process::assertNothingRan();
    }

    #[Test]
    public function the_command_reads_defaults_from_git(): void
    {
        Date::setTestNow('2026-11-22 09:12:44');
        Process::fake([
            'git describe --tags --always' => "v1.4.2\n",
            'git rev-parse HEAD' => "ae06613b0c\n",
            'git rev-parse --abbrev-ref HEAD' => "main\n",
        ]);

        $this->artisan('actuator:build-info')->assertSuccessful();

        $this->assertSame(
            ['version' => 'v1.4.2', 'time' => '2026-11-22T09:12:44Z', 'commit' => 'ae06613b0c', 'branch' => 'main'],
            json_decode((string) file_get_contents($this->path), true),
        );
    }

    #[Test]
    public function the_command_works_without_git(): void
    {
        Date::setTestNow('2026-11-22 09:12:44');
        Process::fake(['*' => Process::result(errorOutput: 'git: command not found', exitCode: 127)]);
        $path = $this->directory.'/custom/build.json';

        $this->artisan('actuator:build-info', ['--path' => $path])->assertSuccessful();

        $this->assertSame(
            ['version' => null, 'time' => '2026-11-22T09:12:44Z', 'commit' => null, 'branch' => null],
            json_decode((string) file_get_contents($path), true),
        );
    }

    #[Test]
    public function the_command_writes_nulls_when_running_git_throws(): void
    {
        Process::fake(['*' => fn () => throw new RuntimeException('proc_open is disabled')]);

        $this->artisan('actuator:build-info', ['--time' => '2026-11-22T09:12:44Z'])->assertSuccessful();

        $this->assertSame(
            ['version' => null, 'time' => '2026-11-22T09:12:44Z', 'commit' => null, 'branch' => null],
            json_decode((string) file_get_contents($this->path), true),
        );
    }

    #[Test]
    public function the_command_fails_when_the_values_cannot_be_encoded(): void
    {
        Process::fake();

        $this->artisan('actuator:build-info', ['--build-version' => "\xB1\x31"])
            ->expectsOutputToContain('Could not encode the build info: Malformed UTF-8')
            ->assertFailed();

        $this->assertFileDoesNotExist($this->path);
    }

    #[Test]
    public function the_command_fails_when_the_file_cannot_be_written(): void
    {
        Process::fake();
        mkdir($this->directory);
        file_put_contents($this->directory.'/file', '');

        $this->artisan('actuator:build-info', ['--path' => $this->directory.'/file/build.json'])
            ->expectsOutputToContain('Could not write')
            ->assertFailed();
    }

    #[Test]
    public function the_command_ignores_a_detached_head_branch(): void
    {
        Process::fake(['git rev-parse --abbrev-ref HEAD' => "HEAD\n"]);

        $this->artisan('actuator:build-info', ['--commit' => 'abc'])->assertSuccessful();

        $this->assertNull(json_decode((string) file_get_contents($this->path), true)['branch']);
    }

    #[Test]
    public function the_build_section_shows_the_file_without_nulls(): void
    {
        $this->writeFile(['version' => 'v1.4.2', 'time' => '2026-11-22T09:12:44Z', 'commit' => 'ae06613b0c', 'branch' => null]);

        $this->getJson('/actuator/info')->assertOk()->assertJsonPath('build', [
            'version' => 'v1.4.2',
            'time' => '2026-11-22T09:12:44Z',
            'commit' => 'ae06613b0c',
        ]);
    }

    #[Test]
    public function the_build_section_is_omitted_without_a_valid_file(): void
    {
        $this->assertSame([], (new BuildInfoContributor)->contribute());
        $this->getJson('/actuator/info')->assertOk()->assertJsonMissingPath('build');

        mkdir($this->directory);
        file_put_contents($this->path, '{not json');

        $this->assertSame([], (new BuildInfoContributor)->contribute());
        $this->assertSame([], (new BuildInfoContributor($this->directory.'/other.json'))->contribute());
    }

    #[Test]
    public function git_falls_back_to_the_build_file_without_a_git_directory(): void
    {
        $this->writeFile(['version' => 'v1.4.2', 'commit' => 'ae06613b0c', 'branch' => 'main']);

        $this->assertSame(
            ['branch' => 'main', 'commit' => ['id' => 'ae06613']],
            (new GitInfoContributor('/does/not/exist'))->contribute(),
        );
    }

    /**
     * @param  array<string, string|null>  $data
     */
    private function writeFile(array $data): void
    {
        mkdir($this->directory);
        file_put_contents($this->path, (string) json_encode($data));
    }
}

<?php

declare(strict_types=1);

namespace Nwidart\Actuator\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Nwidart\Actuator\Info\Contributors\AppInfoContributor;
use Nwidart\Actuator\Info\Contributors\GitInfoContributor;
use Nwidart\Actuator\Info\Contributors\OsInfoContributor;
use Nwidart\Actuator\Info\Contributors\PhpInfoContributor;
use Nwidart\Actuator\Info\Contributors\ProcessInfoContributor;
use Nwidart\Actuator\Tests\Fixtures\FixedInfoContributor;
use Nwidart\Actuator\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class InfoEndpointTest extends TestCase
{
    private string $git;

    protected function setUp(): void
    {
        parent::setUp();

        $this->git = sys_get_temp_dir().'/actuator-git-'.uniqid();
        mkdir($this->git.'/refs/heads', recursive: true);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->git);

        parent::tearDown();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('actuator.exposure.include', ['info']);
    }

    #[Test]
    public function it_lists_contributions_and_omits_empty_ones(): void
    {
        config()->set('actuator.info.contributors', [
            'fixed' => FixedInfoContributor::class,
            'custom' => [FixedInfoContributor::class, ['data' => ['version' => '1.2.3']]],
            'empty' => [FixedInfoContributor::class, ['data' => []]],
        ]);

        $this->getJson('/actuator/info')->assertOk()->assertExactJson([
            'fixed' => ['key' => 'value'],
            'custom' => ['version' => '1.2.3'],
        ]);
    }

    #[Test]
    public function it_returns_an_empty_object_without_contributions(): void
    {
        config()->set('actuator.info.contributors', []);

        $this->assertSame('{}', $this->get('/actuator/info')->getContent());
    }

    #[Test]
    public function it_ships_default_contributors(): void
    {
        $this->getJson('/actuator/info')
            ->assertOk()
            ->assertJsonPath('app.name', config('app.name'))
            ->assertJsonPath('app.environment', 'testing')
            ->assertJsonPath('php.version', PHP_VERSION)
            ->assertJsonPath('os.name', PHP_OS_FAMILY)
            ->assertJsonPath('process.pid', getmypid())
            ->assertJsonStructure(['app' => ['laravel'], 'process' => ['memory' => ['usage', 'peak', 'limit']]]);
    }

    #[Test]
    public function process_reports_an_unlimited_memory_limit_as_minus_one(): void
    {
        $previous = ini_set('memory_limit', '-1');

        try {
            $this->assertSame(-1, (new ProcessInfoContributor)->contribute()['memory']['limit']);
        } finally {
            ini_set('memory_limit', (string) $previous);
        }
    }

    #[Test]
    public function git_reads_the_branch_and_commit(): void
    {
        file_put_contents($this->git.'/HEAD', "ref: refs/heads/main\n");
        file_put_contents($this->git.'/refs/heads/main', "0123456789abcdef0123456789abcdef01234567\n");

        $this->assertSame(
            ['branch' => 'main', 'commit' => ['id' => '0123456']],
            (new GitInfoContributor($this->git))->contribute(),
        );
    }

    #[Test]
    public function git_falls_back_to_packed_refs(): void
    {
        file_put_contents($this->git.'/HEAD', "ref: refs/heads/main\n");
        file_put_contents($this->git.'/packed-refs', "# pack-refs with: peeled\nabcdef0123456789abcdef0123456789abcdef01 refs/heads/main\n");

        $this->assertSame(
            ['branch' => 'main', 'commit' => ['id' => 'abcdef0']],
            (new GitInfoContributor($this->git))->contribute(),
        );
    }

    #[Test]
    public function git_handles_a_detached_head_and_a_missing_repository(): void
    {
        file_put_contents($this->git.'/HEAD', "0123456789abcdef0123456789abcdef01234567\n");

        $this->assertSame(['commit' => ['id' => '0123456']], (new GitInfoContributor($this->git))->contribute());
        $this->assertSame([], (new GitInfoContributor('/does/not/exist'))->contribute());
    }

    #[Test]
    public function contributors_return_their_sections(): void
    {
        $this->assertSame(['name', 'environment', 'laravel', 'timezone', 'locale'], array_keys($this->app->make(AppInfoContributor::class)->contribute()));
        $this->assertSame(['version', 'sapi'], array_keys((new PhpInfoContributor)->contribute()));
        $this->assertSame(['name', 'version', 'arch'], array_keys((new OsInfoContributor)->contribute()));
    }
}

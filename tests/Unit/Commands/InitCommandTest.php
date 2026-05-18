<?php

declare(strict_types=1);

namespace ACQC\Tests\Unit\Commands;

use ACQC\Commands\InitCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Yaml\Yaml;

#[CoversClass(InitCommand::class)]
#[Group('unit')]
#[Group('commands')]
class InitCommandTest extends TestCase
{
    private string $tmpRoot;
    private CommandTester $tester;
    private string $origCwd;

    protected function setUp(): void
    {
        $this->tmpRoot = sys_get_temp_dir() . '/acqc_init_test_' . uniqid();
        mkdir($this->tmpRoot, 0755, true);

        $this->origCwd = getcwd();
        chdir($this->tmpRoot);

        $cmd = new InitCommand();
        $app = new Application();
        $app->add($cmd);
        $this->tester = new CommandTester($cmd);
    }

    protected function tearDown(): void
    {
        chdir($this->origCwd);
        $this->removeDir($this->tmpRoot);
    }

    // ── command name ──────────────────────────────────────────────────────────

    public function testCommandName(): void
    {
        $cmd = new InitCommand();
        $this->assertSame('init', $cmd->getName());
    }

    // ── creates config file ────────────────────────────────────────────────────

    public function testCreatesAcqcProjectYaml(): void
    {
        $exit = $this->tester->execute([]);

        $this->assertSame(0, $exit);
        $this->assertFileExists($this->tmpRoot . '/.acqc/project.yaml');
    }

    public function testConfigFileContainsRequiredKeys(): void
    {
        $this->tester->execute([]);

        $config = Yaml::parseFile($this->tmpRoot . '/.acqc/project.yaml');
        $this->assertArrayHasKey('schema_version', $config);
        $this->assertArrayHasKey('acqc_version',   $config);
        $this->assertArrayHasKey('project',         $config);
        $this->assertArrayHasKey('rule_bundles',    $config);
        $this->assertArrayHasKey('suppressions',    $config);
    }

    public function testConfigFileHasEmptySuppressions(): void
    {
        $this->tester->execute([]);
        $config = Yaml::parseFile($this->tmpRoot . '/.acqc/project.yaml');
        $this->assertSame([], $config['suppressions']);
    }

    public function testConfigFileContainsPhpVersion(): void
    {
        $this->tester->execute([]);
        $config = Yaml::parseFile($this->tmpRoot . '/.acqc/project.yaml');
        $this->assertArrayHasKey('php_version', $config['project']);
        $this->assertNotEmpty($config['project']['php_version']);
    }

    // ── platform detection ─────────────────────────────────────────────────────

    public function testDetectsPlatformFromComposerJson(): void
    {
        file_put_contents($this->tmpRoot . '/composer.json', json_encode([
            'require' => [
                'magento/product-community-edition' => '^2.4.7',
                'php' => '>=8.1',
            ],
        ]));

        $this->tester->execute([]);
        $config = Yaml::parseFile($this->tmpRoot . '/.acqc/project.yaml');

        $this->assertSame('magento2-open-source', $config['project']['platform']);
    }

    public function testFallsBackToUnknownWhenNoComposerJson(): void
    {
        $this->tester->execute([]);
        $config = Yaml::parseFile($this->tmpRoot . '/.acqc/project.yaml');
        $this->assertSame('unknown', $config['project']['platform']);
    }

    public function testPrefersComposerLockExactVersion(): void
    {
        file_put_contents($this->tmpRoot . '/composer.json', json_encode([
            'require' => ['magento/product-enterprise-edition' => '^2.4'],
        ]));

        file_put_contents($this->tmpRoot . '/composer.lock', json_encode([
            'packages' => [[
                'name'    => 'magento/product-enterprise-edition',
                'version' => '2.4.7-p3',
            ]],
        ]));

        $this->tester->execute([]);
        $config = Yaml::parseFile($this->tmpRoot . '/.acqc/project.yaml');
        $this->assertSame('magento2-enterprise', $config['project']['platform']);
        $this->assertSame('2.4.7-p3', $config['project']['platform_version']);
    }

    // ── --force flag ──────────────────────────────────────────────────────────

    public function testForceOverwritesExistingConfig(): void
    {
        mkdir($this->tmpRoot . '/.acqc', 0755, true);
        file_put_contents($this->tmpRoot . '/.acqc/project.yaml', "schema_version: '0'\n");

        $exit = $this->tester->execute(['--force' => true]);

        $this->assertSame(0, $exit);
        $config = Yaml::parseFile($this->tmpRoot . '/.acqc/project.yaml');
        $this->assertSame('1', (string) $config['schema_version']);
    }

    public function testAbortWhenFileExistsWithoutForceAndNonInteractive(): void
    {
        mkdir($this->tmpRoot . '/.acqc', 0755, true);
        file_put_contents($this->tmpRoot . '/.acqc/project.yaml', "schema_version: '0'\n");

        $exit = $this->tester->execute([], ['interactive' => false]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('--force', $this->tester->getDisplay());
    }

    // ── --ci=github ───────────────────────────────────────────────────────────

    public function testCreatesGithubWorkflowFile(): void
    {
        $exit = $this->tester->execute(['--ci' => 'github']);

        $this->assertSame(0, $exit);
        $this->assertFileExists($this->tmpRoot . '/.github/workflows/acqc.yml');
    }

    public function testGithubWorkflowContainsAcqcScan(): void
    {
        $this->tester->execute(['--ci' => 'github']);
        $content = file_get_contents($this->tmpRoot . '/.github/workflows/acqc.yml');
        $this->assertStringContainsString('acqc', $content);
    }

    public function testInvalidCiPlatformReturnsFailure(): void
    {
        $exit = $this->tester->execute(['--ci' => 'jenkins']);
        $this->assertSame(1, $exit);
    }

    // ── output ────────────────────────────────────────────────────────────────

    public function testOutputContainsNextSteps(): void
    {
        $this->tester->execute([]);
        $display = $this->tester->getDisplay();
        $this->assertStringContainsString('Next steps', $display);
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}

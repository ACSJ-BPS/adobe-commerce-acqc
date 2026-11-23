<?php

declare(strict_types=1);

namespace ACQC\Tests\Unit\Commands;

use ACQC\Commands\Phpmd\SetupCommand;
use ACQC\Analyzers\PHP\PHPMDAnalyzer;
use ACQC\Core\Setup\MagentoPhpmdInstaller;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Yaml\Yaml;

#[CoversClass(SetupCommand::class)]
#[CoversClass(MagentoPhpmdInstaller::class)]
#[Group('unit')]
#[Group('commands')]
class PhpmdSetupCommandTest extends TestCase
{
    private string $root;
    private array $config;
    private const RULESET = 'dev/tests/static/testsuite/Magento/Test/Php/_files/phpmd/ruleset.xml';
    private const FRAMEWORK = 'dev/tests/static/framework/Magento/CodeMessDetector';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/acqc_phpmd_setup_' . bin2hex(random_bytes(8));
        mkdir($this->root . '/.acqc', 0755, true);
        mkdir($this->root . '/vendor');
        file_put_contents($this->root . '/vendor/autoload.php', "<?php\n");
        $this->config = [
            'project' => ['name' => 'Example', 'platform_version' => '2.4.8-p5'],
            'phpcs' => ['error_severity' => 7],
            'report' => ['run_phpunit' => false, 'prefer_external_checks' => true],
            'analyzers' => ['php_phpcs' => false],
            'scoring' => ['category_weights' => ['documentation' => 0.5]],
        ];
        $this->saveConfig();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testInstallsPinnedResourcesAndPreservesOtherSettings(): void
    {
        $installer = $this->installer();
        $tester = $this->tester($installer);
        $this->assertSame(0, $tester->execute(['path' => $this->root], ['interactive' => false]));
        $installed = Yaml::parseFile($this->configPath());
        foreach (['phpcs', 'report', 'scoring'] as $key) {
            $this->assertSame($this->config[$key], $installed[$key]);
        }
        $this->assertSame('Example', $installed['project']['name']);
        $this->assertFalse($installed['analyzers']['php_phpcs']);
        $this->assertTrue($installed['analyzers']['php_phpmd']);
        $this->assertSame(5, $installed['phpmd']['minimum_priority']);
        $this->assertSame(['.acqc/phpmd/2.4.8-p5/testsuite/Magento/Test/Php/_files/phpmd/ruleset.xml'], $installed['phpmd']['rulesets']);
        $this->assertFileExists($this->destination() . '/COPYING.txt');
        $this->assertFileExists($this->destination() . '/framework/Magento/CodeMessDetector/resources/rulesets/unusedcode.xml');
        $manifest = json_decode(file_get_contents($this->destination() . '/setup.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(str_repeat('a', 40), $manifest['commit']);
        foreach ($installer->requests as $request) {
            if (str_starts_with($request, 'contents/')) {
                $this->assertStringEndsWith('?ref=' . str_repeat('a', 40), $request);
            }
        }
    }

    public function testOlderReleaseDoesNotRequireNewerUnusedcodeResource(): void
    {
        $this->config['project']['platform_version'] = '2.4.6';
        $this->saveConfig();
        $this->assertSame(0, $this->tester($this->installer(false))->execute(['path' => $this->root], ['interactive' => false]));
        $this->assertFileDoesNotExist($this->root . '/.acqc/phpmd/2.4.6/framework/Magento/CodeMessDetector/resources/rulesets/unusedcode.xml');
        $this->assertStringContainsString('rulesets/unusedcode.xml', file_get_contents($this->root . '/.acqc/phpmd/2.4.6/testsuite/Magento/Test/Php/_files/phpmd/ruleset.xml'));
    }

    public function testPreservesSeverityPolicyThroughSetupCacheAndVersionChange(): void
    {
        $severitySettings = [
            'severity_mapping' => [1 => 'error', 2 => 'warning'],
            'rule_severity_overrides' => ['phpmd.Magento Specific Design Rules.AllPurposeAction' => 'error'],
        ];
        $this->config['phpmd'] = [
            'rulesets' => ['missing-old-ruleset.xml'],
            'bootstrap' => 'missing-old-bootstrap.php',
        ] + $severitySettings;
        $this->saveConfig();
        $tester = $this->tester($this->installer());
        foreach ([[], [], ['--commerce-version' => '2.4.6']] as $options) {
            $this->assertSame(0, $tester->execute(['path' => $this->root] + $options, ['interactive' => false]));
            $installed = Yaml::parseFile($this->configPath());
            foreach ($severitySettings as $key => $value) {
                $this->assertSame($value, $installed['phpmd'][$key]);
            }
        }
    }

    public function testRejectsInvalidSeverityPolicyBeforeDownloading(): void
    {
        $this->config['phpmd'] = ['severity_mapping' => [2 => 'high']];
        $this->saveConfig();
        $snapshot = file_get_contents($this->configPath());
        $installer = $this->installer();
        $tester = $this->tester($installer);
        $this->assertSame(2, $tester->execute(['path' => $this->root], ['interactive' => false]));
        $this->assertStringContainsString('phpmd.severity_mapping.2', $tester->getDisplay());
        $this->assertSame([], $installer->requests);
        $this->assertSame($snapshot, file_get_contents($this->configPath()));
        $this->assertDirectoryDoesNotExist($this->destination());
    }

    public function testVerifiedCacheNeedsNoNetworkAndPreservesUnchangedConfigComments(): void
    {
        $installer = $this->installer();
        $tester = $this->tester($installer);
        $this->assertSame(0, $tester->execute(['path' => $this->root], ['interactive' => false]));
        file_put_contents($this->configPath(), "\n# Keep this comment\n", FILE_APPEND);
        $configBytes = file_get_contents($this->configPath());
        $requests = $installer->requests;
        $this->assertSame(0, $tester->execute(['path' => $this->root], ['interactive' => false]));
        $this->assertSame($requests, $installer->requests);
        $this->assertSame($configBytes, file_get_contents($this->configPath()));
        $this->assertStringContainsString('Verified existing', $tester->getDisplay());
    }

    public function testModifiedCacheFailsUnlessForcedAndForcePreservesBackup(): void
    {
        $tester = $this->tester($this->installer());
        $this->assertSame(0, $tester->execute(['path' => $this->root], ['interactive' => false]));
        file_put_contents($this->destination() . '/LICENSE.txt', 'Developer change');
        $this->assertSame(1, $tester->execute(['path' => $this->root], ['interactive' => false]));
        $this->assertStringContainsString('--force', $tester->getDisplay());
        $this->assertSame(0, $tester->execute(['path' => $this->root, '--force' => true], ['interactive' => false]));
        $backups = glob($this->root . '/.acqc/phpmd/.backup-*');
        $this->assertCount(1, $backups);
        $this->assertSame('Developer change', file_get_contents($backups[0] . '/LICENSE.txt'));
        $this->assertSame('License fixture', file_get_contents($this->destination() . '/LICENSE.txt'));
    }

    public function testDownloadFailureLeavesConfigAndExistingInstallationIntact(): void
    {
        $installer = $this->installer();
        $tester = $this->tester($installer);
        $this->assertSame(0, $tester->execute(['path' => $this->root], ['interactive' => false]));
        $configBytes = file_get_contents($this->configPath());
        $manifest = file_get_contents($this->destination() . '/setup.json');
        $installer->failOn = 'contents/LICENSE.txt';
        $this->assertSame(1, $tester->execute(['path' => $this->root, '--force' => true], ['interactive' => false]));
        $this->assertSame($configBytes, file_get_contents($this->configPath()));
        $this->assertSame($manifest, file_get_contents($this->destination() . '/setup.json'));
        $this->assertSame([], glob($this->root . '/.acqc/phpmd/.setup-*'));
    }

    public function testMalformedDownloadFailsBeforePublication(): void
    {
        $installer = $this->installer();
        $installer->malformed = true;
        $this->assertSame(1, $this->tester($installer)->execute(['path' => $this->root], ['interactive' => false]));
        $this->assertDirectoryDoesNotExist($this->destination());
        $this->assertSame($this->config, Yaml::parseFile($this->configPath()));
        $this->assertSame([], glob($this->root . '/.acqc/phpmd/.setup-*'));
    }

    public function testMissingReleaseAndUnsafeResourcePathsFailExplicitly(): void
    {
        $installer = $this->installer();
        $installer->failOn = 'git/ref/';
        $tester = $this->tester($installer);
        $this->assertSame(1, $tester->execute(['path' => $this->root], ['interactive' => false]));
        $this->assertStringContainsString('Fixture download failure', $tester->getDisplay());
        $installer->failOn = null;
        $installer->unsafe = true;
        $this->assertSame(1, $tester->execute(['path' => $this->root], ['interactive' => false]));
        $this->assertStringContainsString('unsafe', $tester->getDisplay());
        $this->assertDirectoryDoesNotExist($this->destination());
    }

    public function testBootstrapFailureRejectsInstallation(): void
    {
        file_put_contents($this->root . '/vendor/autoload.php', "<?php throw new RuntimeException('Project bootstrap failed');");
        $tester = $this->tester($this->installer());
        $this->assertSame(1, $tester->execute(['path' => $this->root], ['interactive' => false]));
        $this->assertStringContainsString('Project bootstrap failed', $tester->getDisplay());
        $this->assertDirectoryDoesNotExist($this->destination());
        $this->assertSame($this->config, Yaml::parseFile($this->configPath()));
    }

    public function testSetupUsesBundledPhpmdWithoutPathEntry(): void
    {
        $path = getenv('PATH');
        putenv('PATH=' . $this->root);
        try {
            $this->assertFalse((new PHPMDAnalyzer())->isAvailable());
            $this->assertSame(0, $this->tester($this->installer())->execute(['path' => $this->root], ['interactive' => false]));
            $analyzer = new PHPMDAnalyzer(['phpmd' => ['rulesets' => ['unusedcode'], 'minimum_priority' => 5]]);
            $this->assertTrue($analyzer->isAvailable());
            $this->assertStringContainsString('PHPMD', $analyzer->getVersion());
            $file = $this->root . '/empty.php';
            file_put_contents($file, "<?php\n");
            $this->assertSame([], $analyzer->analyze([$file]));
        } finally {
            putenv($path === false ? 'PATH' : 'PATH=' . $path);
        }
    }

    public function testInvalidVersionFailsBeforeDownloadingAndPromptCanSupplyVersion(): void
    {
        $this->config['project']['platform_version'] = '^2.4.8';
        $this->saveConfig();
        $installer = $this->installer();
        $tester = $this->tester($installer);
        $this->assertSame(2, $tester->execute(['path' => $this->root], ['interactive' => false]));
        $this->assertSame([], $installer->requests);
        $tester->setInputs(['2.4.8-p5']);
        $this->assertSame(0, $tester->execute(['path' => $this->root]));
        $this->assertSame('2.4.8-p5', Yaml::parseFile($this->configPath())['project']['platform_version']);
        $this->assertSame(2, $tester->execute(['path' => $this->root, '--commerce-version' => '^2.4.8']));
    }

    public function testNonMappingConfigIsNotOverwritten(): void
    {
        file_put_contents($this->configPath(), "- invalid\n");
        $tester = $this->tester($this->installer());
        $this->assertSame(2, $tester->execute(['path' => $this->root, '--commerce-version' => '2.4.8-p5'], ['interactive' => false]));
        $this->assertSame("- invalid\n", file_get_contents($this->configPath()));
    }

    public function testInvalidYamlAndMissingAutoloadFailWithoutNetwork(): void
    {
        $installer = $this->installer();
        $tester = $this->tester($installer);
        file_put_contents($this->configPath(), "project: [invalid\n");
        $this->assertSame(2, $tester->execute(['path' => $this->root], ['interactive' => false]));
        $this->saveConfig();
        unlink($this->root . '/vendor/autoload.php');
        $this->assertSame(2, $tester->execute(['path' => $this->root], ['interactive' => false]));
        $this->assertSame([], $installer->requests);
        $this->assertSame($this->config, Yaml::parseFile($this->configPath()));
    }

    public function testConcurrentConfigEditIsPreserved(): void
    {
        $installer = $this->installer();
        $installer->configToChange = $this->configPath();
        $tester = $this->tester($installer);
        $this->assertSame(1, $tester->execute(['path' => $this->root], ['interactive' => false]));
        $this->assertStringContainsString('changed during setup', $tester->getDisplay());
        $this->assertTrue(Yaml::parseFile($this->configPath())['concurrent']);
        $this->assertDirectoryDoesNotExist($this->destination());
        $this->assertSame([], glob($this->root . '/.acqc/phpmd/.setup-*'));
    }

    private function configPath(): string
    {
        return $this->root . '/.acqc/project.yaml';
    }

    private function destination(): string
    {
        return $this->root . '/.acqc/phpmd/2.4.8-p5';
    }

    private function saveConfig(): void
    {
        file_put_contents($this->configPath(), Yaml::dump($this->config, 10, 2));
    }

    private function tester(MagentoPhpmdInstaller $installer): CommandTester
    {
        $command = new SetupCommand($installer);
        $application = new Application();
        $application->add($command);
        return new CommandTester($command);
    }

    private function installer(bool $modern = true): MagentoPhpmdInstaller
    {
        $unused = $modern ? 'Magento/CodeMessDetector/resources/rulesets/unusedcode.xml' : 'rulesets/unusedcode.xml';
        $files = [
            self::RULESET => '<ruleset name="Fixture"><description>Fixture</description><php-includepath>../../../../../../framework</php-includepath><rule ref="Magento/CodeMessDetector/resources/rulesets/design.xml"/><rule ref="' . $unused . '"/></ruleset>',
            self::FRAMEWORK . '/resources/rulesets/design.xml' => '<ruleset name="Fixture"><description>Fixture</description><rule ref="rulesets/design.xml/GotoStatement"/></ruleset>',
            'LICENSE.txt' => 'License fixture',
            'COPYING.txt' => 'Copying fixture',
        ];
        if ($modern) {
            $files[self::FRAMEWORK . '/resources/rulesets/unusedcode.xml'] = '<ruleset name="Fixture"><description>Fixture</description><rule ref="rulesets/unusedcode.xml/UnusedLocalVariable"/></ruleset>';
        }
        return new class($files) extends MagentoPhpmdInstaller {
            public array $requests = [];
            public ?string $failOn = null;
            public bool $malformed = false;
            public bool $unsafe = false;
            public ?string $configToChange = null;

            public function __construct(private readonly array $files)
            {
            }

            protected function request(string $path): array
            {
                $this->requests[] = $path;
                if ($this->failOn !== null && str_starts_with($path, $this->failOn)) {
                    throw new \RuntimeException('Fixture download failure');
                }
                if (str_starts_with($path, 'git/ref/tags/')) {
                    return ['object' => ['type' => 'commit', 'sha' => str_repeat('a', 40)]];
                }
                $source = substr(explode('?ref=', $path)[0], strlen('contents/'));
                if (isset($this->files[$source])) {
                    if ($this->configToChange !== null) {
                        file_put_contents($this->configToChange, "\nconcurrent: true\n", FILE_APPEND);
                        $this->configToChange = null;
                    }
                    $content = $this->files[$source];
                    return [
                        'type' => 'file',
                        'encoding' => 'base64',
                        'content' => $this->malformed ? 'invalid base64!' : base64_encode($content),
                        'sha' => sha1('blob ' . strlen($content) . "\0" . $content),
                    ];
                }
                if ($this->unsafe) {
                    return [['name' => '..', 'path' => $source . '/..', 'type' => 'dir']];
                }
                $entries = [];
                foreach ($this->files as $file => $content) {
                    if (str_starts_with($file, $source . '/')) {
                        $remaining = substr($file, strlen($source) + 1);
                        $name = explode('/', $remaining)[0];
                        $entries[$name] = ['name' => $name, 'path' => $source . '/' . $name, 'type' => str_contains($remaining, '/') ? 'dir' : 'file'];
                    }
                }
                return array_values($entries);
            }
        };
    }

    private function removeDirectory(string $directory): void
    {
        foreach (new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS) as $file) {
            if ($file->isDir() && !$file->isLink()) {
                $this->removeDirectory($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($directory);
    }
}

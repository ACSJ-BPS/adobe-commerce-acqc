<?php

declare(strict_types=1);

namespace ACQC\Tests\Unit\Commands\Rules;

use ACQC\Commands\Rules\SuppressCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Yaml\Yaml;

#[CoversClass(SuppressCommand::class)]
#[Group('unit')]
#[Group('commands')]
class SuppressCommandTest extends TestCase
{
    private string $tmpRoot;
    private CommandTester $tester;
    private string $origCwd;

    protected function setUp(): void
    {
        $this->tmpRoot = sys_get_temp_dir() . '/acqc_suppress_test_' . uniqid();
        mkdir($this->tmpRoot, 0755, true);

        $this->origCwd = getcwd();
        chdir($this->tmpRoot);

        $cmd = new SuppressCommand();
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
        $cmd = new SuppressCommand();
        $this->assertSame('rules:suppress', $cmd->getName());
    }

    // ── validation ────────────────────────────────────────────────────────────

    public function testMissingReasonReturnsFailure(): void
    {
        $this->createProjectYaml();
        $exit = $this->tester->execute(['rule-id' => 'GL_PHP_001']);
        $this->assertSame(1, $exit);
    }

    public function testMissingReasonOutputsErrorMessage(): void
    {
        $this->createProjectYaml();
        $this->tester->execute(['rule-id' => 'GL_PHP_001']);
        $this->assertStringContainsString('--reason', $this->tester->getDisplay());
    }

    public function testInvalidReviewByFormatReturnsFailure(): void
    {
        $this->createProjectYaml();
        $exit = $this->tester->execute([
            'rule-id'    => 'GL_PHP_001',
            '--reason'   => 'Test reason',
            '--review-by' => 'not-a-date',
        ]);
        $this->assertSame(1, $exit);
    }

    public function testInvalidReviewByOutputsFormatHint(): void
    {
        $this->createProjectYaml();
        $this->tester->execute([
            'rule-id'    => 'GL_PHP_001',
            '--reason'   => 'Test reason',
            '--review-by' => 'not-a-date',
        ]);
        $this->assertStringContainsString('YYYY-MM-DD', $this->tester->getDisplay());
    }

    public function testMissingProjectYamlReturnsFailure(): void
    {
        $exit = $this->tester->execute([
            'rule-id'  => 'GL_PHP_001',
            '--reason' => 'Test reason',
        ]);
        $this->assertSame(1, $exit);
    }

    // ── successful suppression ─────────────────────────────────────────────────

    public function testSuccessfulSuppressionReturnsSuccess(): void
    {
        $this->createProjectYaml();
        $exit = $this->tester->execute([
            'rule-id'  => 'GL_PHP_001',
            '--reason' => 'Legacy code, scheduled for removal',
        ]);
        $this->assertSame(0, $exit);
    }

    public function testSuppressionWrittenToProjectYaml(): void
    {
        $this->createProjectYaml();
        $this->tester->execute([
            'rule-id'  => 'GL_PHP_001',
            '--reason' => 'Legacy code, scheduled for removal',
        ]);

        $config = Yaml::parseFile($this->tmpRoot . '/.acqc/project.yaml');
        $this->assertNotEmpty($config['suppressions']);
        $this->assertSame('GL_PHP_001', $config['suppressions'][0]['rule_id']);
        $this->assertSame('Legacy code, scheduled for removal', $config['suppressions'][0]['reason']);
    }

    public function testSuppressionOutputContainsRuleId(): void
    {
        $this->createProjectYaml();
        $this->tester->execute([
            'rule-id'  => 'GL_PHP_001',
            '--reason' => 'Test reason',
        ]);
        $this->assertStringContainsString('GL_PHP_001', $this->tester->getDisplay());
    }

    public function testSuppressionWithReviewByWritesDate(): void
    {
        $this->createProjectYaml();
        $this->tester->execute([
            'rule-id'    => 'GL_PHP_001',
            '--reason'   => 'Legacy code',
            '--review-by' => '2026-12-31',
        ]);

        $config = Yaml::parseFile($this->tmpRoot . '/.acqc/project.yaml');
        $this->assertSame('2026-12-31', $config['suppressions'][0]['review_by']);
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    private function createProjectYaml(): void
    {
        mkdir($this->tmpRoot . '/.acqc', 0755, true);
        file_put_contents($this->tmpRoot . '/.acqc/project.yaml', Yaml::dump([
            'schema_version' => '1',
            'acqc_version'   => '2.0.0',
            'project'        => ['platform' => 'unknown', 'php_version' => '8.1'],
            'rule_bundles'   => [],
            'suppressions'   => [],
        ], 4, 2));
    }

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

<?php

declare(strict_types=1);

namespace ACQC\Tests\Unit\Commands\Rules;

use ACQC\Commands\Rules\ValidateCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Yaml\Yaml;

#[CoversClass(ValidateCommand::class)]
#[Group('unit')]
#[Group('commands')]
class ValidateCommandTest extends TestCase
{
    private string $tmpRoot;
    private CommandTester $tester;
    private string $origCwd;

    protected function setUp(): void
    {
        $this->tmpRoot = sys_get_temp_dir() . '/acqc_validate_test_' . uniqid();
        mkdir($this->tmpRoot, 0755, true);

        $this->origCwd = getcwd();
        chdir($this->tmpRoot);

        $cmd = new ValidateCommand();
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
        $cmd = new ValidateCommand();
        $this->assertSame('rules:validate', $cmd->getName());
    }

    // ── directory validation ──────────────────────────────────────────────────

    public function testNonExistentDirectoryReturnsFailure(): void
    {
        $exit = $this->tester->execute(['path' => '/tmp/acqc_does_not_exist_xyz']);
        $this->assertSame(1, $exit);
    }

    public function testNonExistentDirectoryOutputsError(): void
    {
        $this->tester->execute(['path' => '/tmp/acqc_does_not_exist_xyz']);
        $this->assertStringContainsString('Directory not found', $this->tester->getDisplay());
    }

    // ── valid rule YAML ───────────────────────────────────────────────────────

    public function testValidRuleFileReturnsSuccess(): void
    {
        $this->writeRuleFile('valid-rule.yaml', $this->validRuleData());
        $exit = $this->tester->execute(['path' => $this->tmpRoot]);
        $this->assertSame(0, $exit);
    }

    public function testValidRuleFileOutputsCheckmark(): void
    {
        $this->writeRuleFile('valid-rule.yaml', $this->validRuleData());
        $this->tester->execute(['path' => $this->tmpRoot]);
        $this->assertStringContainsString('valid-rule.yaml', $this->tester->getDisplay());
    }

    public function testOutputContainsSummaryLine(): void
    {
        $this->writeRuleFile('valid-rule.yaml', $this->validRuleData());
        $this->tester->execute(['path' => $this->tmpRoot]);
        $this->assertStringContainsString('rule(s) validated', $this->tester->getDisplay());
    }

    public function testSummaryShowsPassedCount(): void
    {
        $this->writeRuleFile('valid-rule.yaml', $this->validRuleData());
        $this->tester->execute(['path' => $this->tmpRoot]);
        $this->assertStringContainsString('1 passed', $this->tester->getDisplay());
    }

    // ── invalid rule YAML ─────────────────────────────────────────────────────

    public function testInvalidRuleFileReturnsFailure(): void
    {
        $this->writeRuleFile('broken.yaml', ['title' => 'Incomplete rule — missing everything else']);
        $exit = $this->tester->execute(['path' => $this->tmpRoot]);
        $this->assertSame(1, $exit);
    }

    public function testInvalidRuleFileOutputsFailedCount(): void
    {
        $this->writeRuleFile('broken.yaml', ['title' => 'Incomplete']);
        $this->tester->execute(['path' => $this->tmpRoot]);
        $this->assertStringContainsString('1 failed', $this->tester->getDisplay());
    }

    public function testInvalidRuleOutputsMissingFieldError(): void
    {
        $this->writeRuleFile('broken.yaml', ['title' => 'Incomplete']);
        $this->tester->execute(['path' => $this->tmpRoot]);
        $this->assertStringContainsString('Missing required field', $this->tester->getDisplay());
    }

    // ── mixed results ─────────────────────────────────────────────────────────

    public function testMixedResultsReturnsFailure(): void
    {
        $this->writeRuleFile('valid.yaml',  $this->validRuleData());
        $this->writeRuleFile('broken.yaml', ['title' => 'Bad rule']);
        $exit = $this->tester->execute(['path' => $this->tmpRoot]);
        $this->assertSame(1, $exit);
    }

    public function testMixedResultsSummaryShowsBothCounts(): void
    {
        $this->writeRuleFile('valid.yaml',  $this->validRuleData());
        $this->writeRuleFile('broken.yaml', ['title' => 'Bad rule']);
        $this->tester->execute(['path' => $this->tmpRoot]);
        $display = $this->tester->getDisplay();
        $this->assertStringContainsString('1 passed', $display);
        $this->assertStringContainsString('1 failed', $display);
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    private function validRuleData(): array
    {
        return [
            'schema_version' => '1.0',
            'id'             => 'GL_PHP_001',
            'name'           => 'gl-php-001-test',
            'title'          => 'Use Throwable not Exception for catch-all blocks',
            'description'    => 'Catches Throwable instead of Exception to handle fatal errors.',
            'category'       => 'error_handling',
            'severity'       => 'warning',
            'tier'           => 3,
            'roles'          => ['developer', 'ci'],
            'platforms'      => ['cli', 'ci'],
            'introduced_in'  => '1.0.0',
            'tags'           => [],
            'references'     => [],
        ];
    }

    private function writeRuleFile(string $filename, array $data): void
    {
        file_put_contents($this->tmpRoot . '/' . $filename, Yaml::dump($data, 4, 2));
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

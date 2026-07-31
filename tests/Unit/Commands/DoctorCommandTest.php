<?php

declare(strict_types=1);

namespace ACQC\Tests\Unit\Commands;

use ACQC\Commands\DoctorCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(DoctorCommand::class)]
#[Group('unit')]
#[Group('commands')]
class DoctorCommandTest extends TestCase
{
    private string $tmpRoot;
    private CommandTester $tester;
    private string $origCwd;

    protected function setUp(): void
    {
        $this->tmpRoot = sys_get_temp_dir() . '/acqc_doctor_test_' . uniqid();
        mkdir($this->tmpRoot, 0755, true);

        $this->origCwd = getcwd();
        chdir($this->tmpRoot);

        $cmd = new DoctorCommand();
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
        $cmd = new DoctorCommand();
        $this->assertSame('doctor', $cmd->getName());
    }

    // ── exit codes ────────────────────────────────────────────────────────────

    public function testMissingConfigReturnsFailure(): void
    {
        $exit = $this->tester->execute([]);
        $this->assertSame(1, $exit);
    }

    public function testJsonModeReturnsFailureWhenChecksFail(): void
    {
        $exit = $this->tester->execute(['--json' => true]);
        $this->assertSame(1, $exit);
    }

    // ── text output ───────────────────────────────────────────────────────────

    public function testOutputContainsScore(): void
    {
        $this->tester->execute([]);
        $this->assertStringContainsString('Score:', $this->tester->getDisplay());
    }

    public function testOutputScoreFormatIsNofEight(): void
    {
        $this->tester->execute([]);
        $this->assertMatchesRegularExpression('/Score:\s+\d\/8/', $this->tester->getDisplay());
    }

    public function testOutputContainsPriorityActions(): void
    {
        $this->tester->execute([]);
        $this->assertStringContainsString('Priority actions', $this->tester->getDisplay());
    }

    // ── JSON mode ────────────────────────────────────────────────────────────

    public function testJsonModeOutputsValidJsonArray(): void
    {
        $this->tester->execute(['--json' => true]);
        $decoded = json_decode($this->tester->getDisplay(), true);
        $this->assertIsArray($decoded);
        $this->assertCount(8, $decoded);
    }

    public function testJsonResultsContainRequiredKeys(): void
    {
        $this->tester->execute(['--json' => true]);
        $results = json_decode($this->tester->getDisplay(), true);

        foreach ($results as $result) {
            $this->assertArrayHasKey('status', $result);
            $this->assertArrayHasKey('label',  $result);
            $this->assertArrayHasKey('detail', $result);
        }
    }

    public function testJsonStatusValuesAreValid(): void
    {
        $this->tester->execute(['--json' => true]);
        $results = json_decode($this->tester->getDisplay(), true);
        $valid   = ['pass', 'warn', 'fail'];

        foreach ($results as $result) {
            $this->assertContains($result['status'], $valid);
        }
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

<?php

declare(strict_types=1);

namespace ACQC\Tests\Unit\Commands\Rules;

use ACQC\Commands\Rules\ListCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(ListCommand::class)]
#[Group('unit')]
#[Group('commands')]
class ListCommandTest extends TestCase
{
    private string $tmpRoot;
    private CommandTester $tester;
    private string $origCwd;

    protected function setUp(): void
    {
        $this->tmpRoot = sys_get_temp_dir() . '/acqc_list_test_' . uniqid();
        mkdir($this->tmpRoot, 0755, true);

        $this->origCwd = getcwd();
        chdir($this->tmpRoot);

        $cmd = new ListCommand();
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
        $cmd = new ListCommand();
        $this->assertSame('rules:list', $cmd->getName());
    }

    // ── format validation ─────────────────────────────────────────────────────

    public function testInvalidFormatReturnsFailure(): void
    {
        $exit = $this->tester->execute(['--format' => 'xml']);
        $this->assertSame(1, $exit);
    }

    public function testInvalidFormatOutputsErrorMessage(): void
    {
        $this->tester->execute(['--format' => 'xml']);
        $this->assertStringContainsString('Invalid format', $this->tester->getDisplay());
    }

    // ── default (table) output ────────────────────────────────────────────────

    public function testDefaultRunSucceeds(): void
    {
        $exit = $this->tester->execute([]);
        $this->assertSame(0, $exit);
    }

    public function testOutputContainsRuleSummaryLine(): void
    {
        $this->tester->execute([]);
        $this->assertStringContainsString('rule(s) total', $this->tester->getDisplay());
    }

    public function testTableOutputContainsHeaders(): void
    {
        $this->tester->execute([]);
        $display = $this->tester->getDisplay();
        $this->assertStringContainsString('ID', $display);
        $this->assertStringContainsString('Severity', $display);
        $this->assertStringContainsString('Tier', $display);
    }

    // ── JSON format ───────────────────────────────────────────────────────────

    public function testJsonFormatSucceeds(): void
    {
        $exit = $this->tester->execute(['--format' => 'json']);
        $this->assertSame(0, $exit);
    }

    public function testJsonFormatOutputsValidJson(): void
    {
        $this->tester->execute(['--format' => 'json']);
        $output  = $this->tester->getDisplay();
        $jsonEnd = strrpos($output, ']');
        $json    = substr($output, 0, $jsonEnd + 1);
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
    }

    public function testJsonRulesContainRequiredKeys(): void
    {
        $this->tester->execute(['--format' => 'json']);
        $output  = $this->tester->getDisplay();
        $jsonEnd = strrpos($output, ']');
        $json    = substr($output, 0, $jsonEnd + 1);
        $decoded = json_decode($json, true);

        $this->assertNotEmpty($decoded);
        $first = $decoded[0];
        $this->assertArrayHasKey('id',       $first);
        $this->assertArrayHasKey('title',    $first);
        $this->assertArrayHasKey('severity', $first);
        $this->assertArrayHasKey('tier',     $first);
        $this->assertArrayHasKey('category', $first);
        $this->assertArrayHasKey('status',   $first);
    }

    // ── CSV format ────────────────────────────────────────────────────────────

    public function testCsvFormatSucceeds(): void
    {
        $exit = $this->tester->execute(['--format' => 'csv']);
        $this->assertSame(0, $exit);
    }

    public function testCsvFormatContainsHeader(): void
    {
        $this->tester->execute(['--format' => 'csv']);
        $this->assertStringContainsString('ID,Title,Severity,Tier,Category,Status', $this->tester->getDisplay());
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

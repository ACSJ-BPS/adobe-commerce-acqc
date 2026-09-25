<?php

declare(strict_types=1);

namespace ACQC\Tests\Unit\Reports\Exporters;

use ACQC\Reports\Exporters\CompactTextExporter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(CompactTextExporter::class)]
#[Group('unit')]
final class CompactTextExporterTest extends TestCase
{
    private const FAIL_ON = ['blocker', 'critical', 'error'];

    public function testPassWithNoIssues(): void
    {
        $out = (new CompactTextExporter())->render([], self::FAIL_ON, 'staged', 3);

        $this->assertSame(
            "ACQC: 3 file(s) checked (staged), fail on: blocker, critical, error\nPASSED: no blocking issues.",
            $out
        );
    }

    public function testNonBlockingIssuesAreCountedNotListed(): void
    {
        $out = (new CompactTextExporter())->render(
            [['severity' => 'warning', 'file' => 'a.php', 'line' => 1, 'message' => 'w']],
            self::FAIL_ON,
            'staged',
            1
        );

        $this->assertStringContainsString('PASSED: no blocking issues (1 non-blocking issue(s) not shown).', $out);
        $this->assertStringNotContainsString('a.php:1', $out);
    }

    public function testBlockedListsIssuesSortedByFileAndLineWithSummary(): void
    {
        $issues = [
            ['severity' => 'error',    'file' => 'b.php', 'line' => 9, 'rule_id' => 'R2', 'message' => "multi\n  line"],
            ['severity' => 'critical', 'file' => 'a.php', 'line' => 2, 'rule'    => 'R1', 'message' => 'crit'],
            ['severity' => 'error',    'file' => 'a.php', 'line' => 2, 'type'    => 'T3', 'message' => 'err'],
            ['severity' => 'warning',  'file' => 'a.php', 'line' => 1, 'message' => 'w'],
        ];

        $lines = explode("\n", (new CompactTextExporter())->render($issues, self::FAIL_ON, 'staged', 2));

        $this->assertSame('  a.php:2  CRITICAL  [R1] crit', $lines[1]);
        $this->assertSame('  a.php:2  ERROR  [T3] err', $lines[2]);
        $this->assertSame('  b.php:9  ERROR  [R2] multi line', $lines[3], 'messages collapsed to one line');
        $this->assertSame('BLOCKED: 1 critical, 2 error (1 non-blocking issue(s) not shown).', $lines[4]);
        $this->assertStringContainsString('git commit --no-verify', $lines[5]);
    }

    public function testTruncatesLongLists(): void
    {
        $issues = array_map(
            static fn (int $n): array => ['severity' => 'error', 'file' => 'f.php', 'line' => $n, 'message' => 'm'],
            range(1, 5)
        );

        $out = (new CompactTextExporter())->render($issues, self::FAIL_ON, 'staged', 1, 2);

        $this->assertStringContainsString('  ... and 3 more', $out);
        $this->assertStringContainsString('BLOCKED: 5 error.', $out);
    }

    public function testMissingLineRendersQuestionMark(): void
    {
        $out = (new CompactTextExporter())->render(
            [['severity' => 'error', 'file' => 'x.xml', 'line' => null, 'message' => 'm']],
            self::FAIL_ON,
            'files',
            1
        );
        $this->assertStringContainsString('  x.xml:?  ERROR  m', $out);
    }

    public function testHelperMessages(): void
    {
        $e = new CompactTextExporter();
        $this->assertSame('ACQC: no auditable files in scope (staged), nothing to check.', $e->renderNothingToCheck('staged'));
        $this->assertSame('ACQC ERROR (exit 2): Not a git repository: /x', $e->renderToolError("Not a git\nrepository: /x", 2));
    }
}

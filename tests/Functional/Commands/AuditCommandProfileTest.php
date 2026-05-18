<?php

declare(strict_types=1);

namespace ACQC\Tests\Functional\Commands;

use ACQC\Commands\AuditCommand;
use ACQC\Core\AuditEngine;
use ACQC\Core\Profiles\ProfileConfig;
use ACQC\Core\Profiles\ProfileResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(AuditCommand::class)]
#[CoversClass(ProfileResolver::class)]
#[CoversClass(ProfileConfig::class)]
#[Group('functional')]
class AuditCommandProfileTest extends TestCase
{
    // ── exit codes ────────────────────────────────────────────────────────────

    public function testCiProfileExitsOneOnErrorViolation(): void
    {
        $tester = $this->makeTester($this->errorViolations());
        $exit   = $tester->execute(['--profile' => 'ci', '--output' => 'json']);

        $this->assertSame(1, $exit, 'ci profile must fail when error-severity violations exist');
    }

    public function testExecutiveProfileExitsZeroRegardlessOfViolations(): void
    {
        $tester = $this->makeTester($this->errorViolations());
        $exit   = $tester->execute(['--profile' => 'executive', '--output' => 'json']);

        $this->assertSame(0, $exit, 'executive profile never fails CI (failOn is empty)');
    }

    public function testDeveloperProfileExitsOneOnErrorViolation(): void
    {
        $tester = $this->makeTester($this->errorViolations());
        $exit   = $tester->execute(['--profile' => 'developer', '--output' => 'json']);

        $this->assertSame(1, $exit, 'developer profile fails on error-severity violations');
    }

    public function testDeveloperProfileExitsZeroWithNoFailingViolations(): void
    {
        $tester = $this->makeTester($this->warningOnlyViolations());
        $exit   = $tester->execute(['--profile' => 'developer', '--output' => 'json']);

        // developer failOn=[error,critical,blocker] — warnings do not trigger failure
        $this->assertSame(0, $exit);
    }

    // ── file path visibility ──────────────────────────────────────────────────

    public function testDeveloperProfileOutputIncludesFilePath(): void
    {
        $tester = $this->makeTester($this->errorViolations());
        $tester->execute(['--profile' => 'developer', '--output' => 'json']);

        $json = $this->parseJsonOutput($tester->getDisplay());
        $this->assertNotEmpty($json['issues']);
        $this->assertSame(
            '/app/code/Vendor/Module/Model/Foo.php',
            $json['issues'][0]['file'],
            'developer profile must not hide file paths'
        );
    }

    public function testExecutiveProfileOutputHidesFilePath(): void
    {
        $tester = $this->makeTester($this->errorViolations());
        $tester->execute(['--profile' => 'executive', '--output' => 'json']);

        $json = $this->parseJsonOutput($tester->getDisplay());
        $this->assertNotEmpty($json['issues']);
        $this->assertSame(
            '** (hidden)',
            $json['issues'][0]['file'],
            'executive profile must replace file paths with "** (hidden)"'
        );
    }

    // ── rule ID visibility ────────────────────────────────────────────────────

    public function testExecutiveProfileHidesRuleId(): void
    {
        $tester = $this->makeTester($this->errorViolations());
        $tester->execute(['--profile' => 'executive', '--output' => 'json']);

        $json = $this->parseJsonOutput($tester->getDisplay());
        $this->assertNull($json['issues'][0]['rule_id'] ?? null);
    }

    // ── profile name in output ────────────────────────────────────────────────

    public function testJsonOutputContainsProfileName(): void
    {
        $tester = $this->makeTester([]);
        $tester->execute(['--profile' => 'developer', '--output' => 'json']);

        $json = $this->parseJsonOutput($tester->getDisplay());
        $this->assertSame('developer', $json['profile']);
    }

    // ── unknown profile ───────────────────────────────────────────────────────

    public function testUnknownProfileReturnsFailure(): void
    {
        $tester = $this->makeTester([]);
        $exit   = $tester->execute(['--profile' => 'does-not-exist', '--output' => 'json'], ['capture_stderr_separately' => false]);

        $this->assertSame(1, $exit);
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    private function makeTester(array $issues): CommandTester
    {
        $mockEngine = $this->createMock(AuditEngine::class);
        $mockEngine->method('runAudit')->willReturn($this->buildResult($issues));

        $cmd = new AuditCommand($mockEngine);
        $app = new Application();
        $app->add($cmd);

        return new CommandTester($cmd);
    }

    private function buildResult(array $issues): array
    {
        $errors   = count(array_filter($issues, fn($i) => ($i['severity'] ?? '') === 'error'));
        $warnings = count(array_filter($issues, fn($i) => ($i['severity'] ?? '') === 'warning'));

        return [
            'score'          => 70,
            'files_analyzed' => 1,
            'total_lines'    => 50,
            'issues_found'   => count($issues),
            'errors'         => $errors,
            'warnings'       => $warnings,
            'issues'         => $issues,
        ];
    }

    private function errorViolations(): array
    {
        return [
            [
                'severity'  => 'error',
                'file'      => '/app/code/Vendor/Module/Model/Foo.php',
                'file_path' => '/app/code/Vendor/Module/Model/Foo.php',
                'line'      => 42,
                'column'    => 1,
                'rule_id'   => 'AC_DI_001',
                'rule'      => 'AC_DI_001',
                'message'   => 'Direct ObjectManager usage detected.',
                'category'  => 'dependency_injection',
            ],
        ];
    }

    private function warningOnlyViolations(): array
    {
        return [
            [
                'severity'  => 'warning',
                'file'      => '/app/code/Vendor/Module/Model/Bar.php',
                'file_path' => '/app/code/Vendor/Module/Model/Bar.php',
                'line'      => 10,
                'column'    => 1,
                'rule_id'   => 'GL_PHP_001',
                'rule'      => 'GL_PHP_001',
                'message'   => 'Catch Throwable instead of Exception.',
                'category'  => 'error_handling',
            ],
        ];
    }

    private function parseJsonOutput(string $display): array
    {
        // Strip any non-JSON preamble (the CommandTester may prepend nothing, but be safe)
        $start = strpos($display, '{');
        $end   = strrpos($display, '}');
        if ($start === false || $end === false) {
            $this->fail('Could not find JSON object in command output: ' . $display);
        }
        $decoded = json_decode(substr($display, $start, $end - $start + 1), true);
        $this->assertIsArray($decoded, 'Command output is not valid JSON');
        return $decoded;
    }
}

<?php

declare(strict_types=1);

namespace ACQC\Tests\Functional\Commands;

use ACQC\Commands\AuditCommand;
use ACQC\Core\AuditEngine;
use ACQC\Tests\Support\GitRepoFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Pre-commit gate behaviour: --staged / --diff-from / --files scoping,
 * --fail-on and the 0/1/2 exit-code contract.
 */
#[CoversClass(AuditCommand::class)]
#[Group('functional')]
#[Group('git')]
final class AuditCommandStagedTest extends TestCase
{
    private GitRepoFixture $repo;
    private string $origCwd;

    private const BAD_PHP = "<?php\nclass Bad { public function x() { return \\Magento\\Framework\\App\\ObjectManager::getInstance(); } }\n";
    private const CLEAN_PHP = "<?php\ndeclare(strict_types=1);\n\nnamespace V\\M\\Model;\n\n/**\n * Clean.\n */\nclass Ok\n{\n}\n";

    protected function setUp(): void
    {
        if (!GitRepoFixture::gitAvailable()) {
            $this->markTestSkipped('git not available');
        }
        $this->repo = new GitRepoFixture();
        $this->repo->write('app/code/V/M/Model/Ok.php', self::CLEAN_PHP);
        $this->repo->commitAll('init');
        $this->origCwd = (string) getcwd();
        chdir($this->repo->root);
    }

    protected function tearDown(): void
    {
        if (isset($this->origCwd)) {
            chdir($this->origCwd);
        }
        if (isset($this->repo)) {
            $this->repo->destroy();
        }
    }

    // ── real engine, real git ─────────────────────────────────────────────────

    public function testNothingStagedPasses(): void
    {
        $tester = $this->realTester();
        $exit   = $tester->execute(['path' => $this->repo->root, '--staged' => true, '--profile' => 'ci', '--format' => 'json']);

        $this->assertSame(AuditCommand::EXIT_PASS, $exit);
        $json = $this->json($tester->getDisplay());
        $this->assertSame('staged', $json['scope']);
        $this->assertSame(0, $json['files_analyzed']);
    }

    public function testStagedCriticalIssueBlocksCommit(): void
    {
        $this->repo->write('app/code/V/M/Model/Bad.php', self::BAD_PHP);
        $this->repo->git('add', 'app/code/V/M/Model/Bad.php');

        $tester = $this->realTester();
        $exit   = $tester->execute(['path' => $this->repo->root, '--staged' => true, '--profile' => 'ci', '--format' => 'json']);

        $this->assertSame(AuditCommand::EXIT_GATE_FAILED, $exit);
        $json = $this->json($tester->getDisplay());
        $this->assertSame('failed', $json['gate']);
        $failingFiles = array_unique(array_column(
            array_filter($json['issues'], fn (array $i): bool => in_array($i['severity'], ['critical', 'error'], true)),
            'file'
        ));
        $this->assertSame(['app/code/V/M/Model/Bad.php'], array_values($failingFiles), 'paths are repo-relative, not snapshot paths');
    }

    public function testUppercaseExtensionIsAudited(): void
    {
        $this->repo->write('app/code/V/M/Model/Bad.PHP', self::BAD_PHP);
        $this->repo->git('add', '-A');

        $tester = $this->realTester();
        $exit   = $tester->execute(['path' => $this->repo->root, '--staged' => true, '--profile' => 'ci', '--format' => 'json']);

        $this->assertSame(AuditCommand::EXIT_GATE_FAILED, $exit, 'QJ-1: Bad.PHP must not bypass the gate');
        $this->assertSame(1, $this->json($tester->getDisplay())['files_analyzed']);
    }

    public function testDiffFromAndFilesReportRepoRelativePaths(): void
    {
        $this->repo->write('app/code/V/M/Model/Bad.php', self::BAD_PHP);
        $this->repo->commitAll('bad');

        foreach ([['--diff-from' => 'HEAD~1'], ['--files' => ['app/code/V/M/Model/Bad.php']]] as $opt) {
            $tester = $this->realTester();
            $exit   = $tester->execute(['path' => $this->repo->root, '--profile' => 'ci', '--format' => 'json'] + $opt);
            $this->assertSame(AuditCommand::EXIT_GATE_FAILED, $exit);
            $files = array_values(array_unique(array_column($this->json($tester->getDisplay())['issues'], 'file')));
            $this->assertSame(['app/code/V/M/Model/Bad.php'], $files, 'QA-2: ' . key($opt) . ' must report repo-relative paths');
        }
    }

    public function testStagedContentIsAuditedNotWorkingTree(): void
    {
        $this->repo->write('app/code/V/M/Model/Bad.php', self::BAD_PHP);
        $this->repo->git('add', 'app/code/V/M/Model/Bad.php');
        // Fixed only in the working tree — the commit would still contain the bad code.
        $this->repo->write('app/code/V/M/Model/Bad.php', self::CLEAN_PHP);

        $exit = $this->realTester()->execute(['path' => $this->repo->root, '--staged' => true, '--profile' => 'ci', '--format' => 'json']);
        $this->assertSame(AuditCommand::EXIT_GATE_FAILED, $exit);
    }

    public function testCleanStagedFilePasses(): void
    {
        $this->repo->write('app/code/V/M/Model/Ok2.php', str_replace('class Ok', 'class Ok2', self::CLEAN_PHP));
        $this->repo->git('add', '-A');

        $exit = $this->realTester()->execute(['path' => $this->repo->root, '--staged' => true, '--profile' => 'ci', '--format' => 'json']);
        $this->assertSame(AuditCommand::EXIT_PASS, $exit);
    }

    public function testUnchangedLegacyIssueOutsideScopeIsIgnored(): void
    {
        // Legacy bad file already committed; developer stages an unrelated clean file.
        $this->repo->write('app/code/V/M/Model/Legacy.php', self::BAD_PHP);
        $this->repo->commitAll('legacy');
        $this->repo->write('app/code/V/M/Model/Ok3.php', str_replace('class Ok', 'class Ok3', self::CLEAN_PHP));
        $this->repo->git('add', '-A');

        $exit = $this->realTester()->execute(['path' => $this->repo->root, '--staged' => true, '--profile' => 'ci', '--format' => 'json']);
        $this->assertSame(AuditCommand::EXIT_PASS, $exit);
    }

    public function testStagedTemplateAndXmlAreChecked(): void
    {
        $this->repo->write('app/code/V/M/view/frontend/templates/x.phtml', "<div><?php echo \$_GET['x']; ?></div>\n");
        $this->repo->write('app/code/V/M/etc/di.xml', "<?xml version=\"1.0\"?>\n<config/>\n");
        $this->repo->git('add', '-A');

        $tester = $this->realTester();
        $exit   = $tester->execute(['path' => $this->repo->root, '--staged' => true, '--profile' => 'ci', '--format' => 'json']);
        $json   = $this->json($tester->getDisplay());

        $this->assertSame(2, $json['files_analyzed'], 'phtml and xml are in scope');
        $this->assertSame(AuditCommand::EXIT_GATE_FAILED, $exit);
        $this->assertContains(
            'app/code/V/M/view/frontend/templates/x.phtml',
            array_column($json['issues'], 'file'),
            'unescaped template output is reported'
        );
    }

    public function testRenamedFileReportedAtNewPath(): void
    {
        $this->repo->write('app/code/V/M/Model/Old.php', self::BAD_PHP);
        $this->repo->commitAll('bad file');
        $this->repo->git('mv', 'app/code/V/M/Model/Old.php', 'app/code/V/M/Model/New.php');

        $tester = $this->realTester();
        $exit   = $tester->execute(['path' => $this->repo->root, '--staged' => true, '--profile' => 'ci', '--format' => 'json']);
        $files  = array_unique(array_column($this->json($tester->getDisplay())['issues'], 'file'));

        $this->assertSame(AuditCommand::EXIT_GATE_FAILED, $exit);
        $this->assertSame(['app/code/V/M/Model/New.php'], array_values($files));
    }

    public function testPathWithSpacesAndLeadingDash(): void
    {
        $this->repo->write('app/code/V/M/sp ace/-Bad.php', self::BAD_PHP);
        $this->repo->git('add', '-A');

        $tester = $this->realTester();
        $exit   = $tester->execute(['path' => $this->repo->root, '--staged' => true, '--profile' => 'ci', '--format' => 'json']);

        $this->assertSame(AuditCommand::EXIT_GATE_FAILED, $exit);
        $this->assertContains('app/code/V/M/sp ace/-Bad.php', array_column($this->json($tester->getDisplay())['issues'], 'file'));
    }

    public function testShallowCloneMissingBaseIsToolError(): void
    {
        $this->repo->write('app/code/V/M/Model/Ok5.php', str_replace('class Ok', 'class Ok5', self::CLEAN_PHP));
        $this->repo->commitAll('second');

        $clone = sys_get_temp_dir() . '/acqc_shallow_' . bin2hex(random_bytes(4));
        exec(sprintf('git clone -q --depth 1 %s %s 2>&1', escapeshellarg('file://' . $this->repo->root), escapeshellarg($clone)), $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
        try {
            $tester = $this->realTester();
            $exit   = $tester->execute(['path' => $clone, '--diff-from' => 'HEAD~1', '--format' => 'json']);

            $this->assertSame(AuditCommand::EXIT_TOOL_ERROR, $exit);
            $this->assertStringContainsString('git diff failed', $tester->getDisplay());
        } finally {
            exec('rm -rf ' . escapeshellarg($clone));
        }
    }

    public function testDiffFromUnknownRefIsToolError(): void
    {
        $exit = $this->realTester()->execute(['path' => $this->repo->root, '--diff-from' => 'no-such-ref', '--format' => 'json']);
        $this->assertSame(AuditCommand::EXIT_TOOL_ERROR, $exit);
    }

    public function testStagedOutsideGitRepoIsToolError(): void
    {
        $dir = sys_get_temp_dir() . '/acqc_nogit_' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            $exit = $this->realTester()->execute(['path' => $dir, '--staged' => true, '--format' => 'json']);
            $this->assertSame(AuditCommand::EXIT_TOOL_ERROR, $exit);
        } finally {
            rmdir($dir);
        }
    }

    // ── pre-commit profile / compact format ──────────────────────────────────

    public function testPreCommitProfileBlocksWithCompactOutput(): void
    {
        $this->repo->write('app/code/V/M/Model/Bad.php', self::BAD_PHP);
        $this->repo->git('add', 'app/code/V/M/Model/Bad.php');

        $tester = $this->realTester();
        $exit   = $tester->execute(['path' => $this->repo->root, '--staged' => true, '--profile' => 'pre-commit']);
        $out    = $tester->getDisplay();

        $this->assertSame(AuditCommand::EXIT_GATE_FAILED, $exit);
        $this->assertStringStartsWith('ACQC: 1 file(s) checked (staged), fail on: blocker, critical, error', $out);
        $this->assertMatchesRegularExpression('#^  app/code/V/M/Model/Bad\.php:\d+  (CRITICAL|ERROR)  #m', $out);
        $this->assertStringContainsString('BLOCKED:', $out);
        $this->assertStringNotContainsString('Adobe Commerce Quality Control Tool', $out, 'no banner in hook output');
    }

    public function testPreCommitProfilePassesCleanFile(): void
    {
        $this->repo->write('app/code/V/M/Model/Ok4.php', str_replace('class Ok', 'class Ok4', self::CLEAN_PHP));
        $this->repo->git('add', '-A');

        $tester = $this->realTester();
        $exit   = $tester->execute(['path' => $this->repo->root, '--staged' => true, '--profile' => 'pre-commit']);

        $this->assertSame(AuditCommand::EXIT_PASS, $exit);
        $this->assertStringContainsString('PASSED', $tester->getDisplay());
    }

    public function testPreCommitProfileNothingStaged(): void
    {
        $tester = $this->realTester();
        $exit   = $tester->execute(['path' => $this->repo->root, '--staged' => true, '--profile' => 'pre-commit']);

        $this->assertSame(AuditCommand::EXIT_PASS, $exit);
        $this->assertStringContainsString('nothing to check', $tester->getDisplay());
    }

    public function testCompactToolErrorOutsideRepo(): void
    {
        $dir = sys_get_temp_dir() . '/acqc_nogit_' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            $tester = $this->realTester();
            $exit   = $tester->execute(['path' => $dir, '--staged' => true, '--profile' => 'pre-commit']);
            $this->assertSame(AuditCommand::EXIT_TOOL_ERROR, $exit);
            $this->assertStringStartsWith('ACQC ERROR (exit 2): ', $tester->getDisplay());
        } finally {
            rmdir($dir);
        }
    }

    // ── mocked engine: option handling ────────────────────────────────────────

    public function testFailOnOverridesProfile(): void
    {
        $issues = [['severity' => 'error', 'file' => 'x.php', 'line' => 1, 'message' => 'm']];

        $this->assertSame(AuditCommand::EXIT_GATE_FAILED, $this->mockTester($issues)->execute(['--profile' => 'ci', '--format' => 'json']));
        $this->assertSame(AuditCommand::EXIT_PASS, $this->mockTester($issues)->execute(['--profile' => 'ci', '--format' => 'json', '--fail-on' => 'critical']));
    }

    public function testInvalidFailOnIsUsageError(): void
    {
        $exit = $this->mockTester([])->execute(['--fail-on' => 'bogus', '--format' => 'json']);
        $this->assertSame(AuditCommand::EXIT_GATE_FAILED, $exit);
    }

    public function testScopeOptionsAreMutuallyExclusive(): void
    {
        $exit = $this->mockTester([])->execute(['--staged' => true, '--diff-from' => 'main', '--format' => 'json']);
        $this->assertSame(AuditCommand::EXIT_GATE_FAILED, $exit);
    }

    public function testEngineExceptionIsToolError(): void
    {
        $engine = $this->createMock(AuditEngine::class);
        $engine->method('runAudit')->willThrowException(new \RuntimeException('boom'));
        $tester = new CommandTester($this->register(new AuditCommand($engine)));

        $this->assertSame(AuditCommand::EXIT_TOOL_ERROR, $tester->execute(['--format' => 'json']));
    }

    public function testFilesOptionPassesExplicitListToEngine(): void
    {
        $engine = $this->createMock(AuditEngine::class);
        $engine->expects($this->once())
            ->method('runAudit')
            ->with(
                $this->repo->root,
                $this->callback(fn (array $o): bool =>
                    $o['files'] === [$this->repo->root . '/a.php', $this->repo->root . '/b.xml']
                    && $o['changed_relative_paths'] === ['a.php', 'b.xml'])
            )
            ->willReturn($this->engineResult([]));

        $tester = new CommandTester($this->register(new AuditCommand($engine)));
        $exit   = $tester->execute(['path' => $this->repo->root, '--files' => ['a.php,b.xml'], '--format' => 'json']);
        $this->assertSame(AuditCommand::EXIT_PASS, $exit);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function realTester(): CommandTester
    {
        return new CommandTester($this->register(new AuditCommand()));
    }

    private function mockTester(array $issues): CommandTester
    {
        $engine = $this->createMock(AuditEngine::class);
        $engine->method('runAudit')->willReturn($this->engineResult($issues));
        return new CommandTester($this->register(new AuditCommand($engine)));
    }

    private function register(AuditCommand $cmd): AuditCommand
    {
        (new Application())->add($cmd);
        return $cmd;
    }

    private function engineResult(array $issues): array
    {
        return [
            'score' => 90, 'files_analyzed' => 1, 'total_lines' => 1,
            'issues_found' => count($issues),
            'errors' => count(array_filter($issues, fn ($i) => $i['severity'] === 'error')),
            'warnings' => count(array_filter($issues, fn ($i) => $i['severity'] === 'warning')),
            'issues' => $issues,
        ];
    }

    private function json(string $display): array
    {
        $start = strpos($display, '{');
        $end   = strrpos($display, '}');
        $this->assertNotFalse($start, 'no JSON in output: ' . $display);
        $decoded = json_decode(substr($display, $start, $end - $start + 1), true);
        $this->assertIsArray($decoded, 'invalid JSON: ' . $display);
        return $decoded;
    }
}

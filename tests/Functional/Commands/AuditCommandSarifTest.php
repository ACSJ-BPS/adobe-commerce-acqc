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

/** `acqc audit --format=sarif` / `--sarif-file` with git scopes. */
#[CoversClass(AuditCommand::class)]
#[Group('functional')]
#[Group('git')]
final class AuditCommandSarifTest extends TestCase
{
    private GitRepoFixture $repo;
    private string $origCwd;

    private const BAD_PHP = "<?php\nclass Bad { public function x() { return \\Magento\\Framework\\App\\ObjectManager::getInstance(); } }\n";

    protected function setUp(): void
    {
        if (!GitRepoFixture::gitAvailable()) {
            $this->markTestSkipped('git not available');
        }
        $this->repo = new GitRepoFixture();
        $this->repo->write('README.md', "x\n");
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

    private function tester(): CommandTester
    {
        $app = new Application();
        $app->add(new AuditCommand(new AuditEngine()));
        return new CommandTester($app->find('audit'));
    }

    private function decode(string $s): array
    {
        $j = json_decode($s, true);
        $this->assertIsArray($j, "stdout is not pure SARIF JSON:\n" . substr($s, 0, 500));
        $this->assertSame('2.1.0', $j['version']);
        return $j;
    }

    public function testStagedSarifIsPureJsonWithRelativePathsAndGateExitCode(): void
    {
        $this->repo->write('app/code/V/M/Model/Bad.php', self::BAD_PHP);
        $this->repo->git('add', '-A');

        $t    = $this->tester();
        $exit = $t->execute(['path' => $this->repo->root, '--staged' => true, '--profile' => 'ci', '--format' => 'sarif']);

        $this->assertSame(AuditCommand::EXIT_GATE_FAILED, $exit);
        $run  = $this->decode($t->getDisplay())['runs'][0];
        $uris = array_unique(array_map(
            static fn (array $r): string => $r['locations'][0]['physicalLocation']['artifactLocation']['uri'],
            $run['results']
        ));
        $this->assertSame(['app/code/V/M/Model/Bad.php'], array_values($uris));
        $this->assertContains('error', array_column($run['results'], 'level'));
        $this->assertSame('staged', $run['properties']['scope']);
        $this->assertSame('failed', $run['properties']['gate']);
        $this->assertStringNotContainsString('acqc-staged-', json_encode($run), 'snapshot temp paths must not leak');
    }

    public function testNothingInScopeEmitsValidEmptySarif(): void
    {
        $t    = $this->tester();
        $exit = $t->execute(['path' => $this->repo->root, '--staged' => true, '--format' => 'sarif']);

        $this->assertSame(AuditCommand::EXIT_PASS, $exit);
        $this->assertSame([], $this->decode($t->getDisplay())['runs'][0]['results']);
    }

    public function testSarifFileWrittenAlongsideCompactOutput(): void
    {
        $this->repo->write('app/code/V/M/Model/Bad.php', self::BAD_PHP);
        $this->repo->git('add', '-A');
        $file = $this->repo->root . '/out/acqc.sarif';

        $t    = $this->tester();
        $exit = $t->execute(['path' => $this->repo->root, '--staged' => true, '--profile' => 'pre-commit', '--sarif-file' => $file]);

        $this->assertSame(AuditCommand::EXIT_GATE_FAILED, $exit);
        $this->assertStringContainsString('BLOCKED', $t->getDisplay());
        $this->assertFileExists($file);
        $this->assertNotEmpty($this->decode((string) file_get_contents($file))['runs'][0]['results']);
    }

    public function testUnwritableSarifFileIsToolError(): void
    {
        // README.md is a file, so README.md/out.sarif cannot be created.
        $t    = $this->tester();
        $exit = $t->execute([
            'path'         => $this->repo->root,
            '--staged'     => true,
            '--format'     => 'json',
            '--sarif-file' => $this->repo->root . '/README.md/out.sarif',
        ]);

        $this->assertSame(AuditCommand::EXIT_TOOL_ERROR, $exit);
        $this->assertStringContainsString('Cannot write SARIF file', $t->getDisplay());
    }

    public function testToolErrorKeepsStdoutEmpty(): void
    {
        $t    = $this->tester();
        $exit = $t->execute(
            ['path' => $this->repo->root, '--diff-from' => 'no-such-ref', '--format' => 'sarif'],
            ['capture_stderr_separately' => true]
        );

        $this->assertSame(AuditCommand::EXIT_TOOL_ERROR, $exit);
        $this->assertSame('', trim($t->getDisplay()));
        $this->assertStringContainsString('ACQC ERROR', $t->getErrorOutput());
    }
}

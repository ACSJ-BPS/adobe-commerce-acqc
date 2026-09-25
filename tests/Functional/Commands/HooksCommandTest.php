<?php

declare(strict_types=1);

namespace ACQC\Tests\Functional\Commands;

use ACQC\Commands\Hooks\InstallCommand;
use ACQC\Commands\Hooks\StatusCommand;
use ACQC\Commands\Hooks\UninstallCommand;
use ACQC\Tests\Support\GitRepoFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * End-to-end: install the hook, then run REAL `git commit` and assert it is
 * blocked / allowed. This is the core acceptance test for the pre-commit gate.
 */
#[CoversClass(InstallCommand::class)]
#[CoversClass(UninstallCommand::class)]
#[CoversClass(StatusCommand::class)]
#[Group('functional')]
#[Group('git')]
final class HooksCommandTest extends TestCase
{
    private const BAD_PHP   = "<?php\nclass Bad { public function x() { return \\Magento\\Framework\\App\\ObjectManager::getInstance(); } }\n";
    private const CLEAN_PHP = "<?php\ndeclare(strict_types=1);\n\nnamespace V\\M\\Model;\n\n/**\n * Clean.\n */\nclass Ok\n{\n}\n";

    private GitRepoFixture $repo;

    protected function setUp(): void
    {
        if (!GitRepoFixture::gitAvailable()) {
            $this->markTestSkipped('git not available');
        }
        $this->repo = new GitRepoFixture();
    }

    protected function tearDown(): void
    {
        if (isset($this->repo)) {
            $this->repo->destroy();
        }
    }

    public function testStatusFailsWhenNotInstalled(): void
    {
        $this->assertSame(Command::FAILURE, $this->tester(new StatusCommand())->execute(['path' => $this->repo->root]));
    }

    public function testInstallStatusUninstallCycle(): void
    {
        $install = $this->tester(new InstallCommand());
        $this->assertSame(Command::SUCCESS, $install->execute(['path' => $this->repo->root]));
        $this->assertStringContainsString('Installed ACQC pre-commit hook', $install->getDisplay());

        $this->assertSame(Command::SUCCESS, $this->tester(new StatusCommand())->execute(['path' => $this->repo->root]));

        $uninstall = $this->tester(new UninstallCommand());
        $this->assertSame(Command::SUCCESS, $uninstall->execute(['path' => $this->repo->root]));
        $this->assertFileDoesNotExist($this->repo->root . '/.git/hooks/pre-commit');
    }

    public function testInstallOutsideGitRepoFails(): void
    {
        $dir = sys_get_temp_dir() . '/acqc_nogit_' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            $this->assertSame(Command::FAILURE, $this->tester(new InstallCommand())->execute(['path' => $dir]));
        } finally {
            rmdir($dir);
        }
    }

    public function testRealCommitIsBlockedOnCriticalOrErrorAndAllowedWhenClean(): void
    {
        $this->assertSame(Command::SUCCESS, $this->tester(new InstallCommand())->execute(['path' => $this->repo->root]));

        // Bad file → commit blocked, nothing recorded.
        $this->repo->write('app/code/V/M/Model/Bad.php', self::BAD_PHP);
        $this->repo->git('add', '-A');
        [$code, $out] = $this->commit('bad');
        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('BLOCKED:', $out);
        $this->assertStringContainsString('app/code/V/M/Model/Bad.php:', $out);
        $this->assertSame(0, $this->commitCount());

        // Fix staged → commit allowed.
        $this->repo->write('app/code/V/M/Model/Bad.php', str_replace('class Ok', 'class Bad', self::CLEAN_PHP));
        $this->repo->git('add', '-A');
        [$code, $out] = $this->commit('clean');
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('PASSED', $out);
        $this->assertSame(1, $this->commitCount());
    }

    public function testSkipAndToolErrorBehaviour(): void
    {
        $this->tester(new InstallCommand())->execute(['path' => $this->repo->root]);
        $this->repo->write('app/code/V/M/Model/Bad.php', self::BAD_PHP);
        $this->repo->git('add', '-A');

        [$code, $out] = $this->commit('skip', ['ACQC_SKIP' => '1']);
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('skipped', $out);

        $this->repo->write('app/code/V/M/Model/Bad2.php', self::BAD_PHP);
        $this->repo->git('add', '-A');

        // ACQC missing → warn and allow by default…
        [$code, $out] = $this->commit('nobin', ['ACQC_BIN' => '/no/such/acqc']);
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('ACQC WARNING', $out);

        // …but block with ACQC_STRICT=1.
        [$code, $out] = $this->commit('strict', ['ACQC_BIN' => '/no/such/acqc', 'ACQC_STRICT' => '1'], true);
        $this->assertSame(1, $code, $out);
    }

    public function testStrictInstallFailsClosedAndCanBeOverridden(): void
    {
        $this->assertSame(Command::SUCCESS, $this->tester(new InstallCommand())->execute(['path' => $this->repo->root, '--strict' => true]));
        $this->repo->write('app/code/V/M/Model/Bad.php', self::BAD_PHP);
        $this->repo->git('add', '-A');

        [$code, $out] = $this->commit('nobin', ['ACQC_BIN' => '/no/such/acqc']);
        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('commit blocked', $out);
        $this->assertSame(0, $this->commitCount());

        [$code, $out] = $this->commit('override', ['ACQC_BIN' => '/no/such/acqc', 'ACQC_STRICT' => '0']);
        $this->assertSame(0, $code, $out);
    }

    public function testFailOnOverrideViaEnvironment(): void
    {
        $this->tester(new InstallCommand())->execute(['path' => $this->repo->root]);
        $this->repo->write('app/code/V/M/Model/Bad.php', self::BAD_PHP);
        $this->repo->git('add', '-A');

        // Only blocker severity fails → ObjectManager error/critical do not block.
        [$code, $out] = $this->commit('relaxed', ['ACQC_FAIL_ON' => 'blocker']);
        $this->assertSame(0, $code, $out);
    }

    public function testChainedHookFailureBlocksCommit(): void
    {
        $hooks = $this->repo->root . '/.git/hooks';
        file_put_contents($hooks . '/pre-commit', "#!/bin/sh\necho custom-lint-failed >&2\nexit 3\n");
        chmod($hooks . '/pre-commit', 0755);
        $this->tester(new InstallCommand())->execute(['path' => $this->repo->root]);

        $this->repo->write('app/code/V/M/Model/Ok.php', self::CLEAN_PHP);
        $this->repo->git('add', '-A');
        [$code, $out] = $this->commit('chained');

        // git reports any non-zero pre-commit status as a generic failure (1).
        $this->assertNotSame(0, $code, $out);
        $this->assertStringContainsString('custom-lint-failed', $out);
        $this->assertStringNotContainsString('ACQC:', $out, 'ACQC must not run after the chained hook failed');
        $this->assertSame(0, $this->commitCount());
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function tester(Command $cmd): CommandTester
    {
        (new Application())->add($cmd);
        return new CommandTester($cmd);
    }

    /**
     * Run a real `git commit` (hooks enabled). Returns [exitCode, combinedOutput].
     *
     * @param array<string,string> $env
     * @return array{0:int,1:string}
     */
    private function commit(string $message, array $env = [], bool $allowEmpty = false): array
    {
        $args = ['git', '-C', $this->repo->root, 'commit', '-q', '-m', $message];
        if ($allowEmpty) {
            $args[] = '--allow-empty';
        }
        $fullEnv = array_merge(getenv(), $env);
        unset($fullEnv['GIT_DIR'], $fullEnv['GIT_INDEX_FILE'], $fullEnv['GIT_WORK_TREE']);

        $proc = proc_open($args, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, null, $fullEnv);
        $out  = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        return [proc_close($proc), $out];
    }

    private function commitCount(): int
    {
        try {
            return (int) trim($this->repo->git('rev-list', '--count', 'HEAD'));
        } catch (\RuntimeException) {
            return 0; // no commits yet
        }
    }
}

<?php

declare(strict_types=1);

namespace ACQC\Tests\Unit\Core\Git;

use ACQC\Core\Git\HookInstaller;
use ACQC\Tests\Support\GitRepoFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(HookInstaller::class)]
#[Group('unit')]
#[Group('git')]
final class HookInstallerTest extends TestCase
{
    private GitRepoFixture $repo;
    private HookInstaller $installer;
    private string $bin;

    protected function setUp(): void
    {
        if (!GitRepoFixture::gitAvailable()) {
            $this->markTestSkipped('git not available');
        }
        $this->repo      = new GitRepoFixture();
        $this->installer = new HookInstaller();
        $this->bin       = (string) realpath(HookInstaller::defaultAcqcBin());
    }

    protected function tearDown(): void
    {
        if (isset($this->repo)) {
            $this->repo->destroy();
        }
    }

    public function testInstallWritesExecutableMarkedHook(): void
    {
        $r = $this->installer->install($this->repo->root, $this->bin, PHP_BINARY);

        $this->assertSame($this->repo->root . '/.git/hooks/pre-commit', $r['hook']);
        $this->assertFalse($r['updated']);
        $this->assertNull($r['chained']);
        $this->assertTrue(is_executable($r['hook']));
        $this->assertTrue($this->installer->isAcqcHook($r['hook']));

        $content = (string) file_get_contents($r['hook']);
        $this->assertStringNotContainsString('__ACQC_', $content, 'all placeholders replaced');
        $this->assertStringContainsString("ACQC_INSTALLED_BIN='{$this->bin}'", $content);
    }

    public function testReinstallUpdatesInPlaceWithoutChainingItself(): void
    {
        $this->installer->install($this->repo->root, $this->bin, PHP_BINARY);
        $r = $this->installer->install($this->repo->root, $this->bin, PHP_BINARY, 'ci');

        $this->assertTrue($r['updated']);
        $this->assertNull($r['chained']);
        $this->assertSame('ci', $this->installer->status($this->repo->root)['profile']);
    }

    public function testExistingForeignHookIsPreservedAndRestored(): void
    {
        $hooks = $this->repo->root . '/.git/hooks';
        file_put_contents($hooks . '/pre-commit', "#!/bin/sh\necho mine\n");

        $r = $this->installer->install($this->repo->root, $this->bin, PHP_BINARY);
        $this->assertSame($hooks . '/pre-commit.local', $r['chained']);
        $this->assertSame("#!/bin/sh\necho mine\n", file_get_contents($hooks . '/pre-commit.local'));

        $u = $this->installer->uninstall($this->repo->root);
        $this->assertTrue($u['removed']);
        $this->assertTrue($u['restored']);
        $this->assertSame("#!/bin/sh\necho mine\n", file_get_contents($hooks . '/pre-commit'));
        $this->assertFileDoesNotExist($hooks . '/pre-commit.local');
    }

    public function testRefusesWhenForeignHookAndLocalSlotTaken(): void
    {
        $hooks = $this->repo->root . '/.git/hooks';
        file_put_contents($hooks . '/pre-commit', "#!/bin/sh\necho a\n");
        file_put_contents($hooks . '/pre-commit.local', "#!/bin/sh\necho b\n");

        try {
            $this->installer->install($this->repo->root, $this->bin, PHP_BINARY);
            $this->fail('expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('--force', $e->getMessage());
        }
        $this->assertSame("#!/bin/sh\necho a\n", file_get_contents($hooks . '/pre-commit'), 'foreign hook untouched');

        $this->installer->install($this->repo->root, $this->bin, PHP_BINARY, 'pre-commit', true);
        $this->assertTrue($this->installer->isAcqcHook($hooks . '/pre-commit'));
        $this->assertSame("#!/bin/sh\necho b\n", file_get_contents($hooks . '/pre-commit.local'));
    }

    public function testUninstallLeavesForeignHookAlone(): void
    {
        $hook = $this->repo->root . '/.git/hooks/pre-commit';
        file_put_contents($hook, "#!/bin/sh\n");

        $this->assertFalse($this->installer->uninstall($this->repo->root)['removed']);
        $this->assertFileExists($hook);
        $this->assertTrue($this->installer->status($this->repo->root)['foreign_hook']);
    }

    public function testRespectsCoreHooksPath(): void
    {
        $this->repo->git('config', 'core.hooksPath', '.githooks');
        $r = $this->installer->install($this->repo->root, $this->bin, PHP_BINARY);

        $this->assertSame($this->repo->root . '/.githooks/pre-commit', $r['hook']);
    }

    public function testStatusReportsRecordedValues(): void
    {
        $this->installer->install($this->repo->root, $this->bin, PHP_BINARY);
        $s = $this->installer->status($this->repo->root);

        $this->assertTrue($s['installed']);
        $this->assertTrue($s['executable']);
        $this->assertSame(HookInstaller::HOOK_VERSION, $s['version']);
        $this->assertSame($this->bin, $s['acqc_bin']);
        $this->assertTrue($s['acqc_bin_exists']);
        $this->assertSame(PHP_BINARY, $s['php']);
        $this->assertSame('pre-commit', $s['profile']);
    }

    public function testPathsWithQuotesAreShellSafe(): void
    {
        $dir = sys_get_temp_dir() . "/acqc it's " . bin2hex(random_bytes(3));
        mkdir($dir);
        $fakeBin = $dir . '/acqc';
        file_put_contents($fakeBin, "<?php\n");
        try {
            $this->installer->install($this->repo->root, $fakeBin, PHP_BINARY);
            $hook = $this->repo->root . '/.git/hooks/pre-commit';
            exec('sh -n ' . escapeshellarg($hook) . ' 2>&1', $out, $code);
            $this->assertSame(0, $code, implode("\n", $out));
            $this->assertSame($fakeBin, $this->installer->status($this->repo->root)['acqc_bin']);
        } finally {
            unlink($fakeBin);
            rmdir($dir);
        }
    }

    public function testRejectsBadInput(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->installer->install($this->repo->root, $this->bin, PHP_BINARY, 'x; rm -rf /');
    }

    public function testRejectsMissingBinary(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->installer->install($this->repo->root, '/no/such/acqc', PHP_BINARY);
    }

    public function testTemplateIsValidPosixShell(): void
    {
        exec('sh -n ' . escapeshellarg(HookInstaller::defaultTemplatePath()) . ' 2>&1', $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
    }
}

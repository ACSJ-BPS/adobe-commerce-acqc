<?php

declare(strict_types=1);

namespace ACQC\Tests\Unit\Core\Git;

use ACQC\Core\Git\StagedSnapshot;
use ACQC\Tests\Support\GitRepoFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(StagedSnapshot::class)]
#[Group('unit')]
#[Group('git')]
final class StagedSnapshotTest extends TestCase
{
    private GitRepoFixture $repo;

    protected function setUp(): void
    {
        if (!GitRepoFixture::gitAvailable()) {
            $this->markTestSkipped('git not available');
        }
        $this->repo = new GitRepoFixture();
        $this->repo->write('README.md', "init\n");
        $this->repo->commitAll('init');
    }

    protected function tearDown(): void
    {
        if (isset($this->repo)) {
            $this->repo->destroy();
        }
    }

    public function testEmptyWhenNothingStaged(): void
    {
        $snap = StagedSnapshot::create($this->repo->root);
        try {
            $this->assertTrue($snap->isEmpty());
            $this->assertSame([], $snap->getFiles());
        } finally {
            $snap->cleanup();
        }
    }

    public function testSnapshotContainsStagedContentNotWorkingTree(): void
    {
        $this->repo->write('app/code/V/M/A.php', "<?php // STAGED\n");
        $this->repo->git('add', 'app/code/V/M/A.php');
        // Unstaged edit after `git add` must NOT be seen by the snapshot.
        $this->repo->write('app/code/V/M/A.php', "<?php // WORKING TREE\n");

        $snap = StagedSnapshot::create($this->repo->root);
        try {
            $files = $snap->getFiles();
            $this->assertCount(1, $files);
            $this->assertSame("<?php // STAGED\n", file_get_contents($files[0]));
            $this->assertSame(['app/code/V/M/A.php'], $snap->getRelativePaths());
            $this->assertSame('app/code/V/M/A.php', $snap->toRelative($files[0]));
            $this->assertStringEndsWith('/app/code/V/M/A.php', $files[0], 'relative layout preserved');
        } finally {
            $snap->cleanup();
        }
    }

    public function testFiltersByExtensionAndIgnoresDeletedFiles(): void
    {
        $this->repo->write('gone.php', "<?php\n");
        $this->repo->commitAll('add gone');

        $this->repo->write('etc/di.xml', "<config/>\n");
        $this->repo->write('notes.txt', "text\n");
        $this->repo->git('add', 'etc/di.xml', 'notes.txt');
        $this->repo->git('rm', '-q', 'gone.php');

        $snap = StagedSnapshot::create($this->repo->root);
        try {
            $this->assertSame(['etc/di.xml'], $snap->getRelativePaths());
        } finally {
            $snap->cleanup();
        }
    }

    public function testWorksFromSubdirectoryAndCleansUp(): void
    {
        $this->repo->write('app/code/V/M/B.php', "<?php\n");
        $this->repo->git('add', '-A');

        $snap = StagedSnapshot::create($this->repo->root . '/app/code');
        $dir  = $snap->getDirectory();
        $this->assertSame($this->repo->root, $snap->getRepoRoot());
        $this->assertDirectoryExists($dir);

        $snap->cleanup();
        $this->assertDirectoryDoesNotExist($dir);
    }

    public function testGlobPatternsSelectAppBuilderFiles(): void
    {
        $this->repo->write('actions/a/index.js', "exports.main = () => ({})\n");
        $this->repo->write('app.config.yaml', "application: {}\n");
        $this->repo->write('notes.txt', "x\n");
        $this->repo->git('add', '-A');

        $snap = StagedSnapshot::create($this->repo->root, ['php', '*.js', 'app.config.yaml']);
        try {
            $rel = $snap->getRelativePaths();
            sort($rel);
            $this->assertSame(['actions/a/index.js', 'app.config.yaml'], $rel);
        } finally {
            $snap->cleanup();
        }
    }

    public function testContextFilesUseStagedVersionAndAreNotReportedAsChanged(): void
    {
        $this->repo->write('package.json', "{\"v\":\"committed\"}\n");
        $this->repo->write('node_modules/x/package.json', "{}\n");
        $this->repo->commitAll('pkg');
        $this->repo->write('package.json', "{\"v\":\"staged\"}\n");
        $this->repo->git('add', 'package.json');
        $this->repo->write('package.json', "{\"v\":\"worktree\"}\n");
        $this->repo->write('actions/a/index.js', "x\n");
        $this->repo->git('add', 'actions/a/index.js');

        $snap = StagedSnapshot::create($this->repo->root, ['*.js']);
        try {
            $copied = $snap->addContextFiles(['package.json']);
            $this->assertSame(['package.json'], $copied, 'node_modules is skipped');
            $this->assertSame("{\"v\":\"staged\"}\n", file_get_contents($snap->getDirectory() . '/package.json'));
            $this->assertSame(['actions/a/index.js'], $snap->getRelativePaths());
        } finally {
            $snap->cleanup();
        }
    }

    public function testCleanupAllRemovesLiveSnapshots(): void
    {
        $this->repo->write('a.php', "<?php\n");
        $this->repo->git('add', 'a.php');
        $dir = StagedSnapshot::create($this->repo->root)->getDirectory();
        $this->assertDirectoryExists($dir);

        StagedSnapshot::cleanupAll(); // what the shutdown/signal handler runs
        $this->assertDirectoryDoesNotExist($dir);
    }

    public function testThrowsOutsideGitRepository(): void
    {
        $dir = sys_get_temp_dir() . '/acqc_not_git_' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            $this->expectException(\RuntimeException::class);
            StagedSnapshot::create($dir);
        } finally {
            rmdir($dir);
        }
    }
}

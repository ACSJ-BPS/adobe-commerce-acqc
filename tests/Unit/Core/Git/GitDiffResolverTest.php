<?php

declare(strict_types=1);

namespace ACQC\Tests\Unit\Core\Git;

use ACQC\Core\Git\GitDiffResolver;
use ACQC\Tests\Support\GitRepoFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(GitDiffResolver::class)]
#[Group('unit')]
#[Group('git')]
final class GitDiffResolverTest extends TestCase
{
    private GitRepoFixture $repo;

    protected function setUp(): void
    {
        if (!GitRepoFixture::gitAvailable()) {
            $this->markTestSkipped('git not available');
        }
        $this->repo = new GitRepoFixture();
        $this->repo->write('a.php', "<?php\n");
        $this->repo->commitAll('init');
    }

    protected function tearDown(): void
    {
        if (isset($this->repo)) {
            $this->repo->destroy();
        }
    }

    public function testChangedFilesIncludeNonPhpByDefault(): void
    {
        $this->repo->write('a.php', "<?php // changed\n");
        $this->repo->write('etc/di.xml', "<config/>\n");
        $this->repo->write('schema.graphqls', "type Q {}\n");
        $this->repo->write('readme.md', "x\n");
        $this->repo->git('add', '-A');

        $files = (new GitDiffResolver())->getChangedFiles($this->repo->root, 'staged');
        $rel   = array_map(fn (string $f): string => substr($f, strlen($this->repo->root) + 1), $files);
        sort($rel);

        $this->assertSame(['a.php', 'etc/di.xml', 'schema.graphqls'], $rel);
    }

    public function testDiffAgainstRef(): void
    {
        $base = trim($this->repo->git('rev-parse', 'HEAD'));
        $this->repo->write('b.php', "<?php\n");
        $this->repo->commitAll('second');

        $files = (new GitDiffResolver())->getChangedFiles($this->repo->root, $base . '..HEAD');
        $this->assertSame([$this->repo->root . '/b.php'], $files);
    }

    public function testUnknownRefThrowsInsteadOfFallingBack(): void
    {
        $this->expectException(\RuntimeException::class);
        (new GitDiffResolver())->getChangedFiles($this->repo->root, 'no-such-ref');
    }

    public function testRepositoryRootFromSubdirectory(): void
    {
        mkdir($this->repo->root . '/sub/dir', 0755, true);
        $this->assertSame($this->repo->root, (new GitDiffResolver())->getRepositoryRoot($this->repo->root . '/sub/dir'));
    }

    public function testHasExtension(): void
    {
        $this->assertTrue(GitDiffResolver::hasExtension('X/Y.PHP', ['php']));
        $this->assertFalse(GitDiffResolver::hasExtension('X/Y.js', ['php']));
        $this->assertTrue(GitDiffResolver::hasExtension('anything', []));
    }

    public function testHasExtensionGlobsAndExactNames(): void
    {
        $patterns = ['php', '*.js', '*.tsx', 'package.json', 'app.config.yaml'];
        $this->assertTrue(GitDiffResolver::hasExtension('actions/a/index.js', $patterns));
        $this->assertTrue(GitDiffResolver::hasExtension('web-src/App.TSX', $patterns));
        $this->assertTrue(GitDiffResolver::hasExtension('sub/package.json', $patterns));
        $this->assertTrue(GitDiffResolver::hasExtension('app.config.yaml', $patterns));
        $this->assertFalse(GitDiffResolver::hasExtension('ext.config.yaml', $patterns));
        $this->assertFalse(GitDiffResolver::hasExtension('package-lock.json', $patterns));
        $this->assertFalse(GitDiffResolver::hasExtension('README.md', $patterns));
    }

    public function testChangedFilesWithAppBuilderPatterns(): void
    {
        $this->repo->write('actions/a/index.js', "x\n");
        $this->repo->write('docs/x.md', "x\n");
        $this->repo->git('add', '-A');

        $files = (new GitDiffResolver())->getChangedFiles($this->repo->root, 'staged', ['php', '*.js']);
        $this->assertSame([$this->repo->root . '/actions/a/index.js'], $files);
    }
}

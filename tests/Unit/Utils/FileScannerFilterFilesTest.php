<?php

declare(strict_types=1);

namespace ACQC\Tests\Unit\Utils;

use ACQC\Utils\FileScanner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(FileScanner::class)]
#[Group('unit')]
final class FileScannerFilterFilesTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/acqc_fs_' . bin2hex(random_bytes(4));
        foreach ([
            'app/code/V/M/Model/A.php',
            'app/code/V/M/etc/di.xml',
            'app/code/V/M/view/x.js',
            'vendor/foo/bar/B.php',
            'app/code/V/M/lib/C.php',
            'custom/skip/D.php',
        ] as $rel) {
            @mkdir(dirname($this->root . '/' . $rel), 0755, true);
            file_put_contents($this->root . '/' . $rel, "<?php\n");
        }
        $this->root = realpath($this->root);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testFiltersByIncludePatternsAndDefaultExcludes(): void
    {
        $files = (new FileScanner())->filterFiles(
            [
                'app/code/V/M/Model/A.php',
                'app/code/V/M/etc/di.xml',
                'app/code/V/M/view/x.js',
                'vendor/foo/bar/B.php',   // default exclude "vendor"
                'app/code/V/M/lib/C.php', // default exclude "lib" (any depth)
                'missing.php',            // does not exist
            ],
            $this->root,
            ['*.php', '*.xml'],
            [],
            false
        );

        $this->assertSame([
            $this->root . '/app/code/V/M/Model/A.php',
            $this->root . '/app/code/V/M/etc/di.xml',
        ], $files);
    }

    public function testCallerExcludeWithSlashIsPrefixMatch(): void
    {
        $files = (new FileScanner())->filterFiles(
            [$this->root . '/custom/skip/D.php', 'app/code/V/M/Model/A.php'],
            $this->root,
            ['*.php'],
            ['custom/skip'],
            false
        );

        $this->assertSame([$this->root . '/app/code/V/M/Model/A.php'], $files);
    }

    public function testEmptyInputGivesEmptyOutput(): void
    {
        $this->assertSame([], (new FileScanner())->filterFiles([], $this->root));
    }
}

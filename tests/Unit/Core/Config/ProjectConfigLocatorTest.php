<?php

declare(strict_types=1);

namespace ACQC\Tests\Unit\Core\Config;

use ACQC\Core\Config\ProjectConfigLocator;
use PHPUnit\Framework\TestCase;

final class ProjectConfigLocatorTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/acqc_locator_test_' . uniqid('', true);
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testFindsProjectYamlInRepoRootFromAppCodeSubfolder(): void
    {
        $repoRoot = $this->tempDir . '/shell-local';
        $subDir = $repoRoot . '/app/code/Shell/Catalog';
        mkdir($repoRoot . '/.git', 0777, true);
        mkdir($repoRoot . '/.acqc', 0777, true);
        mkdir($subDir, 0777, true);

        $yamlPath = $repoRoot . '/.acqc/project.yaml';
        file_put_contents($yamlPath, "project:\n  name: Shell Commerce\nscoring:\n  bands:\n    healthy: 90\n");

        $locator = new ProjectConfigLocator();
        $found = $locator->locate($subDir);

        $this->assertNotNull($found);
        $this->assertSame(realpath($yamlPath), realpath($found));

        $loaded = $locator->loadNearest($subDir);
        $this->assertSame('Shell Commerce', $loaded['config']['project']['name'] ?? null);
        $this->assertSame(90, $loaded['config']['scoring']['bands']['healthy'] ?? null);
    }

    public function testStopsWalkingAtGitRootWhenNoProjectYamlExists(): void
    {
        $parentWithConfig = $this->tempDir . '/outer';
        $innerRepo = $parentWithConfig . '/inner-repo';
        $targetDir = $innerRepo . '/app/code';

        mkdir($parentWithConfig . '/.acqc', 0777, true);
        file_put_contents($parentWithConfig . '/.acqc/project.yaml', "project:\n  name: Outer\n");

        mkdir($innerRepo . '/.git', 0777, true);
        mkdir($targetDir, 0777, true);

        $locator = new ProjectConfigLocator();
        $this->assertNull($locator->locate($targetDir));
    }

    public function testWithoutGitOnlyTheTargetFolderIsChecked(): void
    {
        // No .git anywhere: a project.yaml in a parent folder must NOT apply
        $outer = $this->tempDir . '/no-git-outer';
        $target = $outer . '/app/code';
        mkdir($outer . '/.acqc', 0777, true);
        mkdir($target, 0777, true);
        file_put_contents($outer . '/.acqc/project.yaml', "project:\n  name: Outer\n");

        $locator = new ProjectConfigLocator();
        $this->assertNull($locator->locate($target));

        // ...but a project.yaml in the target folder itself still applies
        mkdir($target . '/.acqc', 0777, true);
        file_put_contents($target . '/.acqc/project.yaml', "project:\n  name: Inner\n");
        $found = $locator->locate($target);
        $this->assertNotNull($found);
        $this->assertSame(realpath($target . '/.acqc/project.yaml'), realpath($found));
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}

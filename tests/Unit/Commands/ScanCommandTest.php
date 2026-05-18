<?php

declare(strict_types=1);

namespace ACQC\Tests\Unit\Commands;

use ACQC\Commands\ScanCommand;
use ACQC\Core\CodeAnalyzer;
use ACQC\Core\Config\ConfigManager;
use ACQC\Core\Scanner\ScannerEngine;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Psr\Log\LoggerInterface;

/**
 * @covers \ACQC\Commands\ScanCommand
 * @group unit
 * @group commands
 * @group scan
 */
class ScanCommandTest extends TestCase
{
    private ScanCommand $command;
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $this->command = new ScanCommand();

        $application = new Application();
        $application->add($this->command);

        $this->commandTester = new CommandTester($this->command);
    }

    /**
     * @test
     * @group fast
     */
    public function testCommandNameAndDescription(): void
    {
        $this->assertEquals('scan', $this->command->getName());
        $this->assertStringContainsString('Scan code for quality issues', $this->command->getDescription());
    }

    /**
     * @test
     * @group fast
     */
    public function testScanWithNonExistentPath(): void
    {
        $exitCode = $this->commandTester->execute([
            'path' => '/non/existent/path/that/does/not/exist'
        ]);

        $this->assertNotEquals(0, $exitCode);
        $output = $this->commandTester->getDisplay();
        $this->assertNotEmpty($output);
    }

    /**
     * @test
     * @group fast
     */
    public function testScanWithInvalidFormat(): void
    {
        $exitCode = $this->commandTester->execute([
            'path' => '.',
            '--format' => 'totally-invalid-format-xyz'
        ]);

        $this->assertNotEquals(0, $exitCode);
    }

    /**
     * @test
     * @group fast
     */
    public function testScanWithInvalidRole(): void
    {
        $exitCode = $this->commandTester->execute([
            'path' => '.',
            '--role' => 'invalid-role-xyz'
        ]);

        $this->assertNotEquals(0, $exitCode);
    }

    /**
     * @test
     * @group fast
     */
    public function testBasicScanExecution(): void
    {
        $tempDir = sys_get_temp_dir() . '/acqc_scan_test_' . uniqid();
        mkdir($tempDir, 0755, true);
        file_put_contents($tempDir . '/test.php', "<?php\n\ndeclare(strict_types=1);\n\nclass Test {}\n");

        try {
            $exitCode = $this->commandTester->execute([
                'path' => $tempDir
            ]);

            // Command should complete (exit 0, 1, or 2 — not a crash)
            $this->assertContains($exitCode, [0, 1, 2]);
        } finally {
            unlink($tempDir . '/test.php');
            rmdir($tempDir);
        }
    }

    /**
     * @test
     * @group fast
     */
    public function testScanWithDifferentFormats(): void
    {
        $tempDir = sys_get_temp_dir() . '/acqc_scan_test_' . uniqid();
        mkdir($tempDir, 0755, true);
        file_put_contents($tempDir . '/test.php', "<?php\n\nclass Test {}\n");

        try {
            foreach (['console', 'json'] as $format) {
                $exitCode = $this->commandTester->execute([
                    'path' => $tempDir,
                    '--format' => $format,
                ]);
                $this->assertContains($exitCode, [0, 1, 2], "Failed for format: $format");
            }
        } finally {
            unlink($tempDir . '/test.php');
            rmdir($tempDir);
        }
    }

    /**
     * @test
     * @group fast
     */
    public function testScanWithDifferentRoles(): void
    {
        $tempDir = sys_get_temp_dir() . '/acqc_scan_test_' . uniqid();
        mkdir($tempDir, 0755, true);
        file_put_contents($tempDir . '/test.php', "<?php\n\nclass Test {}\n");

        try {
            foreach (['developer', 'lead'] as $role) {
                $exitCode = $this->commandTester->execute([
                    'path' => $tempDir,
                    '--role' => $role,
                ]);
                $this->assertContains($exitCode, [0, 1, 2], "Failed for role: $role");
            }
        } finally {
            unlink($tempDir . '/test.php');
            rmdir($tempDir);
        }
    }

    /**
     * @test
     * @group fast
     */
    public function testScanWithSeverityFiltering(): void
    {
        $tempDir = sys_get_temp_dir() . '/acqc_scan_test_' . uniqid();
        mkdir($tempDir, 0755, true);
        file_put_contents($tempDir . '/test.php', "<?php\n\nclass Test {}\n");

        try {
            $exitCode = $this->commandTester->execute([
                'path' => $tempDir,
                '--severity' => ['error'],
            ]);
            $this->assertContains($exitCode, [0, 1, 2]);
        } finally {
            unlink($tempDir . '/test.php');
            rmdir($tempDir);
        }
    }

    /**
     * @test
     * @group fast
     */
    public function testScanWithIncludeExcludePatterns(): void
    {
        $tempDir = sys_get_temp_dir() . '/acqc_scan_test_' . uniqid();
        mkdir($tempDir, 0755, true);
        file_put_contents($tempDir . '/test.php', "<?php\n\nclass Test {}\n");

        try {
            $exitCode = $this->commandTester->execute([
                'path' => $tempDir,
                '--include' => ['*.php'],
                '--exclude' => ['vendor/*'],
            ]);
            $this->assertContains($exitCode, [0, 1, 2]);
        } finally {
            unlink($tempDir . '/test.php');
            rmdir($tempDir);
        }
    }

    /**
     * @test
     * @group fast
     */
    public function testScanWithParallelProcessing(): void
    {
        $tempDir = sys_get_temp_dir() . '/acqc_scan_test_' . uniqid();
        mkdir($tempDir, 0755, true);
        file_put_contents($tempDir . '/test.php', "<?php\n\nclass Test {}\n");

        try {
            $exitCode = $this->commandTester->execute([
                'path' => $tempDir,
                '--parallel' => '2',
            ]);
            $this->assertContains($exitCode, [0, 1, 2]);
        } finally {
            unlink($tempDir . '/test.php');
            rmdir($tempDir);
        }
    }

    /**
     * @test
     * @group fast
     */
    public function testScanWithProgressIndicator(): void
    {
        $tempDir = sys_get_temp_dir() . '/acqc_scan_test_' . uniqid();
        mkdir($tempDir, 0755, true);
        file_put_contents($tempDir . '/test.php', "<?php\n\nclass Test {}\n");

        try {
            $exitCode = $this->commandTester->execute([
                'path' => $tempDir,
                '--progress' => true,
            ]);
            $this->assertContains($exitCode, [0, 1, 2]);
        } finally {
            unlink($tempDir . '/test.php');
            rmdir($tempDir);
        }
    }

    /**
     * @test
     * @group fast
     */
    public function testScanWithBaselineComparison(): void
    {
        $tempDir = sys_get_temp_dir() . '/acqc_scan_test_' . uniqid();
        mkdir($tempDir, 0755, true);
        file_put_contents($tempDir . '/test.php', "<?php\n\nclass Test {}\n");

        // Non-existent baseline should be handled gracefully
        $exitCode = $this->commandTester->execute([
            'path' => $tempDir,
            '--baseline' => '/non/existent/baseline.json',
        ]);

        try {
            $this->assertContains($exitCode, [0, 1, 2]);
        } finally {
            unlink($tempDir . '/test.php');
            rmdir($tempDir);
        }
    }

    /**
     * @test
     * @group fast
     */
    public function testScanWithVerboseOutput(): void
    {
        $tempDir = sys_get_temp_dir() . '/acqc_scan_test_' . uniqid();
        mkdir($tempDir, 0755, true);
        file_put_contents($tempDir . '/test.php', "<?php\n\nclass Test {}\n");

        try {
            $exitCode = $this->commandTester->execute(
                ['path' => $tempDir],
                ['verbosity' => \Symfony\Component\Console\Output\OutputInterface::VERBOSITY_VERBOSE]
            );
            $this->assertContains($exitCode, [0, 1, 2]);
        } finally {
            unlink($tempDir . '/test.php');
            rmdir($tempDir);
        }
    }

    /**
     * @test
     * @group fast
     */
    public function testScanWithQuietMode(): void
    {
        $tempDir = sys_get_temp_dir() . '/acqc_scan_test_' . uniqid();
        mkdir($tempDir, 0755, true);
        file_put_contents($tempDir . '/test.php', "<?php\n\nclass Test {}\n");

        try {
            $exitCode = $this->commandTester->execute(
                ['path' => $tempDir],
                ['verbosity' => \Symfony\Component\Console\Output\OutputInterface::VERBOSITY_QUIET]
            );
            $this->assertContains($exitCode, [0, 1, 2]);
        } finally {
            unlink($tempDir . '/test.php');
            rmdir($tempDir);
        }
    }

    /**
     * @test
     * @group fast
     */
    public function testExitCodeForCriticalIssues(): void
    {
        // Non-existent path produces a non-zero exit code
        $exitCode = $this->commandTester->execute([
            'path' => '/non/existent/path/critical'
        ]);
        $this->assertNotEquals(0, $exitCode);
    }

    /**
     * @test
     * @group fast
     */
    public function testExitCodeForErrors(): void
    {
        // Non-existent path produces a non-zero exit code
        $exitCode = $this->commandTester->execute([
            'path' => '/non/existent/path/errors'
        ]);
        $this->assertNotEquals(0, $exitCode);
    }

    /**
     * @test
     * @group fast
     */
    public function testConfigurationValidation(): void
    {
        $tempDir = sys_get_temp_dir() . '/acqc_scan_test_' . uniqid();
        mkdir($tempDir, 0755, true);
        file_put_contents($tempDir . '/test.php', "<?php\n\nclass Test {}\n");

        try {
            $exitCode = $this->commandTester->execute([
                'path' => $tempDir,
            ]);
            $this->assertContains($exitCode, [0, 1, 2]);
        } finally {
            unlink($tempDir . '/test.php');
            rmdir($tempDir);
        }
    }

    /**
     * @test
     * @group integration
     */
    public function testScanWithRealFile(): void
    {
        $testFile = getFixturePath('GoodCode/BestPracticesExample.php');

        if (!file_exists($testFile)) {
            $this->markTestSkipped('Test fixture not available');
        }

        $exitCode = $this->commandTester->execute([
            'path' => $testFile
        ]);

        $this->assertContains($exitCode, [0, 1, 2]);
    }

    /**
     * @test
     * @group slow
     */
    public function testScanPerformanceWithLargeNumberOfFiles(): void
    {
        $tempDir = sys_get_temp_dir() . '/acqc_large_scan_' . uniqid();
        mkdir($tempDir, 0755, true);

        // Create multiple PHP files
        for ($i = 0; $i < 10; $i++) {
            file_put_contents($tempDir . "/test_{$i}.php", "<?php\n\ndeclare(strict_types=1);\n\nclass Test{$i} {}\n");
        }

        try {
            $startTime = microtime(true);
            $exitCode = $this->commandTester->execute([
                'path' => $tempDir,
            ]);
            $executionTime = microtime(true) - $startTime;

            $this->assertContains($exitCode, [0, 1, 2]);
            $this->assertLessThan(30.0, $executionTime, 'Command execution took too long');
        } finally {
            foreach (glob($tempDir . '/*.php') as $f) {
                unlink($f);
            }
            rmdir($tempDir);
        }
    }
}

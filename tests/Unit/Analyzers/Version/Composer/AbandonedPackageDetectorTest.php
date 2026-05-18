<?php

declare(strict_types=1);

namespace ACQC\Tests\Unit\Analyzers\Version\Composer;

use ACQC\Analyzers\Version\Composer\AbandonedPackageDetector;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ACQC\Analyzers\Version\Composer\AbandonedPackageDetector
 * @group unit
 * @group fast
 */
class AbandonedPackageDetectorTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/acqc_abandoned_test_' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->cacheDir)) {
            $files = glob($this->cacheDir . '/*') ?: [];
            foreach ($files as $f) {
                unlink($f);
            }
            rmdir($this->cacheDir);
        }
    }

    /** @test */
    public function testFlagsAbandonedPackage(): void
    {
        $detector = $this->detectorWithResponse([
            'package' => [
                'name'      => 'vendor/old-abandoned-module',
                'abandoned' => true,
            ],
        ]);

        $packages = [
            'vendor/old-abandoned-module' => ['version' => '2.0.0', 'direct' => true, 'requires' => []],
        ];

        $findings = $detector->detectAbandoned($packages);

        $this->assertCount(1, $findings);
        $this->assertSame('composer_package_abandoned', $findings[0]['type']);
        $this->assertSame('warning', $findings[0]['severity']);
        $this->assertStringContainsString('vendor/old-abandoned-module', $findings[0]['message']);
        $this->assertStringContainsString('No official replacement', $findings[0]['message']);
        $this->assertSame(2, $findings[0]['effort_hours_min']);
        $this->assertSame(16, $findings[0]['effort_hours_max']);
    }

    /** @test */
    public function testFlagsAbandonedPackageWithReplacement(): void
    {
        $detector = $this->detectorWithResponse([
            'package' => [
                'name'      => 'vendor/old-module',
                'abandoned' => 'vendor/new-module',
            ],
        ]);

        $packages = ['vendor/old-module' => ['version' => '1.0.0', 'direct' => false, 'requires' => []]];
        $findings = $detector->detectAbandoned($packages);

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('Replacement: vendor/new-module', $findings[0]['message']);
    }

    /** @test */
    public function testUsesLocalCacheOnSecondCall(): void
    {
        $fetchCount = 0;
        $detector   = $this->detectorWithCallback(
            function () use (&$fetchCount): ?array {
                $fetchCount++;
                return ['package' => ['name' => 'acme/pkg', 'abandoned' => false]];
            }
        );

        $packages = ['acme/pkg' => ['version' => '1.0.0', 'direct' => true, 'requires' => []]];

        $detector->detectAbandoned($packages);
        $detector->detectAbandoned($packages);

        $this->assertSame(1, $fetchCount, 'HTTP should be called only once; second call should use cache');
    }

    /** @test */
    public function testSkipsOnHttpFailure(): void
    {
        $detector = $this->detectorWithCallback(static fn() => null);

        $packages = [
            'vendor/unreachable' => ['version' => '1.0.0', 'direct' => true, 'requires' => []],
        ];

        $findings = $detector->detectAbandoned($packages);

        $this->assertEmpty($findings, 'Failed HTTP calls must produce no findings');
    }

    /** @test */
    public function testSkipsPackagesWithoutVendorSlash(): void
    {
        $detector = $this->detectorWithCallback(static fn() => ['package' => ['abandoned' => true]]);

        $packages = ['php' => ['version' => '8.1.0', 'direct' => false, 'requires' => []]];

        $findings = $detector->detectAbandoned($packages);

        $this->assertEmpty($findings);
    }

    /** @test */
    public function testCreatesCacheDirIfMissing(): void
    {
        $this->assertDirectoryDoesNotExist($this->cacheDir);

        $detector = $this->detectorWithResponse(['package' => ['name' => 'a/b', 'abandoned' => false]]);
        $detector->detectAbandoned(['a/b' => ['version' => '1.0.0', 'direct' => true, 'requires' => []]]);

        $this->assertDirectoryExists($this->cacheDir);
    }

    private function detectorWithResponse(array $response): AbandonedPackageDetector
    {
        return $this->detectorWithCallback(static fn() => $response);
    }

    /**
     * Returns a detector whose HTTP layer is replaced by $callback.
     * $callback receives (string $packageName) and returns ?array (the decoded Packagist JSON).
     */
    private function detectorWithCallback(callable $callback): AbandonedPackageDetector
    {
        return new class($this->cacheDir, $callback) extends AbandonedPackageDetector {
            public function __construct(string $cacheDir, private readonly \Closure $httpCallback)
            {
                parent::__construct($cacheDir);
            }

            // Override the batch-fetch method so tests run without real curl/network.
            protected function fetchBatch(array $batch): array
            {
                $results = [];
                foreach ($batch as $name) {
                    [$vendor, $package] = explode('/', $name, 2);
                    $data = ($this->httpCallback)($vendor, $package);
                    if (is_array($data)) {
                        $results[$name] = $data;
                        // Simulate cache write so testUsesLocalCacheOnSecondCall works
                        $this->saveToCache($name, $data);
                    }
                }
                return $results;
            }
        };
    }
}

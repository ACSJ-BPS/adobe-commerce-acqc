<?php

declare(strict_types=1);

namespace ACQC\Tests\Unit\Core\Version;

use ACQC\Core\Version\SyncResult;
use ACQC\Core\Version\VersionFeedSyncer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Yaml\Yaml;

/**
 * @covers \ACQC\Core\Version\VersionFeedSyncer
 * @covers \ACQC\Core\Version\SyncResult
 * @group unit
 * @group fast
 */
class VersionFeedSyncerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/acqc_syncer_test_' . uniqid('', true);
        mkdir($this->tempDir . '/adobe-commerce', 0755, true);

        // Create a minimal registry.yaml in the temp dir
        $registry = [
            'schema_version' => '1',
            'known_versions' => ['2.4.8'],
            'last_synced' => null,
            'sync_source' => 'test',
        ];
        file_put_contents($this->tempDir . '/registry.yaml', Yaml::dump($registry));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    /**
     * @test
     */
    public function testSyncReturnsOfflineOnHttpFailure(): void
    {
        $syncer = $this->getMockBuilder(VersionFeedSyncer::class)
            ->setConstructorArgs([$this->tempDir . '/adobe-commerce', new NullLogger()])
            ->onlyMethods(['fetchUrl'])
            ->getMock();

        $syncer->method('fetchUrl')
            ->willThrowException(new \RuntimeException('Connection refused'));

        $result = $syncer->sync(['packagist']);

        $this->assertInstanceOf(SyncResult::class, $result);
        $this->assertTrue($result->isOffline);
        $this->assertFalse($result->synced);
        $this->assertEmpty($result->newVersionsFound);
    }

    /**
     * @test
     */
    public function testSyncWritesNewVersionFile(): void
    {
        $newVersion = '2.4.9';
        $packagistJson = json_encode([
            'packages' => [
                'magento/community-edition' => [
                    [
                        'version' => $newVersion,
                        'time' => '2026-01-01T00:00:00+00:00',
                    ],
                    [
                        'version' => '2.4.8',   // already exists → should be skipped
                        'time' => '2025-04-08T00:00:00+00:00',
                    ],
                ],
            ],
        ]);

        // Pre-create 2.4.8.yaml so the syncer sees it as known
        file_put_contents($this->tempDir . '/adobe-commerce/2.4.8.yaml', Yaml::dump(['version' => '2.4.8']));

        $syncer = $this->getMockBuilder(VersionFeedSyncer::class)
            ->setConstructorArgs([$this->tempDir . '/adobe-commerce', new NullLogger()])
            ->onlyMethods(['fetchUrl'])
            ->getMock();

        $syncer->method('fetchUrl')
            ->willReturn($packagistJson);

        $result = $syncer->sync(['packagist']);

        $this->assertInstanceOf(SyncResult::class, $result);
        $this->assertFalse($result->isOffline);
        $this->assertTrue($result->synced);
        $this->assertContains($newVersion, $result->newVersionsFound);

        // The new version YAML file must have been written
        $yamlPath = $this->tempDir . '/adobe-commerce/' . $newVersion . '.yaml';
        $this->assertFileExists($yamlPath);

        $written = Yaml::parseFile($yamlPath);
        $this->assertSame($newVersion, $written['version']);
        $this->assertSame('unknown', $written['release_type']);
        $this->assertSame('2026-01-01', $written['release_date']);
    }

    /**
     * @test
     */
    public function testSyncSkipsAlreadyKnownVersions(): void
    {
        // 2.4.8 already exists in the temp dir
        file_put_contents($this->tempDir . '/adobe-commerce/2.4.8.yaml', Yaml::dump(['version' => '2.4.8']));

        $packagistJson = json_encode([
            'packages' => [
                'magento/magento2-base' => [
                    ['version' => '2.4.8', 'time' => '2025-04-08T00:00:00+00:00'],
                ],
            ],
        ]);

        $syncer = $this->getMockBuilder(VersionFeedSyncer::class)
            ->setConstructorArgs([$this->tempDir . '/adobe-commerce', new NullLogger()])
            ->onlyMethods(['fetchUrl'])
            ->getMock();

        $syncer->method('fetchUrl')->willReturn($packagistJson);

        $result = $syncer->sync(['packagist']);

        $this->assertEmpty($result->newVersionsFound);
        $this->assertTrue($result->synced);
    }

    /**
     * @test
     */
    public function testIsRegistryStaleReturnsTrueWhenLastSyncedIsNull(): void
    {
        $syncer = new VersionFeedSyncer($this->tempDir . '/adobe-commerce', new NullLogger());

        $this->assertTrue($syncer->isRegistryStale());
    }

    /**
     * @test
     */
    public function testIsRegistryStaleReturnsFalseWhenRecentlySynced(): void
    {
        $registry = [
            'schema_version' => '1',
            'known_versions' => [],
            'last_synced' => (new \DateTimeImmutable())->format('Y-m-d\TH:i:sP'),
        ];
        file_put_contents($this->tempDir . '/registry.yaml', Yaml::dump($registry));

        $syncer = new VersionFeedSyncer($this->tempDir . '/adobe-commerce', new NullLogger());

        $this->assertFalse($syncer->isRegistryStale(7));
    }

    /**
     * @test
     */
    public function testSyncResultOfflineFactory(): void
    {
        $result = SyncResult::offline();

        $this->assertTrue($result->isOffline);
        $this->assertFalse($result->synced);
        $this->assertEmpty($result->newVersionsFound);
        $this->assertNotEmpty($result->errors);
    }

    /**
     * @test
     */
    public function testSyncResultSuccessFactory(): void
    {
        $result = SyncResult::success(['2.4.9'], 'packagist');

        $this->assertFalse($result->isOffline);
        $this->assertTrue($result->synced);
        $this->assertContains('2.4.9', $result->newVersionsFound);
        $this->assertEmpty($result->errors);
        $this->assertSame('packagist', $result->source);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }

        rmdir($dir);
    }
}

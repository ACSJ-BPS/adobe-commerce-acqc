<?php

declare(strict_types=1);

namespace ACQC\Tests\Unit\Core\Version;

use ACQC\Core\Version\VersionManifest;
use ACQC\Core\Version\VersionRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * @covers \ACQC\Core\Version\VersionRegistry
 * @covers \ACQC\Core\Version\VersionManifest
 * @covers \ACQC\Core\Version\BreakingChange
 * @covers \ACQC\Core\Version\DbSchemaChange
 * @covers \ACQC\Core\Version\SecurityAdvisory
 * @group unit
 * @group fast
 */
class VersionRegistryTest extends TestCase
{
    private VersionRegistry $registry;

    protected function setUp(): void
    {
        $registryPath = dirname(dirname(dirname(dirname(__DIR__)))) . '/config/versions/adobe-commerce';
        $this->registry = new VersionRegistry($registryPath, new ArrayAdapter());
    }

    /**
     * @test
     */
    public function testGetManifestLoadsCorrectYaml(): void
    {
        $manifest = $this->registry->getManifest('2.4.8');

        $this->assertInstanceOf(VersionManifest::class, $manifest);
        $this->assertSame('2.4.8', $manifest->version);
        $this->assertSame('minor', $manifest->releaseType);
        $this->assertNotNull($manifest->eolDate);
    }

    /**
     * @test
     */
    public function testGetManifestThrowsForUnknownVersion(): void
    {
        $this->expectException(\Throwable::class);
        $this->registry->getManifest('9.9.9');
    }

    /**
     * @test
     */
    public function testGetBreakingChangesAccumulatesAcrossVersions(): void
    {
        $changes = $this->registry->getBreakingChanges('2.4.5', '2.4.8');

        $this->assertIsArray($changes);
        $this->assertGreaterThan(0, count($changes));

        // All entries must be BreakingChange instances
        foreach ($changes as $bc) {
            $this->assertInstanceOf(\ACQC\Core\Version\BreakingChange::class, $bc);
            $this->assertNotEmpty($bc->id);
        }
    }

    /**
     * @test
     */
    public function testGetBreakingChangesDoesNotIncludeSourceVersion(): void
    {
        // Breaking changes should be strictly > source
        $changes246 = $this->registry->getBreakingChanges('2.4.5', '2.4.6');
        $changes247 = $this->registry->getBreakingChanges('2.4.6', '2.4.7');

        foreach ($changes246 as $bc) {
            $this->assertStringStartsWith('BC-2.4.6-', $bc->id);
        }

        foreach ($changes247 as $bc) {
            $this->assertStringStartsWith('BC-2.4.7-', $bc->id);
        }
    }

    /**
     * @test
     */
    public function testIsEndOfLifeReturnsTrueForEolVersion(): void
    {
        // 2.4.5 EOL date: 2024-08-09 — today is 2026-04-26, so it must be EOL
        $result = $this->registry->isEndOfLife('2.4.5');

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function testIsEndOfLifeReturnsFalseForCurrentVersion(): void
    {
        // 2.4.8 EOL date: 2027-04-08 — still active as of 2026-04-26
        $result = $this->registry->isEndOfLife('2.4.8');

        $this->assertFalse($result);
    }

    /**
     * @test
     */
    public function testIsEndOfLifeWithCustomAsOfDate(): void
    {
        // 2.4.8 EOL is 2027-04-08 — if we check after that date it should be EOL
        $futureDate = new \DateTimeImmutable('2028-01-01');
        $this->assertTrue($this->registry->isEndOfLife('2.4.8', $futureDate));

        // Before EOL date → not EOL
        $pastDate = new \DateTimeImmutable('2026-01-01');
        $this->assertFalse($this->registry->isEndOfLife('2.4.8', $pastDate));
    }

    /**
     * @test
     */
    public function testGetKnownVersionsReturnsSortedArray(): void
    {
        $versions = $this->registry->getKnownVersions();

        $this->assertIsArray($versions);
        $this->assertNotEmpty($versions);

        // 2.4.5 must appear before 2.4.7 which must appear before 2.4.8
        $pos245 = array_search('2.4.5', $versions, true);
        $pos247 = array_search('2.4.7', $versions, true);
        $pos248 = array_search('2.4.8', $versions, true);

        $this->assertIsInt($pos245);
        $this->assertIsInt($pos247);
        $this->assertIsInt($pos248);

        $this->assertLessThan($pos247, $pos245);
        $this->assertLessThan($pos248, $pos247);
    }

    /**
     * @test
     */
    public function testGetKnownVersionsIncludesPatchVersions(): void
    {
        $versions = $this->registry->getKnownVersions();

        $this->assertContains('2.4.5-p1', $versions);
        $this->assertContains('2.4.7-p3', $versions);
    }

    /**
     * @test
     */
    public function testPatchVersionOrderIsCorrect(): void
    {
        $versions = $this->registry->getKnownVersions();

        $pos247 = array_search('2.4.7', $versions, true);
        $pos247p1 = array_search('2.4.7-p1', $versions, true);
        $pos248 = array_search('2.4.8', $versions, true);

        // 2.4.7 < 2.4.7-p1 < 2.4.8
        $this->assertLessThan($pos247p1, $pos247);
        $this->assertLessThan($pos248, $pos247p1);
    }

    /**
     * @test
     */
    public function testGetVersionsSinceFiltersCorrectly(): void
    {
        $since = $this->registry->getVersionsSince('2.4.7');

        $this->assertNotContains('2.4.7', $since);
        $this->assertNotContains('2.4.5', $since);
        $this->assertContains('2.4.8', $since);
    }

    /**
     * @test
     */
    public function testGetLatestPatchForReturnsCorrectVersion(): void
    {
        $this->assertSame('2.4.5-p3', $this->registry->getLatestPatchFor('2.4.5'));
        $this->assertSame('2.4.7-p3', $this->registry->getLatestPatchFor('2.4.7'));
        $this->assertSame('2.4.8', $this->registry->getLatestPatchFor('2.4.8'));
    }

    /**
     * @test
     */
    public function testGetPhpSupportMatrixReturnsArray(): void
    {
        $matrix = $this->registry->getPhpSupportMatrix('2.4.8');

        $this->assertIsArray($matrix);
        $this->assertArrayHasKey('minimum', $matrix);
        $this->assertArrayHasKey('maximum', $matrix);
        $this->assertSame('8.2', $matrix['minimum']);
    }

    /**
     * @test
     */
    public function testGetDbSchemaChangesAccumulates(): void
    {
        $changes = $this->registry->getDbSchemaChanges('2.4.5', '2.4.8');

        $this->assertIsArray($changes);
        $this->assertGreaterThan(0, count($changes));

        foreach ($changes as $dsc) {
            $this->assertInstanceOf(\ACQC\Core\Version\DbSchemaChange::class, $dsc);
            $this->assertNotEmpty($dsc->table);
        }
    }

    /**
     * @test
     */
    public function testGetRemovedSymbolsOnlyReturnsClassRemoved(): void
    {
        $removed = $this->registry->getRemovedSymbols('2.4.5', '2.4.8');

        foreach ($removed as $bc) {
            $this->assertSame('class_removed', $bc->type);
        }
    }

    /**
     * @test
     */
    public function testGetSecurityAdvisoriesReturnsAdvisoryObjects(): void
    {
        $advisories = $this->registry->getSecurityAdvisories('2.4.8');

        $this->assertIsArray($advisories);
        foreach ($advisories as $adv) {
            $this->assertInstanceOf(\ACQC\Core\Version\SecurityAdvisory::class, $adv);
            $this->assertNotEmpty($adv->id);
        }
    }
}

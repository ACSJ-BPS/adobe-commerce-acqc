<?php

declare(strict_types=1);

namespace ACQC\Tests\Unit\Analyzers\Version;

use ACQC\Analyzers\Version\CloudCompatibilityAnalyzer;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ACQC\Analyzers\Version\CloudCompatibilityAnalyzer
 * @group unit
 * @group fast
 */
class CloudCompatibilityAnalyzerTest extends TestCase
{
    private string $fixturesBase;
    private string $acqcRoot;

    protected function setUp(): void
    {
        $this->fixturesBase = dirname(dirname(dirname(dirname(__DIR__)))) . '/tests/Fixtures/UpgradeReadiness';
        $this->acqcRoot     = dirname(dirname(dirname(dirname(__DIR__))));
    }

    /** @test */
    public function testReturnsEmptyForNonCloudProject(): void
    {
        $projectRoot = $this->fixturesBase . '/NonCloudProject';
        $analyzer    = new CloudCompatibilityAnalyzer($this->acqcRoot);

        $result = $analyzer->analyze($projectRoot, '2.4.8');

        $this->assertFalse($result->isCloud);
        $this->assertEmpty($result->findings);
        $this->assertNull($result->detectedPhpVersion);
    }

    /** @test */
    public function testIsCloudProjectReturnsFalseWhenNoIndicatorsPresent(): void
    {
        $projectRoot = $this->fixturesBase . '/NonCloudProject';

        $this->assertFalse(CloudCompatibilityAnalyzer::isCloudProject($projectRoot));
    }

    /** @test */
    public function testIsCloudProjectReturnsTrueWhenAppYamlPresent(): void
    {
        $projectRoot = $this->fixturesBase . '/CloudProject';

        $this->assertTrue(CloudCompatibilityAnalyzer::isCloudProject($projectRoot));
    }

    /** @test */
    public function testDetectsElasticsearchInCloudConfig(): void
    {
        // CloudProject fixture has elasticsearch7 configured in .magento.env.yaml
        // AC 2.4.8 dropped Elasticsearch support
        $projectRoot = $this->fixturesBase . '/CloudProject';
        $analyzer    = new CloudCompatibilityAnalyzer($this->acqcRoot);

        $result = $analyzer->analyze($projectRoot, '2.4.8');

        $this->assertTrue($result->isCloud);
        $this->assertGreaterThan(0, $result->totalFindings());

        $types = array_column($result->findings, 'type');
        $this->assertContains('cloud_search_engine_incompatible', $types,
            'Expected cloud_search_engine_incompatible finding when elasticsearch7 is configured for AC 2.4.8'
        );
    }

    /** @test */
    public function testSearchFindingHasCorrectMessage(): void
    {
        $projectRoot = $this->fixturesBase . '/CloudProject';
        $analyzer    = new CloudCompatibilityAnalyzer($this->acqcRoot);

        $result = $analyzer->analyze($projectRoot, '2.4.8');

        foreach ($result->findings as $finding) {
            if ($finding['type'] === 'cloud_search_engine_incompatible') {
                $this->assertStringContainsString('2.4.8', $finding['message']);
                $this->assertStringContainsString('opensearch', $finding['remediation']);
                $this->assertSame('error', $finding['severity']);
                return;
            }
        }

        $this->fail('Expected cloud_search_engine_incompatible finding was not present');
    }

    /** @test */
    public function testDetectsPhpVersionFromAppYaml(): void
    {
        // CloudProject fixture declares php:8.1 in .magento.app.yaml
        // AC 2.4.8 requires PHP >= 8.2
        $projectRoot = $this->fixturesBase . '/CloudProject';
        $analyzer    = new CloudCompatibilityAnalyzer($this->acqcRoot);

        $result = $analyzer->analyze($projectRoot, '2.4.8');

        $this->assertSame('8.1', $result->detectedPhpVersion);

        $types = array_column($result->findings, 'type');
        $this->assertContains('cloud_php_version_incompatible', $types,
            'Expected cloud_php_version_incompatible finding when PHP 8.1 is declared for AC 2.4.8'
        );
    }

    /** @test */
    public function testFindingHasEffortEstimate(): void
    {
        $projectRoot = $this->fixturesBase . '/CloudProject';
        $analyzer    = new CloudCompatibilityAnalyzer($this->acqcRoot);

        $result = $analyzer->analyze($projectRoot, '2.4.8');

        foreach ($result->findings as $finding) {
            $this->assertArrayHasKey('effort_hours_min', $finding);
            $this->assertGreaterThan(0, $finding['effort_hours_min']);
            $this->assertGreaterThanOrEqual($finding['effort_hours_min'], $finding['effort_hours_max']);
        }
    }

    /** @test */
    public function testReturnsEmptyForNonExistentPath(): void
    {
        $analyzer = new CloudCompatibilityAnalyzer($this->acqcRoot);
        $result   = $analyzer->analyze('/nonexistent/path', '2.4.8');

        $this->assertFalse($result->isCloud);
        $this->assertSame([], $result->findings);
    }
}

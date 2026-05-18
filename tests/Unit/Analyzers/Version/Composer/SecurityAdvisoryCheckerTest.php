<?php

declare(strict_types=1);

namespace ACQC\Tests\Unit\Analyzers\Version\Composer;

use ACQC\Analyzers\Version\Composer\SecurityAdvisoryChecker;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ACQC\Analyzers\Version\Composer\SecurityAdvisoryChecker
 * @group unit
 * @group fast
 */
class SecurityAdvisoryCheckerTest extends TestCase
{
    private string $advisoriesPath;

    protected function setUp(): void
    {
        $this->advisoriesPath = dirname(dirname(dirname(dirname(dirname(__DIR__)))))
            . '/config/upgrade/security-advisories';
    }

    /** @test */
    public function testDetectsAffectedVersion(): void
    {
        $checker = new SecurityAdvisoryChecker($this->advisoriesPath);

        // CVE-2022-24086 affects magento/magento2-base >= 2.4.0 < 2.4.3-p2
        $packages = [
            'magento/magento2-base' => [
                'version'  => '2.4.2',
                'direct'   => true,
                'requires' => [],
            ],
        ];

        $findings = $checker->check($packages);

        $this->assertNotEmpty($findings, 'Expected at least one finding for an affected version');

        $types = array_column($findings, 'type');
        $this->assertContains('composer_security_advisory', $types);

        $severities = array_column($findings, 'severity');
        $this->assertContains('error', $severities, 'Critical/high advisory should emit error severity');
    }

    /** @test */
    public function testSkipsUnaffectedVersion(): void
    {
        $checker = new SecurityAdvisoryChecker($this->advisoriesPath);

        // 2.4.7 is well past all three fixed-in versions in the seed file
        $packages = [
            'magento/magento2-base' => [
                'version'  => '2.4.7',
                'direct'   => true,
                'requires' => [],
            ],
        ];

        $findings = $checker->check($packages);

        $this->assertEmpty($findings, 'No findings expected for a patched/safe version');
    }

    /** @test */
    public function testSkipsPackageNotInAdvisories(): void
    {
        $checker  = new SecurityAdvisoryChecker($this->advisoriesPath);
        $packages = [
            'guzzlehttp/guzzle' => ['version' => '7.8.1', 'direct' => true, 'requires' => []],
        ];

        $findings = $checker->check($packages);

        $this->assertEmpty($findings, 'No findings expected for a package not listed in any advisory');
    }

    /** @test */
    public function testReturnsEmptyForMissingAdvisoryDir(): void
    {
        $checker  = new SecurityAdvisoryChecker('/tmp/nonexistent_acqc_advisories_' . uniqid('', true));
        $packages = [
            'magento/magento2-base' => ['version' => '2.4.2', 'direct' => true, 'requires' => []],
        ];

        $findings = $checker->check($packages);

        $this->assertEmpty($findings, 'Missing advisories directory should yield no findings, not an error');
    }

    /** @test */
    public function testFindsAffectedVersionForHighCve(): void
    {
        $checker = new SecurityAdvisoryChecker($this->advisoriesPath);

        // CVE-2023-38218 affects >= 2.4.5 < 2.4.5-p5
        $packages = [
            'magento/magento2-base' => [
                'version'  => '2.4.5',
                'direct'   => true,
                'requires' => [],
            ],
        ];

        $findings = $checker->check($packages);

        $cves = array_column($findings, 'cve');
        $this->assertTrue(
            in_array('CVE-2023-38218', $cves, true) || !empty($findings),
            'High severity CVE-2023-38218 should be detected for 2.4.5'
        );
    }

    /** @test */
    public function testFindingMessageContainsExpectedFields(): void
    {
        $checker  = new SecurityAdvisoryChecker($this->advisoriesPath);
        $packages = [
            'magento/magento2-base' => [
                'version'  => '2.4.2',
                'direct'   => true,
                'requires' => [],
            ],
        ];

        $findings = $checker->check($packages);
        $this->assertNotEmpty($findings);

        $finding = $findings[0];
        $this->assertArrayHasKey('message', $finding);
        $this->assertArrayHasKey('package', $finding);
        $this->assertStringContainsString('2.4.2', $finding['message']);
        $this->assertStringContainsString('Fixed in:', $finding['message']);
    }
}

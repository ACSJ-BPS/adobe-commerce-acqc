<?php

declare(strict_types=1);

namespace ACQC\Tests\Unit\Analyzers\Version\DB;

use ACQC\Analyzers\Version\DB\WhitelistValidator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ACQC\Analyzers\Version\DB\WhitelistValidator
 * @group unit
 * @group fast
 */
class WhitelistValidatorTest extends TestCase
{
    private string $legacyRoot;
    private string $modernRoot;

    protected function setUp(): void
    {
        $fixturesBase    = dirname(dirname(dirname(dirname(dirname(__DIR__))))) . '/tests/Fixtures/UpgradeReadiness';
        $this->legacyRoot = $fixturesBase . '/LegacyProject';
        $this->modernRoot = $fixturesBase . '/ModernProject';
    }

    /** @test */
    public function testFlagsMissingWhitelist(): void
    {
        $validator = new WhitelistValidator($this->legacyRoot);
        $findings  = $validator->validate();

        $this->assertNotEmpty($findings, 'Expected a finding for missing db_schema_whitelist.json');

        $types = array_column($findings, 'type');
        $this->assertContains('db_schema_whitelist_missing', $types);

        $severities = array_column($findings, 'severity');
        foreach ($severities as $sev) {
            $this->assertSame('error', $sev);
        }
    }

    /** @test */
    public function testMissingWhitelistFindingContainsRemediationCommand(): void
    {
        $validator = new WhitelistValidator($this->legacyRoot);
        $findings  = $validator->validate();

        foreach ($findings as $finding) {
            if ($finding['type'] === 'db_schema_whitelist_missing') {
                $this->assertStringContainsString(
                    'generate-whitelist',
                    $finding['remediation'],
                    'Remediation should mention the generate-whitelist command'
                );
                $this->assertGreaterThan(0, $finding['effort_hours_min']);
                $this->assertGreaterThan(0, $finding['effort_hours_max']);
                return;
            }
        }

        $this->fail('No db_schema_whitelist_missing finding found');
    }

    /** @test */
    public function testPassesCompleteWhitelist(): void
    {
        $validator = new WhitelistValidator($this->modernRoot);
        $findings  = $validator->validate();

        $missingFindings = array_filter(
            $findings,
            fn($f) => $f['type'] === 'db_schema_whitelist_missing'
        );

        $this->assertEmpty($missingFindings, 'Modern project with complete whitelist should have no missing-whitelist findings');
    }

    /** @test */
    public function testReturnsEmptyForNonExistentDirectory(): void
    {
        $validator = new WhitelistValidator('/nonexistent/path/that/does/not/exist');
        $findings  = $validator->validate();

        $this->assertSame([], $findings);
    }
}

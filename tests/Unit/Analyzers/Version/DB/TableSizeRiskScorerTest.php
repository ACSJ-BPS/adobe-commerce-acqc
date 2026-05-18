<?php

declare(strict_types=1);

namespace ACQC\Tests\Unit\Analyzers\Version\DB;

use ACQC\Analyzers\Version\DB\TableSizeRiskScorer;
use ACQC\Core\Version\DbSchemaChange;
use ACQC\Core\Version\VersionRegistry;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * @covers \ACQC\Analyzers\Version\DB\TableSizeRiskScorer
 * @group unit
 * @group fast
 */
class TableSizeRiskScorerTest extends TestCase
{
    private string $largeTablesConfig;

    protected function setUp(): void
    {
        $this->largeTablesConfig = dirname(dirname(dirname(dirname(dirname(__DIR__))))) . '/config/upgrade/large-tables.yaml';
    }

    private function makeRegistry(array $changes): VersionRegistry&MockObject
    {
        $registry = $this->createMock(VersionRegistry::class);
        $registry->method('getDbSchemaChanges')->willReturn($changes);
        return $registry;
    }

    private function makeChange(array $data): DbSchemaChange
    {
        return DbSchemaChange::fromArray($data);
    }

    /** @test */
    public function testFlagsUniqueIndexOnLargeTable(): void
    {
        $change = $this->makeChange([
            'table'       => 'catalog_product_entity',
            'column'      => null,
            'change_type' => 'index_added',
            'index_name'  => 'IDX_CUSTOM_UNIQUE',
            'index_type'  => 'unique',
            'migration_risk'            => 'high',
            'data_migration_required'   => false,
        ]);

        $registry = $this->makeRegistry([$change]);
        $scorer   = new TableSizeRiskScorer($registry, $this->largeTablesConfig);
        $findings = $scorer->scoreDbChanges('2.4.6', '2.4.8');

        $this->assertNotEmpty($findings);

        $types = array_column($findings, 'type');
        $this->assertContains('large_table_unique_index_risk', $types);

        $errorFindings = array_filter($findings, fn($f) => $f['severity'] === 'error');
        $this->assertNotEmpty($errorFindings, 'Unique index on large table should be severity=error');
    }

    /** @test */
    public function testFlagsColumnDropOnSalesOrder(): void
    {
        $change = $this->makeChange([
            'table'       => 'sales_order',
            'column'      => 'base_shipping_discount_amount',
            'change_type' => 'column_dropped',
            'migration_risk'          => 'high',
            'data_migration_required' => false,
        ]);

        $registry = $this->makeRegistry([$change]);
        $scorer   = new TableSizeRiskScorer($registry, $this->largeTablesConfig);
        $findings = $scorer->scoreDbChanges('2.4.6', '2.4.8');

        $this->assertNotEmpty($findings);

        $types = array_column($findings, 'type');
        $this->assertContains('large_table_column_drop_risk', $types);

        foreach (array_filter($findings, fn($f) => $f['type'] === 'large_table_column_drop_risk') as $f) {
            $this->assertSame('error', $f['severity']);
            $this->assertStringContainsString('sales_order', $f['message']);
            $this->assertGreaterThan(0, $f['effort_hours_min']);
        }
    }

    /** @test */
    public function testNonUniqueIndexOnLargeTableIsWarning(): void
    {
        $change = $this->makeChange([
            'table'       => 'catalog_product_entity',
            'column'      => null,
            'change_type' => 'index_added',
            'index_name'  => 'IDX_CUSTOM_BTREE',
            'index_type'  => 'btree',
            'migration_risk'          => 'medium',
            'data_migration_required' => false,
        ]);

        $registry = $this->makeRegistry([$change]);
        $scorer   = new TableSizeRiskScorer($registry, $this->largeTablesConfig);
        $findings = $scorer->scoreDbChanges('2.4.6', '2.4.8');

        $this->assertNotEmpty($findings);

        $warnFindings = array_filter($findings, fn($f) => $f['severity'] === 'warning');
        $this->assertNotEmpty($warnFindings, 'Non-unique index on large table should be severity=warning');
    }

    /** @test */
    public function testSmallTableChangesAreIgnored(): void
    {
        $change = $this->makeChange([
            'table'       => 'core_config_data',
            'column'      => 'scope_id',
            'change_type' => 'column_dropped',
            'migration_risk'          => 'low',
            'data_migration_required' => false,
        ]);

        $registry = $this->makeRegistry([$change]);
        $scorer   = new TableSizeRiskScorer($registry, $this->largeTablesConfig);
        $findings = $scorer->scoreDbChanges('2.4.6', '2.4.8');

        $this->assertEmpty($findings, 'Changes to non-large tables should produce no findings');
    }

    /** @test */
    public function testTableRenameOnLargeTableIsError(): void
    {
        $change = $this->makeChange([
            'table'       => 'quote',
            'column'      => null,
            'change_type' => 'table_renamed',
            'migration_risk'          => 'critical',
            'data_migration_required' => true,
        ]);

        $registry = $this->makeRegistry([$change]);
        $scorer   = new TableSizeRiskScorer($registry, $this->largeTablesConfig);
        $findings = $scorer->scoreDbChanges('2.4.6', '2.4.8');

        $this->assertNotEmpty($findings);

        $types = array_column($findings, 'type');
        $this->assertContains('large_table_rename_risk', $types);

        foreach (array_filter($findings, fn($f) => $f['type'] === 'large_table_rename_risk') as $f) {
            $this->assertSame('error', $f['severity']);
        }
    }

    /** @test */
    public function testHandlesRegistryException(): void
    {
        $registry = $this->createMock(VersionRegistry::class);
        $registry->method('getDbSchemaChanges')->willThrowException(new \RuntimeException('No manifest'));

        $scorer   = new TableSizeRiskScorer($registry, $this->largeTablesConfig);
        $findings = $scorer->scoreDbChanges('2.4.6', '9.9.9');

        $this->assertSame([], $findings, 'Should return empty array when registry throws');
    }
}

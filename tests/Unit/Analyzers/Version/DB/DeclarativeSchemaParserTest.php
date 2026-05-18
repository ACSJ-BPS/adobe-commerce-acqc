<?php

declare(strict_types=1);

namespace ACQC\Tests\Unit\Analyzers\Version\DB;

use ACQC\Analyzers\Version\DB\DeclarativeSchemaParser;
use ACQC\Core\Version\VersionRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * @covers \ACQC\Analyzers\Version\DB\DeclarativeSchemaParser
 * @group unit
 * @group analyzers
 * @group fast
 */
class DeclarativeSchemaParserTest extends TestCase
{
    private string $tmpDir;
    private VersionRegistry $registry;

    protected function setUp(): void
    {
        $this->tmpDir   = sys_get_temp_dir() . '/acqc_schema_parser_test_' . uniqid();
        $registryPath   = dirname(dirname(dirname(dirname(dirname(dirname(__DIR__)))))) . '/config/versions/adobe-commerce';
        $this->registry = new VersionRegistry($registryPath, new ArrayAdapter());
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpDir);
    }

    /** @test */
    public function testReturnsEmptyWhenNoAppCode(): void
    {
        $parser   = new DeclarativeSchemaParser('/nonexistent', $this->registry);
        $findings = $parser->parse('2.4.5', '2.4.8');
        $this->assertEmpty($findings);
    }

    /** @test */
    public function testReturnsEmptyWhenNoSchemaFiles(): void
    {
        mkdir($this->tmpDir . '/app/code', 0777, true);
        $parser   = new DeclarativeSchemaParser($this->tmpDir, $this->registry);
        $findings = $parser->parse('2.4.5', '2.4.8');
        $this->assertEmpty($findings);
    }

    /** @test */
    public function testDetectsVanillaColumnCollision(): void
    {
        // Vanilla core has catalog_product_entity.sku
        $this->createFile(
            $this->tmpDir . '/var/vanilla/2.4.8/vendor/magento/module-catalog/etc/db_schema.xml',
            $this->schemaXml('catalog_product_entity', [['sku', 'varchar']], hasPrimaryKey: true)
        );

        // Custom module also declares sku on the same core table
        $this->createFile(
            $this->tmpDir . '/app/code/Acme/Module/etc/db_schema.xml',
            $this->schemaXml('catalog_product_entity', [['sku', 'varchar']])
        );

        $parser   = new DeclarativeSchemaParser($this->tmpDir, $this->registry, $this->tmpDir . '/var/vanilla/2.4.8');
        $findings = $parser->parse('2.4.5', '2.4.8');

        $types = array_column($findings, 'type');
        $this->assertContains('db_schema_vanilla_column_collision', $types);
    }

    /** @test */
    public function testDetectsVanillaTableCollisionWithPrimaryKey(): void
    {
        // Vanilla core owns catalog_product_entity (has PK)
        $this->createFile(
            $this->tmpDir . '/var/vanilla/2.4.8/vendor/magento/module-catalog/etc/db_schema.xml',
            $this->schemaXml('catalog_product_entity', [['entity_id', 'int']], hasPrimaryKey: true)
        );

        // Custom module also declares catalog_product_entity with a PK (ownership conflict)
        $this->createFile(
            $this->tmpDir . '/app/code/Acme/Module/etc/db_schema.xml',
            $this->schemaXml('catalog_product_entity', [['custom_col', 'varchar']], hasPrimaryKey: true)
        );

        $parser   = new DeclarativeSchemaParser($this->tmpDir, $this->registry, $this->tmpDir . '/var/vanilla/2.4.8');
        $findings = $parser->parse('2.4.5', '2.4.8');

        $types = array_column($findings, 'type');
        $this->assertContains('db_schema_vanilla_table_collision', $types);
    }

    /** @test */
    public function testNoCollisionForCustomOwnedTable(): void
    {
        // Vanilla does NOT have this custom table
        $this->createFile(
            $this->tmpDir . '/var/vanilla/2.4.8/vendor/magento/module-catalog/etc/db_schema.xml',
            $this->schemaXml('catalog_product_entity', [['entity_id', 'int']], hasPrimaryKey: true)
        );

        // Custom module creates its own completely new table
        $this->createFile(
            $this->tmpDir . '/app/code/Acme/Module/etc/db_schema.xml',
            $this->schemaXml('acme_custom_table', [['id', 'int']], hasPrimaryKey: true)
        );

        $parser   = new DeclarativeSchemaParser($this->tmpDir, $this->registry, $this->tmpDir . '/var/vanilla/2.4.8');
        $findings = $parser->parse('2.4.5', '2.4.8');

        $types = array_column($findings, 'type');
        $this->assertNotContains('db_schema_vanilla_column_collision', $types);
        $this->assertNotContains('db_schema_vanilla_table_collision', $types);
    }

    /** @test */
    public function testBuildCoreSchemaFromVanilla(): void
    {
        $this->createFile(
            $this->tmpDir . '/vendor/magento/module-catalog/etc/db_schema.xml',
            $this->schemaXml('catalog_product_entity', [['entity_id', 'int'], ['sku', 'varchar']])
        );

        $parser = new DeclarativeSchemaParser($this->tmpDir, $this->registry);
        $schema = $parser->buildCoreSchemaFromVanilla($this->tmpDir);

        $this->assertArrayHasKey('catalog_product_entity', $schema);
        $this->assertArrayHasKey('entity_id', $schema['catalog_product_entity']['columns']);
        $this->assertArrayHasKey('sku', $schema['catalog_product_entity']['columns']);
        $this->assertSame('magento/module-catalog', $schema['catalog_product_entity']['owner']);
    }

    /** @test */
    public function testBuildCoreSchemaIgnoresNonMagentoVendors(): void
    {
        $this->createFile(
            $this->tmpDir . '/vendor/thirdparty/module-foo/etc/db_schema.xml',
            $this->schemaXml('thirdparty_table', [['id', 'int']])
        );

        $parser = new DeclarativeSchemaParser($this->tmpDir, $this->registry);
        $schema = $parser->buildCoreSchemaFromVanilla($this->tmpDir);

        $this->assertArrayNotHasKey('thirdparty_table', $schema);
    }

    /** @test */
    public function testNoVanillaPathMeansNoVanillaChecks(): void
    {
        $this->createFile(
            $this->tmpDir . '/app/code/Acme/Module/etc/db_schema.xml',
            $this->schemaXml('catalog_product_entity', [['sku', 'varchar']])
        );

        $parser   = new DeclarativeSchemaParser($this->tmpDir, $this->registry);
        $findings = $parser->parse('2.4.5', '2.4.8');

        $types = array_column($findings, 'type');
        $this->assertNotContains('db_schema_vanilla_column_collision', $types);
        $this->assertNotContains('db_schema_vanilla_table_collision', $types);
    }

    // -------------------------------------------------------------------------

    private function schemaXml(string $tableName, array $columns, bool $hasPrimaryKey = false): string
    {
        $colXml = '';
        foreach ($columns as [$colName, $colType]) {
            $colXml .= "    <column name=\"{$colName}\" xsi:type=\"{$colType}\"/>\n";
        }
        $pkXml = $hasPrimaryKey
            ? "    <constraint xsi:type=\"PRIMARY KEY\" referenceId=\"PRIMARY\"><column name=\"{$columns[0][0]}\"/></constraint>\n"
            : '';

        return <<<XML
<?xml version="1.0"?>
<schema xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:framework:Setup/Declaration/Schema/etc/schema.xsd">
  <table name="{$tableName}" resource="default" charset="utf8" comment="Test table">
{$colXml}{$pkXml}  </table>
</schema>
XML;
    }

    private function createFile(string $path, string $content): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($path, $content);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getRealPath()) : unlink($item->getRealPath());
        }
        rmdir($dir);
    }
}

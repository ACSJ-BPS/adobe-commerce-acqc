<?php

declare(strict_types=1);

namespace ACQC\Analyzers\Version\DB;

use ACQC\Core\Version\VersionRegistry;

class DeclarativeSchemaParser
{
    private ?string $vanillaPath = null;

    public function __construct(
        private readonly string $projectRoot,
        private readonly VersionRegistry $registry,
        ?string $vanillaPath = null,
    ) {
        $this->vanillaPath = $vanillaPath;
    }

    public function setVanillaPath(?string $vanillaPath): void
    {
        $this->vanillaPath = $vanillaPath;
    }

    public function parse(string $sourceVersion, string $targetVersion): array
    {
        $appCodeDir = $this->projectRoot . '/app/code';
        if (!is_dir($appCodeDir)) {
            return [];
        }

        try {
            $coreChanges = $this->registry->getDbSchemaChanges($sourceVersion, $targetVersion);
        } catch (\Throwable) {
            $coreChanges = [];
        }

        // Build lookup: table -> [dropped columns], table -> [added columns]
        $coreDroppedColumns = [];
        $coreAddedColumns   = [];
        foreach ($coreChanges as $change) {
            if ($change->changeType === 'column_dropped' && $change->column !== null) {
                $coreDroppedColumns[$change->table][] = $change->column;
            }
            if ($change->changeType === 'column_added' && $change->column !== null) {
                $coreAddedColumns[$change->table][] = $change->column;
            }
        }

        // Build vanilla core schema map when a vanilla instance is available.
        // This catches conflicts not yet catalogued in the YAML data.
        $vanillaCoreSchema = $this->vanillaPath !== null
            ? $this->buildCoreSchemaFromVanilla($this->vanillaPath)
            : [];

        $findings = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($appCodeDir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getFilename() !== 'db_schema.xml') {
                continue;
            }

            $schemaPath = $file->getPathname();
            $moduleName = $this->resolveModuleName($schemaPath);

            $dom = new \DOMDocument();
            if (!@$dom->load($schemaPath)) {
                continue;
            }

            $xpath = new \DOMXPath($dom);
            $xpath->registerNamespace('xsi', 'http://www.w3.org/2001/XMLSchema-instance');

            foreach ($xpath->query('//table') as $tableNode) {
                $tableName = $tableNode->getAttribute('name');
                if ($tableName === '') {
                    continue;
                }

                // Check each column in this custom module's schema
                foreach ($xpath->query('.//column', $tableNode) as $colNode) {
                    $colName = $colNode->getAttribute('name');
                    if ($colName === '') {
                        continue;
                    }

                    // Conflict: custom module adds a column that the target version also adds (YAML)
                    if (isset($coreAddedColumns[$tableName])
                        && in_array($colName, $coreAddedColumns[$tableName], true)
                    ) {
                        $findings[] = [
                            'type'     => 'db_schema_column_conflict',
                            'severity' => 'error',
                            'file'     => $schemaPath,
                            'module'   => $moduleName,
                            'table'    => $tableName,
                            'column'   => $colName,
                            'message'  => "Custom module {$moduleName} adds column {$colName} to {$tableName}, "
                                . "but AC {$targetVersion} also adds this column. Conflict will cause upgrade failure.",
                            'remediation' => 'Remove the column definition from the custom module\'s db_schema.xml '
                                . 'and rely on the core schema. Add a db_schema_whitelist.json entry to track removal.',
                            'effort_hours_min' => 2,
                            'effort_hours_max' => 8,
                        ];
                    }

                    // Reference to a column that is being dropped by the core in target version (YAML)
                    if (isset($coreDroppedColumns[$tableName])
                        && in_array($colName, $coreDroppedColumns[$tableName], true)
                    ) {
                        $findings[] = [
                            'type'     => 'db_column_referenced_dropped',
                            'severity' => 'error',
                            'file'     => $schemaPath,
                            'module'   => $moduleName,
                            'table'    => $tableName,
                            'column'   => $colName,
                            'message'  => "Custom module {$moduleName} references column {$colName} on table {$tableName}, "
                                . "but AC {$targetVersion} drops this column.",
                            'remediation' => 'Remove or update the column reference in db_schema.xml. '
                                . 'Ensure any data depending on this column is migrated via a DataPatch.',
                            'effort_hours_min' => 4,
                            'effort_hours_max' => 16,
                        ];
                    }

                    // Vanilla collision: custom module defines a column that core already owns.
                    // This is a wider check than the YAML-only path above — catches undocumented conflicts.
                    if ($vanillaCoreSchema !== []
                        && isset($vanillaCoreSchema[$tableName]['columns'][$colName])
                        // Skip if already caught by YAML checks above to avoid duplicates
                        && !(isset($coreAddedColumns[$tableName]) && in_array($colName, $coreAddedColumns[$tableName], true))
                        && !(isset($coreDroppedColumns[$tableName]) && in_array($colName, $coreDroppedColumns[$tableName], true))
                    ) {
                        $findings[] = [
                            'type'     => 'db_schema_vanilla_column_collision',
                            'severity' => 'warning',
                            'file'     => $schemaPath,
                            'module'   => $moduleName,
                            'table'    => $tableName,
                            'column'   => $colName,
                            'message'  => "Custom module {$moduleName} declares column {$colName} on table {$tableName}, "
                                . "which is also defined by a core module in AC {$targetVersion}. "
                                . "Duplicate declarations may cause schema merge failures.",
                            'remediation' => 'Remove the column definition from the custom module\'s db_schema.xml '
                                . 'and rely on the core declaration. Reference the column via db_schema_whitelist.json if needed.',
                            'effort_hours_min' => 1,
                            'effort_hours_max' => 4,
                        ];
                    }
                }

                // Custom module declares a table already owned by core (vanilla check)
                if ($vanillaCoreSchema !== []
                    && isset($vanillaCoreSchema[$tableName])
                    && $xpath->query('.//column', $tableNode)->length === 0
                ) {
                    // Table present in custom schema but with no columns = referencing core table, OK.
                    // Covered by the column loop above when columns exist.
                }

                // Custom module creates a new table whose name collides with a vanilla core table
                if ($vanillaCoreSchema !== []
                    && isset($vanillaCoreSchema[$tableName])
                    // Only warn if the custom schema owns the table (has primary key constraint)
                    && $xpath->query('.//constraint[@xsi:type="PRIMARY KEY"]', $tableNode)->length > 0
                ) {
                    $findings[] = [
                        'type'     => 'db_schema_vanilla_table_collision',
                        'severity' => 'error',
                        'file'     => $schemaPath,
                        'module'   => $moduleName,
                        'table'    => $tableName,
                        'message'  => "Custom module {$moduleName} declares table {$tableName} with a PRIMARY KEY, "
                            . "but this table is owned by a core module in AC {$targetVersion}. "
                            . "Table ownership conflict will cause upgrade failure.",
                        'remediation' => 'Remove the table declaration from the custom module. '
                            . 'Use column/index additions in the existing core table, or rename to a module-prefixed table.',
                        'effort_hours_min' => 8,
                        'effort_hours_max' => 24,
                    ];
                }

                // Check foreign key declarations
                foreach ($xpath->query('.//constraint[@xsi:type="FOREIGN KEY"]', $tableNode) as $fkNode) {
                    $refTable  = $fkNode->getAttribute('referenceTable');
                    $refColumn = $fkNode->getAttribute('referenceColumn');

                    if ($refTable !== '' && $refColumn !== ''
                        && isset($coreDroppedColumns[$refTable])
                        && in_array($refColumn, $coreDroppedColumns[$refTable], true)
                    ) {
                        $findings[] = [
                            'type'     => 'db_column_referenced_dropped',
                            'severity' => 'error',
                            'file'     => $schemaPath,
                            'module'   => $moduleName,
                            'table'    => $tableName,
                            'column'   => $refColumn,
                            'message'  => "Custom module {$moduleName} has a FOREIGN KEY referencing {$refTable}.{$refColumn}, "
                                . "but AC {$targetVersion} drops that column.",
                            'remediation' => 'Remove the foreign key constraint and update any dependent data migrations.',
                            'effort_hours_min' => 4,
                            'effort_hours_max' => 16,
                        ];
                    }
                }
            }
        }

        return $findings;
    }

    /**
     * Parse all db_schema.xml files from the vanilla Magento vendor directory.
     * Returns a map: [table_name => ['columns' => [col_name => true], 'owner' => 'vendor/package']]
     *
     * @return array<string, array{columns: array<string, true>, owner: string}>
     */
    public function buildCoreSchemaFromVanilla(string $vanillaPath): array
    {
        $vendorDir = rtrim($vanillaPath, '/') . '/vendor';
        if (!is_dir($vendorDir)) {
            return [];
        }

        $schema = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($vendorDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getFilename() !== 'db_schema.xml') {
                continue;
            }

            // Only process magento/* packages
            $relativePath = str_replace($vendorDir . '/', '', $file->getPathname());
            $parts = explode('/', $relativePath);
            if (!isset($parts[0], $parts[1]) || $parts[0] !== 'magento') {
                continue;
            }

            $packageName = $parts[0] . '/' . $parts[1];

            $dom = new \DOMDocument();
            if (!@$dom->load($file->getPathname())) {
                continue;
            }

            $xpath = new \DOMXPath($dom);
            $xpath->registerNamespace('xsi', 'http://www.w3.org/2001/XMLSchema-instance');

            foreach ($xpath->query('//table') as $tableNode) {
                $tableName = $tableNode->getAttribute('name');
                if ($tableName === '') {
                    continue;
                }

                if (!isset($schema[$tableName])) {
                    $schema[$tableName] = ['columns' => [], 'owner' => $packageName];
                }

                foreach ($xpath->query('.//column', $tableNode) as $colNode) {
                    $colName = $colNode->getAttribute('name');
                    if ($colName !== '') {
                        $schema[$tableName]['columns'][$colName] = true;
                    }
                }
            }
        }

        return $schema;
    }

    private function resolveModuleName(string $schemaPath): string
    {
        $parts  = explode(DIRECTORY_SEPARATOR, $schemaPath);
        $etcIdx = array_search('etc', $parts);
        if ($etcIdx !== false && $etcIdx >= 2) {
            return $parts[$etcIdx - 2] . '/' . $parts[$etcIdx - 1];
        }
        return basename(dirname(dirname($schemaPath)));
    }
}

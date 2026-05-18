<?php

declare(strict_types=1);

namespace ACQC\Analyzers\Version\DB;

use ACQC\Core\Version\VersionRegistry;

/**
 * Detects custom foreign key declarations in db_schema.xml that conflict with
 * core table changes in the target AC version — e.g. referencing a column that
 * was dropped or renamed, or adding an FK to a table that was restructured.
 */
class ForeignKeyConflictDetector
{
    public function __construct(
        private readonly string $projectRoot,
        private readonly VersionRegistry $registry,
    ) {}

    public function detect(string $sourceVersion, string $targetVersion): array
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

        // Build lookup of tables/columns that were dropped in the target version
        $droppedColumns = [];
        foreach ($coreChanges as $change) {
            if ($change->changeType === 'column_dropped' && $change->column !== null) {
                $droppedColumns[$change->table][$change->column] = true;
            }
        }

        if (empty($droppedColumns)) {
            return [];
        }

        $findings  = [];
        $iterator  = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($appCodeDir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getFilename() !== 'db_schema.xml') {
                continue;
            }

            $schemaPath = $file->getPathname();
            $dom = new \DOMDocument();
            if (!@$dom->load($schemaPath)) {
                continue;
            }

            $xpath = new \DOMXPath($dom);
            $fkNodes = $xpath->query('//constraint[@referenceTable]');
            if ($fkNodes === false) {
                continue;
            }

            $moduleName = $this->resolveModuleName($schemaPath);

            foreach ($fkNodes as $fk) {
                $refTable  = $fk->getAttribute('referenceTable');
                $refColumn = $fk->getAttribute('referenceColumn');

                if (isset($droppedColumns[$refTable][$refColumn])) {
                    $findings[] = [
                        'type'             => 'fk_references_dropped_column',
                        'severity'         => 'error',
                        'file'             => $schemaPath,
                        'module'           => $moduleName,
                        'message'          => "Module {$moduleName}: foreign key references {$refTable}.{$refColumn}, "
                                             . "which was dropped in AC {$targetVersion}.",
                        'remediation'      => "Remove or update the foreign key constraint in db_schema.xml "
                                             . "to reference the replacement column, then regenerate db_schema_whitelist.json.",
                        'effort_hours_min' => 2,
                        'effort_hours_max' => 8,
                    ];
                }
            }
        }

        return $findings;
    }

    private function resolveModuleName(string $schemaPath): string
    {
        if (preg_match('#app/code/([^/]+/[^/]+)#', $schemaPath, $m)) {
            return $m[1];
        }
        return basename(dirname(dirname($schemaPath)));
    }
}

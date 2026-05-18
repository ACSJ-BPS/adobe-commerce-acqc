<?php

declare(strict_types=1);

namespace ACQC\Analyzers\Version\DB;

use ACQC\Core\Version\VersionRegistry;
use Symfony\Component\Yaml\Yaml;

class TableSizeRiskScorer
{
    /** @var array<string,array<string,mixed>> */
    private array $largeTablesIndex = [];

    public function __construct(
        private readonly VersionRegistry $registry,
        private readonly string $largeTablesConfigPath,
    ) {
        $this->loadLargeTablesConfig();
    }

    public function scoreDbChanges(string $sourceVersion, string $targetVersion): array
    {
        $findings = [];

        try {
            $changes = $this->registry->getDbSchemaChanges($sourceVersion, $targetVersion);
        } catch (\Throwable) {
            return [];
        }

        foreach ($changes as $change) {
            $table      = $change->table;
            $changeType = $change->changeType;

            if (!$this->isLargeTable($table)) {
                continue;
            }

            $indexType = $change->indexType ?? '';

            if ($changeType === 'column_dropped') {
                $column = $change->column ?? 'unknown';
                $findings[] = [
                    'type'             => 'large_table_column_drop_risk',
                    'severity'         => 'error',
                    'table'            => $table,
                    'column'           => $column,
                    'target_version'   => $targetVersion,
                    'message'          => "Dropping column {$column} from large table {$table} in AC {$targetVersion} requires a maintenance window.",
                    'remediation'      => 'Schedule a maintenance window. Consider pt-online-schema-change or gh-ost for zero-downtime DDL.',
                    'effort_hours_min' => 8,
                    'effort_hours_max' => 24,
                ];
                continue;
            }

            if ($changeType === 'index_added') {
                if (strtolower($indexType) === 'unique') {
                    $findings[] = [
                        'type'             => 'large_table_unique_index_risk',
                        'severity'         => 'error',
                        'table'            => $table,
                        'index_type'       => $indexType,
                        'target_version'   => $targetVersion,
                        'message'          => "Adding unique index to large table {$table} in AC {$targetVersion} requires full table scan. Plan maintenance window.",
                        'remediation'      => 'Use pt-online-schema-change or gh-ost to add the unique index without locking the table.',
                        'effort_hours_min' => 8,
                        'effort_hours_max' => 40,
                    ];
                } else {
                    $findings[] = [
                        'type'           => 'large_table_index_add_warning',
                        'severity'       => 'warning',
                        'table'          => $table,
                        'index_type'     => $indexType,
                        'target_version' => $targetVersion,
                        'message'        => "Adding index to large table {$table} in AC {$targetVersion}. Consider ALGORITHM=INPLACE,LOCK=NONE.",
                        'remediation'    => 'Verify MySQL supports online DDL for this operation. Use ALGORITHM=INPLACE,LOCK=NONE when possible.',
                    ];
                }
                continue;
            }

            if ($changeType === 'table_renamed') {
                $findings[] = [
                    'type'             => 'large_table_rename_risk',
                    'severity'         => 'error',
                    'table'            => $table,
                    'target_version'   => $targetVersion,
                    'message'          => "Table {$table} is renamed in AC {$targetVersion}. All custom joins and raw SQL will break.",
                    'remediation'      => 'Audit all custom modules for direct table name references. Update ResourceModels, collections, and raw SQL queries.',
                    'effort_hours_min' => 4,
                    'effort_hours_max' => 16,
                ];
            }
        }

        return $findings;
    }

    private function isLargeTable(string $table): bool
    {
        return isset($this->largeTablesIndex[$table]);
    }

    private function loadLargeTablesConfig(): void
    {
        if (!file_exists($this->largeTablesConfigPath)) {
            return;
        }

        $data = Yaml::parseFile($this->largeTablesConfigPath);
        foreach ($data['known_large_tables'] ?? [] as $entry) {
            $tableName = $entry['table'] ?? '';
            if ($tableName !== '') {
                $this->largeTablesIndex[$tableName] = $entry;
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace ACQC\Analyzers\Version;

use ACQC\Core\Version\MagentoVersion;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Database Compatibility Analyzer
 * 
 * Analyzes database schema changes, migration requirements,
 * and estimates effort for database upgrades.
 */
class DatabaseCompatibilityAnalyzer
{
    private LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Analyze database compatibility for upgrade
     */
    public function analyzeDatabaseCompatibility(
        string $projectPath,
        MagentoVersion $currentVersion,
        MagentoVersion $targetVersion
    ): array {
        $this->logger->info('Analyzing database compatibility', [
            'current' => $currentVersion->toString(),
            'target' => $targetVersion->toString(),
        ]);

        $issues = [];

        // 1. Analyze db_schema.xml files
        $schemaIssues = $this->analyzeDbSchema($projectPath, $currentVersion, $targetVersion);
        $issues['schema'] = $schemaIssues;

        // 2. Check for setup scripts that need conversion
        $setupScripts = $this->findSetupScripts($projectPath);
        $issues['setup_scripts'] = $setupScripts;

        // 3. Analyze data patches
        $dataPatches = $this->analyzeDataPatches($projectPath);
        $issues['data_patches'] = $dataPatches;

        // 4. Check for direct SQL queries
        $directSql = $this->findDirectSqlQueries($projectPath);
        $issues['direct_sql'] = $directSql;

        // 5. EAV attribute analysis
        $issues['eav_attributes'] = $this->analyzeEavAttributes($projectPath);

        // 6. Indexer configuration analysis
        $issues['indexers'] = $this->analyzeIndexerConfiguration($projectPath);

        // 7. Large table risk assessment
        $issues['large_table_risks'] = $this->assessLargeTableRisks($projectPath);

        // 8. Backward compatibility analysis
        $issues['backward_compatibility'] = $this->analyzeBackwardCompatibility($projectPath, $currentVersion, $targetVersion);

        // 9. Rollback preparedness
        $issues['rollback_plan'] = $this->buildRollbackConsiderations($issues);

        // 10. Estimate migration effort
        $effort = $this->estimateDatabaseMigrationEffort($issues);
        $issues['effort_estimation'] = $effort;

        return $issues;
    }

    /**
     * Analyze db_schema.xml files
     */
    private function analyzeDbSchema(
        string $projectPath,
        MagentoVersion $currentVersion,
        MagentoVersion $targetVersion
    ): array {
        $issues = [];
        
        // Find all db_schema.xml files
        $schemaFiles = $this->findDbSchemaFiles($projectPath);
        
        $this->logger->info('Found db_schema.xml files', ['count' => count($schemaFiles)]);

        foreach ($schemaFiles as $file) {
            $fileIssues = $this->analyzeSchemaFile($file, $currentVersion, $targetVersion);
            if (!empty($fileIssues)) {
                $issues[$file] = $fileIssues;
            }
        }

        return $issues;
    }

    /**
     * Analyze single db_schema.xml file
     */
    private function analyzeSchemaFile(
        string $filePath,
        MagentoVersion $currentVersion,
        MagentoVersion $targetVersion
    ): array {
        $issues = [];
        
        if (!file_exists($filePath)) {
            return $issues;
        }

        $xml = simplexml_load_file($filePath);
        if (!$xml) {
            $issues[] = [
                'type' => 'parse_error',
                'severity' => 'critical',
                'message' => 'Failed to parse db_schema.xml',
            ];
            return $issues;
        }

        // Check for deprecated column types
        foreach ($xml->xpath('//column') as $column) {
            $type = (string)$column['type'];
            if ($this->isDeprecatedColumnType($type, $targetVersion)) {
                $issues[] = [
                    'type' => 'deprecated_column_type',
                    'severity' => 'high',
                    'column' => (string)$column['name'],
                    'column_type' => $type,
                    'message' => "Column type '$type' is deprecated in {$targetVersion->toString()}",
                    'fix' => $this->getRecommendedColumnType($type),
                ];
            }
        }

        // Check for missing indexes on foreign keys
        $foreignKeys = $xml->xpath('//constraint[@type="foreign"]');
        $indexes = $xml->xpath('//index');
        $indexedColumns = [];
        
        foreach ($indexes as $index) {
            foreach ($index->column as $column) {
                $indexedColumns[] = (string)$column['name'];
            }
        }

        foreach ($foreignKeys as $fk) {
            $column = (string)$fk['column'];
            if (!in_array($column, $indexedColumns)) {
                $issues[] = [
                    'type' => 'missing_index',
                    'severity' => 'medium',
                    'column' => $column,
                    'message' => "Foreign key column '$column' should have an index for performance",
                    'fix' => 'Add index on foreign key column',
                ];
            }
        }

        return $issues;
    }

    /**
     * Find setup scripts that need conversion
     */
    private function findSetupScripts(string $projectPath): array
    {
        $scripts = [
            'install_schema' => [],
            'upgrade_schema' => [],
            'install_data' => [],
            'upgrade_data' => [],
            'recurring' => [],
        ];

        $setupDir = $projectPath . '/Setup';
        if (!is_dir($setupDir)) {
            return $scripts;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($setupDir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $content = file_get_contents($file->getPathname());
                
                if (preg_match('/implements\s+InstallSchemaInterface/', $content)) {
                    $scripts['install_schema'][] = [
                        'file' => $file->getPathname(),
                        'severity' => 'critical',
                        'message' => 'InstallSchema must be converted to db_schema.xml',
                        'effort_hours' => 3,
                    ];
                } elseif (preg_match('/implements\s+UpgradeSchemaInterface/', $content)) {
                    $scripts['upgrade_schema'][] = [
                        'file' => $file->getPathname(),
                        'severity' => 'critical',
                        'message' => 'UpgradeSchema must be converted to declarative patches',
                        'effort_hours' => 4,
                    ];
                } elseif (preg_match('/implements\s+InstallDataInterface/', $content)) {
                    $scripts['install_data'][] = [
                        'file' => $file->getPathname(),
                        'severity' => 'high',
                        'message' => 'InstallData should be converted to data patches',
                        'effort_hours' => 2,
                    ];
                } elseif (preg_match('/implements\s+UpgradeDataInterface/', $content)) {
                    $scripts['upgrade_data'][] = [
                        'file' => $file->getPathname(),
                        'severity' => 'high',
                        'message' => 'UpgradeData should be converted to data patches',
                        'effort_hours' => 2,
                    ];
                }
            }
        }

        return $scripts;
    }

    /**
     * Analyze data patches
     */
    private function analyzeDataPatches(string $projectPath): array
    {
        $issues = [];
        
        $patchDir = $projectPath . '/Setup/Patch/Data';
        if (!is_dir($patchDir)) {
            return $issues;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($patchDir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $patchIssues = $this->analyzePatchFile($file->getPathname());
                if (!empty($patchIssues)) {
                    $issues[$file->getPathname()] = $patchIssues;
                }
            }
        }

        return $issues;
    }

    /**
     * Analyze patch file
     */
    private function analyzePatchFile(string $filePath): array
    {
        $issues = [];
        $content = file_get_contents($filePath);

        // Check for direct SQL usage
        if (preg_match('/->query\s*\(/', $content)) {
            $issues[] = [
                'type' => 'direct_sql_in_patch',
                'severity' => 'medium',
                'message' => 'Consider using Magento APIs instead of direct SQL',
                'fix' => 'Use repository interfaces and models',
            ];
        }

        // Check for missing dependencies
        if (!preg_match('/public\s+static\s+function\s+getDependencies/', $content)) {
            $issues[] = [
                'type' => 'missing_dependencies',
                'severity' => 'low',
                'message' => 'Data patch should define dependencies if needed',
                'fix' => 'Implement getDependencies() method',
            ];
        }

        return $issues;
    }

    /**
     * Find direct SQL queries in code
     */
    private function findDirectSqlQueries(string $projectPath): array
    {
        $issues = [];

        $files = $this->scanPhpFiles($projectPath);

        foreach ($files as $file) {
            // Skip test files — they intentionally exercise SQL paths
            if (preg_match('#/Test[s]?/#i', $file) || str_ends_with($file, 'Test.php')) {
                continue;
            }

            $content = file_get_contents($file);
            $lines = explode("\n", $content);

            foreach ($lines as $lineNum => $line) {
                // Check for direct SQL queries
                if (preg_match('/->(query|exec|execute)\s*\(\s*["\']SELECT|INSERT|UPDATE|DELETE/i', $line)) {
                    $issues[] = [
                        'file' => $file,
                        'line' => $lineNum + 1,
                        'type' => 'direct_sql',
                        'severity' => 'medium',
                        'code' => trim($line),
                        'message' => 'Direct SQL query found - may need update for new schema',
                        'fix' => 'Review for compatibility with target version schema',
                    ];
                }
            }
        }

        return $issues;
    }

    /**
     * Estimate database migration effort
     */
    private function estimateDatabaseMigrationEffort(array $issues): array
    {
        /*
         * Database Migration Effort Formula:
         * 
         * Total Effort = Schema Analysis + Script Conversion + Testing + Validation
         * 
         * Schema Analysis: 1 hour per db_schema.xml with issues
         * Script Conversion:
         *   - InstallSchema: 3 hours each
         *   - UpgradeSchema: 4 hours each
         *   - InstallData: 2 hours each
         *   - UpgradeData: 2 hours each
         * Testing: 50% of conversion time
         * Validation: 25% of conversion time
         * Buffer: 20%
         */

        $schemaAnalysisTime = count($issues['schema'] ?? []) * 1.0;
        
        $conversionTime = 0;
        $setupScripts = $issues['setup_scripts'] ?? [];
        
        foreach ($setupScripts as $type => $scripts) {
            foreach ($scripts as $script) {
                $conversionTime += $script['effort_hours'] ?? 0;
            }
        }

        $testingTime    = $conversionTime * 0.5;
        $validationTime = $conversionTime * 0.25;
        $subtotal       = $schemaAnalysisTime + $conversionTime + $testingTime + $validationTime;
        $bufferTime     = $subtotal * 0.2;
        $totalTime      = $subtotal + $bufferTime;

        return [
            'schema_analysis_hours'   => round($schemaAnalysisTime, 2),
            'script_conversion_hours' => round($conversionTime, 2),
            'testing_hours'           => round($testingTime, 2),
            'validation_hours'        => round($validationTime, 2),
            'buffer_hours'            => round($bufferTime, 2),
            'total_hours'             => round($totalTime, 2),
            'setup_scripts_count'     => array_sum(array_map('count', $setupScripts)),
            'schema_files_with_issues' => count($issues['schema'] ?? []),
        ];
    }

    /**
     * Find db_schema.xml files
     */
    private function findDbSchemaFiles(string $directory): array
    {
        $files = [];
        
        if (!is_dir($directory)) {
            return $files;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getFilename() === 'db_schema.xml') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * Scan PHP files
     */
    private function scanPhpFiles(string $directory): array
    {
        $files = [];
        
        if (!is_dir($directory)) {
            return $files;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * Check if column type is deprecated
     */
    private function isDeprecatedColumnType(string $type, MagentoVersion $targetVersion): bool
    {
        // Types deprecated/removed in specific Commerce versions.
        // Key: db_schema.xml type value; Value: version where deprecation was introduced.
        $deprecatedTypes = [
            'tinyint'    => new MagentoVersion(2, 4, 0),  // use boolean or smallint
            'mediumint'  => new MagentoVersion(2, 4, 0),  // use integer
            'mediumtext' => new MagentoVersion(2, 4, 0),  // use text
            'mediumblob' => new MagentoVersion(2, 4, 0),  // use blob
            'longblob'   => new MagentoVersion(2, 4, 0),  // use blob
            'longtext'   => new MagentoVersion(2, 4, 0),  // use text
            'real'       => new MagentoVersion(2, 4, 0),  // use float or decimal
            'numeric'    => new MagentoVersion(2, 4, 0),  // use decimal
            'nvarchar'   => new MagentoVersion(2, 3, 0),  // MySQL-specific alias
            'nchar'      => new MagentoVersion(2, 3, 0),  // MySQL-specific alias
            'set'        => new MagentoVersion(2, 3, 0),  // non-portable
            'enum'       => new MagentoVersion(2, 3, 0),  // use smallint + value mapping
            'year'       => new MagentoVersion(2, 4, 0),  // use integer
            'bit'        => new MagentoVersion(2, 4, 0),  // use boolean or smallint
            'geometry'   => new MagentoVersion(2, 4, 0),  // not supported in declarative schema
            'point'      => new MagentoVersion(2, 4, 0),
            'linestring' => new MagentoVersion(2, 4, 0),
            'polygon'    => new MagentoVersion(2, 4, 0),
        ];

        if (!isset($deprecatedTypes[$type])) {
            return false;
        }
        return $targetVersion->isGreaterThanOrEqual($deprecatedTypes[$type]);
    }

    /**
     * Get recommended column type
     */
    private function getRecommendedColumnType(string $oldType): string
    {
        $recommendations = [
            'tinyint'    => 'Use `boolean` for flags, `smallint` for numeric values',
            'mediumint'  => 'Use `integer` (INT)',
            'mediumtext' => 'Use `text` — Commerce declarative schema maps TEXT to equivalent storage',
            'mediumblob' => 'Use `blob`',
            'longblob'   => 'Use `blob`',
            'longtext'   => 'Use `text`',
            'real'       => 'Use `float` (FLOAT) or `decimal` with explicit precision/scale',
            'numeric'    => 'Use `decimal` with explicit precision and scale, e.g. decimal(12,4)',
            'nvarchar'   => 'Use `varchar` with length attribute',
            'nchar'      => 'Use `char` with length attribute',
            'set'        => 'Use `smallint` with application-level value mapping or a lookup table',
            'enum'       => 'Use `smallint` with constants in the model, or a foreign-key lookup table',
            'year'       => 'Use `integer` (SMALLINT) to store 4-digit year values',
            'bit'        => 'Use `boolean` (maps to TINYINT(1) in MySQL)',
            'geometry'   => 'Geometry types are not supported in declarative schema — use raw SQL migration',
            'point'      => 'Not supported in declarative schema — use raw SQL migration',
            'linestring' => 'Not supported in declarative schema — use raw SQL migration',
            'polygon'    => 'Not supported in declarative schema — use raw SQL migration',
        ];

        return $recommendations[$oldType] ?? 'Review Adobe Commerce declarative schema documentation';
    }

    /**
     * Analyze EAV attribute definitions in Setup/Patch/Data and InstallData files.
     * Detects deprecated attribute input types, missing source models, and non-standard backend types.
     */
    private function analyzeEavAttributes(string $projectPath): array
    {
        $issues = [];
        $files  = $this->scanPhpFiles($projectPath);

        $deprecatedInputTypes = ['file', 'image', 'gallery'];
        $legacyBackendTypes   = ['Magento\Eav\Model\Entity\Attribute\Backend\Datetime'];

        foreach ($files as $file) {
            $content = file_get_contents($file);

            // addAttribute calls with deprecated input types
            foreach ($deprecatedInputTypes as $inputType) {
                if (preg_match('/addAttribute\s*\(.*[\'"]input[\'"]\s*=>\s*[\'"]' . preg_quote($inputType, '/') . '[\'"]/', $content)) {
                    $issues[] = [
                        'file'     => $file,
                        'type'     => 'deprecated_eav_input_type',
                        'severity' => 'high',
                        'message'  => "EAV attribute uses deprecated input type '{$inputType}'",
                        'fix'      => "Replace '{$inputType}' input type with a supported alternative (e.g., text, select)",
                    ];
                }
            }

            // addAttribute without source_model when type is select/multiselect
            if (preg_match('/addAttribute\s*\(/', $content)
                && preg_match('/[\'"]type[\'"]\s*=>\s*[\'"]select[\'"]/', $content)
                && !preg_match('/[\'"]source[\'"]\s*=>/', $content)
            ) {
                $issues[] = [
                    'file'     => $file,
                    'type'     => 'missing_eav_source_model',
                    'severity' => 'medium',
                    'message'  => 'EAV select attribute defined without a source_model',
                    'fix'      => 'Add source_model pointing to a SourceInterface implementation',
                ];
            }

            // Legacy backend types
            foreach ($legacyBackendTypes as $backendType) {
                if (strpos($content, $backendType) !== false) {
                    $issues[] = [
                        'file'     => $file,
                        'type'     => 'legacy_eav_backend_type',
                        'severity' => 'medium',
                        'message'  => "Legacy EAV backend class '{$backendType}' may have changed",
                        'fix'      => 'Verify backend class compatibility with target Magento version',
                    ];
                }
            }

            // updateAttribute / removeAttribute patterns that may break declarative flow
            if (preg_match('/->removeAttribute\s*\(/', $content)) {
                $issues[] = [
                    'file'     => $file,
                    'type'     => 'eav_attribute_removal',
                    'severity' => 'high',
                    'message'  => 'EAV attribute removal detected — ensure data migration handles orphaned values',
                    'fix'      => 'Add a corresponding data patch to clean up orphaned eav_* table rows',
                ];
            }
        }

        return $issues;
    }

    /**
     * Analyze indexer.xml configuration files for performance and compatibility issues.
     */
    private function analyzeIndexerConfiguration(string $projectPath): array
    {
        $issues = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($projectPath, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getFilename() !== 'indexer.xml') {
                continue;
            }

            $xml = @simplexml_load_file($file->getPathname());
            if (!$xml) {
                continue;
            }

            foreach ($xml->xpath('//indexer') as $indexer) {
                $indexerId = (string)($indexer['id'] ?? 'unknown');

                // batchRowsCount > 10 000 is a known performance risk
                $batchRows = (int)($indexer['batchRowsCount'] ?? 0);
                if ($batchRows > 10000) {
                    $issues[] = [
                        'file'     => $file->getPathname(),
                        'type'     => 'oversized_batch_rows',
                        'severity' => 'medium',
                        'indexer'  => $indexerId,
                        'value'    => $batchRows,
                        'message'  => "Indexer '{$indexerId}' batchRowsCount={$batchRows} exceeds recommended 10 000 limit",
                        'fix'      => 'Reduce batchRowsCount to ≤10 000 to avoid memory exhaustion during full reindex',
                    ];
                }

                // Missing view reference
                $viewId = (string)($indexer['view_id'] ?? '');
                if (empty($viewId)) {
                    $issues[] = [
                        'file'     => $file->getPathname(),
                        'type'     => 'missing_indexer_view',
                        'severity' => 'high',
                        'indexer'  => $indexerId,
                        'message'  => "Indexer '{$indexerId}' has no view_id — incremental indexing will not work",
                        'fix'      => 'Define a mview.xml view for this indexer',
                    ];
                }
            }

            // mview.xml checks
            $mviewPath = dirname($file->getPathname()) . '/mview.xml';
            if (file_exists($mviewPath)) {
                $mview = @simplexml_load_file($mviewPath);
                if ($mview) {
                    foreach ($mview->xpath('//view') as $view) {
                        $viewId = (string)($view['id'] ?? '');
                        // Subscriptions without column spec can trigger full reindex on any table change
                        foreach ($view->xpath('.//table') as $table) {
                            if (empty((string)($table['entity_column'] ?? ''))) {
                                $issues[] = [
                                    'file'     => $mviewPath,
                                    'type'     => 'mview_missing_entity_column',
                                    'severity' => 'low',
                                    'view'     => $viewId,
                                    'message'  => "mview '{$viewId}' table subscription has no entity_column — may trigger unnecessary reindex",
                                    'fix'      => 'Set entity_column to limit reindex scope to affected rows only',
                                ];
                            }
                        }
                    }
                }
            }
        }

        return $issues;
    }

    /**
     * Assess large table upgrade risks by examining table definitions in db_schema.xml.
     * Flags tables with text/blob columns, no primary key identity, or known high-volume patterns.
     */
    private function assessLargeTableRisks(string $projectPath): array
    {
        $risks = [];
        $seen  = [];  // deduplicate by table + type
        $schemaFiles = $this->findDbSchemaFiles($projectPath);

        $highVolumePatterns = ['order', 'quote', 'customer', 'catalog_product', 'inventory', 'log', 'report', 'session'];

        foreach ($schemaFiles as $file) {
            $xml = @simplexml_load_file($file);
            if (!$xml) {
                continue;
            }

            foreach ($xml->xpath('//table') as $table) {
                $tableName = (string)($table['name'] ?? '');

                $isHighVolume = false;
                foreach ($highVolumePatterns as $pattern) {
                    if (stripos($tableName, $pattern) !== false) {
                        $isHighVolume = true;
                        break;
                    }
                }

                // Count columns directly defined in THIS file for this table.
                // Custom modules that only add columns to a core table will have
                // few columns and no PK — skip those to avoid false positives.
                $columns     = $table->xpath('.//column');
                $columnCount = count($columns);
                $textBlob    = 0;

                foreach ($columns as $col) {
                    $type = strtolower((string)($col['type'] ?? ''));
                    if (in_array($type, ['text', 'blob', 'longtext', 'mediumtext', 'longblob', 'mediumblob'], true)) {
                        $textBlob++;
                    }
                }

                // Only flag missing identity PK when this file defines ≥5 columns
                // (i.e., it's the owning module defining the full table, not an extension).
                $identityPk = $table->xpath('.//column[@identity="true"]');
                $riskKey    = $tableName . ':missing_identity_pk';
                if ($columnCount >= 5 && empty($identityPk) && $isHighVolume && !isset($seen[$riskKey])) {
                    $seen[$riskKey] = true;
                    $risks[] = [
                        'table'         => $tableName,
                        'type'          => 'missing_identity_pk',
                        'severity'      => 'high',
                        'message'       => "Table '{$tableName}' appears high-volume but declares no identity primary key — ALTER TABLE will lock large datasets",
                        'fix'           => 'Verify PK exists in owning module; for large tables use pt-online-schema-change or gh-ost',
                        'rollback_risk' => 'HIGH',
                    ];
                }

                $blobKey = $tableName . ':large_table_blob_columns';
                if ($isHighVolume && $textBlob > 2 && !isset($seen[$blobKey])) {
                    $seen[$blobKey] = true;
                    $risks[] = [
                        'table'         => $tableName,
                        'type'          => 'large_table_blob_columns',
                        'severity'      => 'medium',
                        'message'       => "Table '{$tableName}' has {$textBlob} text/blob columns — migration may be slow on large datasets",
                        'fix'           => 'Plan offline migration window or use online schema change tools',
                        'rollback_risk' => 'MEDIUM',
                    ];
                }

                $wideKey = $tableName . ':wide_table';
                if ($columnCount > 60 && !isset($seen[$wideKey])) {
                    $seen[$wideKey] = true;
                    $risks[] = [
                        'table'         => $tableName,
                        'type'          => 'wide_table',
                        'severity'      => 'low',
                        'message'       => "Table '{$tableName}' declares {$columnCount} columns — consider normalization",
                        'fix'           => 'Review columns for archival or normalization opportunities before upgrade',
                        'rollback_risk' => 'LOW',
                    ];
                }
            }
        }

        return $risks;
    }

    /**
     * Analyze backward compatibility concerns: removed events, changed interfaces, deprecated config paths.
     */
    private function analyzeBackwardCompatibility(
        string $projectPath,
        MagentoVersion $currentVersion,
        MagentoVersion $targetVersion
    ): array {
        $issues = [];
        $files  = $this->scanPhpFiles($projectPath);

        // Known removed/renamed events between 2.3→2.4 and 2.4.x patch lines
        $removedEvents = [
            'catalog_product_save_before'          => 'Use plugin on ProductRepository::save() instead',
            'sales_order_invoice_register'         => 'Replaced by sales.order.invoice.save event in 2.4.4+',
            'checkout_cart_product_add_after'      => 'Deprecated; use quote observer checkout_cart_add_product_complete',
        ];

        // Config paths removed/restructured
        $removedConfigPaths = [
            'dev/grid/async_indexing'              => 'Moved to indexer configuration in 2.4.x',
            'catalog/seo/product_url_suffix_store' => 'Removed in 2.4.0 — use catalog/seo/product_url_suffix',
        ];

        // Scan events.xml for removed events
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($projectPath, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            if ($file->getFilename() === 'events.xml') {
                $xml = @simplexml_load_file($file->getPathname());
                if ($xml) {
                    foreach ($xml->xpath('//event') as $event) {
                        $eventName = (string)($event['name'] ?? '');
                        if (isset($removedEvents[$eventName])) {
                            $issues[] = [
                                'file'     => $file->getPathname(),
                                'type'     => 'removed_event',
                                'severity' => 'high',
                                'event'    => $eventName,
                                'message'  => "Observer registered on removed/deprecated event '{$eventName}'",
                                'fix'      => $removedEvents[$eventName],
                            ];
                        }
                    }
                }
            }

            if ($file->getFilename() === 'config.xml' || $file->getFilename() === 'system.xml') {
                $content = file_get_contents($file->getPathname());
                foreach ($removedConfigPaths as $path => $advice) {
                    if (strpos($content, $path) !== false) {
                        $issues[] = [
                            'file'     => $file->getPathname(),
                            'type'     => 'removed_config_path',
                            'severity' => 'medium',
                            'path'     => $path,
                            'message'  => "Config path '{$path}' has been removed/restructured in target version",
                            'fix'      => $advice,
                        ];
                    }
                }
            }
        }

        // PHP file checks for removed interfaces/classes
        $removedClasses = [
            'Magento\Framework\App\Cache\StateInterface' => 'Use Magento\Framework\App\Cache\State instead',
            'Magento\Sales\Model\Order\Pdf\AbstractPdf'  => 'Abstract PDF classes removed in 2.4.4+; use InvoicePdf/ShipmentPdf directly',
        ];

        foreach ($files as $file) {
            $content = file_get_contents($file);
            foreach ($removedClasses as $class => $advice) {
                if (strpos($content, $class) !== false) {
                    $issues[] = [
                        'file'     => $file,
                        'type'     => 'removed_class',
                        'severity' => 'critical',
                        'class'    => $class,
                        'message'  => "Reference to removed/restructured class '{$class}'",
                        'fix'      => $advice,
                    ];
                }
            }
        }

        return $issues;
    }

    /**
     * Build rollback considerations based on all detected issues.
     */
    private function buildRollbackConsiderations(array $issues): array
    {
        $considerations = [];
        $riskLevel = 'LOW';

        $schemaCount   = count($issues['schema'] ?? []);
        $setupCount    = array_sum(array_map('count', $issues['setup_scripts'] ?? []));
        $largeTableHigh = 0;
        foreach ($issues['large_table_risks'] ?? [] as $risk) {
            if (($risk['rollback_risk'] ?? '') === 'HIGH') {
                $largeTableHigh++;
            }
        }

        if ($schemaCount > 0 || $largeTableHigh > 0) {
            $riskLevel = 'HIGH';
        } elseif ($setupCount > 0) {
            $riskLevel = 'MEDIUM';
        }

        $considerations['risk_level'] = $riskLevel;
        $considerations['checklist'] = [
            'Take full database backup before any migration step (mysqldump with --single-transaction)',
            'Test rollback procedure in staging before production deployment',
            'Document all schema changes with before/after column definitions',
            'Keep previous version codebase deployable for at least 72h post-upgrade',
            'Verify all data patches are idempotent (safe to re-run)',
            'Confirm bin/magento setup:db:status reports no pending changes post-upgrade',
            'Monitor replication lag if using MySQL read replicas during migration',
        ];

        if ($largeTableHigh > 0) {
            $considerations['checklist'][] = "CRITICAL: {$largeTableHigh} high-volume table(s) detected — use pt-online-schema-change or gh-ost for zero-downtime DDL";
        }

        if ($schemaCount > 0) {
            $considerations['checklist'][] = "Review {$schemaCount} db_schema.xml file(s) with issues — each schema change is irreversible without a down-migration patch";
        }

        $considerations['data_migration_risks'] = [
            'Declarative schema changes apply automatically on bin/magento setup:upgrade — no manual SQL needed',
            'Data patches run once per patch class — ensure getDependencies() is correct to avoid ordering issues',
            'Legacy InstallSchema/UpgradeSchema scripts still execute but are not trackable in schema_whitelist.json',
            'EAV attribute removal leaves orphaned rows in eav_attribute_option, eav_attribute_option_value tables',
            'Indexer state is reset on schema upgrade — plan for full reindex after deployment',
        ];

        $considerations['deployment_sequence'] = [
            '1. Deploy new codebase to all nodes (maintenance mode ON)',
            '2. Run bin/magento setup:upgrade (schema + data patches apply)',
            '3. Run bin/magento setup:di:compile',
            '4. Run bin/magento setup:static-content:deploy',
            '5. Run bin/magento indexer:reindex (schedule all indexers)',
            '6. Smoke test critical paths (checkout, product detail, admin orders)',
            '7. Disable maintenance mode',
            '8. Monitor error logs for 30 minutes post-deployment',
        ];

        return $considerations;
    }

}

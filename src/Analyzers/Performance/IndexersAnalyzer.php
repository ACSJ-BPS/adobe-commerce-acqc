<?php
declare(strict_types=1);

namespace ACQC\Analyzers\Performance;

use ACQC\Analyzers\AbstractAnalyzer;
use ACQC\Core\Rules\Violation;
use Psr\Log\LoggerInterface;

/**
 * Analyzes Magento indexer configuration and status for performance optimization
 *
 * Checks:
 * - Indexer modes (Update on Save vs Schedule)
 * - Search engine configuration (MySQL vs Elasticsearch/OpenSearch)
 * - Invalid indexer status
 * - Mview backlog (outdated indexes)
 * - Flat catalog scheduling issues
 *
 * @package ACQC\Analyzers\Performance
 */
class IndexersAnalyzer extends AbstractAnalyzer
{
    /**
     * Indexers that should ALWAYS use scheduled mode in production
     */
    private const SCHEDULE_RECOMMENDED = [
        'catalog_product_price',
        'catalog_category_product',
        'cataloginventory_stock',
        'catalogsearch_fulltext',
        'catalog_product_flat',
        'catalog_category_flat',
    ];

    /**
     * Recommended search engines
     */
    private const RECOMMENDED_SEARCH_ENGINES = [
        'elasticsearch7',
        'elasticsearch8',
        'opensearch',
    ];

    /**
     * Mview backlog threshold (number of pending updates)
     */
    private const MVIEW_BACKLOG_THRESHOLD = 1000;

    public function __construct(array $configuration = [], ?LoggerInterface $logger = null)
    {
        parent::__construct($configuration, $logger);
    }

    public function getName(): string
    {
        return 'indexers';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function isAvailable(): bool
    {
        return true; // Always available
    }

    public function getDefaultConfiguration(): array
    {
        return [
            'enabled' => true,
            'check_modes' => true,
            'check_search_engine' => true,
            'check_invalid_status' => true,
            'check_mview_backlog' => true,
            'check_flat_catalog' => true,
        ];
    }

    public function analyze(array $files, array $options = []): array
    {
        $violations = [];
        $magentoRoot = $this->findMagentoRoot($files, $options);

        if (!$magentoRoot) {
            $this->logger->warning('Could not determine Magento root directory');
            return $violations;
        }

        $this->logger->info('Starting indexer analysis', ['magento_root' => $magentoRoot]);

        // Check 1: Indexer modes
        $violations = array_merge(
            $violations,
            $this->checkIndexerModes($magentoRoot)
        );

        // Check 2: Search engine configuration
        $violations = array_merge(
            $violations,
            $this->checkSearchEngine($magentoRoot)
        );

        // Check 3: Invalid indexer status
        $violations = array_merge(
            $violations,
            $this->checkInvalidIndexers($magentoRoot)
        );

        // Check 4: Outdated indexes (mview backlog)
        $violations = array_merge(
            $violations,
            $this->checkOutdatedIndexes($magentoRoot)
        );

        // Check 5: Flat catalog scheduling
        $violations = array_merge(
            $violations,
            $this->checkFlatCatalogScheduling($magentoRoot)
        );

        $this->logger->info('Indexer analysis complete', [
            'violations' => count($violations)
        ]);

        return $violations;
    }

    /**
     * Check indexer modes (Update on Save vs Schedule)
     */
    private function checkIndexerModes(string $magentoRoot): array
    {
        $violations = [];
        $binMagento = $magentoRoot . '/bin/magento';

        if (!is_file($binMagento) || !is_executable($binMagento)) {
            $this->logger->debug('bin/magento not found or not executable');
            return $violations;
        }

        try {
            $output = $this->runCommand("cd {$magentoRoot} && php bin/magento indexer:status 2>&1");
            
            if (empty($output)) {
                return $violations;
            }

            // Parse indexer status output
            $lines = explode("\n", $output);
            foreach ($lines as $line) {
                // Look for "Update on Save" or "Update by Schedule"
                if (preg_match('/^([a-z_]+)\s+.*Update on Save/i', $line, $matches)) {
                    $indexerId = $matches[1];
                    
                    // Check if this indexer should use scheduled mode
                    if (in_array($indexerId, self::SCHEDULE_RECOMMENDED)) {
                        $violation = new Violation(
                            'indexer-update-on-save',
                            "Indexer '{$indexerId}' is set to 'Update on Save' mode which can cause performance issues in production. Use 'Update by Schedule' mode instead.",
                            '',
                            0,
                            0,
                            Violation::SEVERITY_WARNING,
                            Violation::TYPE_PERFORMANCE
                        );
                        $violation->setCategory('performance');
                        $violation->setMetadata([
                            'indexer' => $indexerId,
                            'current_mode' => 'realtime',
                            'recommended_mode' => 'schedule',
                        ]);
                        $violations[] = $violation;
                    }
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to check indexer modes', [
                'error' => $e->getMessage()
            ]);
        }

        return $violations;
    }

    /**
     * Check search engine configuration
     */
    private function checkSearchEngine(string $magentoRoot): array
    {
        $violations = [];
        $envFile = $magentoRoot . '/app/etc/env.php';

        if (!is_file($envFile)) {
            $this->logger->debug('env.php not found');
            return $violations;
        }

        try {
            $config = include $envFile;
            $searchEngine = $config['system']['default']['catalog']['search']['engine'] ?? 'mysql';

            if (!in_array($searchEngine, self::RECOMMENDED_SEARCH_ENGINES)) {
                $violation = new Violation(
                    'search-engine-not-elasticsearch',
                    "Search engine is set to '{$searchEngine}'. For better search performance, use Elasticsearch or OpenSearch instead of MySQL search.",
                    $envFile,
                    0,
                    0,
                    Violation::SEVERITY_WARNING,
                    Violation::TYPE_PERFORMANCE
                );
                $violation->setCategory('performance');
                $violation->setMetadata([
                    'current_engine' => $searchEngine,
                    'recommended_engines' => self::RECOMMENDED_SEARCH_ENGINES,
                ]);
                $violations[] = $violation;
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to check search engine', [
                'error' => $e->getMessage()
            ]);
        }

        return $violations;
    }

    /**
     * Check for invalid indexers that need reindexing
     */
    private function checkInvalidIndexers(string $magentoRoot): array
    {
        $violations = [];
        $binMagento = $magentoRoot . '/bin/magento';

        if (!is_file($binMagento) || !is_executable($binMagento)) {
            return $violations;
        }

        try {
            $output = $this->runCommand("cd {$magentoRoot} && php bin/magento indexer:status 2>&1");
            
            if (empty($output)) {
                return $violations;
            }

            // Parse for invalid status
            $lines = explode("\n", $output);
            foreach ($lines as $line) {
                if (preg_match('/^([a-z_]+)\s+.*Status:\s*invalid/i', $line, $matches)) {
                    $indexerId = $matches[1];
                    
                    $violation = new Violation(
                        'indexer-invalid-status',
                        "Indexer '{$indexerId}' has invalid status and needs to be reindexed. This can cause incorrect data display and search results.",
                        '',
                        0,
                        0,
                        Violation::SEVERITY_ERROR,
                        Violation::TYPE_PERFORMANCE
                    );
                    $violation->setCategory('performance');
                    $violation->setMetadata([
                        'indexer' => $indexerId,
                        'status' => 'invalid',
                    ]);
                    $violations[] = $violation;
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to check invalid indexers', [
                'error' => $e->getMessage()
            ]);
        }

        return $violations;
    }

    /**
     * Check for outdated indexes (mview backlog)
     */
    private function checkOutdatedIndexes(string $magentoRoot): array
    {
        $violations = [];
        $envFile = $magentoRoot . '/app/etc/env.php';

        if (!is_file($envFile)) {
            return $violations;
        }

        try {
            $config = include $envFile;
            $dbConfig = $config['db']['connection']['default'] ?? null;

            if (!$dbConfig) {
                return $violations;
            }

            // Try to check mview_state table
            $host = $dbConfig['host'] ?? 'localhost';
            $dbname = $dbConfig['dbname'] ?? '';
            $username = $dbConfig['username'] ?? '';
            $password = $dbConfig['password'] ?? '';

            if (empty($dbname) || empty($username)) {
                return $violations;
            }

            // Check if we can connect (simplified check)
            $mysqlCmd = sprintf(
                "mysql -h%s -u%s %s %s -e \"SELECT view_id, version_id, status FROM mview_state WHERE status = 'working' OR (version_id - 0) > 1000 LIMIT 5\" 2>&1",
                escapeshellarg($host),
                escapeshellarg($username),
                !empty($password) ? '-p' . escapeshellarg($password) : '',
                escapeshellarg($dbname)
            );

            $output = $this->runCommand($mysqlCmd);

            if (!empty($output) && !stripos($output, 'error')) {
                $lines = explode("\n", trim($output));
                if (count($lines) > 1) { // Has results beyond header
                    $violation = new Violation(
                        'indexer-outdated-mview',
                        "Mview backlog detected. Some indexers have a large number of pending updates. This indicates scheduled indexing might not be running properly.",
                        '',
                        0,
                        0,
                        Violation::SEVERITY_WARNING,
                        Violation::TYPE_PERFORMANCE
                    );
                    $violation->setCategory('performance');
                    $violation->setMetadata([
                        'backlog_threshold' => self::MVIEW_BACKLOG_THRESHOLD,
                        'affected_views' => count($lines) - 1,
                    ]);
                    $violations[] = $violation;
                }
            }
        } catch (\Exception $e) {
            $this->logger->debug('Could not check mview backlog', [
                'error' => $e->getMessage()
            ]);
        }

        return $violations;
    }

    /**
     * Check flat catalog scheduling issues
     */
    private function checkFlatCatalogScheduling(string $magentoRoot): array
    {
        $violations = [];
        $envFile = $magentoRoot . '/app/etc/env.php';
        $binMagento = $magentoRoot . '/bin/magento';

        if (!is_file($envFile) || !is_file($binMagento)) {
            return $violations;
        }

        try {
            // Check if flat catalog is enabled
            $configCmd = "cd {$magentoRoot} && php bin/magento config:show catalog/frontend/flat_catalog_product 2>&1";
            $flatProductEnabled = trim($this->runCommand($configCmd)) === '1';

            $configCmd = "cd {$magentoRoot} && php bin/magento config:show catalog/frontend/flat_catalog_category 2>&1";
            $flatCategoryEnabled = trim($this->runCommand($configCmd)) === '1';

            if (!$flatProductEnabled && !$flatCategoryEnabled) {
                return $violations; // Flat catalog not enabled
            }

            // Check indexer modes
            $output = $this->runCommand("cd {$magentoRoot} && php bin/magento indexer:status 2>&1");
            
            $flatProductOnSave = stripos($output, 'catalog_product_flat') !== false 
                && stripos($output, 'Update on Save') !== false;
            $flatCategoryOnSave = stripos($output, 'catalog_category_flat') !== false 
                && stripos($output, 'Update on Save') !== false;

            if (($flatProductEnabled && $flatProductOnSave) || ($flatCategoryEnabled && $flatCategoryOnSave)) {
                $violation = new Violation(
                    'flat-catalog-without-schedule',
                    "Flat catalog is enabled with 'Update on Save' indexing mode. This can cause severe performance issues during catalog updates. Either disable flat catalog or use 'Update by Schedule' mode.",
                    '',
                    0,
                    0,
                    Violation::SEVERITY_WARNING,
                    Violation::TYPE_PERFORMANCE
                );
                $violation->setCategory('performance');
                $violation->setMetadata([
                    'flat_product_enabled' => $flatProductEnabled,
                    'flat_category_enabled' => $flatCategoryEnabled,
                    'using_realtime_mode' => true,
                ]);
                $violations[] = $violation;
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to check flat catalog scheduling', [
                'error' => $e->getMessage()
            ]);
        }

        return $violations;
    }

    /**
     * Execute a shell command and return output
     */
    private function runCommand(string $command): string
    {
        $output = '';
        $returnVar = 0;
        
        exec($command, $outputArray, $returnVar);
        
        if ($returnVar === 0) {
            $output = implode("\n", $outputArray);
        }
        
        return $output;
    }

    /**
     * Find Magento root directory
     */
    private function findMagentoRoot(array $files, array $options): ?string
    {
        // Check if provided in options
        if (!empty($options['magento_root'])) {
            return $options['magento_root'];
        }

        // Try to find from files
        foreach ($files as $file) {
            if (is_dir($file)) {
                if (is_file($file . '/bin/magento')) {
                    return $file;
                }
            } else {
                // Go up directories to find bin/magento
                $dir = dirname($file);
                $maxDepth = 10;
                while ($maxDepth-- > 0) {
                    if (is_file($dir . '/bin/magento')) {
                        return $dir;
                    }
                    $parent = dirname($dir);
                    if ($parent === $dir) {
                        break;
                    }
                    $dir = $parent;
                }
            }
        }

        return null;
    }

    protected function executeAnalysis(array $files, array $options = []): array
    {
        // Not used for this analyzer - all logic in analyze() method
        return [];
    }

    protected function parseOutput(string $output, array $files): array
    {
        // Not used for this analyzer - all logic in analyze() method
        return [];
    }
}

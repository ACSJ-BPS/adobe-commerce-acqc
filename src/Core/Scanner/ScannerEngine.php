<?php

declare(strict_types=1);

namespace ACQC\Core\Scanner;

use ACQC\Analyzers\AnalyzerInterface;
use ACQC\Core\Config\ConfigManager;
use Symfony\Component\Finder\Finder;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class ScannerEngine
{
    private FileScanner $fileScanner;
    private ParallelScanner $parallelScanner;
    private ConfigManager $configManager;
    private LoggerInterface $logger;
    private array $analyzers = [];
    private array $scanMetrics = [];
    private ?array $baseline = null;
    private $progressCallback = null;

    public function __construct(
        ?ConfigManager $configManager = null,
        ?LoggerInterface $logger = null
    ) {
        $this->configManager = $configManager ?? new ConfigManager();
        $this->logger = $logger ?? new NullLogger();
        $this->fileScanner = new FileScanner($this->logger);
        $this->parallelScanner = new ParallelScanner($this->fileScanner, $this->logger);
        
        $this->initializeMetrics();
    }

    /**
     * Add analyzer to the engine
     */
    public function addAnalyzer(AnalyzerInterface $analyzer): void
    {
        $this->analyzers[] = $analyzer;
        $this->fileScanner->addAnalyzer($analyzer);
    }

    /**
     * Set analyzers for the engine
     */
    public function setAnalyzers(array $analyzers): void
    {
        $this->analyzers = [];
        foreach ($analyzers as $analyzer) {
            if ($analyzer instanceof AnalyzerInterface) {
                $this->addAnalyzer($analyzer);
            }
        }
    }

    /**
     * Scan a single file
     */
    public function scanFile(string $filePath, array $options = []): ScanResult
    {
        $context = $this->createScanContext($options);
        $startTime = microtime(true);

        try {
            $result = $this->fileScanner->scanFile($filePath, $context);
            
            // Apply baseline comparison if available
            if ($this->baseline && isset($this->baseline[$filePath])) {
                $baselineResult = ScanResult::fromArray($this->baseline[$filePath]);
                $comparison = $result->compareWithBaseline($baselineResult);
                $result->setMetadata('baseline_comparison', $comparison);
            }

            $this->updateScanMetrics('files_scanned', 1);
            $this->updateScanMetrics('total_scan_time', microtime(true) - $startTime);

            return $result;

        } catch (\Exception $e) {
            $this->logger->error("Failed to scan file {$filePath}: " . $e->getMessage());
            
            $result = new ScanResult($filePath);
            $result->addError("Scan failed: " . $e->getMessage());
            return $result;
        }
    }

    /**
     * Scan a directory
     */
    /**
     * Discover files in a directory without scanning them
     * Returns array of file paths
     */
    public function discoverDirectory(string $directoryPath, ?ScanContext $context = null): array
    {
        $context = $context ?? $this->createScanContext();
        
        try {
            $filePaths = $this->discoverFiles($directoryPath, $context);
            $validFiles = $this->filterFiles($filePaths, $context);
            
            $this->logger->info("Discovered " . count($validFiles) . " valid files in {$directoryPath}");
            
            return $validFiles;
        } catch (\Exception $e) {
            $this->logger->error("Failed to discover files in {$directoryPath}: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Scan directory and return scan results
     */
    public function scanDirectory(string $directoryPath, ?ScanContext $context = null): array
    {
        $context = $context ?? $this->createScanContext();
        $startTime = microtime(true);

        try {
            // Discover files to scan
            $filePaths = $this->discoverFiles($directoryPath, $context);
            
            if (empty($filePaths)) {
                $this->logger->warning("No files found to scan in directory: {$directoryPath}");
                return [];
            }

            $this->logger->info("Found " . count($filePaths) . " files to scan in {$directoryPath}");

            // Load baseline if specified
            if ($context->getBaselinePath()) {
                $this->loadBaseline($context->getBaselinePath());
            }

            // Set up progress tracking
            if ($context->isProgressTrackingEnabled() && $this->progressCallback) {
                $this->parallelScanner->setProgressCallback($this->progressCallback);
            }

            // Scan files
            $results = $this->scanFiles($filePaths, $context);

            // Update metrics
            $this->updateScanMetrics('directories_scanned', 1);
            $this->updateScanMetrics('files_scanned', count($results));
            $this->updateScanMetrics('total_scan_time', microtime(true) - $startTime);

            return $results;

        } catch (\Exception $e) {
            $this->logger->error("Failed to scan directory {$directoryPath}: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Scan multiple files
     */
    public function scanFiles(array $filePaths, ?ScanContext $context = null): array
    {
        $context = $context ?? $this->createScanContext();
        $startTime = microtime(true);

        try {
            // Filter files based on context criteria
            $validFiles = $this->filterFiles($filePaths, $context);
            
            if (empty($validFiles)) {
                $this->logger->warning("No valid files found to scan");
                return [];
            }

            $this->logger->info("Scanning " . count($validFiles) . " files");

            // Choose scanning strategy based on context
            if ($context->isParallelProcessingEnabled() && count($validFiles) > 10) {
                $results = $this->parallelScanner->scanFiles($validFiles, $context);
            } else {
                $results = $this->scanFilesSequentially($validFiles, $context);
            }

            // Apply post-processing
            $results = $this->postProcessResults($results, $context);

            $this->updateScanMetrics('files_scanned', count($results));
            $this->updateScanMetrics('total_scan_time', microtime(true) - $startTime);

            return $results;

        } catch (\Exception $e) {
            $this->logger->error("Failed to scan files: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Scan files matching a pattern
     */
    public function scanPattern(string $pattern, ?ScanContext $context = null): array
    {
        $context = $context ?? $this->createScanContext();
        $startTime = microtime(true);

        try {
            // Find files matching the pattern
            $filePaths = glob($pattern, GLOB_BRACE);
            
            if (empty($filePaths)) {
                $this->logger->warning("No files found matching pattern: {$pattern}");
                return [];
            }

            $this->logger->info("Found " . count($filePaths) . " files matching pattern: {$pattern}");

            // Filter out directories
            $filePaths = array_filter($filePaths, 'is_file');

            if (empty($filePaths)) {
                $this->logger->warning("No valid files found matching pattern: {$pattern}");
                return [];
            }

            // Scan the files
            $results = $this->scanFiles($filePaths, $context);

            // Update metrics
            $this->updateScanMetrics('patterns_scanned', 1);
            $this->updateScanMetrics('files_scanned', count($results));
            $this->updateScanMetrics('total_scan_time', microtime(true) - $startTime);

            return $results;

        } catch (\Exception $e) {
            $this->logger->error("Failed to scan pattern {$pattern}: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Discover files in a directory
     */
    private function discoverFiles(string $directoryPath, ScanContext $context): array
    {
        $finder = new Finder();
        $filePaths = [];

        try {
            $finder->files()
                   ->in($directoryPath)
                   ->ignoreVCS(true);

            // Apply include patterns (combine all patterns)
            $includePatterns = $context->getIncludePatterns();
            if (!empty($includePatterns)) {
                $finder->name($includePatterns);
            }

            // Apply exclude patterns
            foreach ($context->getExcludePatterns() as $pattern) {
                $finder->notPath($pattern);
            }

            // Apply file size limits
            if ($context->getScanOption('skip_large_files', true)) {
                $maxSize = $context->getMaxFileSize();
                $finder->size("<= {$maxSize}");
            }

            foreach ($finder as $file) {
                $filePaths[] = $file->getRealPath();
            }
            
            // DEBUG logging
            $this->logger->info("discoverFiles found " . count($filePaths) . " files in {$directoryPath}");

        } catch (\Exception $e) {
            $this->logger->error("Failed to discover files in {$directoryPath}: " . $e->getMessage());
            throw $e;
        }

        return $filePaths;
    }

    /**
     * Filter files based on context criteria
     */
    private function filterFiles(array $filePaths, ScanContext $context): array
    {
        $validFiles = [];

        foreach ($filePaths as $filePath) {
            // Check if file should be included
            if (!$context->shouldIncludeFile($filePath)) {
                $this->logger->debug("File excluded by pattern: {$filePath}");
                continue;
            }

            // Check if file type is supported
            if (!$context->isSupportedFileType($filePath)) {
                $this->logger->debug("File type not supported: {$filePath}");
                continue;
            }

            // Check file size
            if (!$context->isFileSizeAcceptable($filePath)) {
                $this->logger->debug("File size not acceptable: {$filePath}");
                continue;
            }

            $validFiles[] = $filePath;
        }
        
        $this->logger->info("filterFiles: input=" . count($filePaths) . " valid=" . count($validFiles));

        return $validFiles;
    }

    /**
     * Scan files sequentially
     */
    private function scanFilesSequentially(array $filePaths, ScanContext $context): array
    {
        $results = [];
        $total = count($filePaths);

        foreach ($filePaths as $index => $filePath) {
            $result = $this->fileScanner->scanFile($filePath, $context);
            $results[] = $result;

            // Report progress
            if ($this->progressCallback) {
                call_user_func($this->progressCallback, $index + 1, $total, $filePath);
            }

            // Memory optimization
            if ($context->isMemoryOptimizationEnabled() && ($index % 100) === 0) {
                $this->performMemoryCleanup();
            }
        }

        return $results;
    }

    /**
     * Post-process scan results
     */
    private function postProcessResults(array $results, ScanContext $context): array
    {
        // Apply baseline comparison
        if ($this->baseline) {
            $results = $this->applyBaselineComparison($results);
        }

        // Sort results if requested
        if ($context->getScanOption('sort_results', false)) {
            usort($results, function($a, $b) {
                return strcmp($a->getFilePath(), $b->getFilePath());
            });
        }

        // Calculate aggregate metrics
        $this->calculateAggregateMetrics($results);

        return $results;
    }

    /**
     * Apply baseline comparison to results
     */
    private function applyBaselineComparison(array $results): array
    {
        foreach ($results as $result) {
            $filePath = $result->getFilePath();
            
            if (isset($this->baseline[$filePath])) {
                $baselineResult = ScanResult::fromArray($this->baseline[$filePath]);
                $comparison = $result->compareWithBaseline($baselineResult);
                $result->setMetadata('baseline_comparison', $comparison);
            } else {
                // New file not in baseline
                $result->setMetadata('baseline_comparison', [
                    'new_file' => true,
                    'new' => $result->getIssues(),
                    'resolved' => [],
                    'unchanged' => [],
                ]);
            }
        }

        return $results;
    }

    /**
     * Load baseline data from file
     */
    public function loadBaseline(string $baselinePath): void
    {
        try {
            if (!file_exists($baselinePath)) {
                $this->logger->warning("Baseline file not found: {$baselinePath}");
                return;
            }

            $baselineData = json_decode(file_get_contents($baselinePath), true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception("Invalid JSON in baseline file: " . json_last_error_msg());
            }

            $this->baseline = $baselineData['results'] ?? [];
            $this->logger->info("Loaded baseline with " . count($this->baseline) . " file results");

        } catch (\Exception $e) {
            $this->logger->error("Failed to load baseline: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Save results as baseline
     */
    public function saveBaseline(array $results, string $baselinePath): void
    {
        try {
            $baselineData = [
                'created_at' => (new \DateTime())->format(\DateTime::ISO8601),
                'version' => '1.0',
                'total_files' => count($results),
                'results' => [],
            ];

            foreach ($results as $result) {
                $baselineData['results'][$result->getFilePath()] = $result->toArray();
            }

            $jsonData = json_encode($baselineData, JSON_PRETTY_PRINT);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception("Failed to encode baseline data: " . json_last_error_msg());
            }

            file_put_contents($baselinePath, $jsonData);
            $this->logger->info("Saved baseline to: {$baselinePath}");

        } catch (\Exception $e) {
            $this->logger->error("Failed to save baseline: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Create scan context from options
     */
    private function createScanContext(array $options = []): ScanContext
    {
        // Merge with configuration settings
        $config = $this->configManager->loadConfig();
        
        $contextOptions = array_merge([
            'include_patterns' => $config['paths']['include_patterns'] ?? [],
            'exclude_patterns' => $config['paths']['exclude_paths'] ?? [],
            'max_file_size' => $config['performance']['max_file_size'] ?? 10485760,
            'parallel_processing' => $config['performance']['parallel_processing'] ?? true,
            'max_workers' => $config['performance']['max_workers'] ?? 4,
            'progress_tracking' => true,
            'memory_optimization' => true,
            'analyzers' => array_keys($config['analyzers'] ?? []),
        ], $options);

        return new ScanContext($contextOptions);
    }

    /**
     * Initialize scan metrics
     */
    private function initializeMetrics(): void
    {
        $this->scanMetrics = [
            'scan_start_time' => microtime(true),
            'files_scanned' => 0,
            'directories_scanned' => 0,
            'total_scan_time' => 0.0,
            'total_issues_found' => 0,
            'memory_peak_usage' => 0,
            'analyzers_used' => count($this->analyzers),
        ];
    }

    /**
     * Update scan metrics
     */
    private function updateScanMetrics(string $key, $value): void
    {
        if (isset($this->scanMetrics[$key])) {
            $this->scanMetrics[$key] += $value;
        } else {
            $this->scanMetrics[$key] = $value;
        }

        // Update peak memory usage
        $currentMemory = memory_get_peak_usage(true);
        if ($currentMemory > $this->scanMetrics['memory_peak_usage']) {
            $this->scanMetrics['memory_peak_usage'] = $currentMemory;
        }
    }

    /**
     * Calculate aggregate metrics from results
     */
    private function calculateAggregateMetrics(array $results): void
    {
        $totalIssues = 0;
        $issuesBySeverity = ['critical' => 0, 'error' => 0, 'warning' => 0, 'info' => 0];
        $issuesByCategory = [];

        foreach ($results as $result) {
            $totalIssues += $result->getIssueCount();
            
            // Count by severity
            $severityCounts = $result->getIssueCountBySeverity();
            foreach ($severityCounts as $severity => $count) {
                $issuesBySeverity[$severity] += $count;
            }

            // Count by category
            foreach ($result->getIssues() as $issue) {
                $category = $issue['category'] ?? 'general';
                $issuesByCategory[$category] = ($issuesByCategory[$category] ?? 0) + 1;
            }
        }

        $this->scanMetrics['total_issues_found'] = $totalIssues;
        $this->scanMetrics['issues_by_severity'] = $issuesBySeverity;
        $this->scanMetrics['issues_by_category'] = $issuesByCategory;
    }

    /**
     * Perform memory cleanup
     */
    private function performMemoryCleanup(): void
    {
        gc_collect_cycles();
        
        $currentMemory = memory_get_usage(true);
        $this->logger->debug("Memory cleanup performed. Current usage: " . $this->formatBytes($currentMemory));
    }

    /**
     * Format bytes to human readable format
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $unitIndex = 0;
        
        while ($bytes >= 1024 && $unitIndex < count($units) - 1) {
            $bytes /= 1024;
            $unitIndex++;
        }
        
        return round($bytes, 2) . ' ' . $units[$unitIndex];
    }

    /**
     * Set progress callback
     */
    public function setProgressCallback(callable $callback): void
    {
        $this->progressCallback = $callback;
    }

    /**
     * Get scan metrics
     */
    public function getScanMetrics(): array
    {
        $metrics = $this->scanMetrics;
        $metrics['scan_duration'] = microtime(true) - $metrics['scan_start_time'];
        $metrics['memory_peak_usage_formatted'] = $this->formatBytes($metrics['memory_peak_usage']);
        
        return $metrics;
    }

    /**
     * Get engine statistics
     */
    public function getEngineStats(): array
    {
        return [
            'analyzers_configured' => count($this->analyzers),
            'analyzer_names' => array_map(function($analyzer) {
                return $analyzer->getName();
            }, $this->analyzers),
            'parallel_scanner_stats' => $this->parallelScanner->getWorkerStats(),
            'file_scanner_stats' => $this->fileScanner->getMetrics(),
            'baseline_loaded' => $this->baseline !== null,
            'baseline_file_count' => $this->baseline ? count($this->baseline) : 0,
        ];
    }

    /**
     * Reset engine state
     */
    public function reset(): void
    {
        $this->baseline = null;
        $this->progressCallback = null;
        $this->initializeMetrics();
        $this->fileScanner->resetMetrics();
        $this->parallelScanner->cleanup();
    }

    /**
     * Cleanup resources
     */
    public function cleanup(): void
    {
        $this->parallelScanner->cleanup();
        $this->reset();
    }

    /**
     * Destructor
     */
    public function __destruct()
    {
        $this->cleanup();
    }
} 
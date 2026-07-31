<?php

declare(strict_types=1);

namespace ACQC\Core\Scanner;

use ACQC\Utils\PerformanceProfiler;
use ACQC\Utils\MemoryManager;
use ACQC\Utils\CacheManager;
use ACQC\Utils\ParallelProcessor;
use ACQC\Core\Rules\RuleEngine;
use ACQC\Core\AnalyzerRegistry;
use Psr\Log\LoggerInterface;

/**
 * Optimized Scanner Engine
 * 
 * High-performance scanner that integrates all optimization utilities:
 * - Performance profiling and monitoring
 * - Memory management and optimization
 * - Result caching for faster subsequent scans
 * - Parallel processing for large codebases
 * - Incremental analysis support
 */
class OptimizedScannerEngine extends ScannerEngine
{
    private PerformanceProfiler $profiler;
    private MemoryManager $memoryManager;
    private CacheManager $cacheManager;
    private ParallelProcessor $parallelProcessor;
    private array $optimizationConfig;
    private bool $incrementalMode = false;

    public function __construct(
        RuleEngine $ruleEngine,
        AnalyzerRegistry $analyzerRegistry = null,
        LoggerInterface $logger = null,
        array $optimizationConfig = []
    ) {
        parent::__construct($ruleEngine, $analyzerRegistry, $logger);
        
        $this->profiler = new PerformanceProfiler($this->logger);
        $this->memoryManager = new MemoryManager($this->logger);
        $this->cacheManager = new CacheManager(null, [], $this->logger);
        $this->parallelProcessor = new ParallelProcessor(null, 300, $this->logger, $this->profiler);
        
        $this->optimizationConfig = array_merge([
            'enable_caching' => true,
            'enable_parallel' => true,
            'enable_incremental' => true,
            'max_parallel_processes' => 8,
            'cache_ttl' => 3600,
            'memory_threshold' => 0.8,
            'batch_size' => 50,
            'chunk_size' => 8192
        ], $optimizationConfig);
    }

    /**
     * Scan with full optimization
     */
    public function scan(string $path, array $options = []): ScanResult
    {
        $this->profiler->startTimer('optimized_scan');
        $this->profiler->takeMemorySnapshot('scan_start');
        
        try {
            // Optimize memory settings for large scans
            $originalSettings = $this->memoryManager->optimizeForLargeOperation();
            
            // Prepare scan context
            $scanContext = $this->prepareScanContext($path, $options);
            
            // Determine scan strategy
            $strategy = $this->determineScanStrategy($scanContext);
            
            $this->logger->info('Starting optimized scan', [
                'path' => $path,
                'strategy' => $strategy,
                'files' => count($scanContext->getFiles()),
                'analyzers' => $scanContext->getAnalyzers()
            ]);

            // Execute scan based on strategy
            switch ($strategy) {
                case 'cached':
                    $result = $this->performCachedScan($scanContext);
                    break;
                case 'incremental':
                    $result = $this->performIncrementalScan($scanContext);
                    break;
                case 'parallel':
                    $result = $this->performParallelScan($scanContext);
                    break;
                default:
                    $result = $this->performOptimizedSequentialScan($scanContext);
            }

            // Restore original settings
            $this->memoryManager->restoreSettings($originalSettings);
            
            $this->profiler->takeMemorySnapshot('scan_end');
            $duration = $this->profiler->stopTimer('optimized_scan');
            
            // Add performance metrics to result
            $this->addPerformanceMetrics($result, $duration);
            
            $this->logger->info('Optimized scan completed', [
                'duration' => $duration,
                'violations' => count($result->getViolations()),
                'files_scanned' => count($scanContext->getFiles())
            ]);

            return $result;

        } catch (\Throwable $e) {
            $this->profiler->stopTimer('optimized_scan');
            $this->logger->error('Optimized scan failed', [
                'error' => $e->getMessage(),
                'path' => $path
            ]);
            throw $e;
        }
    }

    /**
     * Enable incremental scanning mode
     */
    public function enableIncrementalMode(bool $enabled = true): self
    {
        $this->incrementalMode = $enabled;
        return $this;
    }

    /**
     * Warm up cache for common files
     */
    public function warmUpCache(array $files, array $analyzers): array
    {
        $this->profiler->startTimer('cache_warmup');
        
        $this->logger->info('Starting cache warm-up', [
            'files' => count($files),
            'analyzers' => $analyzers
        ]);

        $warmupResults = $this->cacheManager->warmUp($files, $analyzers);
        
        // Analyze files that need caching
        $needsAnalysis = array_filter($warmupResults, fn($r) => $r['needs_analysis']);
        
        if (!empty($needsAnalysis)) {
            $filesToAnalyze = array_column($needsAnalysis, 'file');
            
            // Use parallel processing for warmup
            $this->parallelProcessor->processFiles($filesToAnalyze, function($file) use ($analyzers) {
                $context = new ScanContext($file);
                $context->setAnalyzers($analyzers);
                
                $result = $this->analyzeFile($context);
                
                // Cache the result
                if ($this->optimizationConfig['enable_caching']) {
                    $this->cacheManager->cacheAnalysisResult($file, $analyzers, $result->toArray());
                }
                
                return $result;
            });
        }

        $duration = $this->profiler->stopTimer('cache_warmup');
        
        $this->logger->info('Cache warm-up completed', [
            'duration' => $duration,
            'cached_files' => count($warmupResults) - count($needsAnalysis),
            'analyzed_files' => count($needsAnalysis)
        ]);

        return $warmupResults;
    }

    /**
     * Get comprehensive performance report
     */
    public function getPerformanceReport(): array
    {
        return [
            'profiler' => $this->profiler->getSummary(),
            'memory' => $this->memoryManager->getMemoryInfo(),
            'cache' => $this->cacheManager->getStats(),
            'parallel' => $this->parallelProcessor->getStatistics(),
            'optimization_config' => $this->optimizationConfig
        ];
    }

    /**
     * Prepare optimized scan context
     */
    private function prepareScanContext(string $path, array $options): OptimizedScanContext
    {
        $context = new OptimizedScanContext($path, $options);
        
        // Set optimization options
        $context->setEnableCaching($this->optimizationConfig['enable_caching']);
        $context->setEnableParallel($this->optimizationConfig['enable_parallel']);
        $context->setBatchSize($this->optimizationConfig['batch_size']);
        
        // Discover files efficiently
        $this->profiler->startTimer('file_discovery');
        $files = $this->discoverFiles($path, $options);
        $context->setFiles($files);
        $this->profiler->stopTimer('file_discovery');
        
        // Filter changed files for incremental mode
        if ($this->incrementalMode && $this->optimizationConfig['enable_incremental']) {
            $changedFiles = $this->findChangedFiles($files);
            $context->setChangedFiles($changedFiles);
        }

        return $context;
    }

    /**
     * Determine optimal scan strategy
     */
    private function determineScanStrategy(OptimizedScanContext $context): string
    {
        $fileCount = count($context->getFiles());
        
        // Check cache coverage
        if ($this->optimizationConfig['enable_caching']) {
            $cacheHitRate = $this->calculateCacheHitRate($context);
            if ($cacheHitRate > 0.8) {
                return 'cached';
            }
        }
        
        // Check incremental opportunity
        if ($this->incrementalMode && $context->hasChangedFiles() && $fileCount > 0) {
            $changedRatio = count($context->getChangedFiles()) / $fileCount;
            if ($changedRatio < 0.3) { // Less than 30% changed
                return 'incremental';
            }
        }
        
        // Determine parallel vs sequential
        if ($this->optimizationConfig['enable_parallel'] && $fileCount > 20) {
            return 'parallel';
        }
        
        return 'sequential';
    }

    /**
     * Perform cached scan
     */
    private function performCachedScan(OptimizedScanContext $context): ScanResult
    {
        $this->profiler->startTimer('cached_scan');
        
        $files = $context->getFiles();
        $analyzers = $context->getAnalyzers();
        $allViolations = [];
        $metrics = [];

        // Process files in batches
        foreach ($this->memoryManager->processBatch($files, function($fileBatch) use ($analyzers, &$allViolations, &$metrics) {
            
            foreach ($fileBatch as $file) {
                // Try cache first
                $cachedResult = $this->cacheManager->getCachedAnalysisResult($file, $analyzers);
                
                if ($cachedResult !== null) {
                    $this->profiler->incrementCounter('cache_hits');
                    $allViolations = array_merge($allViolations, $cachedResult['violations'] ?? []);
                    $metrics = array_merge($metrics, $cachedResult['metrics'] ?? []);
                } else {
                    // Analyze and cache
                    $this->profiler->incrementCounter('cache_misses');
                    $fileContext = new ScanContext($file);
                    $fileContext->setAnalyzers($analyzers);
                    
                    $result = $this->analyzeFile($fileContext);
                    $resultArray = $result->toArray();
                    
                    $allViolations = array_merge($allViolations, $result->getViolations());
                    $metrics = array_merge($metrics, $resultArray['metrics'] ?? []);
                    
                    // Cache for future use
                    $this->cacheManager->cacheAnalysisResult($file, $analyzers, $resultArray);
                }
            }
            
        }, $context->getBatchSize()) as $batchResult) {
            // Memory management during processing
            $this->memoryManager->checkMemoryUsage();
        }

        $duration = $this->profiler->stopTimer('cached_scan');
        
        return new ScanResult($allViolations, true, $metrics);
    }

    /**
     * Perform incremental scan
     */
    private function performIncrementalScan(OptimizedScanContext $context): ScanResult
    {
        $this->profiler->startTimer('incremental_scan');
        
        $changedFiles = $context->getChangedFiles();
        $allFiles = $context->getFiles();
        $analyzers = $context->getAnalyzers();
        
        $this->logger->info('Performing incremental scan', [
            'total_files' => count($allFiles),
            'changed_files' => count($changedFiles),
            'change_ratio' => count($allFiles) > 0 ? count($changedFiles) / count($allFiles) : 0.0
        ]);

        // Load cached results for unchanged files
        $unchangedFiles = array_diff($allFiles, $changedFiles);
        $cachedViolations = [];
        $cachedMetrics = [];
        
        foreach ($unchangedFiles as $file) {
            $cached = $this->cacheManager->getCachedAnalysisResult($file, $analyzers);
            if ($cached !== null) {
                $cachedViolations = array_merge($cachedViolations, $cached['violations'] ?? []);
                $cachedMetrics = array_merge($cachedMetrics, $cached['metrics'] ?? []);
            }
        }

        // Analyze only changed files
        $changedResults = $this->performParallelFileAnalysis($changedFiles, $analyzers);
        
        // Update cache for changed files
        foreach ($changedFiles as $file) {
            // Cache invalidation and update would happen here
            $this->cacheManager->invalidateFile($file);
        }

        $duration = $this->profiler->stopTimer('incremental_scan');
        
        // Merge results
        $allViolations = array_merge($cachedViolations, $changedResults['violations'] ?? []);
        $allMetrics = array_merge($cachedMetrics, $changedResults['metrics'] ?? []);
        
        return new ScanResult($allViolations, true, $allMetrics);
    }

    /**
     * Perform parallel scan
     */
    private function performParallelScan(OptimizedScanContext $context): ScanResult
    {
        $this->profiler->startTimer('parallel_scan');
        
        $files = $context->getFiles();
        $analyzers = $context->getAnalyzers();
        
        $this->logger->info('Performing parallel scan', [
            'files' => count($files),
            'max_processes' => $this->optimizationConfig['max_parallel_processes']
        ]);

        $results = $this->performParallelFileAnalysis($files, $analyzers);
        
        $duration = $this->profiler->stopTimer('parallel_scan');
        
        return new ScanResult($results['violations'] ?? [], true, $results['metrics'] ?? []);
    }

    /**
     * Perform optimized sequential scan
     */
    private function performOptimizedSequentialScan(OptimizedScanContext $context): ScanResult
    {
        $this->profiler->startTimer('sequential_scan');
        
        $files = $context->getFiles();
        $analyzers = $context->getAnalyzers();
        $allViolations = [];
        $metrics = [];

        // Use memory-efficient batch processing
        foreach ($this->memoryManager->processBatch($files, function($fileBatch) use ($analyzers, &$allViolations, &$metrics) {
            
            foreach ($fileBatch as $file) {
                $fileContext = new ScanContext($file);
                $fileContext->setAnalyzers($analyzers);
                
                $result = $this->analyzeFile($fileContext);
                $allViolations = array_merge($allViolations, $result->getViolations());
                
                // Cache if enabled
                if ($this->optimizationConfig['enable_caching']) {
                    $this->cacheManager->cacheAnalysisResult($file, $analyzers, $result->toArray());
                }
            }
            
        }, $context->getBatchSize()) as $batchResult) {
            $this->memoryManager->checkMemoryUsage();
        }

        $duration = $this->profiler->stopTimer('sequential_scan');
        
        return new ScanResult($allViolations, true, $metrics);
    }

    /**
     * Helper method for parallel file analysis
     */
    private function performParallelFileAnalysis(array $files, array $analyzers): array
    {
        $allViolations = [];
        $allMetrics = [];

        $results = $this->parallelProcessor->processFiles($files, function($file) use ($analyzers) {
            $context = new ScanContext($file);
            $context->setAnalyzers($analyzers);
            
            return $this->analyzeFile($context);
        });

        foreach ($results['completed'] as $result) {
            if (isset($result['result'])) {
                $scanResult = $result['result'];
                $allViolations = array_merge($allViolations, $scanResult->getViolations());
                $allMetrics = array_merge($allMetrics, $scanResult->getMetrics());
            }
        }

        return [
            'violations' => $allViolations,
            'metrics' => $allMetrics,
            'statistics' => $results['statistics']
        ];
    }

    /**
     * Calculate cache hit rate for context
     */
    private function calculateCacheHitRate(OptimizedScanContext $context): float
    {
        $files = $context->getFiles();
        $analyzers = $context->getAnalyzers();
        $hits = 0;

        foreach ($files as $file) {
            if ($this->cacheManager->getCachedAnalysisResult($file, $analyzers) !== null) {
                $hits++;
            }
        }

        return count($files) > 0 ? $hits / count($files) : 0;
    }

    /**
     * Find files that have changed since last scan
     */
    private function findChangedFiles(array $files): array
    {
        $changed = [];
        
        foreach ($files as $file) {
            // Check modification time, git status, etc.
            if (file_exists($file)) {
                $mtime = filemtime($file);
                $lastScanTime = $this->getLastScanTime($file);
                
                if ($mtime > $lastScanTime) {
                    $changed[] = $file;
                }
            }
        }

        return $changed;
    }

    /**
     * Get last scan time for file
     */
    private function getLastScanTime(string $file): int
    {
        // This would typically be stored in a database or cache
        // For now, use a simple file-based approach
        $scanTimeFile = sys_get_temp_dir() . '/acqc_scantimes/' . md5($file);
        
        if (file_exists($scanTimeFile)) {
            return (int)file_get_contents($scanTimeFile);
        }
        
        return 0;
    }

    /**
     * Add performance metrics to scan result
     */
    private function addPerformanceMetrics(ScanResult $result, float $duration): void
    {
        $memoryInfo = $this->memoryManager->getMemoryInfo();
        $cacheStats = $this->cacheManager->getStats();
        
        $performanceMetrics = [
            'scan_duration' => $duration,
            'memory_peak' => $memoryInfo['peak'],
            'memory_usage' => $memoryInfo['current'],
            'cache_hit_ratio' => $cacheStats['requests']['hit_ratio'] ?? 0,
            'profiler_summary' => $this->profiler->getSummary()
        ];
        
        $result->addMetrics($performanceMetrics);
    }
}

/**
 * Optimized Scan Context
 */
class OptimizedScanContext extends ScanContext
{
    private bool $enableCaching = true;
    private bool $enableParallel = true;
    private int $batchSize = 50;
    private array $changedFiles = [];

    public function setEnableCaching(bool $enable): void
    {
        $this->enableCaching = $enable;
    }

    public function setEnableParallel(bool $enable): void
    {
        $this->enableParallel = $enable;
    }

    public function setBatchSize(int $size): void
    {
        $this->batchSize = $size;
    }

    public function getBatchSize(): int
    {
        return $this->batchSize;
    }

    public function setChangedFiles(array $files): void
    {
        $this->changedFiles = $files;
    }

    public function getChangedFiles(): array
    {
        return $this->changedFiles;
    }

    public function hasChangedFiles(): bool
    {
        return !empty($this->changedFiles);
    }
} 
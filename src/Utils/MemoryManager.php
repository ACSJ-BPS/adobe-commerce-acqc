<?php

declare(strict_types=1);

namespace ACQC\Utils;

use Psr\Log\LoggerInterface;

/**
 * Memory Manager
 * 
 * Comprehensive memory management utility for ACQC.
 * Provides memory optimization, monitoring, and cleanup functionality.
 */
class MemoryManager
{
    private LoggerInterface $logger;
    private array $memoryPools = [];
    private array $cleanupCallbacks = [];
    private int $memoryLimit;
    private float $warningThreshold = 0.8; // 80% of limit
    private float $criticalThreshold = 0.9; // 90% of limit
    private bool $autoCleanup = true;
    private array $allocationTracker = [];

    // Memory optimization settings
    private const CHUNK_SIZE = 8192; // 8KB chunks for file processing
    private const MAX_CACHE_SIZE = 50 * 1024 * 1024; // 50MB cache limit
    private const CLEANUP_INTERVAL = 100; // Operations between cleanup checks

    public function __construct(LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new \Psr\Log\NullLogger();
        $this->memoryLimit = $this->parseMemoryLimit(ini_get('memory_limit'));
        
        // Register shutdown function for cleanup
        register_shutdown_function([$this, 'cleanup']);
    }

    /**
     * Create a memory pool for grouped allocations
     */
    public function createPool(string $name): MemoryPool
    {
        if (isset($this->memoryPools[$name])) {
            throw new \InvalidArgumentException("Memory pool '$name' already exists");
        }

        $pool = new MemoryPool($name, $this);
        $this->memoryPools[$name] = $pool;

        $this->logger->debug("Created memory pool: $name");

        return $pool;
    }

    /**
     * Get existing memory pool
     */
    public function getPool(string $name): ?MemoryPool
    {
        return $this->memoryPools[$name] ?? null;
    }

    /**
     * Process large files in memory-efficient chunks
     */
    public function processFileInChunks(string $filePath, callable $processor, int $chunkSize = null): \Generator
    {
        $chunkSize = $chunkSize ?? self::CHUNK_SIZE;
        
        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException("File not found: $filePath");
        }

        $fileSize = filesize($filePath);
        $this->logger->debug("Processing file in chunks", [
            'file' => $filePath,
            'size' => $fileSize,
            'chunk_size' => $chunkSize
        ]);

        $handle = fopen($filePath, 'r');
        if (!$handle) {
            throw new \RuntimeException("Cannot open file: $filePath");
        }

        try {
            $chunkNumber = 0;
            while (!feof($handle)) {
                $chunk = fread($handle, $chunkSize);
                if ($chunk === false) {
                    break;
                }

                $this->checkMemoryUsage();
                
                yield $chunkNumber => $processor($chunk, $chunkNumber);
                
                $chunkNumber++;
                
                // Force garbage collection periodically
                if ($chunkNumber % 50 === 0) {
                    $this->forceGarbageCollection();
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Process large arrays in memory-efficient batches
     */
    public function processBatch(array $items, callable $processor, int $batchSize = 100): \Generator
    {
        $total = count($items);
        $this->logger->debug("Processing batch", [
            'total_items' => $total,
            'batch_size' => $batchSize
        ]);

        $chunks = array_chunk($items, $batchSize);
        
        foreach ($chunks as $batchNumber => $batch) {
            $this->checkMemoryUsage();
            
            yield $batchNumber => $processor($batch, $batchNumber);
            
            // Clear processed items from memory
            unset($batch);
            
            if ($batchNumber % 10 === 0) {
                $this->forceGarbageCollection();
            }
        }
    }

    /**
     * Create a memory-efficient iterator for large datasets
     */
    public function createIterator(iterable $data, callable $processor = null): \Generator
    {
        $count = 0;
        foreach ($data as $key => $value) {
            $this->checkMemoryUsage();
            
            if ($processor) {
                yield $key => $processor($value, $key);
            } else {
                yield $key => $value;
            }
            
            $count++;
            
            // Periodic cleanup
            if ($count % self::CLEANUP_INTERVAL === 0) {
                if ($this->autoCleanup) {
                    $this->performAutoCleanup();
                }
            }
        }
    }

    /**
     * Register cleanup callback
     */
    public function registerCleanupCallback(callable $callback, string $name = null): void
    {
        $name = $name ?? uniqid('cleanup_');
        $this->cleanupCallbacks[$name] = $callback;
        
        $this->logger->debug("Registered cleanup callback: $name");
    }

    /**
     * Monitor memory allocation
     */
    public function trackAllocation(string $name, int $size): void
    {
        $this->allocationTracker[$name] = [
            'size' => $size,
            'timestamp' => microtime(true),
            'memory_before' => memory_get_usage(true),
            'memory_after' => memory_get_usage(true) + $size
        ];
    }

    /**
     * Release tracked allocation
     */
    public function releaseAllocation(string $name): void
    {
        if (isset($this->allocationTracker[$name])) {
            unset($this->allocationTracker[$name]);
        }
    }

    /**
     * Get current memory usage information
     */
    public function getMemoryInfo(): array
    {
        $current = memory_get_usage(true);
        $peak = memory_get_peak_usage(true);
        
        return [
            'current' => $current,
            'peak' => $peak,
            'limit' => $this->memoryLimit,
            'current_formatted' => $this->formatBytes($current),
            'peak_formatted' => $this->formatBytes($peak),
            'limit_formatted' => $this->formatBytes($this->memoryLimit),
            'usage_percentage' => $this->memoryLimit > 0 ? ($current / $this->memoryLimit) * 100 : 0,
            'peak_percentage' => $this->memoryLimit > 0 ? ($peak / $this->memoryLimit) * 100 : 0,
            'available' => max(0, $this->memoryLimit - $current),
            'available_formatted' => $this->formatBytes(max(0, $this->memoryLimit - $current)),
            'warning_threshold' => $this->warningThreshold * 100,
            'critical_threshold' => $this->criticalThreshold * 100,
            'pools' => count($this->memoryPools),
            'tracked_allocations' => count($this->allocationTracker)
        ];
    }

    /**
     * Check memory usage and trigger warnings if necessary
     */
    public function checkMemoryUsage(): bool
    {
        if ($this->memoryLimit <= 0) {
            return true; // No limit set
        }

        $current = memory_get_usage(true);
        $usagePercentage = $current / $this->memoryLimit;

        if ($usagePercentage >= $this->criticalThreshold) {
            $this->logger->critical('Critical memory usage reached', [
                'current' => $this->formatBytes($current),
                'limit' => $this->formatBytes($this->memoryLimit),
                'percentage' => round($usagePercentage * 100, 2)
            ]);
            
            // Force cleanup
            $this->performEmergencyCleanup();
            return false;
            
        } elseif ($usagePercentage >= $this->warningThreshold) {
            $this->logger->warning('High memory usage detected', [
                'current' => $this->formatBytes($current),
                'limit' => $this->formatBytes($this->memoryLimit),
                'percentage' => round($usagePercentage * 100, 2)
            ]);
            
            if ($this->autoCleanup) {
                $this->performAutoCleanup();
            }
        }

        return true;
    }

    /**
     * Force garbage collection
     */
    public function forceGarbageCollection(): int
    {
        $cyclesBefore = gc_collect_cycles();
        $memoryBefore = memory_get_usage(true);
        
        gc_collect_cycles();
        
        $cyclesAfter = gc_collect_cycles();
        $memoryAfter = memory_get_usage(true);
        $memoryFreed = $memoryBefore - $memoryAfter;

        $this->logger->debug('Garbage collection completed', [
            'cycles_collected' => $cyclesAfter - $cyclesBefore,
            'memory_freed' => $this->formatBytes($memoryFreed)
        ]);

        return $memoryFreed;
    }

    /**
     * Optimize memory settings for large operations
     */
    public function optimizeForLargeOperation(): array
    {
        $originalSettings = [];
        
        // Store original settings
        $originalSettings['memory_limit'] = ini_get('memory_limit');
        $originalSettings['max_execution_time'] = ini_get('max_execution_time');
        
        // Optimize settings
        if ($this->memoryLimit < 512 * 1024 * 1024) { // Less than 512MB
            ini_set('memory_limit', '512M');
            $this->memoryLimit = 512 * 1024 * 1024;
        }
        
        ini_set('max_execution_time', '300'); // 5 minutes
        
        $this->logger->info('Memory optimized for large operation', [
            'new_memory_limit' => ini_get('memory_limit'),
            'new_execution_time' => ini_get('max_execution_time')
        ]);

        return $originalSettings;
    }

    /**
     * Restore original memory settings
     */
    public function restoreSettings(array $originalSettings): void
    {
        foreach ($originalSettings as $setting => $value) {
            ini_set($setting, $value);
        }
        
        $this->memoryLimit = $this->parseMemoryLimit($originalSettings['memory_limit']);
        
        $this->logger->info('Memory settings restored');
    }

    /**
     * Perform automatic cleanup
     */
    public function performAutoCleanup(): void
    {
        $memoryBefore = memory_get_usage(true);
        
        // Clean up expired pools
        foreach ($this->memoryPools as $name => $pool) {
            if ($pool->shouldCleanup()) {
                $pool->cleanup();
            }
        }
        
        // Run registered cleanup callbacks
        foreach ($this->cleanupCallbacks as $name => $callback) {
            try {
                $callback();
            } catch (\Throwable $e) {
                $this->logger->warning("Cleanup callback failed: $name", [
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        // Force garbage collection
        $this->forceGarbageCollection();
        
        $memoryAfter = memory_get_usage(true);
        $memoryFreed = $memoryBefore - $memoryAfter;
        
        $this->logger->debug('Auto cleanup completed', [
            'memory_freed' => $this->formatBytes($memoryFreed)
        ]);
    }

    /**
     * Perform emergency cleanup when memory is critically low
     */
    public function performEmergencyCleanup(): void
    {
        $this->logger->warning('Performing emergency memory cleanup');
        
        $memoryBefore = memory_get_usage(true);
        
        // Aggressively clean all pools
        foreach ($this->memoryPools as $pool) {
            $pool->forceCleanup();
        }
        
        // Clear allocation tracker
        $this->allocationTracker = [];
        
        // Run all cleanup callbacks
        foreach ($this->cleanupCallbacks as $callback) {
            try {
                $callback();
            } catch (\Throwable $e) {
                // Ignore errors during emergency cleanup
            }
        }
        
        // Multiple garbage collection cycles
        for ($i = 0; $i < 3; $i++) {
            gc_collect_cycles();
        }
        
        $memoryAfter = memory_get_usage(true);
        $memoryFreed = $memoryBefore - $memoryAfter;
        
        $this->logger->info('Emergency cleanup completed', [
            'memory_freed' => $this->formatBytes($memoryFreed),
            'current_usage' => $this->formatBytes($memoryAfter)
        ]);
    }

    /**
     * Cleanup all resources
     */
    public function cleanup(): void
    {
        foreach ($this->memoryPools as $pool) {
            $pool->cleanup();
        }
        
        $this->memoryPools = [];
        $this->cleanupCallbacks = [];
        $this->allocationTracker = [];
        
        $this->forceGarbageCollection();
    }

    /**
     * Set memory thresholds
     */
    public function setThresholds(float $warning, float $critical): void
    {
        if ($warning < 0 || $warning > 1 || $critical < 0 || $critical > 1) {
            throw new \InvalidArgumentException('Thresholds must be between 0 and 1');
        }
        
        if ($warning >= $critical) {
            throw new \InvalidArgumentException('Warning threshold must be less than critical threshold');
        }
        
        $this->warningThreshold = $warning;
        $this->criticalThreshold = $critical;
    }

    /**
     * Enable or disable auto cleanup
     */
    public function setAutoCleanup(bool $enabled): void
    {
        $this->autoCleanup = $enabled;
    }

    /**
     * Parse memory limit from ini setting
     */
    private function parseMemoryLimit(string $memoryLimit): int
    {
        if ($memoryLimit === '-1') {
            return PHP_INT_MAX; // No limit
        }
        
        $memoryLimit = trim($memoryLimit);
        $last = strtolower($memoryLimit[strlen($memoryLimit) - 1]);
        $value = (int)$memoryLimit;
        
        switch ($last) {
            case 'g':
                $value *= 1024;
                // no break
            case 'm':
                $value *= 1024;
                // no break
            case 'k':
                $value *= 1024;
        }
        
        return $value;
    }

    /**
     * Format bytes to human readable format
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $unitIndex = 0;
        $size = (float)$bytes;

        while ($size >= 1024 && $unitIndex < count($units) - 1) {
            $size /= 1024;
            $unitIndex++;
        }

        return round($size, 2) . ' ' . $units[$unitIndex];
    }
}

/**
 * Memory Pool
 * 
 * Represents a group of related memory allocations that can be managed together.
 */
class MemoryPool
{
    private string $name;
    private MemoryManager $manager;
    private array $allocations = [];
    private int $totalSize = 0;
    private float $createdAt;
    private float $lastAccess;
    private int $maxSize;
    private int $ttl; // Time to live in seconds

    public function __construct(string $name, MemoryManager $manager, int $maxSize = 0, int $ttl = 3600)
    {
        $this->name = $name;
        $this->manager = $manager;
        $this->maxSize = $maxSize;
        $this->ttl = $ttl;
        $this->createdAt = microtime(true);
        $this->lastAccess = microtime(true);
    }

    /**
     * Allocate memory in this pool
     */
    public function allocate(string $key, $data): void
    {
        $size = $this->calculateSize($data);
        
        // Check if allocation would exceed max size
        if ($this->maxSize > 0 && ($this->totalSize + $size) > $this->maxSize) {
            throw new \RuntimeException("Memory pool '$this->name' size limit exceeded");
        }
        
        $this->allocations[$key] = [
            'data' => $data,
            'size' => $size,
            'created_at' => microtime(true)
        ];
        
        $this->totalSize += $size;
        $this->lastAccess = microtime(true);
    }

    /**
     * Get data from pool
     */
    public function get(string $key)
    {
        if (!isset($this->allocations[$key])) {
            return null;
        }
        
        $this->lastAccess = microtime(true);
        return $this->allocations[$key]['data'];
    }

    /**
     * Remove allocation from pool
     */
    public function deallocate(string $key): bool
    {
        if (!isset($this->allocations[$key])) {
            return false;
        }
        
        $this->totalSize -= $this->allocations[$key]['size'];
        unset($this->allocations[$key]);
        
        return true;
    }

    /**
     * Check if pool should be cleaned up
     */
    public function shouldCleanup(): bool
    {
        $age = microtime(true) - $this->lastAccess;
        return $age > $this->ttl;
    }

    /**
     * Cleanup expired allocations
     */
    public function cleanup(): int
    {
        $cleaned = 0;
        $now = microtime(true);
        
        foreach ($this->allocations as $key => $allocation) {
            $age = $now - $allocation['created_at'];
            if ($age > $this->ttl) {
                $this->deallocate($key);
                $cleaned++;
            }
        }
        
        return $cleaned;
    }

    /**
     * Force cleanup of all allocations
     */
    public function forceCleanup(): void
    {
        $this->allocations = [];
        $this->totalSize = 0;
    }

    /**
     * Get pool statistics
     */
    public function getStats(): array
    {
        return [
            'name' => $this->name,
            'allocations' => count($this->allocations),
            'total_size' => $this->totalSize,
            'total_size_formatted' => $this->formatBytes($this->totalSize),
            'max_size' => $this->maxSize,
            'max_size_formatted' => $this->formatBytes($this->maxSize),
            'usage_percentage' => $this->maxSize > 0 ? ($this->totalSize / $this->maxSize) * 100 : 0,
            'created_at' => $this->createdAt,
            'last_access' => $this->lastAccess,
            'age' => microtime(true) - $this->createdAt,
            'ttl' => $this->ttl
        ];
    }

    /**
     * Calculate approximate size of data
     */
    private function calculateSize($data): int
    {
        if (is_string($data)) {
            return strlen($data);
        } elseif (is_array($data)) {
            return strlen(serialize($data));
        } elseif (is_object($data)) {
            return strlen(serialize($data));
        } else {
            return 8; // Approximate size for primitives
        }
    }

    /**
     * Format bytes to human readable format
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $unitIndex = 0;
        $size = (float)$bytes;

        while ($size >= 1024 && $unitIndex < count($units) - 1) {
            $size /= 1024;
            $unitIndex++;
        }

        return round($size, 2) . ' ' . $units[$unitIndex];
    }
} 
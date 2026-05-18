<?php

declare(strict_types=1);

namespace ACQC\Utils;

use Psr\Log\LoggerInterface;

/**
 * Cache Manager
 * 
 * Comprehensive caching system for ACQC analysis results.
 * Supports multiple cache backends, TTL, invalidation, and performance optimization.
 */
class CacheManager
{
    private LoggerInterface $logger;
    private CacheBackend $backend;
    private array $config;
    private array $hitCounts = [];
    private array $missCounts = [];
    private float $totalHitTime = 0.0;
    private float $totalMissTime = 0.0;

    // Cache configuration
    private const DEFAULT_TTL = 3600; // 1 hour
    private const MAX_KEY_LENGTH = 250;
    private const CLEANUP_PROBABILITY = 0.01; // 1% chance of cleanup on operation

    public function __construct(
        CacheBackend $backend = null,
        array $config = [],
        LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new \Psr\Log\NullLogger();
        $this->backend = $backend ?? new FileCacheBackend();
        $this->config = array_merge([
            'default_ttl' => self::DEFAULT_TTL,
            'namespace' => 'acqc',
            'serialize' => true,
            'compress' => false,
            'max_size' => 100 * 1024 * 1024, // 100MB
            'cleanup_probability' => self::CLEANUP_PROBABILITY
        ], $config);

        $this->backend->configure($this->config);
    }

    /**
     * Get cached value
     */
    public function get(string $key, $default = null)
    {
        $startTime = microtime(true);
        $fullKey = $this->buildKey($key);

        try {
            $value = $this->backend->get($fullKey);
            
            if ($value !== null) {
                $this->recordHit($key, microtime(true) - $startTime);
                
                if ($this->config['serialize']) {
                    $value = unserialize($value);
                }
                
                $this->logger->debug("Cache hit: $key");
                return $value;
            }
            
            $this->recordMiss($key, microtime(true) - $startTime);
            $this->logger->debug("Cache miss: $key");
            
            return $default;
            
        } catch (\Exception $e) {
            $this->logger->error("Cache get error for key: $key", [
                'error' => $e->getMessage()
            ]);
            
            $this->recordMiss($key, microtime(true) - $startTime);
            return $default;
        }
    }

    /**
     * Set cached value
     */
    public function set(string $key, $value, int $ttl = null): bool
    {
        $startTime = microtime(true);
        $fullKey = $this->buildKey($key);
        $ttl = $ttl ?? $this->config['default_ttl'];

        try {
            if ($this->config['serialize']) {
                $value = serialize($value);
            }
            
            if ($this->config['compress'] && is_string($value)) {
                $value = gzcompress($value);
            }

            $result = $this->backend->set($fullKey, $value, $ttl);
            
            $duration = microtime(true) - $startTime;
            $this->logger->debug("Cache set: $key", [
                'duration' => $duration,
                'ttl' => $ttl,
                'size' => strlen((string)$value)
            ]);

            // Probabilistic cleanup
            if (mt_rand(1, 100) <= ($this->config['cleanup_probability'] * 100)) {
                $this->cleanup();
            }

            return $result;
            
        } catch (\Exception $e) {
            $this->logger->error("Cache set error for key: $key", [
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }

    /**
     * Delete cached value
     */
    public function delete(string $key): bool
    {
        $fullKey = $this->buildKey($key);

        try {
            $result = $this->backend->delete($fullKey);
            $this->logger->debug("Cache delete: $key");
            return $result;
            
        } catch (\Exception $e) {
            $this->logger->error("Cache delete error for key: $key", [
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }

    /**
     * Check if key exists in cache
     */
    public function has(string $key): bool
    {
        $fullKey = $this->buildKey($key);
        return $this->backend->has($fullKey);
    }

    /**
     * Get or set cached value (cache-aside pattern)
     */
    public function remember(string $key, callable $callback, int $ttl = null)
    {
        $value = $this->get($key);
        
        if ($value !== null) {
            return $value;
        }

        $value = $callback();
        $this->set($key, $value, $ttl);

        return $value;
    }

    /**
     * Get multiple cached values
     */
    public function getMultiple(array $keys, $default = null): array
    {
        $results = [];
        $fullKeys = [];
        
        foreach ($keys as $key) {
            $fullKeys[$key] = $this->buildKey($key);
        }

        try {
            $values = $this->backend->getMultiple(array_values($fullKeys));
            
            foreach ($keys as $originalKey) {
                $fullKey = $fullKeys[$originalKey];
                
                if (isset($values[$fullKey]) && $values[$fullKey] !== null) {
                    $value = $values[$fullKey];
                    
                    if ($this->config['serialize']) {
                        $value = unserialize($value);
                    }
                    
                    $results[$originalKey] = $value;
                    $this->recordHit($originalKey, 0);
                } else {
                    $results[$originalKey] = $default;
                    $this->recordMiss($originalKey, 0);
                }
            }
            
        } catch (\Exception $e) {
            $this->logger->error("Cache getMultiple error", [
                'keys' => $keys,
                'error' => $e->getMessage()
            ]);
            
            // Return defaults for all keys
            foreach ($keys as $key) {
                $results[$key] = $default;
                $this->recordMiss($key, 0);
            }
        }

        return $results;
    }

    /**
     * Set multiple cached values
     */
    public function setMultiple(array $values, int $ttl = null): bool
    {
        $ttl = $ttl ?? $this->config['default_ttl'];
        $prepared = [];

        foreach ($values as $key => $value) {
            $fullKey = $this->buildKey($key);
            
            if ($this->config['serialize']) {
                $value = serialize($value);
            }
            
            if ($this->config['compress'] && is_string($value)) {
                $value = gzcompress($value);
            }
            
            $prepared[$fullKey] = $value;
        }

        try {
            $result = $this->backend->setMultiple($prepared, $ttl);
            
            $this->logger->debug("Cache setMultiple", [
                'count' => count($values),
                'ttl' => $ttl
            ]);

            return $result;
            
        } catch (\Exception $e) {
            $this->logger->error("Cache setMultiple error", [
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }

    /**
     * Clear cache by pattern
     */
    public function clearByPattern(string $pattern): int
    {
        try {
            $count = $this->backend->clearByPattern($this->buildKey($pattern));
            
            $this->logger->info("Cache cleared by pattern", [
                'pattern' => $pattern,
                'count' => $count
            ]);

            return $count;
            
        } catch (\Exception $e) {
            $this->logger->error("Cache clearByPattern error", [
                'pattern' => $pattern,
                'error' => $e->getMessage()
            ]);
            
            return 0;
        }
    }

    /**
     * Clear all cache
     */
    public function clear(): bool
    {
        try {
            $result = $this->backend->clear();
            
            // Reset statistics
            $this->hitCounts = [];
            $this->missCounts = [];
            $this->totalHitTime = 0.0;
            $this->totalMissTime = 0.0;
            
            $this->logger->info("Cache cleared");
            return $result;
            
        } catch (\Exception $e) {
            $this->logger->error("Cache clear error", [
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }

    /**
     * Cleanup expired entries
     */
    public function cleanup(): int
    {
        try {
            $count = $this->backend->cleanup();
            
            $this->logger->debug("Cache cleanup completed", [
                'expired_count' => $count
            ]);

            return $count;
            
        } catch (\Exception $e) {
            $this->logger->error("Cache cleanup error", [
                'error' => $e->getMessage()
            ]);
            
            return 0;
        }
    }

    /**
     * Get cache statistics
     */
    public function getStats(): array
    {
        $totalRequests = array_sum($this->hitCounts) + array_sum($this->missCounts);
        $totalHits = array_sum($this->hitCounts);
        $totalMisses = array_sum($this->missCounts);
        
        $hitRatio = $totalRequests > 0 ? ($totalHits / $totalRequests) * 100 : 0;
        $avgHitTime = $totalHits > 0 ? $this->totalHitTime / $totalHits : 0;
        $avgMissTime = $totalMisses > 0 ? $this->totalMissTime / $totalMisses : 0;

        $backendStats = [];
        try {
            $backendStats = $this->backend->getStats();
        } catch (\Exception $e) {
            $this->logger->warning("Could not get backend stats", [
                'error' => $e->getMessage()
            ]);
        }

        return [
            'requests' => [
                'total' => $totalRequests,
                'hits' => $totalHits,
                'misses' => $totalMisses,
                'hit_ratio' => round($hitRatio, 2)
            ],
            'timing' => [
                'avg_hit_time' => round($avgHitTime * 1000, 3), // milliseconds
                'avg_miss_time' => round($avgMissTime * 1000, 3), // milliseconds
                'total_hit_time' => round($this->totalHitTime, 3),
                'total_miss_time' => round($this->totalMissTime, 3)
            ],
            'backend' => $backendStats,
            'config' => $this->config
        ];
    }

    /**
     * Generate cache key for analysis results
     */
    public function generateAnalysisKey(string $filePath, array $analyzers, array $options = []): string
    {
        sort($analyzers);
        $components = [
            'file' => $filePath,
            'mtime' => file_exists($filePath) ? filemtime($filePath) : 0,
            'analyzers' => implode(',', $analyzers),
            'options' => md5(serialize($options)),
            'version' => '1.0' // Cache version for invalidation
        ];

        return 'analysis:' . md5(serialize($components));
    }

    /**
     * Generate cache key for file content
     */
    public function generateFileKey(string $filePath): string
    {
        $mtime = file_exists($filePath) ? filemtime($filePath) : 0;
        return 'file:' . md5($filePath . ':' . $mtime);
    }

    /**
     * Cache analysis result
     */
    public function cacheAnalysisResult(string $filePath, array $analyzers, array $result, array $options = []): bool
    {
        $key = $this->generateAnalysisKey($filePath, $analyzers, $options);
        return $this->set($key, $result, $this->config['default_ttl']);
    }

    /**
     * Get cached analysis result
     */
    public function getCachedAnalysisResult(string $filePath, array $analyzers, array $options = []): ?array
    {
        $key = $this->generateAnalysisKey($filePath, $analyzers, $options);
        return $this->get($key);
    }

    /**
     * Invalidate cache for specific file
     */
    public function invalidateFile(string $filePath): int
    {
        $pattern = 'analysis:' . md5($filePath . '*');
        return $this->clearByPattern($pattern);
    }

    /**
     * Warm up cache with common analysis results
     */
    public function warmUp(array $files, array $analyzers): array
    {
        $results = [];
        $this->logger->info("Cache warm-up started", [
            'files' => count($files),
            'analyzers' => $analyzers
        ]);

        foreach ($files as $file) {
            if (!file_exists($file)) {
                continue;
            }

            $key = $this->generateAnalysisKey($file, $analyzers);
            
            if (!$this->has($key)) {
                // File needs analysis - add to warm-up queue
                $results[] = [
                    'file' => $file,
                    'key' => $key,
                    'needs_analysis' => true
                ];
            } else {
                $results[] = [
                    'file' => $file,
                    'key' => $key,
                    'needs_analysis' => false
                ];
            }
        }

        $needsAnalysis = array_filter($results, fn($r) => $r['needs_analysis']);
        
        $this->logger->info("Cache warm-up completed", [
            'total_files' => count($files),
            'needs_analysis' => count($needsAnalysis),
            'cached' => count($files) - count($needsAnalysis)
        ]);

        return $results;
    }

    /**
     * Build full cache key with namespace
     */
    private function buildKey(string $key): string
    {
        $fullKey = $this->config['namespace'] . ':' . $key;
        
        if (strlen($fullKey) > self::MAX_KEY_LENGTH) {
            $fullKey = $this->config['namespace'] . ':' . md5($key);
        }

        return $fullKey;
    }

    /**
     * Record cache hit
     */
    private function recordHit(string $key, float $duration): void
    {
        if (!isset($this->hitCounts[$key])) {
            $this->hitCounts[$key] = 0;
        }
        
        $this->hitCounts[$key]++;
        $this->totalHitTime += $duration;
    }

    /**
     * Record cache miss
     */
    private function recordMiss(string $key, float $duration): void
    {
        if (!isset($this->missCounts[$key])) {
            $this->missCounts[$key] = 0;
        }
        
        $this->missCounts[$key]++;
        $this->totalMissTime += $duration;
    }
}

/**
 * Cache Backend Interface
 */
interface CacheBackend
{
    public function configure(array $config): void;
    public function get(string $key);
    public function set(string $key, $value, int $ttl): bool;
    public function delete(string $key): bool;
    public function has(string $key): bool;
    public function getMultiple(array $keys): array;
    public function setMultiple(array $values, int $ttl): bool;
    public function clear(): bool;
    public function clearByPattern(string $pattern): int;
    public function cleanup(): int;
    public function getStats(): array;
}

/**
 * File-based Cache Backend
 */
class FileCacheBackend implements CacheBackend
{
    private string $cacheDir;
    private array $config = [];

    public function configure(array $config): void
    {
        $this->config = $config;
        $this->cacheDir = $config['cache_dir'] ?? sys_get_temp_dir() . '/acqc_cache';
        
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }

    public function get(string $key)
    {
        $filePath = $this->getFilePath($key);
        
        if (!file_exists($filePath)) {
            return null;
        }

        $data = file_get_contents($filePath);
        if ($data === false) {
            return null;
        }

        $decoded = json_decode($data, true);
        if (!$decoded || !isset($decoded['expires'], $decoded['value'])) {
            return null;
        }

        if ($decoded['expires'] > 0 && $decoded['expires'] < time()) {
            unlink($filePath);
            return null;
        }

        return $decoded['value'];
    }

    public function set(string $key, $value, int $ttl): bool
    {
        $filePath = $this->getFilePath($key);
        $expires = $ttl > 0 ? time() + $ttl : 0;
        
        $data = [
            'expires' => $expires,
            'value' => $value,
            'created' => time()
        ];

        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return file_put_contents($filePath, json_encode($data)) !== false;
    }

    public function delete(string $key): bool
    {
        $filePath = $this->getFilePath($key);
        
        if (file_exists($filePath)) {
            return unlink($filePath);
        }

        return true;
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    public function getMultiple(array $keys): array
    {
        $results = [];
        
        foreach ($keys as $key) {
            $results[$key] = $this->get($key);
        }

        return $results;
    }

    public function setMultiple(array $values, int $ttl): bool
    {
        $success = true;
        
        foreach ($values as $key => $value) {
            if (!$this->set($key, $value, $ttl)) {
                $success = false;
            }
        }

        return $success;
    }

    public function clear(): bool
    {
        $this->removeDirectory($this->cacheDir);
        mkdir($this->cacheDir, 0755, true);
        return true;
    }

    public function clearByPattern(string $pattern): int
    {
        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->cacheDir)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && fnmatch($pattern, $file->getFilename())) {
                if (unlink($file->getPathname())) {
                    $count++;
                }
            }
        }

        return $count;
    }

    public function cleanup(): int
    {
        $count = 0;
        $now = time();
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->cacheDir)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $data = file_get_contents($file->getPathname());
            if ($data === false) {
                continue;
            }

            $decoded = json_decode($data, true);
            if (!$decoded || !isset($decoded['expires'])) {
                continue;
            }

            if ($decoded['expires'] > 0 && $decoded['expires'] < $now) {
                if (unlink($file->getPathname())) {
                    $count++;
                }
            }
        }

        return $count;
    }

    public function getStats(): array
    {
        $totalFiles = 0;
        $totalSize = 0;
        $expiredFiles = 0;
        $now = time();

        if (!is_dir($this->cacheDir)) {
            return [
                'total_files' => 0,
                'total_size' => 0,
                'expired_files' => 0,
                'cache_dir' => $this->cacheDir
            ];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->cacheDir)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $totalFiles++;
            $totalSize += $file->getSize();

            // Check if expired
            $data = file_get_contents($file->getPathname());
            if ($data !== false) {
                $decoded = json_decode($data, true);
                if ($decoded && isset($decoded['expires']) && 
                    $decoded['expires'] > 0 && $decoded['expires'] < $now) {
                    $expiredFiles++;
                }
            }
        }

        return [
            'total_files' => $totalFiles,
            'total_size' => $totalSize,
            'total_size_formatted' => $this->formatBytes($totalSize),
            'expired_files' => $expiredFiles,
            'cache_dir' => $this->cacheDir
        ];
    }

    private function getFilePath(string $key): string
    {
        $hash = md5($key);
        $subDir = substr($hash, 0, 2);
        return $this->cacheDir . '/' . $subDir . '/' . $hash . '.cache';
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        
        rmdir($dir);
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $unitIndex = 0;
        $size = (float)$bytes;

        while ($size >= 1024 && $unitIndex < count($units) - 1) {
            $size /= 1024;
            $unitIndex++;
        }

        return round($size, 2) . ' ' . $units[$unitIndex];
    }
} 
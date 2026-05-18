<?php

declare(strict_types=1);

namespace ACQC\Analyzers\Performance;

use ACQC\Analyzers\AbstractAnalyzer;
use ACQC\Core\Rules\Violation;

/**
 * Enhanced Redis analysis for Magento
 * 
 * Analyzes Redis configuration, memory usage, persistence,
 * and connection patterns for optimal Magento performance.
 */
class RedisEnhancedAnalyzer extends AbstractAnalyzer
{
    private const RULE_REDIS_NOT_AVAILABLE = 'redis-not-available';
    private const RULE_MEMORY_MAXMEMORY_NOT_SET = 'redis-maxmemory-not-set';
    private const RULE_EVICTION_POLICY_WRONG = 'redis-eviction-policy-wrong';
    private const RULE_PERSISTENCE_INAPPROPRIATE = 'redis-persistence-inappropriate';
    private const RULE_SLOW_QUERIES = 'redis-slow-queries';
    private const RULE_HIGH_MEMORY_FRAGMENTATION = 'redis-memory-fragmentation';
    private const RULE_MULTIPLE_DATABASES_NOT_USED = 'redis-single-database';
    
    private const RECOMMENDED_EVICTION_POLICIES = ['allkeys-lru', 'volatile-lru'];
    private const RECOMMENDED_MAXMEMORY_MB = 512;
    private const MAX_FRAGMENTATION_RATIO = 1.5;
    
    public function getName(): string
    {
        return 'redis-enhanced';
    }
    
    public function getVersion(): string
    {
        return '1.0.0';
    }
    
    public function isAvailable(): bool
    {
        return class_exists('\Redis') || class_exists('\Predis\Client');
    }
    
    protected function getDefaultConfiguration(): array
    {
        return [
            'check_availability' => true,
            'check_memory' => true,
            'check_eviction' => true,
            'check_persistence' => true,
            'check_slow_queries' => true,
            'check_fragmentation' => true,
            'check_database_usage' => true,
            'redis_host' => '127.0.0.1',
            'redis_port' => 6379,
            'redis_password' => null,
            'redis_timeout' => 2,
        ];
    }
    
    protected function executeAnalysis(array $files, array $options = []): array
    {
        return [];
    }
    
    protected function parseOutput(string $output, array $files): array
    {
        return [];
    }
    
    /**
     * Main analysis method
     */
    public function analyze(array $files, array $options = []): array
    {
        $violations = [];
        
        if (!$this->isAvailable()) {
            $this->logger->info('Redis extension not available');
            return $violations;
        }
        
        // Connect to Redis
        $redis = $this->connectRedis();
        
        if (!$redis) {
            $violations[] = $this->createNotAvailableViolation();
            return $violations;
        }
        
        // Get Redis info
        $info = $this->getRedisInfo($redis);
        
        if (empty($info)) {
            return $violations;
        }
        
        // Check memory configuration
        if ($this->configuration['check_memory']) {
            $memoryViolation = $this->checkMemoryConfiguration($info);
            if ($memoryViolation) {
                $violations[] = $memoryViolation;
            }
        }
        
        // Check eviction policy
        if ($this->configuration['check_eviction']) {
            $evictionViolation = $this->checkEvictionPolicy($info);
            if ($evictionViolation) {
                $violations[] = $evictionViolation;
            }
        }
        
        // Check persistence
        if ($this->configuration['check_persistence']) {
            $persistenceViolation = $this->checkPersistence($info);
            if ($persistenceViolation) {
                $violations[] = $persistenceViolation;
            }
        }
        
        // Check slow queries
        if ($this->configuration['check_slow_queries']) {
            $slowQueryViolations = $this->checkSlowQueries($redis);
            $violations = array_merge($violations, $slowQueryViolations);
        }
        
        // Check memory fragmentation
        if ($this->configuration['check_fragmentation']) {
            $fragmentationViolation = $this->checkFragmentation($info);
            if ($fragmentationViolation) {
                $violations[] = $fragmentationViolation;
            }
        }
        
        // Check database usage
        if ($this->configuration['check_database_usage']) {
            $dbViolation = $this->checkDatabaseUsage($info);
            if ($dbViolation) {
                $violations[] = $dbViolation;
            }
        }
        
        // Close connection
        try {
            if (method_exists($redis, 'close')) {
                $redis->close();
            }
        } catch (\Exception $e) {
            // Ignore close errors
        }
        
        return $violations;
    }
    
    private function connectRedis()
    {
        try {
            if (class_exists('\Redis')) {
                $redis = new \Redis();
                $connected = $redis->connect(
                    $this->configuration['redis_host'],
                    $this->configuration['redis_port'],
                    $this->configuration['redis_timeout']
                );
                
                if (!$connected) {
                    return null;
                }
                
                if ($this->configuration['redis_password']) {
                    $redis->auth($this->configuration['redis_password']);
                }
                
                return $redis;
            }
        } catch (\Exception $e) {
            $this->logger->warning('Failed to connect to Redis: ' . $e->getMessage());
        }
        
        return null;
    }
    
    private function getRedisInfo($redis): array
    {
        try {
            $info = $redis->info('ALL');
            return is_array($info) ? $info : [];
        } catch (\Exception $e) {
            $this->logger->warning('Failed to get Redis info: ' . $e->getMessage());
            return [];
        }
    }
    
    private function createNotAvailableViolation(): Violation
    {
        $violation = new Violation(
            self::RULE_REDIS_NOT_AVAILABLE,
            'Redis is not available or not configured. Redis is highly recommended for Magento cache, ' .
            'sessions, and Full Page Cache backend. Performance will be significantly impacted without Redis.',
            'redis',
            0,
            0,
            Violation::SEVERITY_WARNING,
            Violation::TYPE_PERFORMANCE
        );
        
        $violation->setCategory('performance');
        $violation->setSuggestion(
            "Install and configure Redis:\n\n" .
            "1. Install Redis:\n" .
            "   Ubuntu: sudo apt-get install redis-server\n" .
            "   CentOS: sudo yum install redis\n\n" .
            "2. Install PHP Redis extension:\n" .
            "   sudo pecl install redis\n" .
            "   Add to php.ini: extension=redis.so\n\n" .
            "3. Configure Magento to use Redis:\n" .
            "   See CacheFixGenerator for configuration templates\n\n" .
            "4. Restart services:\n" .
            "   sudo systemctl restart redis\n" .
            "   sudo systemctl restart php-fpm"
        );
        
        return $violation;
    }
    
    private function checkMemoryConfiguration(array $info): ?Violation
    {
        $maxMemory = $info['maxmemory'] ?? 0;
        
        if ($maxMemory == 0) {
            $violation = new Violation(
                self::RULE_MEMORY_MAXMEMORY_NOT_SET,
                'Redis maxmemory is not set. Without a memory limit, Redis can consume all available RAM, ' .
                'potentially causing system instability. For Magento, set maxmemory to control cache size.',
                'redis.conf',
                0,
                0,
                Violation::SEVERITY_WARNING,
                Violation::TYPE_PERFORMANCE
            );
            
            $violation->setCategory('performance');
            $violation->setSuggestion(
                "Set Redis maxmemory in redis.conf:\n\n" .
                "maxmemory " . (self::RECOMMENDED_MAXMEMORY_MB * 1024 * 1024) . "  # " . self::RECOMMENDED_MAXMEMORY_MB . "MB\n\n" .
                "Recommended values for Magento:\n" .
                "- Small sites: 256MB\n" .
                "- Medium sites: 512MB (recommended)\n" .
                "- Large sites: 1GB+\n\n" .
                "After updating:\n" .
                "  sudo systemctl restart redis\n" .
                "  # or\n" .
                "  redis-cli CONFIG SET maxmemory " . (self::RECOMMENDED_MAXMEMORY_MB * 1024 * 1024)
            );
            
            return $violation;
        }
        
        return null;
    }
    
    private function checkEvictionPolicy(array $info): ?Violation
    {
        $policy = $info['maxmemory_policy'] ?? 'noeviction';
        
        if (!in_array($policy, self::RECOMMENDED_EVICTION_POLICIES, true)) {
            $violation = new Violation(
                self::RULE_EVICTION_POLICY_WRONG,
                sprintf(
                    'Redis eviction policy is "%s" but Magento recommends "allkeys-lru" or "volatile-lru". ' .
                    'Wrong eviction policy can cause cache inefficiency or Redis to stop accepting writes when memory is full.',
                    $policy
                ),
                'redis.conf',
                0,
                0,
                $policy === 'noeviction' ? Violation::SEVERITY_ERROR : Violation::SEVERITY_WARNING,
                Violation::TYPE_PERFORMANCE
            );
            
            $violation->setCategory('performance');
            $violation->setMetadata([
                'current_policy' => $policy,
                'recommended_policies' => self::RECOMMENDED_EVICTION_POLICIES,
            ]);
            
            $violation->setSuggestion(
                "Set appropriate eviction policy in redis.conf:\n\n" .
                "maxmemory-policy allkeys-lru\n\n" .
                "Eviction policies explained:\n" .
                "- allkeys-lru: Evict any key using LRU (Recommended for Magento)\n" .
                "- volatile-lru: Evict keys with TTL using LRU\n" .
                "- noeviction: Return errors when memory full (NOT recommended)\n\n" .
                "Apply immediately:\n" .
                "  redis-cli CONFIG SET maxmemory-policy allkeys-lru\n\n" .
                "Make permanent in redis.conf and restart:\n" .
                "  sudo systemctl restart redis"
            );
            
            return $violation;
        }
        
        return null;
    }
    
    private function checkPersistence(array $info): ?Violation
    {
        $rdbEnabled = isset($info['rdb_last_save_time']);
        $aofEnabled = ($info['aof_enabled'] ?? '0') === '1';
        
        // For Magento cache, persistence is usually not needed and hurts performance
        if ($rdbEnabled || $aofEnabled) {
            $violation = new Violation(
                self::RULE_PERSISTENCE_INAPPROPRIATE,
                'Redis persistence (RDB/AOF) is enabled. For Magento cache and sessions, persistence is usually ' .
                'unnecessary and reduces performance. Cache can be regenerated, and sessions can use database backup.',
                'redis.conf',
                0,
                0,
                Violation::SEVERITY_INFO,
                Violation::TYPE_PERFORMANCE
            );
            
            $violation->setCategory('performance');
            $violation->setMetadata([
                'rdb_enabled' => $rdbEnabled,
                'aof_enabled' => $aofEnabled,
            ]);
            
            $violation->setSuggestion(
                "For Magento cache, disable persistence in redis.conf:\n\n" .
                "# Disable RDB snapshots\n" .
                "save \"\"\n\n" .
                "# Disable AOF\n" .
                "appendonly no\n\n" .
                "Benefits:\n" .
                "- Better write performance\n" .
                "- Reduced disk I/O\n" .
                "- Lower latency\n\n" .
                "Note: Keep persistence enabled if Redis stores critical data\n" .
                "that cannot be regenerated (not typical for Magento cache).\n\n" .
                "Restart Redis:\n" .
                "  sudo systemctl restart redis"
            );
            
            return $violation;
        }
        
        return null;
    }
    
    private function checkSlowQueries($redis): array
    {
        $violations = [];
        
        try {
            // Get slow log
            $slowLog = $redis->slowlog('get', 10);
            
            if (!empty($slowLog) && is_array($slowLog)) {
                $violation = new Violation(
                    self::RULE_SLOW_QUERIES,
                    sprintf(
                        'Redis slow log contains %d slow queries. Slow Redis operations impact Magento performance. ' .
                        'Review queries and optimize if possible.',
                        count($slowLog)
                    ),
                    'redis',
                    0,
                    0,
                    Violation::SEVERITY_WARNING,
                    Violation::TYPE_PERFORMANCE
                );
                
                $violation->setCategory('performance');
                $violation->setMetadata([
                    'slow_query_count' => count($slowLog),
                ]);
                
                // Extract sample commands
                $sampleCommands = [];
                foreach (array_slice($slowLog, 0, 3) as $entry) {
                    if (isset($entry[3]) && is_array($entry[3])) {
                        $sampleCommands[] = implode(' ', $entry[3]);
                    }
                }
                
                $violation->setSuggestion(
                    "Review slow Redis queries:\n\n" .
                    "Sample slow commands:\n" .
                    implode("\n", array_map(fn($cmd) => "  - {$cmd}", $sampleCommands)) . "\n\n" .
                    "Check full slow log:\n" .
                    "  redis-cli SLOWLOG GET 10\n\n" .
                    "Common causes:\n" .
                    "- Large key operations (KEYS *, SMEMBERS on large sets)\n" .
                    "- Inefficient data structures\n" .
                    "- Network latency\n\n" .
                    "Solutions:\n" .
                    "- Use SCAN instead of KEYS\n" .
                    "- Optimize data structures\n" .
                    "- Use connection pooling\n" .
                    "- Review Magento cache operations"
                );
                
                $violations[] = $violation;
            }
        } catch (\Exception $e) {
            // Slow log not available, skip
        }
        
        return $violations;
    }
    
    private function checkFragmentation(array $info): ?Violation
    {
        $usedMemory = $info['used_memory'] ?? 0;
        $usedMemoryRss = $info['used_memory_rss'] ?? 0;
        
        if ($usedMemory > 0 && $usedMemoryRss > 0) {
            $fragRatio = $usedMemoryRss / $usedMemory;
            
            if ($fragRatio > self::MAX_FRAGMENTATION_RATIO) {
                $violation = new Violation(
                    self::RULE_HIGH_MEMORY_FRAGMENTATION,
                    sprintf(
                        'Redis memory fragmentation ratio is %.2f (RSS/Used). High fragmentation wastes memory. ' .
                        'Ratios > %.1f indicate significant fragmentation.',
                        $fragRatio,
                        self::MAX_FRAGMENTATION_RATIO
                    ),
                    'redis',
                    0,
                    0,
                    Violation::SEVERITY_WARNING,
                    Violation::TYPE_PERFORMANCE
                );
                
                $violation->setCategory('performance');
                $violation->setMetadata([
                    'fragmentation_ratio' => round($fragRatio, 2),
                    'used_memory_mb' => round($usedMemory / 1024 / 1024, 2),
                    'rss_memory_mb' => round($usedMemoryRss / 1024 / 1024, 2),
                ]);
                
                $violation->setSuggestion(
                    "Reduce Redis memory fragmentation:\n\n" .
                    "1. Restart Redis (simple but causes downtime):\n" .
                    "   sudo systemctl restart redis\n\n" .
                    "2. Enable active defragmentation (Redis 4.0+):\n" .
                    "   In redis.conf:\n" .
                    "   activedefrag yes\n" .
                    "   active-defrag-threshold-lower 10\n" .
                    "   active-defrag-threshold-upper 100\n\n" .
                    "3. Use jemalloc allocator:\n" .
                    "   Redis compiled with jemalloc has better fragmentation\n\n" .
                    "4. Monitor fragmentation:\n" .
                    "   redis-cli INFO memory | grep mem_fragmentation_ratio"
                );
                
                return $violation;
            }
        }
        
        return null;
    }
    
    private function checkDatabaseUsage(array $info): ?Violation
    {
        // Check if Magento is using multiple Redis databases
        // Magento should use db0 for cache, db1 for page_cache, db2 for session
        $db0Keys = 0;
        $db1Keys = 0;
        $db2Keys = 0;
        
        // Parse database info
        foreach ($info as $key => $value) {
            if (preg_match('/^db(\d+)$/', $key, $matches)) {
                $dbNum = (int)$matches[1];
                if (preg_match('/keys=(\d+)/', $value, $keyMatches)) {
                    $keys = (int)$keyMatches[1];
                    if ($dbNum === 0) $db0Keys = $keys;
                    if ($dbNum === 1) $db1Keys = $keys;
                    if ($dbNum === 2) $db2Keys = $keys;
                }
            }
        }
        
        // If all keys in db0, databases not properly separated
        if ($db0Keys > 0 && $db1Keys === 0 && $db2Keys === 0) {
            $violation = new Violation(
                self::RULE_MULTIPLE_DATABASES_NOT_USED,
                'All Redis keys are in database 0. Magento should use separate databases for cache (0), ' .
                'page_cache (1), and sessions (2) for better organization and selective flushing.',
                'app/etc/env.php',
                0,
                0,
                Violation::SEVERITY_INFO,
                Violation::TYPE_PERFORMANCE
            );
            
            $violation->setCategory('performance');
            $violation->setSuggestion(
                "Configure Magento to use separate Redis databases:\n\n" .
                "In app/etc/env.php:\n\n" .
                "'cache' => [\n" .
                "    'frontend' => [\n" .
                "        'default' => [\n" .
                "            'backend' => 'Cm_Cache_Backend_Redis',\n" .
                "            'backend_options' => [\n" .
                "                'server' => '127.0.0.1',\n" .
                "                'port' => '6379',\n" .
                "                'database' => '0',  // Default cache\n" .
                "            ]\n" .
                "        ],\n" .
                "        'page_cache' => [\n" .
                "            'backend' => 'Cm_Cache_Backend_Redis',\n" .
                "            'backend_options' => [\n" .
                "                'server' => '127.0.0.1',\n" .
                "                'port' => '6379',\n" .
                "                'database' => '1',  // Page cache\n" .
                "            ]\n" .
                "        ]\n" .
                "    ]\n" .
                "],\n" .
                "'session' => [\n" .
                "    'save' => 'redis',\n" .
                "    'redis' => [\n" .
                "        'host' => '127.0.0.1',\n" .
                "        'port' => '6379',\n" .
                "        'database' => '2',  // Sessions\n" .
                "    ]\n" .
                "]"
            );
            
            return $violation;
        }
        
        return null;
    }
    
    /**
     * Get Redis statistics for reporting
     * 
     * @return array Statistics
     */
    public function getStatistics(): array
    {
        if (!$this->isAvailable()) {
            return ['available' => false];
        }
        
        $redis = $this->connectRedis();
        if (!$redis) {
            return ['available' => false];
        }
        
        $info = $this->getRedisInfo($redis);
        
        try {
            if (method_exists($redis, 'close')) {
                $redis->close();
            }
        } catch (\Exception $e) {
            // Ignore
        }
        
        if (empty($info)) {
            return ['available' => false];
        }
        
        return [
            'available' => true,
            'version' => $info['redis_version'] ?? 'unknown',
            'used_memory_mb' => round(($info['used_memory'] ?? 0) / 1024 / 1024, 2),
            'maxmemory_mb' => round(($info['maxmemory'] ?? 0) / 1024 / 1024, 2),
            'eviction_policy' => $info['maxmemory_policy'] ?? 'unknown',
            'connected_clients' => $info['connected_clients'] ?? 0,
            'total_commands' => $info['total_commands_processed'] ?? 0,
            'keyspace_hits' => $info['keyspace_hits'] ?? 0,
            'keyspace_misses' => $info['keyspace_misses'] ?? 0,
            'hit_rate' => $this->calculateHitRate($info),
        ];
    }
    
    private function calculateHitRate(array $info): float
    {
        $hits = $info['keyspace_hits'] ?? 0;
        $misses = $info['keyspace_misses'] ?? 0;
        $total = $hits + $misses;
        
        return $total > 0 ? round(($hits / $total) * 100, 2) : 0;
    }
}

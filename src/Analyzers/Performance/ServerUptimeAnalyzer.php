<?php

declare(strict_types=1);

namespace ACQC\Analyzers\Performance;

use ACQC\Analyzers\AbstractAnalyzer;
use ACQC\Core\Rules\Violation;

/**
 * Analyzes server health and uptime metrics
 * 
 * Monitors system resources (CPU, memory, disk) and service health
 * to identify operational issues that impact Magento performance.
 */
class ServerUptimeAnalyzer extends AbstractAnalyzer
{
    private const RULE_HIGH_LOAD_AVERAGE = 'server-high-load-average';
    private const RULE_LOW_DISK_SPACE = 'server-low-disk-space';
    private const RULE_HIGH_MEMORY_USAGE = 'server-high-memory-usage';
    private const RULE_PHPFPM_NOT_RUNNING = 'server-phpfpm-not-running';
    private const RULE_MYSQL_NOT_RUNNING = 'server-mysql-not-running';
    
    // Thresholds
    private const MAX_LOAD_MULTIPLIER = 2.0; // 2x CPU cores
    private const MIN_DISK_SPACE_GB = 5;
    private const MAX_MEMORY_USAGE_PERCENT = 90;
    
    public function getName(): string
    {
        return 'server-uptime';
    }
    
    public function getVersion(): string
    {
        return '1.0.0';
    }
    
    public function isAvailable(): bool
    {
        return PHP_OS_FAMILY !== 'Windows'; // Unix-like systems only
    }
    
    protected function getDefaultConfiguration(): array
    {
        return [
            'check_load_average' => true,
            'check_disk_space' => true,
            'check_memory' => true,
            'check_phpfpm' => true,
            'check_mysql' => true,
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
    
    public function analyze(array $files, array $options = []): array
    {
        $violations = [];
        
        if (!$this->isAvailable()) {
            return $violations;
        }
        
        // Check load average
        if ($this->configuration['check_load_average']) {
            $loadViolation = $this->checkLoadAverage();
            if ($loadViolation) {
                $violations[] = $loadViolation;
            }
        }
        
        // Check disk space
        if ($this->configuration['check_disk_space']) {
            $diskViolations = $this->checkDiskSpace();
            $violations = array_merge($violations, $diskViolations);
        }
        
        // Check memory
        if ($this->configuration['check_memory']) {
            $memoryViolation = $this->checkMemoryUsage();
            if ($memoryViolation) {
                $violations[] = $memoryViolation;
            }
        }
        
        // Check PHP-FPM
        if ($this->configuration['check_phpfpm']) {
            $phpfpmViolation = $this->checkPHPFPM();
            if ($phpfpmViolation) {
                $violations[] = $phpfpmViolation;
            }
        }
        
        // Check MySQL
        if ($this->configuration['check_mysql']) {
            $mysqlViolation = $this->checkMySQL();
            if ($mysqlViolation) {
                $violations[] = $mysqlViolation;
            }
        }
        
        return $violations;
    }
    
    private function checkLoadAverage(): ?Violation
    {
        $loadAvg = sys_getloadavg();
        if ($loadAvg === false) {
            return null;
        }
        
        $cpuCores = $this->getCPUCores();
        $load1min = $loadAvg[0];
        $threshold = $cpuCores * self::MAX_LOAD_MULTIPLIER;
        
        if ($load1min > $threshold) {
            $violation = new Violation(
                self::RULE_HIGH_LOAD_AVERAGE,
                sprintf(
                    'Server load average (%.2f) exceeds recommended threshold (%.2f) for %d CPU cores. ' .
                    'High load indicates CPU contention, causing slow response times.',
                    $load1min,
                    $threshold,
                    $cpuCores
                ),
                'system',
                0,
                0,
                $load1min > ($threshold * 1.5) ? Violation::SEVERITY_ERROR : Violation::SEVERITY_WARNING,
                Violation::TYPE_PERFORMANCE
            );
            
            $violation->setCategory('performance');
            $violation->setMetadata([
                'load_1min' => $load1min,
                'load_5min' => $loadAvg[1],
                'load_15min' => $loadAvg[2],
                'cpu_cores' => $cpuCores,
                'threshold' => $threshold,
            ]);
            
            $violation->setSuggestion(
                "Reduce server load:\n\n" .
                "1. Check top processes:\n" .
                "   top -bn1 | head -20\n\n" .
                "2. Identify resource-heavy processes:\n" .
                "   ps aux --sort=-%cpu | head -10\n" .
                "   ps aux --sort=-%mem | head -10\n\n" .
                "3. Common causes:\n" .
                "   - Cron jobs running simultaneously\n" .
                "   - Unoptimized database queries\n" .
                "   - Missing indexes\n" .
                "   - Insufficient server resources\n\n" .
                "4. Consider:\n" .
                "   - Upgrading server (more CPU cores)\n" .
                "   - Optimizing code and queries\n" .
                "   - Distributing cron jobs\n" .
                "   - Adding caching layers"
            );
            
            return $violation;
        }
        
        return null;
    }
    
    private function checkDiskSpace(): array
    {
        $violations = [];
        
        // Check root partition
        $diskSpace = disk_free_space('/');
        $diskTotal = disk_total_space('/');
        
        if ($diskSpace !== false && $diskTotal !== false) {
            $freeGB = $diskSpace / (1024 ** 3);
            $usedPercent = (($diskTotal - $diskSpace) / $diskTotal) * 100;
            
            if ($freeGB < self::MIN_DISK_SPACE_GB || $usedPercent > 95) {
                $violation = new Violation(
                    self::RULE_LOW_DISK_SPACE,
                    sprintf(
                        'Low disk space: %.2fGB free (%.1f%% used). Disk space critical for Magento operation (logs, cache, media). ' .
                        'System may become unstable below 5GB free.',
                        $freeGB,
                        $usedPercent
                    ),
                    '/var',
                    0,
                    0,
                    $freeGB < 2 ? Violation::SEVERITY_CRITICAL : Violation::SEVERITY_ERROR,
                    Violation::TYPE_PERFORMANCE
                );
                
                $violation->setCategory('performance');
                $violation->setMetadata([
                    'free_gb' => round($freeGB, 2),
                    'used_percent' => round($usedPercent, 1),
                    'total_gb' => round($diskTotal / (1024 ** 3), 2),
                ]);
                
                $violation->setSuggestion(
                    "Free up disk space:\n\n" .
                    "1. Clean Magento logs:\n" .
                    "   rm -rf var/log/*.log\n" .
                    "   rm -rf var/report/*\n\n" .
                    "2. Clean old backups:\n" .
                    "   find var/backups -mtime +30 -delete\n\n" .
                    "3. Clean generated files:\n" .
                    "   rm -rf var/view_preprocessed/*\n" .
                    "   rm -rf var/cache/*\n\n" .
                    "4. Check large directories:\n" .
                    "   du -sh * | sort -h | tail -10\n\n" .
                    "5. Consider:\n" .
                    "   - Rotating logs more frequently\n" .
                    "   - Moving media to external storage\n" .
                    "   - Upgrading disk capacity"
                );
                
                $violations[] = $violation;
            }
        }
        
        return $violations;
    }
    
    private function checkMemoryUsage(): ?Violation
    {
        if (!function_exists('exec')) {
            return null;
        }
        
        $meminfo = $this->getMemoryInfo();
        if (empty($meminfo)) {
            return null;
        }
        
        $total = $meminfo['MemTotal'] ?? 0;
        $available = $meminfo['MemAvailable'] ?? ($meminfo['MemFree'] ?? 0);
        
        if ($total > 0) {
            $usedPercent = (($total - $available) / $total) * 100;
            
            if ($usedPercent > self::MAX_MEMORY_USAGE_PERCENT) {
                $violation = new Violation(
                    self::RULE_HIGH_MEMORY_USAGE,
                    sprintf(
                        'High memory usage: %.1f%% used. Low available memory causes swapping and severe performance degradation.',
                        $usedPercent
                    ),
                    'system',
                    0,
                    0,
                    $usedPercent > 95 ? Violation::SEVERITY_ERROR : Violation::SEVERITY_WARNING,
                    Violation::TYPE_PERFORMANCE
                );
                
                $violation->setCategory('performance');
                $violation->setMetadata([
                    'used_percent' => round($usedPercent, 1),
                    'total_mb' => round($total / 1024, 2),
                    'available_mb' => round($available / 1024, 2),
                ]);
                
                $violation->setSuggestion(
                    "Reduce memory usage:\n\n" .
                    "1. Check memory-hungry processes:\n" .
                    "   ps aux --sort=-%mem | head -10\n\n" .
                    "2. Restart services:\n" .
                    "   sudo systemctl restart php-fpm\n" .
                    "   sudo systemctl restart mysql\n\n" .
                    "3. Optimize MySQL:\n" .
                    "   Review innodb_buffer_pool_size\n" .
                    "   Review key_buffer_size\n\n" .
                    "4. Optimize PHP-FPM:\n" .
                    "   Review pm.max_children\n" .
                    "   Review pm.max_requests\n\n" .
                    "5. Consider:\n" .
                    "   - Upgrading server RAM\n" .
                    "   - Moving services to separate servers"
                );
                
                return $violation;
            }
        }
        
        return null;
    }
    
    private function checkPHPFPM(): ?Violation
    {
        // Check if PHP-FPM is running
        $running = $this->isServiceRunning('php-fpm') || 
                  $this->isServiceRunning('php.*-fpm');
        
        if (!$running) {
            $violation = new Violation(
                self::RULE_PHPFPM_NOT_RUNNING,
                'PHP-FPM service is not running. Magento requires PHP-FPM to process requests. Site is likely down.',
                'system',
                0,
                0,
                Violation::SEVERITY_CRITICAL,
                Violation::TYPE_PERFORMANCE
            );
            
            $violation->setCategory('performance');
            $violation->setSuggestion(
                "Start PHP-FPM:\n\n" .
                "sudo systemctl start php-fpm\n" .
                "# or for specific version:\n" .
                "sudo systemctl start php8.1-fpm\n\n" .
                "Check status:\n" .
                "systemctl status php-fpm\n\n" .
                "Check logs:\n" .
                "tail -f /var/log/php-fpm/error.log"
            );
            
            return $violation;
        }
        
        return null;
    }
    
    private function checkMySQL(): ?Violation
    {
        $running = $this->isServiceRunning('mysql') || 
                  $this->isServiceRunning('mysqld') ||
                  $this->isServiceRunning('mariadb');
        
        if (!$running) {
            $violation = new Violation(
                self::RULE_MYSQL_NOT_RUNNING,
                'MySQL/MariaDB service is not running. Magento requires database to function. Site is down.',
                'system',
                0,
                0,
                Violation::SEVERITY_CRITICAL,
                Violation::TYPE_PERFORMANCE
            );
            
            $violation->setCategory('performance');
            $violation->setSuggestion(
                "Start MySQL:\n\n" .
                "sudo systemctl start mysql\n" .
                "# or:\n" .
                "sudo systemctl start mariadb\n\n" .
                "Check status:\n" .
                "systemctl status mysql\n\n" .
                "Check logs:\n" .
                "tail -f /var/log/mysql/error.log"
            );
            
            return $violation;
        }
        
        return null;
    }
    
    private function getCPUCores(): int
    {
        if (function_exists('shell_exec')) {
            $cores = (int)shell_exec('nproc 2>/dev/null || echo 1');
            return max(1, $cores);
        }
        return 1;
    }
    
    private function getMemoryInfo(): array
    {
        if (!file_exists('/proc/meminfo')) {
            return [];
        }
        
        $meminfo = [];
        $lines = file('/proc/meminfo');
        
        foreach ($lines as $line) {
            if (preg_match('/^(\w+):\s+(\d+)/', $line, $matches)) {
                $meminfo[$matches[1]] = (int)$matches[2]; // KB
            }
        }
        
        return $meminfo;
    }
    
    private function isServiceRunning(string $pattern): bool
    {
        if (!function_exists('shell_exec')) {
            return true; // Assume running if can't check
        }
        
        $result = shell_exec("pgrep -f '{$pattern}' 2>/dev/null");
        return !empty($result);
    }
}

<?php

declare(strict_types=1);

namespace ACQC\Core\Scanner;

class ScanContext
{
    private array $includePatterns = [];
    private array $excludePatterns = [];
    private array $supportedFileTypes = [];
    private int $maxFileSize = 10485760; // 10MB default
    private bool $enableParallelProcessing = true;
    private int $maxWorkers = 4;
    private bool $enableProgressTracking = true;
    private bool $enableMemoryOptimization = true;
    private string $scanId;
    private \DateTime $startTime;
    private array $scanOptions = [];
    private array $analyzers = [];
    private ?string $baselinePath = null;
    private bool $enableChecksum = true;
    private string $workingDirectory;
    private array $environmentInfo = [];

    public function __construct($pathOrOptions = [], array $extraOptions = [])
    {
        $this->scanId = uniqid('scan_', true);
        $this->startTime = new \DateTime();
        $this->workingDirectory = getcwd();

        $this->initializeDefaults();

        // Accept either a file-path string or an options array as the first argument
        if (is_string($pathOrOptions)) {
            $options = array_merge(['path' => $pathOrOptions], $extraOptions);
        } else {
            $options = array_merge($pathOrOptions, $extraOptions);
        }

        $this->applyOptions($options);
        $this->collectEnvironmentInfo();
    }

    /**
     * Initialize default settings
     */
    private function initializeDefaults(): void
    {
        $this->includePatterns = [
            '*.php',
            '*.phtml',
            '*.js',
            '*.xml',
            '*.json',
            '*.css',
            '*.less',
            '*.scss',
            '*.twig',
        ];

        $this->excludePatterns = [
            'vendor/*',
            'var/*',
            'generated/*',
            'pub/static/*',
            'dev/tests/*',
            'node_modules/*',
            '.git/*',
            '*.min.js',
            '*.min.css',
            '*.cache.*',
            '*.log',
            '*.tmp',
        ];

        $this->supportedFileTypes = [
            'php' => ['php', 'phtml'],
            'javascript' => ['js'],
            'xml' => ['xml'],
            'json' => ['json'],
            'css' => ['css', 'less', 'scss'],
            'template' => ['twig', 'phtml'],
            'graphql' => ['graphqls', 'graphql'],
        ];

        $this->scanOptions = [
            'skip_large_files' => true,
            'calculate_metrics' => true,
            'deep_scan' => false,
            'quick_scan' => false,
            'verbose' => false,
            'debug' => false,
        ];
    }

    /**
     * Apply configuration options
     */
    private function applyOptions(array $options): void
    {
        if (isset($options['include_patterns'])) {
            $this->includePatterns = (array) $options['include_patterns'];
        }

        if (isset($options['exclude_patterns'])) {
            $this->excludePatterns = array_merge($this->excludePatterns, (array) $options['exclude_patterns']);
        }

        if (isset($options['max_file_size'])) {
            $this->maxFileSize = (int) $options['max_file_size'];
        }

        if (isset($options['parallel_processing'])) {
            $this->enableParallelProcessing = (bool) $options['parallel_processing'];
        }

        if (isset($options['max_workers'])) {
            $this->maxWorkers = max(1, min(16, (int) $options['max_workers']));
        }

        if (isset($options['progress_tracking'])) {
            $this->enableProgressTracking = (bool) $options['progress_tracking'];
        }

        if (isset($options['memory_optimization'])) {
            $this->enableMemoryOptimization = (bool) $options['memory_optimization'];
        }

        if (isset($options['analyzers'])) {
            $this->analyzers = (array) $options['analyzers'];
        }

        if (isset($options['baseline'])) {
            $this->baselinePath = (string) $options['baseline'];
        }

        if (isset($options['enable_checksum'])) {
            $this->enableChecksum = (bool) $options['enable_checksum'];
        }

        if (isset($options['working_directory'])) {
            $this->workingDirectory = (string) $options['working_directory'];
        }

        // Merge scan options
        if (isset($options['scan_options']) && is_array($options['scan_options'])) {
            $this->scanOptions = array_merge($this->scanOptions, $options['scan_options']);
        }
    }

    /**
     * Collect environment information
     */
    private function collectEnvironmentInfo(): void
    {
        $this->environmentInfo = [
            'php_version' => PHP_VERSION,
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'os' => PHP_OS,
            'sapi' => PHP_SAPI,
            'extensions' => [
                'pcntl' => extension_loaded('pcntl'),
                'posix' => extension_loaded('posix'),
                'curl' => extension_loaded('curl'),
                'json' => extension_loaded('json'),
                'xml' => extension_loaded('xml'),
            ],
            'system_info' => [
                'cpu_count' => $this->getCpuCount(),
                'available_memory' => $this->getAvailableMemory(),
                'disk_space' => disk_free_space($this->workingDirectory),
            ],
        ];
    }

    /**
     * Get CPU count for parallel processing
     */
    private function getCpuCount(): int
    {
        if (function_exists('shell_exec')) {
            // Linux/Mac
            $cpuCount = shell_exec('nproc 2>/dev/null || sysctl -n hw.ncpu 2>/dev/null || echo 1');
            return max(1, (int) trim($cpuCount));
        }
        
        return 1; // Default fallback
    }

    /**
     * Get available system memory
     */
    private function getAvailableMemory(): ?int
    {
        if (function_exists('shell_exec')) {
            // Linux
            $meminfo = shell_exec('cat /proc/meminfo 2>/dev/null | grep MemAvailable');
            if ($meminfo && preg_match('/MemAvailable:\s+(\d+) kB/', $meminfo, $matches)) {
                return (int) $matches[1] * 1024; // Convert KB to bytes
            }
            
            // Mac
            $vmStat = shell_exec('vm_stat 2>/dev/null');
            if ($vmStat && preg_match('/free:\s+(\d+)/', $vmStat, $matches)) {
                return (int) $matches[1] * 4096; // Pages to bytes (assuming 4KB pages)
            }
        }
        
        return null;
    }

    /**
     * Get scan ID
     */
    public function getScanId(): string
    {
        return $this->scanId;
    }

    /**
     * Get start time
     */
    public function getStartTime(): \DateTime
    {
        return $this->startTime;
    }

    /**
     * Get include patterns
     */
    public function getIncludePatterns(): array
    {
        return $this->includePatterns;
    }

    /**
     * Set include patterns
     */
    public function setIncludePatterns(array $patterns): void
    {
        $this->includePatterns = $patterns;
    }

    /**
     * Add include pattern
     */
    public function addIncludePattern(string $pattern): void
    {
        $this->includePatterns[] = $pattern;
    }

    /**
     * Get exclude patterns
     */
    public function getExcludePatterns(): array
    {
        return $this->excludePatterns;
    }

    /**
     * Set exclude patterns
     */
    public function setExcludePatterns(array $patterns): void
    {
        $this->excludePatterns = $patterns;
    }

    /**
     * Add exclude pattern
     */
    public function addExcludePattern(string $pattern): void
    {
        $this->excludePatterns[] = $pattern;
    }

    /**
     * Check if a file should be included based on patterns
     */
    public function shouldIncludeFile(string $filePath): bool
    {
        $relativePath = $this->getRelativePath($filePath);
        $basename = basename($filePath);
        
        // Check exclude patterns first (against relative path)
        foreach ($this->excludePatterns as $pattern) {
            if ($this->matchesPattern($relativePath, $pattern)) {
                return false;
            }
        }

        // Check include patterns (against basename for wildcard patterns like *.php)
        foreach ($this->includePatterns as $pattern) {
            // If pattern contains path separators, match against relative path
            // Otherwise match against basename
            $matchAgainst = (strpos($pattern, '/') !== false) ? $relativePath : $basename;
            
            if ($this->matchesPattern($matchAgainst, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if pattern matches file path
     */
    private function matchesPattern(string $path, string $pattern): bool
    {
        // Use fnmatch for simpler, more reliable glob matching
        return fnmatch($pattern, $path, FNM_CASEFOLD);
    }

    /**
     * Get relative path from working directory
     */
    private function getRelativePath(string $filePath): string
    {
        $workingDir = realpath($this->workingDirectory) . DIRECTORY_SEPARATOR;
        $realPath = realpath($filePath);
        
        if ($realPath && strpos($realPath, $workingDir) === 0) {
            return substr($realPath, strlen($workingDir));
        }
        
        return $filePath;
    }

    /**
     * Detect file type based on extension
     */
    public function detectFileType(string $filePath): string
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        
        foreach ($this->supportedFileTypes as $type => $extensions) {
            if (in_array($extension, $extensions)) {
                return $type;
            }
        }
        
        return 'unknown';
    }

    /**
     * Check if file type is supported
     */
    public function isSupportedFileType(string $filePath): bool
    {
        return $this->detectFileType($filePath) !== 'unknown';
    }

    /**
     * Get maximum file size for scanning
     */
    public function getMaxFileSize(): int
    {
        return $this->maxFileSize;
    }

    /**
     * Set maximum file size
     */
    public function setMaxFileSize(int $size): void
    {
        $this->maxFileSize = $size;
    }

    /**
     * Check if file size is within limits
     */
    public function isFileSizeAcceptable(string $filePath): bool
    {
        if (!$this->scanOptions['skip_large_files']) {
            return true;
        }
        
        $fileSize = file_exists($filePath) ? filesize($filePath) : 0;
        return $fileSize <= $this->maxFileSize;
    }

    /**
     * Check if parallel processing is enabled
     */
    public function isParallelProcessingEnabled(): bool
    {
        return $this->enableParallelProcessing && $this->environmentInfo['extensions']['pcntl'];
    }

    /**
     * Get maximum number of worker processes
     */
    public function getMaxWorkers(): int
    {
        if (!$this->isParallelProcessingEnabled()) {
            return 1;
        }
        
        $cpuCount = $this->environmentInfo['system_info']['cpu_count'] ?? 1;
        return min($this->maxWorkers, $cpuCount);
    }

    /**
     * Check if progress tracking is enabled
     */
    public function isProgressTrackingEnabled(): bool
    {
        return $this->enableProgressTracking;
    }

    /**
     * Check if memory optimization is enabled
     */
    public function isMemoryOptimizationEnabled(): bool
    {
        return $this->enableMemoryOptimization;
    }

    /**
     * Get scan options
     */
    public function getScanOptions(): array
    {
        return $this->scanOptions;
    }

    /**
     * Get specific scan option
     */
    public function getScanOption(string $key, $default = null)
    {
        return $this->scanOptions[$key] ?? $default;
    }

    /**
     * Set scan option
     */
    public function setScanOption(string $key, $value): void
    {
        $this->scanOptions[$key] = $value;
    }

    /**
     * Get analyzers to run
     */
    public function getAnalyzers(): array
    {
        return $this->analyzers;
    }

    /**
     * Set analyzers
     */
    public function setAnalyzers(array $analyzers): void
    {
        $this->analyzers = $analyzers;
    }

    /**
     * Get baseline path for comparison
     */
    public function getBaselinePath(): ?string
    {
        return $this->baselinePath;
    }

    /**
     * Set baseline path
     */
    public function setBaselinePath(?string $path): void
    {
        $this->baselinePath = $path;
    }

    /**
     * Check if checksum calculation is enabled
     */
    public function isChecksumEnabled(): bool
    {
        return $this->enableChecksum;
    }

    /**
     * Get working directory
     */
    public function getWorkingDirectory(): string
    {
        return $this->workingDirectory;
    }

    /**
     * Get environment information
     */
    public function getEnvironmentInfo(): array
    {
        return $this->environmentInfo;
    }

    /**
     * Get scan context summary
     */
    public function getSummary(): array
    {
        return [
            'scan_id' => $this->scanId,
            'start_time' => $this->startTime->format(\DateTime::ISO8601),
            'working_directory' => $this->workingDirectory,
            'parallel_processing' => $this->isParallelProcessingEnabled(),
            'max_workers' => $this->getMaxWorkers(),
            'max_file_size' => $this->maxFileSize,
            'include_patterns' => count($this->includePatterns),
            'exclude_patterns' => count($this->excludePatterns),
            'supported_file_types' => array_keys($this->supportedFileTypes),
            'analyzers' => $this->analyzers,
            'baseline_enabled' => $this->baselinePath !== null,
            'checksum_enabled' => $this->enableChecksum,
            'scan_options' => $this->scanOptions,
            'environment' => [
                'php_version' => $this->environmentInfo['php_version'],
                'cpu_count' => $this->environmentInfo['system_info']['cpu_count'] ?? 1,
                'memory_limit' => $this->environmentInfo['memory_limit'],
            ],
        ];
    }

    /**
     * Export context to array
     */
    public function toArray(): array
    {
        return [
            'scan_id' => $this->scanId,
            'start_time' => $this->startTime->format(\DateTime::ISO8601),
            'working_directory' => $this->workingDirectory,
            'include_patterns' => $this->includePatterns,
            'exclude_patterns' => $this->excludePatterns,
            'supported_file_types' => $this->supportedFileTypes,
            'max_file_size' => $this->maxFileSize,
            'enable_parallel_processing' => $this->enableParallelProcessing,
            'max_workers' => $this->maxWorkers,
            'enable_progress_tracking' => $this->enableProgressTracking,
            'enable_memory_optimization' => $this->enableMemoryOptimization,
            'scan_options' => $this->scanOptions,
            'analyzers' => $this->analyzers,
            'baseline_path' => $this->baselinePath,
            'enable_checksum' => $this->enableChecksum,
            'environment_info' => $this->environmentInfo,
        ];
    }
} 
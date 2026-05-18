<?php

declare(strict_types=1);

namespace ACQC\Analyzers;

use ACQC\Core\Rules\Violation;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

abstract class AbstractAnalyzer
{
    protected LoggerInterface $logger;
    protected array $configuration = [];
    protected string $workingDirectory;
    protected array $excludePatterns = [];
    protected array $includePatterns = [];
    protected bool $incrementalMode = false;
    protected array $baselineData = [];
    protected int $timeout = 300; // 5 minutes default timeout
    protected array $lastAnalysisResults = [];
    protected array $supportedFileExtensions = ['php'];

    public function __construct(array $configuration = [], ?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
        $this->configuration = array_merge($this->getDefaultConfiguration(), $configuration);
        $this->workingDirectory = getcwd() ?: '/tmp';
        $this->validateConfiguration();
    }

    /**
     * Get analyzer name
     */
    abstract public function getName(): string;

    /**
     * Get analyzer version
     */
    abstract public function getVersion(): string;

    /**
     * Check if the analyzer tool is available
     */
    abstract public function isAvailable(): bool;

    /**
     * Get default configuration for this analyzer
     */
    abstract protected function getDefaultConfiguration(): array;

    /**
     * Execute the analyzer tool and return raw output
     */
    abstract protected function executeAnalysis(array $files, array $options = []): array;

    /**
     * Parse the tool output and convert to violations
     */
    abstract protected function parseOutput(string $output, array $files): array;

    /**
     * Analyze files and return violations
     */
    public function analyze(array $files, array $options = []): array
    {
        if (empty($files)) {
            $this->logger->debug("{$this->getName()}: No files to analyze");
            return [];
        }

        // Filter files based on supported extensions
        $files = $this->filterSupportedFiles($files);
        
        if (empty($files)) {
            $this->logger->debug("{$this->getName()}: No supported files found");
            return [];
        }

        // Apply include/exclude patterns
        $files = $this->applyFileFilters($files);
        
        if (empty($files)) {
            $this->logger->debug("{$this->getName()}: All files filtered out");
            return [];
        }

        // Check if incremental analysis is possible
        if ($this->incrementalMode) {
            $files = $this->filterChangedFiles($files);
            if (empty($files)) {
                $this->logger->info("{$this->getName()}: No changed files to analyze");
                return [];
            }
        }

        try {
            $this->logger->info("{$this->getName()}: Analyzing " . count($files) . " files");
            
            // Check tool availability
            if (!$this->isAvailable()) {
                throw new AnalyzerException("{$this->getName()} tool is not available");
            }

            // Execute analysis
            $startTime = microtime(true);
            $rawResults = $this->executeAnalysis($files, $options);
            $executionTime = microtime(true) - $startTime;

            // Parse results
            $violations = [];
            foreach ($rawResults as $output) {
                $parsedViolations = $this->parseOutput($output, $files);
                $violations = array_merge($violations, $parsedViolations);
            }

            // Apply baseline filtering if configured
            if (!empty($this->baselineData)) {
                $violations = $this->filterBaseline($violations);
            }

            // Store results for incremental analysis
            $this->lastAnalysisResults = [
                'timestamp' => time(),
                'files' => $files,
                'violations' => count($violations),
                'execution_time' => $executionTime,
            ];

            $this->logger->info("{$this->getName()}: Found " . count($violations) . 
                               " violations in " . round($executionTime, 2) . " seconds");

            return $violations;

        } catch (\Exception $e) {
            $this->logger->error("{$this->getName()}: Analysis failed - " . $e->getMessage());
            throw new AnalyzerException(
                "Analysis failed for {$this->getName()}: " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Analyze a single file
     */
    public function analyzeFile(string $filePath, array $options = []): array
    {
        return $this->analyze([$filePath], $options);
    }

    /**
     * Set configuration value
     */
    public function setConfiguration(array $config): self
    {
        $this->configuration = array_merge($this->configuration, $config);
        $this->validateConfiguration();
        return $this;
    }

    /**
     * Get configuration value
     */
    public function getConfiguration(?string $key = null, $default = null)
    {
        if ($key === null) {
            return $this->configuration;
        }
        return $this->configuration[$key] ?? $default;
    }

    /**
     * Set working directory
     */
    public function setWorkingDirectory(string $directory): self
    {
        if (!is_dir($directory)) {
            throw new \InvalidArgumentException("Directory does not exist: {$directory}");
        }
        $this->workingDirectory = $directory;
        return $this;
    }

    /**
     * Set include patterns
     */
    public function setIncludePatterns(array $patterns): self
    {
        $this->includePatterns = $patterns;
        return $this;
    }

    /**
     * Set exclude patterns
     */
    public function setExcludePatterns(array $patterns): self
    {
        $this->excludePatterns = $patterns;
        return $this;
    }

    /**
     * Enable or disable incremental mode
     */
    public function setIncrementalMode(bool $enabled): self
    {
        $this->incrementalMode = $enabled;
        return $this;
    }

    /**
     * Load baseline data for filtering known issues
     */
    public function loadBaseline(array $baselineData): self
    {
        $this->baselineData = $baselineData;
        return $this;
    }

    /**
     * Set analysis timeout
     */
    public function setTimeout(int $seconds): self
    {
        $this->timeout = max(30, $seconds);
        return $this;
    }

    /**
     * Get last analysis results
     */
    public function getLastResults(): array
    {
        return $this->lastAnalysisResults;
    }

    /**
     * Get analyzer statistics
     */
    public function getStatistics(): array
    {
        return [
            'name' => $this->getName(),
            'version' => $this->getVersion(),
            'available' => $this->isAvailable(),
            'supported_extensions' => $this->supportedFileExtensions,
            'incremental_mode' => $this->incrementalMode,
            'last_analysis' => $this->lastAnalysisResults,
            'configuration' => $this->configuration,
        ];
    }

    /**
     * Create a violation from analyzer data
     */
    protected function createViolation(
        string $ruleId,
        string $message,
        string $filePath,
        int $line = 0,
        int $column = 0,
        string $severity = Violation::SEVERITY_WARNING,
        string $type = Violation::TYPE_STYLE,
        array $metadata = []
    ): Violation {
        $violation = new Violation(
            $this->getName() . '.' . $ruleId,
            $message,
            $filePath,
            $line,
            $column,
            $severity,
            $type
        );

        $violation->setCategory($this->getName());
        $violation->addMetadata('analyzer', $this->getName());
        $violation->addMetadata('analyzer_version', $this->getVersion());

        foreach ($metadata as $key => $value) {
            $violation->addMetadata($key, $value);
        }

        return $violation;
    }

    /**
     * Execute command and return process
     */
    protected function executeCommand(array $command, array $options = []): Process
    {
        $process = new Process($command, $this->workingDirectory);
        $process->setTimeout($this->timeout);

        // Set environment variables if specified
        if (isset($options['env'])) {
            $process->setEnv($options['env']);
        }

        $this->logger->debug("{$this->getName()}: Executing command - " . implode(' ', $command));

        try {
            $process->mustRun();
            return $process;
        } catch (ProcessFailedException $e) {
            // Some tools return non-zero exit codes when violations are found
            // Check if this is expected behavior
            if ($this->isExpectedFailure($process)) {
                return $process;
            }
            throw $e;
        }
    }

    /**
     * Check if process failure is expected (e.g., violations found)
     */
    protected function isExpectedFailure(Process $process): bool
    {
        // Most static analysis tools return exit code 1 when violations are found
        // Override this method in specific analyzers if different behavior is expected
        return in_array($process->getExitCode(), [0, 1, 2]);
    }

    /**
     * Filter files based on supported extensions
     */
    protected function filterSupportedFiles(array $files): array
    {
        return array_filter($files, function($file) {
            $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            return in_array($extension, $this->supportedFileExtensions);
        });
    }

    /**
     * Apply include/exclude file filters
     */
    protected function applyFileFilters(array $files): array
    {
        $filteredFiles = $files;

        // Apply exclude patterns
        foreach ($this->excludePatterns as $pattern) {
            $filteredFiles = array_filter($filteredFiles, function($file) use ($pattern) {
                return !fnmatch($pattern, $file);
            });
        }

        // Apply include patterns (if specified)
        if (!empty($this->includePatterns)) {
            $includedFiles = [];
            foreach ($this->includePatterns as $pattern) {
                $matched = array_filter($filteredFiles, function($file) use ($pattern) {
                    return fnmatch($pattern, $file);
                });
                $includedFiles = array_merge($includedFiles, $matched);
            }
            $filteredFiles = array_unique($includedFiles);
        }

        return array_values($filteredFiles);
    }

    /**
     * Filter files that have changed since last analysis
     */
    protected function filterChangedFiles(array $files): array
    {
        if (empty($this->lastAnalysisResults)) {
            return $files; // First run, analyze all files
        }

        $lastAnalysisTime = $this->lastAnalysisResults['timestamp'] ?? 0;
        $changedFiles = [];

        foreach ($files as $file) {
            if (!file_exists($file)) {
                continue;
            }

            $fileModTime = filemtime($file);
            if ($fileModTime > $lastAnalysisTime) {
                $changedFiles[] = $file;
            }
        }

        return $changedFiles;
    }

    /**
     * Filter violations using baseline data
     */
    protected function filterBaseline(array $violations): array
    {
        if (empty($this->baselineData)) {
            return $violations;
        }

        $filteredViolations = [];
        foreach ($violations as $violation) {
            $fingerprint = $violation->getFingerprint();
            if (!in_array($fingerprint, $this->baselineData)) {
                $filteredViolations[] = $violation;
            }
        }

        return $filteredViolations;
    }

    /**
     * Validate analyzer configuration
     */
    protected function validateConfiguration(): void
    {
        // Override in specific analyzers for custom validation
    }

    /**
     * Map severity from tool-specific to standard format
     */
    protected function mapSeverity(string $toolSeverity): string
    {
        // Default mapping - override in specific analyzers
        $mapping = [
            'error' => Violation::SEVERITY_ERROR,
            'warning' => Violation::SEVERITY_WARNING,
            'info' => Violation::SEVERITY_INFO,
            'notice' => Violation::SEVERITY_INFO,
        ];

        $normalized = strtolower($toolSeverity);
        return $mapping[$normalized] ?? Violation::SEVERITY_WARNING;
    }

    /**
     * Map violation type from tool-specific to standard format
     */
    protected function mapViolationType(string $toolType): string
    {
        // Default mapping - override in specific analyzers
        $mapping = [
            'style' => Violation::TYPE_STYLE,
            'complexity' => Violation::TYPE_MAINTAINABILITY,
            'duplication' => Violation::TYPE_MAINTAINABILITY,
            'security' => Violation::TYPE_SECURITY,
            'performance' => Violation::TYPE_PERFORMANCE,
            'logic' => Violation::TYPE_LOGIC,
        ];

        $normalized = strtolower($toolType);
        return $mapping[$normalized] ?? Violation::TYPE_STYLE;
    }

    /**
     * Check if a command exists in the system
     */
    protected function commandExists(string $command): bool
    {
        $whereIsCommand = (PHP_OS_FAMILY === 'Windows') ? 'where' : 'which';
        
        $process = new Process([$whereIsCommand, $command]);
        $process->run();
        
        return $process->isSuccessful();
    }

    /**
     * Get tool version by executing version command
     */
    protected function getToolVersion(string $command, array $versionArgs = ['--version']): string
    {
        try {
            $process = new Process(array_merge([$command], $versionArgs));
            $process->setTimeout(10);
            $process->run();
            
            if ($process->isSuccessful()) {
                return trim($process->getOutput());
            }
        } catch (\Exception $e) {
            $this->logger->debug("Failed to get version for {$command}: " . $e->getMessage());
        }
        
        return 'Unknown';
    }

    /**
     * Normalize file paths for consistency
     */
    protected function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $path = preg_replace('/\/+/', '/', $path);
        return rtrim($path, '/');
    }

    /**
     * Convert relative path to absolute path
     */
    protected function makeAbsolutePath(string $path): string
    {
        if (strpos($path, '/') === 0 || preg_match('/^[A-Z]:/', $path)) {
            return $path; // Already absolute
        }
        
        return $this->normalizePath($this->workingDirectory . '/' . $path);
    }

    /**
     * Extract source code context around a line
     */
    protected function extractSourceContext(string $filePath, int $line, int $contextLines = 2): array
    {
        if (!file_exists($filePath)) {
            return [];
        }

        $lines = file($filePath, FILE_IGNORE_NEW_LINES);
        $startLine = max(0, $line - $contextLines - 1);
        $endLine = min(count($lines) - 1, $line + $contextLines - 1);

        $context = [];
        for ($i = $startLine; $i <= $endLine; $i++) {
            $context[$i + 1] = $lines[$i] ?? '';
        }

        return $context;
    }
}

/**
 * Custom exception for analyzer errors
 */
class AnalyzerException extends \Exception
{
    public function __construct(string $message = "", int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
} 
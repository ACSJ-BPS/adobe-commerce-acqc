<?php

declare(strict_types=1);

namespace ACQC\Analyzers\PHP;

use ACQC\Analyzers\AbstractAnalyzer;
use ACQC\Analyzers\AnalyzerException;
use ACQC\Core\Rules\Violation;

class PHPStanAnalyzer extends AbstractAnalyzer
{
    private string $phpstanCommand = 'phpstan';
    private ?string $configFile = null;
    private ?string $phpstanNeonContent = null;

    public function getName(): string
    {
        return 'phpstan';
    }

    public function getVersion(): string
    {
        return $this->getToolVersion($this->phpstanCommand, ['--version']);
    }

    public function isAvailable(): bool
    {
        return $this->commandExists($this->phpstanCommand);
    }

    protected function getDefaultConfiguration(): array
    {
        return [
            'level' => 'max',
            'paths' => ['.'],
            'autoload_file' => null,
            'bootstrap' => null,
            'configuration' => null,
            'memory_limit' => '512M',
            'error_format' => 'json',
            'no_progress' => true,
            'no_ansi' => true,
            'no_interaction' => true,
            'debug' => false,
            'quiet' => false,
            'verbose' => false,
            'clear_result_cache' => false,
            'generate_baseline' => false,
            'baseline' => null,
            'includes' => [],
            'excludes' => [
                'vendor',
                'node_modules',
                'var/cache',
                'var/log',
                'generated',
                'pub/static',
            ],
            'parallel' => true,
            'max_parallel_processes' => null,
            'treat_php_doc_types_as_native' => false,
            'strict_rules' => [],
            'custom_rules' => [],
            'extensions' => [],
            'magento_compatibility' => true,
        ];
    }

    protected function validateConfiguration(): void
    {
        parent::validateConfiguration();

        // Validate analysis level
        $level = $this->getConfiguration('level');
        if (!$this->isValidLevel($level)) {
            throw new AnalyzerException("Invalid PHPStan level: {$level}");
        }

        // Validate paths
        $paths = $this->getConfiguration('paths', []);
        if (empty($paths)) {
            throw new AnalyzerException("At least one path must be specified for analysis");
        }

        foreach ($paths as $path) {
            if (!file_exists($path) && !is_dir($path)) {
                $this->logger->warning("Path does not exist: {$path}");
            }
        }

        // Validate autoload file
        $autoloadFile = $this->getConfiguration('autoload_file');
        if ($autoloadFile && !file_exists($autoloadFile)) {
            throw new AnalyzerException("Autoload file does not exist: {$autoloadFile}");
        }

        // Validate bootstrap file
        $bootstrap = $this->getConfiguration('bootstrap');
        if ($bootstrap && !file_exists($bootstrap)) {
            throw new AnalyzerException("Bootstrap file does not exist: {$bootstrap}");
        }

        // Validate baseline file
        $baseline = $this->getConfiguration('baseline');
        if ($baseline && !file_exists($baseline)) {
            $this->logger->warning("Baseline file does not exist: {$baseline}");
        }
    }

    protected function executeAnalysis(array $files, array $options = []): array
    {
        $results = [];

        try {
            // Create temporary config if needed
            $tempConfigFile = null;
            if ($this->shouldGenerateConfig()) {
                $tempConfigFile = $this->generateTempConfig($files);
            }

            $command = $this->buildCommand($files, $options, $tempConfigFile);
            $process = $this->executeCommand($command);
            
            $output = $process->getOutput();
            if (!empty($output)) {
                $results[] = $output;
            }

            // Handle errors and warnings
            $errorOutput = $process->getErrorOutput();
            if (!empty($errorOutput)) {
                // PHPStan often outputs progress to stderr, only log actual errors
                if (!$this->isProgressOutput($errorOutput)) {
                    $this->logger->warning("PHPStan stderr: " . $errorOutput);
                }
            }

            // Clean up temporary config
            if ($tempConfigFile && file_exists($tempConfigFile)) {
                unlink($tempConfigFile);
            }

        } catch (\Exception $e) {
            $this->logger->error("PHPStan analysis failed: " . $e->getMessage());
            throw $e;
        }

        return $results;
    }

    protected function parseOutput(string $output, array $files): array
    {
        $violations = [];

        if (empty($output) || trim($output) === '') {
            return $violations;
        }

        try {
            // Handle JSON output format
            if ($this->getConfiguration('error_format') === 'json') {
                $violations = $this->parseJsonOutput($output);
            } else {
                // Handle table format (fallback)
                $violations = $this->parseTableOutput($output, $files);
            }

        } catch (\Exception $e) {
            $this->logger->error("Failed to parse PHPStan output: " . $e->getMessage());
            $this->logger->debug("Raw output: " . substr($output, 0, 1000));
        }

        return $violations;
    }

    /**
     * Parse JSON output from PHPStan
     */
    private function parseJsonOutput(string $jsonOutput): array
    {
        $violations = [];

        try {
            $data = json_decode($jsonOutput, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new AnalyzerException("Invalid JSON output from PHPStan: " . json_last_error_msg());
            }

            if (!isset($data['files']) || !is_array($data['files'])) {
                // Check if this is an error response
                if (isset($data['errors']) && is_array($data['errors'])) {
                    foreach ($data['errors'] as $error) {
                        $this->logger->error("PHPStan error: " . $error);
                    }
                }
                return $violations;
            }

            foreach ($data['files'] as $filePath => $fileData) {
                if (!isset($fileData['messages']) || !is_array($fileData['messages'])) {
                    continue;
                }

                foreach ($fileData['messages'] as $message) {
                    $violation = $this->createViolationFromMessage($message, $filePath);
                    if ($violation) {
                        $violations[] = $violation;
                    }
                }
            }

        } catch (\Exception $e) {
            throw new AnalyzerException("Failed to parse PHPStan JSON output: " . $e->getMessage());
        }

        return $violations;
    }

    /**
     * Parse table output from PHPStan (fallback)
     */
    private function parseTableOutput(string $tableOutput, array $files): array
    {
        $violations = [];
        $lines = explode("\n", $tableOutput);

        foreach ($lines as $line) {
            $line = trim($line);
            
            // Skip empty lines and separators
            if (empty($line) || strpos($line, '---') === 0 || strpos($line, '===') === 0) {
                continue;
            }

            // Try to parse file:line format
            if (preg_match('/^(.+):(\d+):(.+)$/', $line, $matches)) {
                $filePath = trim($matches[1]);
                $lineNumber = (int) $matches[2];
                $message = trim($matches[3]);

                $violation = $this->createViolation(
                    'analysis',
                    $message,
                    $filePath,
                    $lineNumber,
                    0,
                    Violation::SEVERITY_ERROR,
                    Violation::TYPE_LOGIC,
                    ['format' => 'table']
                );

                $violations[] = $violation;
            }
        }

        return $violations;
    }

    /**
     * Create violation from PHPStan message
     */
    private function createViolationFromMessage(array $message, string $filePath): ?Violation
    {
        if (!isset($message['message']) || !isset($message['line'])) {
            return null;
        }

        $text = $message['message'];
        $line = (int) $message['line'];
        $ignorable = $message['ignorable'] ?? false;
        $tip = $message['tip'] ?? null;
        $identifier = $message['identifier'] ?? null;

        // Determine severity based on message content and type
        $severity = $this->determineSeverity($text, $identifier);
        $violationType = $this->determineViolationType($text, $identifier);

        $ruleId = $identifier ?: $this->extractRuleFromMessage($text);

        $violation = $this->createViolation(
            $ruleId,
            $text,
            $filePath,
            $line,
            0,
            $severity,
            $violationType,
            [
                'ignorable' => $ignorable,
                'identifier' => $identifier,
                'tip' => $tip,
            ]
        );

        // Add source context
        if ($line > 0) {
            $context = $this->extractSourceContext($filePath, $line, 2);
            if (!empty($context)) {
                $violation->setSource($context[$line] ?? '');
                $violation->addContext('source_context', $context);
            }
        }

        // Add tip as suggestion
        if ($tip) {
            $violation->setSuggestion($tip);
        } else {
            $suggestion = $this->getSuggestionForError($text, $identifier);
            if ($suggestion) {
                $violation->setSuggestion($suggestion);
            }
        }

        return $violation;
    }

    /**
     * Build PHPStan command
     */
    private function buildCommand(array $files, array $options = [], ?string $configFile = null): array
    {
        $command = [$this->phpstanCommand, 'analyse'];

        // Add configuration file
        if ($configFile && file_exists($configFile)) {
            $command[] = '--configuration';
            $command[] = $configFile;
        } elseif ($this->configFile && file_exists($this->configFile)) {
            $command[] = '--configuration';
            $command[] = $this->configFile;
        }

        // Add analysis level
        $level = $this->getConfiguration('level');
        if ($level) {
            $command[] = '--level';
            $command[] = (string) $level;
        }

        // Add autoload file
        $autoloadFile = $this->getConfiguration('autoload_file');
        if ($autoloadFile && file_exists($autoloadFile)) {
            $command[] = '--autoload-file';
            $command[] = $autoloadFile;
        }

        // Add bootstrap file
        $bootstrap = $this->getConfiguration('bootstrap');
        if ($bootstrap && file_exists($bootstrap)) {
            $command[] = '--bootstrap';
            $command[] = $bootstrap;
        }

        // Add memory limit
        $memoryLimit = $this->getConfiguration('memory_limit');
        if ($memoryLimit) {
            $command[] = '--memory-limit';
            $command[] = $memoryLimit;
        }

        // Add error format
        $errorFormat = $this->getConfiguration('error_format', 'json');
        $command[] = '--error-format';
        $command[] = $errorFormat;

        // Add baseline
        $baseline = $this->getConfiguration('baseline');
        if ($baseline && file_exists($baseline)) {
            $command[] = '--baseline';
            $command[] = $baseline;
        }

        // Add various flags
        if ($this->getConfiguration('no_progress', true)) {
            $command[] = '--no-progress';
        }

        if ($this->getConfiguration('no_ansi', true)) {
            $command[] = '--no-ansi';
        }

        if ($this->getConfiguration('no_interaction', true)) {
            $command[] = '--no-interaction';
        }

        if ($this->getConfiguration('debug', false)) {
            $command[] = '--debug';
        }

        if ($this->getConfiguration('quiet', false)) {
            $command[] = '--quiet';
        }

        if ($this->getConfiguration('verbose', false)) {
            $command[] = '--verbose';
        }

        if ($this->getConfiguration('clear_result_cache', false)) {
            $command[] = '--clear-result-cache';
        }

        if ($this->getConfiguration('generate_baseline', false)) {
            $command[] = '--generate-baseline';
        }

        // Add parallel processing
        if ($this->getConfiguration('parallel', true)) {
            $maxProcesses = $this->getConfiguration('max_parallel_processes');
            if ($maxProcesses) {
                $command[] = '--parallel';
                $command[] = (string) $maxProcesses;
            } else {
                $command[] = '--parallel';
            }
        }

        // Add paths or files
        if (!empty($files)) {
            foreach ($files as $file) {
                $command[] = $file;
            }
        } else {
            // Use configured paths if no specific files provided
            $paths = $this->getConfiguration('paths', ['.']);
            foreach ($paths as $path) {
                $command[] = $path;
            }
        }

        return $command;
    }

    /**
     * Determine if we should generate a temporary config
     */
    private function shouldGenerateConfig(): bool
    {
        return !$this->configFile && 
               (!empty($this->getConfiguration('includes')) || 
                !empty($this->getConfiguration('excludes')) ||
                $this->getConfiguration('magento_compatibility', true));
    }

    /**
     * Generate temporary configuration file
     */
    private function generateTempConfig(array $files): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'phpstan_config_') . '.neon';
        
        $config = $this->buildNeonConfig($files);
        file_put_contents($tempFile, $config);
        
        return $tempFile;
    }

    /**
     * Build NEON configuration content
     */
    private function buildNeonConfig(array $files): string
    {
        $config = "parameters:\n";
        
        // Add level
        $level = $this->getConfiguration('level');
        if ($level) {
            $config .= "    level: {$level}\n";
        }

        // Add paths
        $paths = $this->getConfiguration('paths', []);
        if (!empty($paths)) {
            $config .= "    paths:\n";
            foreach ($paths as $path) {
                $config .= "        - {$path}\n";
            }
        }

        // Add excludes
        $excludes = $this->getConfiguration('excludes', []);
        if (!empty($excludes)) {
            $config .= "    excludePaths:\n";
            foreach ($excludes as $exclude) {
                $config .= "        - {$exclude}\n";
            }
        }

        // Add strict rules
        $strictRules = $this->getConfiguration('strict_rules', []);
        if (!empty($strictRules)) {
            foreach ($strictRules as $rule => $value) {
                $config .= "    {$rule}: " . ($value ? 'true' : 'false') . "\n";
            }
        }

        // Add Magento compatibility
        if ($this->getConfiguration('magento_compatibility', true)) {
            $config .= "    treatPhpDocTypesAsCertain: false\n";
            $config .= "    checkMissingIterableValueType: false\n";
            $config .= "    checkGenericClassInNonGenericObjectType: false\n";
        }

        // Add custom rules
        $customRules = $this->getConfiguration('custom_rules', []);
        if (!empty($customRules)) {
            $config .= "rules:\n";
            foreach ($customRules as $rule) {
                $config .= "    - {$rule}\n";
            }
        }

        // Add extensions
        $extensions = $this->getConfiguration('extensions', []);
        if (!empty($extensions)) {
            $config .= "services:\n";
            foreach ($extensions as $extension) {
                $config .= "    - {$extension}\n";
            }
        }

        return $config;
    }

    /**
     * Determine severity based on message content
     */
    private function determineSeverity(string $message, ?string $identifier): string
    {
        // Critical issues
        if (str_contains($message, 'fatal error') || 
            str_contains($message, 'syntax error') ||
            str_contains($message, 'class not found')) {
            return Violation::SEVERITY_CRITICAL;
        }

        // Error patterns
        if (str_contains($message, 'undefined') ||
            str_contains($message, 'does not exist') ||
            str_contains($message, 'cannot be called') ||
            str_contains($message, 'type mismatch')) {
            return Violation::SEVERITY_ERROR;
        }

        // Warning patterns
        if (str_contains($message, 'possibly') ||
            str_contains($message, 'might') ||
            str_contains($message, 'deprecated')) {
            return Violation::SEVERITY_WARNING;
        }

        return Violation::SEVERITY_ERROR; // Default for PHPStan
    }

    /**
     * Determine violation type based on message content
     */
    private function determineViolationType(string $message, ?string $identifier): string
    {
        if (str_contains($message, 'type') || str_contains($message, 'return')) {
            return Violation::TYPE_LOGIC;
        }

        if (str_contains($message, 'deprecated')) {
            return Violation::TYPE_MAINTAINABILITY;
        }

        if (str_contains($message, 'security') || str_contains($message, 'unsafe')) {
            return Violation::TYPE_SECURITY;
        }

        return Violation::TYPE_LOGIC; // Default for static analysis
    }

    /**
     * Extract rule identifier from message
     */
    private function extractRuleFromMessage(string $message): string
    {
        // Try to extract meaningful rule identifier from message
        if (preg_match('/^(\w+):/', $message, $matches)) {
            return strtolower($matches[1]);
        }

        if (str_contains($message, 'undefined')) {
            return 'undefined.variable';
        }

        if (str_contains($message, 'type')) {
            return 'type.mismatch';
        }

        return 'analysis';
    }

    /**
     * Get suggestion for common PHPStan errors
     */
    private function getSuggestionForError(string $message, ?string $identifier): ?string
    {
        $suggestions = [
            'undefined' => 'Check that the variable, method, or property is defined and accessible',
            'type' => 'Ensure the types match or add proper type declarations',
            'return' => 'Add or fix the return type declaration',
            'parameter' => 'Check parameter types and add type hints where needed',
            'property' => 'Declare the property or check its visibility',
            'method' => 'Check that the method exists and is accessible',
            'class' => 'Ensure the class is properly imported and exists',
            'deprecated' => 'Replace deprecated functionality with modern alternatives',
        ];

        foreach ($suggestions as $pattern => $suggestion) {
            if (str_contains(strtolower($message), $pattern)) {
                return $suggestion;
            }
        }

        return 'Review the code for potential type or logic issues';
    }

    /**
     * Check if a level is valid
     */
    private function isValidLevel($level): bool
    {
        if (is_int($level)) {
            return $level >= 0 && $level <= 9;
        }

        return in_array($level, ['max', 'rule']);
    }

    /**
     * Check if output is progress information
     */
    private function isProgressOutput(string $output): bool
    {
        return str_contains($output, 'Note: Using configuration file') ||
               str_contains($output, 'files analysed') ||
               str_contains($output, 'errors found');
    }

    /**
     * Set PHPStan command path
     */
    public function setCommand(string $command): self
    {
        $this->phpstanCommand = $command;
        return $this;
    }

    /**
     * Set PHPStan configuration file
     */
    public function setConfigFile(string $configFile): self
    {
        if (!file_exists($configFile)) {
            throw new \InvalidArgumentException("Config file does not exist: {$configFile}");
        }
        $this->configFile = $configFile;
        return $this;
    }

    /**
     * Generate PHPStan configuration file
     */
    public function generateConfigFile(string $outputPath, array $config = []): bool
    {
        $mergedConfig = array_merge($this->getConfiguration(), $config);
        $neonContent = $this->buildNeonConfig([]);

        try {
            file_put_contents($outputPath, $neonContent);
            $this->setConfigFile($outputPath);
            return true;
        } catch (\Exception $e) {
            $this->logger->error("Failed to generate PHPStan config file: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Override to handle PHPStan-specific exit codes
     */
    protected function isExpectedFailure(\Symfony\Component\Process\Process $process): bool
    {
        // PHPStan returns:
        // 0 = no errors found
        // 1 = errors found
        // 2 = fatal error (invalid config, etc.)
        return in_array($process->getExitCode(), [0, 1]);
    }
} 
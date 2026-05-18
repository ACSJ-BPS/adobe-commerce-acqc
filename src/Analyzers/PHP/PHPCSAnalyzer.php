<?php

declare(strict_types=1);

namespace ACQC\Analyzers\PHP;

use ACQC\Analyzers\AbstractAnalyzer;
use ACQC\Analyzers\AnalyzerException;
use ACQC\Core\Rules\Violation;

class PHPCSAnalyzer extends AbstractAnalyzer
{
    private string $phpcsCommand = 'phpcs';
    private ?string $configFile = null;
    private array $installedStandards = [];

    public function getName(): string
    {
        return 'PHPCS';
    }

    public function getDescription(): string
    {
        return 'PHP Code Sniffer — checks PHP files against coding standards';
    }

    public function getVersion(): string
    {
        return $this->getToolVersion($this->phpcsCommand, ['--version']);
    }

    public function isAvailable(): bool
    {
        return $this->commandExists($this->phpcsCommand);
    }

    /**
     * Returns true if this analyzer can process the given file.
     * Accepts a bare extension (e.g. '.php') or a full filename.
     */
    public function supports(string $fileOrExtension): bool
    {
        $ext = strtolower(pathinfo($fileOrExtension, PATHINFO_EXTENSION) ?: ltrim($fileOrExtension, '.'));
        return in_array($ext, ['php', 'phtml'], true);
    }

    /**
     * Apply runtime configuration — merges provided options over the defaults.
     */
    public function configure(array $config): void
    {
        foreach ($config as $key => $value) {
            $this->configuration[$key] = $value;
        }
    }

    protected function getDefaultConfiguration(): array
    {
        return [
            'standard' => 'Magento2',
            'extensions' => ['php'],
            'ignore_patterns' => [
                '*/vendor/*',
                '*/node_modules/*',
                '*/var/*',
                '*/generated/*',
                '*/pub/static/*',
            ],
            'warning_severity' => 5,
            'error_severity' => 5,
            'tab_width' => 4,
            'encoding' => 'utf-8',
            'report_format' => 'json',
            'show_progress' => false,
            'colors' => false,
            'cache' => true,
            'parallel' => 1,
            'memory_limit' => '128M',
            'max_warnings' => 0, // 0 = no limit
            'sniffs' => [], // Specific sniffs to include
            'exclude_sniffs' => [], // Specific sniffs to exclude
            'runtime_set' => [], // Runtime configuration options
        ];
    }

    protected function validateConfiguration(): void
    {
        parent::validateConfiguration();

        // Validate standard
        $standard = $this->getConfiguration('standard');
        if ($standard && !$this->isValidStandard($standard)) {
            throw new AnalyzerException("Invalid PHPCS standard: {$standard}");
        }

        // Validate severity levels
        $warningSeverity = $this->getConfiguration('warning_severity');
        $errorSeverity = $this->getConfiguration('error_severity');
        
        if (!is_int($warningSeverity) || $warningSeverity < 0 || $warningSeverity > 8) {
            throw new AnalyzerException("Warning severity must be between 0 and 8");
        }
        
        if (!is_int($errorSeverity) || $errorSeverity < 0 || $errorSeverity > 8) {
            throw new AnalyzerException("Error severity must be between 0 and 8");
        }
    }

    protected function executeAnalysis(array $files, array $options = []): array
    {
        $batchSize = $this->getConfiguration('batch_size', 50);
        $results = [];

        // Process files in batches to avoid command line length limits
        $fileBatches = array_chunk($files, $batchSize);

        foreach ($fileBatches as $batch) {
            $command = $this->buildCommand($batch, $options);
            
            try {
                $process = $this->executeCommand($command);
                $output = $process->getOutput();
                
                if (!empty($output)) {
                    $results[] = $output;
                }

                // Handle errors
                $errorOutput = $process->getErrorOutput();
                if (!empty($errorOutput)) {
                    $this->logger->warning("PHPCS stderr: " . $errorOutput);
                }

            } catch (\Exception $e) {
                $this->logger->error("PHPCS batch failed: " . $e->getMessage());
                
                // Try individual files if batch fails
                if (count($batch) > 1) {
                    foreach ($batch as $file) {
                        try {
                            $singleCommand = $this->buildCommand([$file], $options);
                            $singleProcess = $this->executeCommand($singleCommand);
                            $singleOutput = $singleProcess->getOutput();
                            
                            if (!empty($singleOutput)) {
                                $results[] = $singleOutput;
                            }
                        } catch (\Exception $singleE) {
                            $this->logger->error("PHPCS failed for file {$file}: " . $singleE->getMessage());
                        }
                    }
                } else {
                    throw $e;
                }
            }
        }

        return $results;
    }

    protected function parseOutput(string $output, array $files): array
    {
        $violations = [];

        if (empty($output) || trim($output) === '') {
            return $violations;
        }

        // The Magento2 standard writes DEPRECATED tokenizer warnings to stdout,
        // prepending them to the JSON. Strip everything before the first '{'.
        $jsonStart = strpos($output, '{');
        if ($jsonStart === false) {
            return $violations;
        }
        if ($jsonStart > 0) {
            $this->logger->debug('PHPCS: stripped ' . $jsonStart . ' bytes of preamble before JSON');
            $output = substr($output, $jsonStart);
        }

        try {
            $data = json_decode($output, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new AnalyzerException("Invalid JSON output from PHPCS: " . json_last_error_msg());
            }

            if (!isset($data['files']) || !is_array($data['files'])) {
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
            $this->logger->error("Failed to parse PHPCS output: " . $e->getMessage());
            $this->logger->debug("Raw output: " . substr($output, 0, 1000));
        }

        return $violations;
    }

    /**
     * Build PHPCS command with all options
     */
    private function buildCommand(array $files, array $options = []): array
    {
        $command = [$this->phpcsCommand];

        // Add configuration file if specified
        if ($this->configFile && file_exists($this->configFile)) {
            $command[] = '--config-set';
            $command[] = 'installed_paths';
            $command[] = dirname($this->configFile);
        }

        // Add standard
        $standard = $this->getConfiguration('standard');
        if ($standard) {
            $command[] = '--standard=' . $standard;
        }

        // Add extensions
        $extensions = $this->getConfiguration('extensions', ['php']);
        if (!empty($extensions)) {
            $command[] = '--extensions=' . implode(',', $extensions);
        }

        // Add ignore patterns
        $ignorePatterns = array_merge(
            $this->getConfiguration('ignore_patterns', []),
            $this->excludePatterns
        );
        if (!empty($ignorePatterns)) {
            $command[] = '--ignore=' . implode(',', $ignorePatterns);
        }

        // Add severity levels
        $warningSeverity = $this->getConfiguration('warning_severity');
        $errorSeverity = $this->getConfiguration('error_severity');
        
        if ($warningSeverity !== null) {
            $command[] = '--warning-severity=' . $warningSeverity;
        }
        
        if ($errorSeverity !== null) {
            $command[] = '--error-severity=' . $errorSeverity;
        }

        // Add report format
        $reportFormat = $this->getConfiguration('report_format', 'json');
        $command[] = '--report=' . $reportFormat;

        // Add tab width
        $tabWidth = $this->getConfiguration('tab_width');
        if ($tabWidth) {
            $command[] = '--tab-width=' . $tabWidth;
        }

        // Add encoding
        $encoding = $this->getConfiguration('encoding');
        if ($encoding) {
            $command[] = '--encoding=' . $encoding;
        }

        // Add specific sniffs
        $sniffs = $this->getConfiguration('sniffs', []);
        if (!empty($sniffs)) {
            $command[] = '--sniffs=' . implode(',', $sniffs);
        }

        // Exclude specific sniffs
        $excludeSniffs = $this->getConfiguration('exclude_sniffs', []);
        if (!empty($excludeSniffs)) {
            $command[] = '--exclude=' . implode(',', $excludeSniffs);
        }

        // Add runtime settings
        $runtimeSet = $this->getConfiguration('runtime_set', []);
        foreach ($runtimeSet as $key => $value) {
            $command[] = '--runtime-set';
            $command[] = $key;
            $command[] = $value;
        }

        // Add parallel processing
        $parallel = $this->getConfiguration('parallel', 1);
        if ($parallel > 1) {
            $command[] = '--parallel=' . $parallel;
        }

        // Add progress indicator
        if ($this->getConfiguration('show_progress', false)) {
            $command[] = '--progress';
        }

        // Disable colors for consistent output
        if (!$this->getConfiguration('colors', false)) {
            $command[] = '--no-colors';
        }

        // Add cache option
        if ($this->getConfiguration('cache', true)) {
            $command[] = '--cache';
        } else {
            $command[] = '--no-cache';
        }

        // Add memory limit
        $memoryLimit = $this->getConfiguration('memory_limit');
        if ($memoryLimit) {
            $command[] = '-d';
            $command[] = 'memory_limit=' . $memoryLimit;
        }

        // Add max warnings
        $maxWarnings = $this->getConfiguration('max_warnings', 0);
        if ($maxWarnings > 0) {
            $command[] = '--max-warnings=' . $maxWarnings;
        }

        // Add files
        foreach ($files as $file) {
            $command[] = $file;
        }

        return $command;
    }

    /**
     * Create violation from PHPCS message
     */
    private function createViolationFromMessage(array $message, string $filePath): ?Violation
    {
        if (!isset($message['message']) || !isset($message['type'])) {
            return null;
        }

        $ruleId = $message['source'] ?? 'unknown';
        $text = $message['message'];
        $line = (int) ($message['line'] ?? 0);
        $column = (int) ($message['column'] ?? 0);
        $type = strtolower($message['type']);
        
        // Map PHPCS severity to our format
        $severity = $this->mapPhpcsSeverity($type, $message['severity'] ?? 5);
        $violationType = $this->mapViolationType('style');

        $violation = $this->createViolation(
            $ruleId,
            $text,
            $filePath,
            $line,
            $column,
            $severity,
            $violationType,
            [
                'phpcs_type' => $type,
                'phpcs_severity' => $message['severity'] ?? 5,
                'fixable' => $message['fixable'] ?? false,
            ]
        );

        // Add source context if available
        if ($line > 0) {
            $context = $this->extractSourceContext($filePath, $line, 1);
            if (!empty($context)) {
                $violation->setSource($context[$line] ?? '');
                $violation->addContext('source_context', $context);
            }
        }

        // Add fix information if available
        if (!empty($message['fixable'])) {
            $violation->setFixable(true);
            $violation->setSuggestion('This issue can be automatically fixed by PHPCS');
        }

        return $violation;
    }

    /**
     * Map PHPCS severity to our severity levels
     */
    private function mapPhpcsSeverity(string $type, int $severity): string
    {
        // PHPCS uses ERROR and WARNING types
        if ($type === 'error') {
            return $severity >= 7 ? Violation::SEVERITY_CRITICAL : Violation::SEVERITY_ERROR;
        }
        
        if ($type === 'warning') {
            return $severity >= 7 ? Violation::SEVERITY_ERROR : Violation::SEVERITY_WARNING;
        }

        return Violation::SEVERITY_WARNING;
    }

    /**
     * Check if a coding standard is valid
     */
    private function isValidStandard(string $standard): bool
    {
        // Check if it's a built-in standard
        $builtInStandards = [
            'PSR1', 'PSR2', 'PSR12', 'PEAR', 'Zend', 'Squiz', 'Generic', 'Magento2'
        ];
        
        if (in_array($standard, $builtInStandards)) {
            return true;
        }

        // Check if it's a file path
        if (file_exists($standard)) {
            return true;
        }

        // Check installed standards
        return in_array($standard, $this->getInstalledStandards());
    }

    /**
     * Get list of installed PHPCS standards
     */
    private function getInstalledStandards(): array
    {
        if (!empty($this->installedStandards)) {
            return $this->installedStandards;
        }

        try {
            $process = $this->executeCommand([$this->phpcsCommand, '-i']);
            $output = $process->getOutput();
            
            if (preg_match('/The installed coding standards are (.+)/', $output, $matches)) {
                $standards = explode(', ', $matches[1]);
                $this->installedStandards = array_map('trim', $standards);
            }
        } catch (\Exception $e) {
            $this->logger->debug("Failed to get installed standards: " . $e->getMessage());
        }

        return $this->installedStandards;
    }

    /**
     * Set PHPCS command path
     */
    public function setCommand(string $command): self
    {
        $this->phpcsCommand = $command;
        return $this;
    }

    /**
     * Set PHPCS configuration file
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
     * Get available coding standards
     */
    public function getAvailableStandards(): array
    {
        return $this->getInstalledStandards();
    }

    /**
     * Install a coding standard
     */
    public function installStandard(string $standardPath): bool
    {
        try {
            $command = [$this->phpcsCommand, '--config-set', 'installed_paths', $standardPath];
            $process = $this->executeCommand($command);
            
            // Clear cached standards list
            $this->installedStandards = [];
            
            return $process->isSuccessful();
        } catch (\Exception $e) {
            $this->logger->error("Failed to install standard: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Generate PHPCS configuration file
     */
    public function generateConfigFile(string $outputPath, array $config = []): bool
    {
        $defaultConfig = [
            'default_standard' => $this->getConfiguration('standard', 'PSR12'),
            'show_warnings' => true,
            'show_progress' => false,
            'colors' => false,
            'cache' => true,
        ];

        $config = array_merge($defaultConfig, $config);

        $xmlContent = '<?xml version="1.0"?>' . "\n";
        $xmlContent .= '<ruleset name="ACQC PHPCS Configuration">' . "\n";
        $xmlContent .= '  <description>Custom PHPCS configuration for ACQC</description>' . "\n\n";

        // Add standard
        $xmlContent .= '  <rule ref="' . $config['default_standard'] . '"/>' . "\n\n";

        // Add file patterns
        $xmlContent .= '  <file>.</file>' . "\n\n";

        // Add ignore patterns
        foreach ($this->getConfiguration('ignore_patterns', []) as $pattern) {
            $xmlContent .= '  <exclude-pattern>' . $pattern . '</exclude-pattern>' . "\n";
        }

        // Add arguments
        $xmlContent .= "\n  <arg name=\"extensions\" value=\"" . 
                      implode(',', $this->getConfiguration('extensions', ['php'])) . '"/>' . "\n";
        
        if ($config['show_progress']) {
            $xmlContent .= '  <arg name="progress"/>' . "\n";
        }
        
        if (!$config['colors']) {
            $xmlContent .= '  <arg name="no-colors"/>' . "\n";
        }
        
        if ($config['cache']) {
            $xmlContent .= '  <arg name="cache"/>' . "\n";
        }

        $xmlContent .= '</ruleset>' . "\n";

        try {
            file_put_contents($outputPath, $xmlContent);
            $this->setConfigFile($outputPath);
            return true;
        } catch (\Exception $e) {
            $this->logger->error("Failed to generate config file: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Override to handle PHPCS-specific exit codes
     */
    protected function isExpectedFailure(\Symfony\Component\Process\Process $process): bool
    {
        // PHPCS returns:
        // 0 = no violations found
        // 1 = violations found
        // 2 = fixable violations found
        // 3 = processing error
        return in_array($process->getExitCode(), [0, 1, 2]);
    }
} 
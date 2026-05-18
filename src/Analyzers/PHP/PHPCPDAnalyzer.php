<?php

declare(strict_types=1);

namespace ACQC\Analyzers\PHP;

use ACQC\Analyzers\AbstractAnalyzer;
use ACQC\Analyzers\AnalyzerException;
use ACQC\Core\Rules\Violation;

class PHPCPDAnalyzer extends AbstractAnalyzer
{
    private string $phpcpdCommand = 'phpcpd';

    public function getName(): string
    {
        return 'phpcpd';
    }

    public function getVersion(): string
    {
        return $this->getToolVersion($this->phpcpdCommand, ['--version']);
    }

    public function isAvailable(): bool
    {
        return $this->commandExists($this->phpcpdCommand);
    }

    protected function getDefaultConfiguration(): array
    {
        return [
            'min_lines' => 5,
            'min_tokens' => 70,
            'suffixes' => ['php'],
            'exclude' => [
                'vendor',
                'node_modules',
                'var',
                'generated',
                'pub/static',
                'tests',
                'Test',
            ],
            'names' => [],
            'names_exclude' => [],
            'regexps_exclude' => [],
            'log_pmd' => true,
            'fuzzy' => false,
            'progress' => false,
            'ansi' => false,
            'quiet' => false,
            'verbose' => false,
        ];
    }

    protected function validateConfiguration(): void
    {
        parent::validateConfiguration();

        // Validate minimum lines
        $minLines = $this->getConfiguration('min_lines');
        if (!is_int($minLines) || $minLines < 1) {
            throw new AnalyzerException("Minimum lines must be a positive integer");
        }

        // Validate minimum tokens
        $minTokens = $this->getConfiguration('min_tokens');
        if (!is_int($minTokens) || $minTokens < 1) {
            throw new AnalyzerException("Minimum tokens must be a positive integer");
        }

        // Validate suffixes
        $suffixes = $this->getConfiguration('suffixes', []);
        if (empty($suffixes)) {
            throw new AnalyzerException("At least one file suffix must be specified");
        }
    }

    protected function executeAnalysis(array $files, array $options = []): array
    {
        $results = [];

        try {
            // PHPCPD works best with directories rather than individual files
            // Group files by directory for better performance
            $directories = $this->groupFilesByDirectory($files);

            foreach ($directories as $directory => $dirFiles) {
                $command = $this->buildCommand([$directory], $options);
                $process = $this->executeCommand($command);
                
                $output = $process->getOutput();
                if (!empty($output)) {
                    $results[] = $output;
                }

                // Log warnings from stderr
                $errorOutput = $process->getErrorOutput();
                if (!empty($errorOutput)) {
                    $this->logger->warning("PHPCPD stderr: " . $errorOutput);
                }
            }

            // If we have individual files not in common directories, process them separately
            $individualFiles = $this->getIndividualFiles($files, $directories);
            if (!empty($individualFiles)) {
                $command = $this->buildCommand($individualFiles, $options);
                $process = $this->executeCommand($command);
                
                $output = $process->getOutput();
                if (!empty($output)) {
                    $results[] = $output;
                }
            }

        } catch (\Exception $e) {
            $this->logger->error("PHPCPD analysis failed: " . $e->getMessage());
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
            // PHPCPD outputs XML in PMD format
            $violations = $this->parseXmlOutput($output);

        } catch (\Exception $e) {
            $this->logger->error("Failed to parse PHPCPD output: " . $e->getMessage());
            $this->logger->debug("Raw output: " . substr($output, 0, 1000));
        }

        return $violations;
    }

    /**
     * Parse XML output from PHPCPD
     */
    private function parseXmlOutput(string $xmlOutput): array
    {
        $violations = [];

        // Clean up the XML output
        $xmlOutput = trim($xmlOutput);
        if (empty($xmlOutput) || strpos($xmlOutput, '<pmd-cpd') === false) {
            return $violations;
        }

        try {
            $doc = new \DOMDocument();
            libxml_use_internal_errors(true);
            
            if (!$doc->loadXML($xmlOutput)) {
                $errors = libxml_get_errors();
                $errorMessages = array_map(fn($error) => $error->message, $errors);
                throw new AnalyzerException("XML parsing failed: " . implode(', ', $errorMessages));
            }

            $xpath = new \DOMXPath($doc);
            $duplicationNodes = $xpath->query('//duplication');

            foreach ($duplicationNodes as $duplicationNode) {
                $violationsFromDuplication = $this->createViolationsFromDuplication($duplicationNode, $xpath);
                $violations = array_merge($violations, $violationsFromDuplication);
            }

        } catch (\Exception $e) {
            throw new AnalyzerException("Failed to parse PHPCPD XML output: " . $e->getMessage());
        } finally {
            libxml_clear_errors();
        }

        return $violations;
    }

    /**
     * Create violations from duplication node
     */
    private function createViolationsFromDuplication(\DOMElement $duplicationNode, \DOMXPath $xpath): array
    {
        $violations = [];

        $lines = (int) $duplicationNode->getAttribute('lines');
        $tokens = (int) $duplicationNode->getAttribute('tokens');
        
        // Get all file nodes within this duplication
        $fileNodes = $xpath->query('.//file', $duplicationNode);
        
        if ($fileNodes->length < 2) {
            return $violations; // Need at least 2 files for duplication
        }

        // Get the duplicated code content
        $codeFragmentNodes = $xpath->query('.//codefragment', $duplicationNode);
        $codeFragment = '';
        if ($codeFragmentNodes->length > 0) {
            $codeFragment = trim($codeFragmentNodes->item(0)->textContent);
        }

        // Create violations for each file involved in the duplication
        $fileList = [];
        foreach ($fileNodes as $fileNode) {
            $filePath = $fileNode->getAttribute('path');
            $beginLine = (int) $fileNode->getAttribute('line');
            $endLine = $beginLine + $lines - 1;

            $fileList[] = [
                'path' => $filePath,
                'line' => $beginLine,
                'end_line' => $endLine,
            ];
        }

        // Create a violation for each file
        foreach ($fileList as $index => $file) {
            $otherFiles = array_filter($fileList, fn($f, $i) => $i !== $index, ARRAY_FILTER_USE_BOTH);
            
            $message = $this->buildDuplicationMessage($lines, $tokens, $otherFiles);
            
            $violation = $this->createViolation(
                'duplication',
                $message,
                $file['path'],
                $file['line'],
                0,
                $this->mapDuplicationSeverity($lines, $tokens),
                Violation::TYPE_MAINTAINABILITY,
                [
                    'duplication_lines' => $lines,
                    'duplication_tokens' => $tokens,
                    'end_line' => $file['end_line'],
                    'duplicated_files' => array_column($otherFiles, 'path'),
                    'code_fragment' => $codeFragment,
                ]
            );

            // Add source context
            $context = $this->extractSourceContext($file['path'], $file['line'], 3);
            if (!empty($context)) {
                $violation->setSource($context[$file['line']] ?? '');
                $violation->addContext('source_context', $context);
            }

            // Add suggestion
            $suggestion = $this->getDuplicationSuggestion($lines, $tokens, count($otherFiles));
            $violation->setSuggestion($suggestion);

            $violations[] = $violation;
        }

        return $violations;
    }

    /**
     * Build PHPCPD command
     */
    private function buildCommand(array $paths, array $options = []): array
    {
        $command = [$this->phpcpdCommand];

        // Add minimum lines
        $minLines = $this->getConfiguration('min_lines');
        if ($minLines) {
            $command[] = '--min-lines';
            $command[] = (string) $minLines;
        }

        // Add minimum tokens
        $minTokens = $this->getConfiguration('min_tokens');
        if ($minTokens) {
            $command[] = '--min-tokens';
            $command[] = (string) $minTokens;
        }

        // Add suffixes
        $suffixes = $this->getConfiguration('suffixes', ['php']);
        if (!empty($suffixes)) {
            $command[] = '--suffix';
            $command[] = implode(',', $suffixes);
        }

        // Add exclude patterns
        $excludes = array_merge(
            $this->getConfiguration('exclude', []),
            $this->excludePatterns
        );
        foreach ($excludes as $exclude) {
            $command[] = '--exclude';
            $command[] = $exclude;
        }

        // Add name patterns
        $names = $this->getConfiguration('names', []);
        foreach ($names as $name) {
            $command[] = '--names';
            $command[] = $name;
        }

        // Add exclude name patterns
        $namesExclude = $this->getConfiguration('names_exclude', []);
        foreach ($namesExclude as $nameExclude) {
            $command[] = '--names-exclude';
            $command[] = $nameExclude;
        }

        // Add exclude regex patterns
        $regexpsExclude = $this->getConfiguration('regexps_exclude', []);
        foreach ($regexpsExclude as $regexpExclude) {
            $command[] = '--regexps-exclude';
            $command[] = $regexpExclude;
        }

        // Add output format (PMD XML)
        if ($this->getConfiguration('log_pmd', true)) {
            $command[] = '--log-pmd';
            $command[] = 'php://stdout';
        }

        // Add fuzzy matching
        if ($this->getConfiguration('fuzzy', false)) {
            $command[] = '--fuzzy';
        }

        // Add progress indicator
        if ($this->getConfiguration('progress', false)) {
            $command[] = '--progress';
        }

        // Add ANSI colors
        if (!$this->getConfiguration('ansi', false)) {
            $command[] = '--no-ansi';
        }

        // Add quiet mode
        if ($this->getConfiguration('quiet', false)) {
            $command[] = '--quiet';
        }

        // Add verbose mode
        if ($this->getConfiguration('verbose', false)) {
            $command[] = '--verbose';
        }

        // Add paths
        foreach ($paths as $path) {
            $command[] = $path;
        }

        return $command;
    }

    /**
     * Group files by their parent directories
     */
    private function groupFilesByDirectory(array $files): array
    {
        $directories = [];

        foreach ($files as $file) {
            $directory = dirname($file);
            if (!isset($directories[$directory])) {
                $directories[$directory] = [];
            }
            $directories[$directory][] = $file;
        }

        // Only keep directories with multiple files for efficiency
        return array_filter($directories, fn($files) => count($files) > 1);
    }

    /**
     * Get individual files not covered by directory grouping
     */
    private function getIndividualFiles(array $allFiles, array $directoryGroups): array
    {
        $coveredFiles = [];
        foreach ($directoryGroups as $files) {
            $coveredFiles = array_merge($coveredFiles, $files);
        }

        return array_diff($allFiles, $coveredFiles);
    }

    /**
     * Build duplication message
     */
    private function buildDuplicationMessage(int $lines, int $tokens, array $otherFiles): string
    {
        $filesCount = count($otherFiles);
        $filesList = implode(', ', array_map(fn($f) => basename($f['path']), $otherFiles));
        
        return "Found {$lines} duplicate lines ({$tokens} tokens) also present in {$filesCount} other file(s): {$filesList}";
    }

    /**
     * Map duplication severity based on size
     */
    private function mapDuplicationSeverity(int $lines, int $tokens): string
    {
        // Large duplications are more critical
        if ($lines >= 50 || $tokens >= 500) {
            return Violation::SEVERITY_ERROR;
        }
        
        if ($lines >= 20 || $tokens >= 200) {
            return Violation::SEVERITY_WARNING;
        }
        
        return Violation::SEVERITY_INFO;
    }

    /**
     * Get suggestion for duplication
     */
    private function getDuplicationSuggestion(int $lines, int $tokens, int $fileCount): string
    {
        if ($lines >= 50) {
            return "Large code duplication detected. Consider extracting this into a shared method or class.";
        }
        
        if ($lines >= 20) {
            return "Significant code duplication found. Refactor into a reusable function or method.";
        }
        
        if ($fileCount > 2) {
            return "Code is duplicated across multiple files. Consider creating a utility function.";
        }
        
        return "Extract duplicated code into a shared method to improve maintainability.";
    }

    /**
     * Set PHPCPD command path
     */
    public function setCommand(string $command): self
    {
        $this->phpcpdCommand = $command;
        return $this;
    }

    /**
     * Get duplication statistics
     */
    public function getDuplicationStatistics(array $violations): array
    {
        $stats = [
            'total_duplications' => 0,
            'total_lines' => 0,
            'total_tokens' => 0,
            'affected_files' => [],
            'by_severity' => [
                Violation::SEVERITY_ERROR => 0,
                Violation::SEVERITY_WARNING => 0,
                Violation::SEVERITY_INFO => 0,
            ],
        ];

        foreach ($violations as $violation) {
            if ($violation->getCategory() === $this->getName()) {
                $stats['total_duplications']++;
                $stats['total_lines'] += $violation->getMetadataValue('duplication_lines', 0);
                $stats['total_tokens'] += $violation->getMetadataValue('duplication_tokens', 0);
                $stats['affected_files'][] = $violation->getFilePath();
                $stats['by_severity'][$violation->getSeverity()]++;
            }
        }

        $stats['affected_files'] = array_unique($stats['affected_files']);
        $stats['affected_file_count'] = count($stats['affected_files']);

        return $stats;
    }

    /**
     * Override to handle PHPCPD-specific exit codes
     */
    protected function isExpectedFailure(\Symfony\Component\Process\Process $process): bool
    {
        // PHPCPD returns:
        // 0 = no duplications found
        // 1 = duplications found
        // 2 = error in execution
        return in_array($process->getExitCode(), [0, 1]);
    }
} 
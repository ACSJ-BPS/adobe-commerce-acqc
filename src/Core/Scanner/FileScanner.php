<?php

declare(strict_types=1);

namespace ACQC\Core\Scanner;

use ACQC\Analyzers\AnalyzerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class FileScanner
{
    private array $analyzers = [];
    private LoggerInterface $logger;
    private array $metrics = [];

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Add analyzer to the scanner
     */
    public function addAnalyzer(AnalyzerInterface $analyzer): void
    {
        $this->analyzers[] = $analyzer;
    }

    /**
     * Set analyzers for the scanner
     */
    public function setAnalyzers(array $analyzers): void
    {
        $this->analyzers = [];
        foreach ($analyzers as $analyzer) {
            if ($analyzer instanceof AnalyzerInterface) {
                $this->addAnalyzer($analyzer);
            }
        }
    }

    /**
     * Scan a single file
     */
    public function scanFile(string $filePath, ScanContext $context): ScanResult
    {
        $startTime = microtime(true);
        $result = new ScanResult($filePath);

        try {
            // Validate file existence and readability
            if (!$this->validateFile($filePath, $result)) {
                $result->setScanDuration(microtime(true) - $startTime);
                return $result;
            }

            // Set file metadata
            $this->setFileMetadata($filePath, $result, $context);

            // Calculate checksum if enabled
            if ($context->isChecksumEnabled()) {
                $result->setChecksum($this->calculateFileChecksum($filePath));
            }

            // Read file content
            $content = $this->readFileContent($filePath, $context, $result);
            if ($content === null) {
                $result->setScanDuration(microtime(true) - $startTime);
                return $result;
            }

            // Run analyzers
            $this->runAnalyzers($filePath, $content, $result, $context);

            // Calculate file metrics
            if ($context->getScanOption('calculate_metrics', true)) {
                $this->calculateFileMetrics($filePath, $content, $result);
            }

            $this->logger->debug("Successfully scanned file: {$filePath}");

        } catch (\Exception $e) {
            $result->addError("Scan failed: " . $e->getMessage(), get_class($e));
            $this->logger->error("Failed to scan file {$filePath}: " . $e->getMessage());
        }

        $result->setScanDuration(microtime(true) - $startTime);
        return $result;
    }

    /**
     * Validate file before scanning
     */
    private function validateFile(string $filePath, ScanResult $result): bool
    {
        if (!file_exists($filePath)) {
            $result->addError("File does not exist: {$filePath}");
            return false;
        }

        if (!is_readable($filePath)) {
            $result->addError("File is not readable: {$filePath}");
            return false;
        }

        if (is_dir($filePath)) {
            $result->addError("Path is a directory, not a file: {$filePath}");
            return false;
        }

        return true;
    }

    /**
     * Set file metadata
     */
    private function setFileMetadata(string $filePath, ScanResult $result, ScanContext $context): void
    {
        $fileSize = filesize($filePath);
        $result->setFileSize($fileSize);
        
        $fileType = $context->detectFileType($filePath);
        $result->setFileType($fileType);

        // Additional metadata
        $stat = stat($filePath);
        $result->setMetadata('modified_time', date('Y-m-d H:i:s', $stat['mtime']));
        $result->setMetadata('permissions', substr(sprintf('%o', $stat['mode']), -4));
        $result->setMetadata('owner', function_exists('posix_getpwuid') ? 
            (posix_getpwuid($stat['uid'])['name'] ?? $stat['uid']) : $stat['uid']);
    }

    /**
     * Calculate file checksum
     */
    private function calculateFileChecksum(string $filePath): string
    {
        return md5_file($filePath);
    }

    /**
     * Read file content with memory optimization
     */
    private function readFileContent(string $filePath, ScanContext $context, ScanResult $result): ?string
    {
        $fileSize = $result->getFileSize();
        
        // Check file size limits
        if (!$context->isFileSizeAcceptable($filePath)) {
            $result->addError("File too large to scan: {$fileSize} bytes (limit: {$context->getMaxFileSize()})");
            return null;
        }

        try {
            // For very large files, use streaming approach
            if ($context->isMemoryOptimizationEnabled() && $fileSize > 1048576) { // 1MB
                return $this->readLargeFile($filePath, $result);
            } else {
                return file_get_contents($filePath);
            }
        } catch (\Exception $e) {
            $result->addError("Failed to read file content: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Read large file with memory optimization
     */
    private function readLargeFile(string $filePath, ScanResult $result): ?string
    {
        $handle = fopen($filePath, 'r');
        if (!$handle) {
            $result->addError("Cannot open file for reading");
            return null;
        }

        $content = '';
        $chunkSize = 8192; // 8KB chunks
        
        while (!feof($handle)) {
            $chunk = fread($handle, $chunkSize);
            if ($chunk === false) {
                fclose($handle);
                $result->addError("Error reading file content");
                return null;
            }
            $content .= $chunk;
            
            // Memory usage check
            if (memory_get_usage() > (1024 * 1024 * 512)) { // 512MB limit
                fclose($handle);
                $result->addError("Memory limit exceeded while reading file");
                return null;
            }
        }

        fclose($handle);
        return $content;
    }

    /**
     * Run all analyzers on the file
     */
    private function runAnalyzers(string $filePath, string $content, ScanResult $result, ScanContext $context): void
    {
        if (empty($this->analyzers)) {
            $this->logger->warning("No analyzers configured for scanning");
            return;
        }

        $enabledAnalyzers = $context->getAnalyzers();
        
        foreach ($this->analyzers as $analyzer) {
            try {
                $analyzerName = $analyzer->getName();
                
                // Skip analyzer if not enabled in context
                if (!empty($enabledAnalyzers) && !in_array($analyzerName, $enabledAnalyzers)) {
                    continue;
                }

                $analyzerStartTime = microtime(true);
                
                // Run analyzer
                $issues = $analyzer->analyze($filePath, $content);
                
                // Add issues to result
                foreach ($issues as $issue) {
                    $result->addIssue(array_merge($issue, [
                        'analyzer' => $analyzerName,
                        'file_path' => $filePath,
                    ]));
                }

                // Track analyzer performance
                $analyzerDuration = microtime(true) - $analyzerStartTime;
                $result->setMetadata("analyzer_duration_{$analyzerName}", $analyzerDuration);

                $this->logger->debug("Analyzer {$analyzerName} found " . count($issues) . " issues in {$filePath}");

            } catch (\Exception $e) {
                $result->addError("Analyzer {$analyzer->getName()} failed: " . $e->getMessage());
                $this->logger->error("Analyzer {$analyzer->getName()} failed on {$filePath}: " . $e->getMessage());
            }
        }
    }

    /**
     * Calculate file metrics
     */
    private function calculateFileMetrics(string $filePath, string $content, ScanResult $result): void
    {
        try {
            $metrics = [];
            
            // Basic metrics
            $metrics['file_size'] = strlen($content);
            $metrics['line_count'] = substr_count($content, "\n") + 1;
            $metrics['character_count'] = strlen($content);
            $metrics['word_count'] = str_word_count($content);
            
            // File type specific metrics
            $fileType = $result->getFileType();
            
            switch ($fileType) {
                case 'php':
                    $metrics = array_merge($metrics, $this->calculatePhpMetrics($content));
                    break;
                case 'javascript':
                    $metrics = array_merge($metrics, $this->calculateJavaScriptMetrics($content));
                    break;
                case 'xml':
                    $metrics = array_merge($metrics, $this->calculateXmlMetrics($content));
                    break;
                case 'css':
                    $metrics = array_merge($metrics, $this->calculateCssMetrics($content));
                    break;
            }

            // Code complexity metrics (for all code files)
            if (in_array($fileType, ['php', 'javascript'])) {
                $metrics = array_merge($metrics, $this->calculateComplexityMetrics($content, $fileType));
            }

            $result->setMetrics($metrics);

        } catch (\Exception $e) {
            $result->addError("Failed to calculate metrics: " . $e->getMessage());
            $this->logger->error("Failed to calculate metrics for {$filePath}: " . $e->getMessage());
        }
    }

    /**
     * Calculate PHP-specific metrics
     */
    private function calculatePhpMetrics(string $content): array
    {
        $metrics = [];
        
        // Count PHP constructs
        $metrics['class_count'] = preg_match_all('/\bclass\s+\w+/i', $content);
        $metrics['interface_count'] = preg_match_all('/\binterface\s+\w+/i', $content);
        $metrics['trait_count'] = preg_match_all('/\btrait\s+\w+/i', $content);
        $metrics['function_count'] = preg_match_all('/\bfunction\s+\w+/i', $content);
        $metrics['method_count'] = preg_match_all('/\b(?:public|private|protected)\s+function\s+\w+/i', $content);
        
        // Count comments
        $metrics['comment_lines'] = preg_match_all('/^\s*\/\/|^\s*\/\*|\*\/|^\s*\*/m', $content);
        
        // Calculate comment ratio
        $totalLines = substr_count($content, "\n") + 1;
        $metrics['comment_ratio'] = $totalLines > 0 ? round($metrics['comment_lines'] / $totalLines, 3) : 0;
        
        return $metrics;
    }

    /**
     * Calculate JavaScript-specific metrics
     */
    private function calculateJavaScriptMetrics(string $content): array
    {
        $metrics = [];
        
        // Count JavaScript constructs
        $metrics['function_count'] = preg_match_all('/\bfunction\s+\w+|=>\s*{|=>\s*[^{]/i', $content);
        $metrics['class_count'] = preg_match_all('/\bclass\s+\w+/i', $content);
        $metrics['var_declarations'] = preg_match_all('/\b(?:var|let|const)\s+\w+/i', $content);
        
        // Count comments
        $metrics['comment_lines'] = preg_match_all('/^\s*\/\/|^\s*\/\*|\*\//m', $content);
        
        return $metrics;
    }

    /**
     * Calculate XML-specific metrics
     */
    private function calculateXmlMetrics(string $content): array
    {
        $metrics = [];
        
        try {
            // Count XML elements
            $metrics['element_count'] = preg_match_all('/<[^\/!][^>]*>/i', $content);
            $metrics['attribute_count'] = preg_match_all('/\s\w+\s*=\s*["\'][^"\']*["\']/i', $content);
            $metrics['comment_count'] = preg_match_all('/<!--.*?-->/s', $content);
            
            // Check if well-formed XML
            libxml_use_internal_errors(true);
            $doc = simplexml_load_string($content);
            $metrics['well_formed'] = $doc !== false;
            $xmlErrors = libxml_get_errors();
            $metrics['xml_errors'] = count($xmlErrors);
            libxml_clear_errors();
            
        } catch (\Exception $e) {
            $metrics['well_formed'] = false;
            $metrics['xml_errors'] = 1;
        }
        
        return $metrics;
    }

    /**
     * Calculate CSS-specific metrics
     */
    private function calculateCssMetrics(string $content): array
    {
        $metrics = [];
        
        // Count CSS constructs
        $metrics['rule_count'] = preg_match_all('/[^{}]+\s*{[^{}]*}/i', $content);
        $metrics['selector_count'] = preg_match_all('/[^{}]+(?=\s*{)/i', $content);
        $metrics['property_count'] = preg_match_all('/[^;{}]+\s*:\s*[^;{}]+;/i', $content);
        $metrics['comment_count'] = preg_match_all('/\/\*.*?\*\//s', $content);
        
        return $metrics;
    }

    /**
     * Calculate complexity metrics
     */
    private function calculateComplexityMetrics(string $content, string $fileType): array
    {
        $metrics = [];
        
        // Cyclomatic complexity indicators
        $complexityKeywords = [
            'if', 'else', 'elseif', 'while', 'for', 'foreach', 'switch', 'case',
            'catch', 'try', '&&', '||', '?:', '??'
        ];
        
        $totalComplexity = 0;
        foreach ($complexityKeywords as $keyword) {
            $count = preg_match_all('/\b' . preg_quote($keyword, '/') . '\b/i', $content);
            $totalComplexity += $count;
        }
        
        $metrics['cyclomatic_complexity'] = $totalComplexity;
        
        // Nesting depth (approximate)
        $maxNesting = 0;
        $currentNesting = 0;
        $lines = explode("\n", $content);
        
        foreach ($lines as $line) {
            $openBraces = substr_count($line, '{');
            $closeBraces = substr_count($line, '}');
            $currentNesting += $openBraces - $closeBraces;
            $maxNesting = max($maxNesting, $currentNesting);
        }
        
        $metrics['max_nesting_depth'] = $maxNesting;
        
        return $metrics;
    }

    /**
     * Get scanner metrics
     */
    public function getMetrics(): array
    {
        return $this->metrics;
    }

    /**
     * Reset scanner metrics
     */
    public function resetMetrics(): void
    {
        $this->metrics = [];
    }

    /**
     * Get number of configured analyzers
     */
    public function getAnalyzerCount(): int
    {
        return count($this->analyzers);
    }

    /**
     * Get list of analyzer names
     */
    public function getAnalyzerNames(): array
    {
        return array_map(function($analyzer) {
            return $analyzer->getName();
        }, $this->analyzers);
    }
} 
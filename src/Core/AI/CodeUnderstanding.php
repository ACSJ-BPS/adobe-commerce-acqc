<?php

declare(strict_types=1);

namespace ACQC\Core\AI;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * AI-powered code understanding and comprehension
 * 
 * Analyzes code structure, patterns, and context to provide
 * intelligent insights about code quality and architecture.
 */
class CodeUnderstanding
{
    private AIEngine $aiEngine;
    private LoggerInterface $logger;
    private array $cache = [];
    private int $cacheTTL = 3600; // 1 hour

    public function __construct(AIEngine $aiEngine, ?LoggerInterface $logger = null)
    {
        $this->aiEngine = $aiEngine;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Analyze code using AI understanding
     */
    public function analyzeCode(
        string $code, 
        string $filePath, 
        array $context = []
    ): AICodeAnalysis {
        $cacheKey = $this->getCacheKey($code, $filePath, $context);
        
        // Check cache first
        if (isset($this->cache[$cacheKey])) {
            $cached = $this->cache[$cacheKey];
            if (time() - $cached['timestamp'] < $this->cacheTTL) {
                return $cached['result'];
            }
        }

        try {
            $analysis = $this->performCodeAnalysis($code, $filePath, $context);
            
            // Cache the result
            $this->cache[$cacheKey] = [
                'result' => $analysis,
                'timestamp' => time()
            ];
            
            return $analysis;
            
        } catch (\Exception $e) {
            $this->logger->error('Code understanding analysis failed', [
                'file' => $filePath,
                'error' => $e->getMessage()
            ]);
            return new AICodeAnalysis(false, 'Analysis failed: ' . $e->getMessage());
        }
    }

    /**
     * Perform actual code analysis
     */
    private function performCodeAnalysis(
        string $code, 
        string $filePath, 
        array $context
    ): AICodeAnalysis {
        $provider = $this->aiEngine->getProvider();
        if (!$provider) {
            return new AICodeAnalysis(false, 'No AI provider available');
        }

        // Prepare analysis context
        $analysisContext = $this->prepareAnalysisContext($code, $filePath, $context);
        
        // Get appropriate prompt
        $prompt = $this->aiEngine->getPromptManager()->getPrompt('code_understanding', $analysisContext);
        
        // Get AI response
        $response = $provider->chat($prompt);
        
        // Parse and return analysis
        return $this->parseCodeAnalysis($response, $filePath);
    }

    /**
     * Prepare context for analysis
     */
    private function prepareAnalysisContext(string $code, string $filePath, array $context): array
    {
        $fileInfo = $this->extractFileInfo($filePath);
        $codeMetrics = $this->calculateCodeMetrics($code);
        $patterns = $this->detectPatterns($code, $fileInfo['type']);
        
        return [
            'code' => $code,
            'file_path' => $filePath,
            'file_info' => $fileInfo,
            'code_metrics' => $codeMetrics,
            'detected_patterns' => $patterns,
            'project_context' => $context['project'] ?? [],
            'module_context' => $context['module'] ?? [],
            'dependencies' => $context['dependencies'] ?? [],
            'magento_version' => $context['magento_version'] ?? '2.4.6'
        ];
    }

    /**
     * Extract file information
     */
    private function extractFileInfo(string $filePath): array
    {
        $pathParts = explode('/', $filePath);
        $fileName = end($pathParts);
        $extension = pathinfo($fileName, PATHINFO_EXTENSION);
        
        $info = [
            'name' => $fileName,
            'extension' => $extension,
            'type' => $this->determineFileType($filePath, $extension),
            'directory' => dirname($filePath),
            'magento_context' => $this->extractMagentoContext($filePath)
        ];

        return $info;
    }

    /**
     * Determine file type based on path and extension
     */
    private function determineFileType(string $filePath, string $extension): string
    {
        // Magento-specific file types
        if (str_contains($filePath, '/Controller/')) {
            return 'controller';
        } elseif (str_contains($filePath, '/Model/')) {
            return 'model';
        } elseif (str_contains($filePath, '/Block/')) {
            return 'block';
        } elseif (str_contains($filePath, '/Helper/')) {
            return 'helper';
        } elseif (str_contains($filePath, '/Observer/')) {
            return 'observer';
        } elseif (str_contains($filePath, '/Plugin/')) {
            return 'plugin';
        } elseif (str_contains($filePath, '/Setup/')) {
            return 'setup';
        } elseif (str_contains($filePath, '/Api/')) {
            return 'api';
        } elseif (str_contains($filePath, '/Ui/')) {
            return 'ui_component';
        } elseif (str_contains($filePath, '/Test/')) {
            return 'test';
        } elseif (str_ends_with($filePath, '.phtml')) {
            return 'template';
        } elseif (str_ends_with($filePath, '.xml')) {
            return $this->determineXmlType($filePath);
        } elseif (str_ends_with($filePath, '.js')) {
            return 'javascript';
        } elseif (str_ends_with($filePath, '.css') || str_ends_with($filePath, '.scss')) {
            return 'stylesheet';
        }

        // Generic types
        switch ($extension) {
            case 'php':
                return 'php_class';
            case 'json':
                return 'json_config';
            case 'yaml':
            case 'yml':
                return 'yaml_config';
            default:
                return 'unknown';
        }
    }

    /**
     * Determine XML file type
     */
    private function determineXmlType(string $filePath): string
    {
        $fileName = basename($filePath);
        
        if ($fileName === 'module.xml') {
            return 'module_xml';
        } elseif ($fileName === 'di.xml') {
            return 'di_xml';
        } elseif ($fileName === 'events.xml') {
            return 'events_xml';
        } elseif ($fileName === 'acl.xml') {
            return 'acl_xml';
        } elseif ($fileName === 'system.xml') {
            return 'system_xml';
        } elseif ($fileName === 'db_schema.xml') {
            return 'db_schema_xml';
        } elseif (str_ends_with($fileName, '_menu.xml')) {
            return 'menu_xml';
        } elseif (str_ends_with($fileName, '.xml')) {
            return 'layout_xml';
        }
        
        return 'xml_config';
    }

    /**
     * Extract Magento-specific context
     */
    private function extractMagentoContext(string $filePath): array
    {
        $context = [
            'is_magento' => false,
            'module_name' => null,
            'vendor_name' => null,
            'area' => null
        ];

        // Check if it's a Magento module
        if (preg_match('#app/code/([^/]+)/([^/]+)/#', $filePath, $matches)) {
            $context['is_magento'] = true;
            $context['vendor_name'] = $matches[1];
            $context['module_name'] = $matches[2];
        } elseif (preg_match('#vendor/([^/]+)/module-([^/]+)/#', $filePath, $matches)) {
            $context['is_magento'] = true;
            $context['vendor_name'] = $matches[1];
            $context['module_name'] = $matches[2];
        }

        // Determine area
        if (str_contains($filePath, '/view/frontend/')) {
            $context['area'] = 'frontend';
        } elseif (str_contains($filePath, '/view/adminhtml/')) {
            $context['area'] = 'adminhtml';
        } elseif (str_contains($filePath, '/view/base/')) {
            $context['area'] = 'base';
        }

        return $context;
    }

    /**
     * Calculate basic code metrics
     */
    private function calculateCodeMetrics(string $code): array
    {
        $lines = explode("\n", $code);
        $totalLines = count($lines);
        $codeLines = 0;
        $commentLines = 0;
        $blankLines = 0;

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (empty($trimmed)) {
                $blankLines++;
            } elseif (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '#') || 
                     str_starts_with($trimmed, '/*') || str_starts_with($trimmed, '*')) {
                $commentLines++;
            } else {
                $codeLines++;
            }
        }

        return [
            'total_lines' => $totalLines,
            'code_lines' => $codeLines,
            'comment_lines' => $commentLines,
            'blank_lines' => $blankLines,
            'comment_ratio' => $totalLines > 0 ? $commentLines / $totalLines : 0,
            'estimated_complexity' => $this->estimateComplexity($code),
            'estimated_maintainability' => $this->estimateMaintainability($code, $codeLines)
        ];
    }

    /**
     * Estimate code complexity
     */
    private function estimateComplexity(string $code): int
    {
        $complexity = 1; // Base complexity
        
        // Count decision points
        $patterns = [
            '/\bif\s*\(/', '/\belseif\s*\(/', '/\belse\b/',
            '/\bfor\s*\(/', '/\bforeach\s*\(/', '/\bwhile\s*\(/',
            '/\bswitch\s*\(/', '/\bcase\s+/',
            '/\btry\s*{/', '/\bcatch\s*\(/',
            '/\b\?\s*.*\s*:/', // Ternary operator
            '/&&/', '/\|\|/' // Logical operators
        ];

        foreach ($patterns as $pattern) {
            $complexity += preg_match_all($pattern, $code);
        }

        return $complexity;
    }

    /**
     * Estimate maintainability index
     */
    private function estimateMaintainability(string $code, int $codeLines): float
    {
        if ($codeLines === 0) {
            return 100.0;
        }

        $complexity = $this->estimateComplexity($code);
        $halsteadVolume = $this->estimateHalsteadVolume($code);
        
        // Simplified maintainability index calculation
        $mi = 171 - 5.2 * log($halsteadVolume) - 0.23 * $complexity - 16.2 * log($codeLines);
        
        return max(0, min(100, $mi));
    }

    /**
     * Estimate Halstead volume (simplified)
     */
    private function estimateHalsteadVolume(string $code): float
    {
        // Count operators and operands (simplified)
        $operators = preg_match_all('/[+\-*\/=<>!&|]/', $code);
        $keywords = preg_match_all('/\b(if|else|for|while|switch|case|function|class|return|new)\b/', $code);
        
        $vocabulary = $operators + $keywords + 10; // Base vocabulary
        $length = strlen(str_replace([' ', "\n", "\t"], '', $code));
        
        return $length * log($vocabulary, 2);
    }

    /**
     * Detect common patterns in code
     */
    private function detectPatterns(string $code, string $fileType): array
    {
        $patterns = [];

        // Design patterns
        if (preg_match('/class\s+\w+Factory/', $code)) {
            $patterns[] = 'factory_pattern';
        }
        if (preg_match('/class\s+\w+Observer/', $code) || str_contains($code, 'ObserverInterface')) {
            $patterns[] = 'observer_pattern';
        }
        if (preg_match('/class\s+\w+Plugin/', $code)) {
            $patterns[] = 'plugin_pattern';
        }
        if (str_contains($code, 'SingletonInterface') || preg_match('/private\s+static\s+\$instance/', $code)) {
            $patterns[] = 'singleton_pattern';
        }

        // Magento-specific patterns
        if (str_contains($code, 'ObjectManager::getInstance()')) {
            $patterns[] = 'object_manager_usage';
        }
        if (str_contains($code, '__construct') && preg_match('/\\\.*Interface/', $code)) {
            $patterns[] = 'dependency_injection';
        }
        if (str_contains($code, 'getConnection()') || str_contains($code, 'rawQuery')) {
            $patterns[] = 'database_access';
        }
        if (str_contains($code, 'dispatch(') || str_contains($code, 'eventManager')) {
            $patterns[] = 'event_dispatch';
        }

        // Code quality patterns
        if (preg_match_all('/\btry\s*{/', $code) > 0) {
            $patterns[] = 'error_handling';
        }
        if (preg_match_all('/\bclass\s+\w+Test/', $code) > 0) {
            $patterns[] = 'unit_testing';
        }
        if (str_contains($code, '@api') || str_contains($code, 'Api\\')) {
            $patterns[] = 'api_interface';
        }

        return $patterns;
    }

    /**
     * Parse AI code analysis response
     */
    private function parseCodeAnalysis(string $response, string $filePath): AICodeAnalysis
    {
        try {
            $data = json_decode($response, true);
            
            if (!$data) {
                return new AICodeAnalysis(false, 'Invalid AI response format');
            }

            return new AICodeAnalysis(
                true,
                $data['summary'] ?? 'Code analysis completed',
                $data['insights'] ?? [],
                $data['quality_score'] ?? 0.0,
                $data['complexity_assessment'] ?? 'Unknown',
                $data['maintainability_score'] ?? 0.0,
                $data['architecture_feedback'] ?? '',
                $data['best_practices'] ?? [],
                $data['potential_issues'] ?? [],
                $data['improvement_suggestions'] ?? []
            );
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to parse AI code analysis', [
                'file' => $filePath,
                'error' => $e->getMessage(),
                'response' => substr($response, 0, 500)
            ]);
            return new AICodeAnalysis(false, 'Failed to parse analysis: ' . $e->getMessage());
        }
    }

    /**
     * Generate cache key
     */
    private function getCacheKey(string $code, string $filePath, array $context): string
    {
        return md5($code . $filePath . serialize($context));
    }

    /**
     * Clear analysis cache
     */
    public function clearCache(): void
    {
        $this->cache = [];
    }

    /**
     * Get cache statistics
     */
    public function getCacheStats(): array
    {
        $total = count($this->cache);
        $expired = 0;
        $now = time();

        foreach ($this->cache as $cached) {
            if ($now - $cached['timestamp'] >= $this->cacheTTL) {
                $expired++;
            }
        }

        return [
            'total_entries' => $total,
            'expired_entries' => $expired,
            'cache_hit_ratio' => $total > 0 ? ($total - $expired) / $total : 0,
            'cache_ttl' => $this->cacheTTL
        ];
    }
} 
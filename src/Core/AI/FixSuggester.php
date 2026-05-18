<?php

declare(strict_types=1);

namespace ACQC\Core\AI;

use ACQC\Core\Rules\Violation;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * AI-powered automated fix suggestion generator
 * 
 * Provides intelligent, context-aware fix suggestions for code violations
 * including code examples, step-by-step instructions, and best practices.
 */
class FixSuggester
{
    private AIEngine $aiEngine;
    private LoggerInterface $logger;
    private array $fixCache = [];
    private array $fixTemplates = [];

    public function __construct(AIEngine $aiEngine, ?LoggerInterface $logger = null)
    {
        $this->aiEngine = $aiEngine;
        $this->logger = $logger ?? new NullLogger();
        $this->initializeFixTemplates();
    }

    /**
     * Suggest fixes for violations using AI
     */
    public function suggestFixes(
        array $violations, 
        string $code, 
        array $context = []
    ): array {
        if (empty($violations)) {
            return [];
        }

        $fixSuggestions = [];
        $batchSize = 3; // Smaller batches for fix suggestions to maintain quality

        $violationBatches = array_chunk($violations, $batchSize);

        foreach ($violationBatches as $batch) {
            try {
                $batchFixes = $this->generateFixBatch($batch, $code, $context);
                $fixSuggestions = array_merge($fixSuggestions, $batchFixes);
            } catch (\Exception $e) {
                $this->logger->error('Failed to generate fix suggestions', [
                    'error' => $e->getMessage(),
                    'batch_size' => count($batch)
                ]);
                
                // Fallback to template-based fixes
                foreach ($batch as $violation) {
                    $fixSuggestions[] = $this->getTemplateFix($violation, $code);
                }
            }
        }

        return $fixSuggestions;
    }

    /**
     * Generate fixes for a batch of violations
     */
    private function generateFixBatch(
        array $violations, 
        string $code, 
        array $context
    ): array {
        $provider = $this->aiEngine->getProvider();
        if (!$provider) {
            return $this->getTemplateFixes($violations, $code);
        }

        // Prepare fix request data
        $fixData = $this->prepareFixData($violations, $code, $context);
        
        // Get fix suggestion prompt
        $prompt = $this->aiEngine->getPromptManager()->getPrompt('fix_suggestion', $fixData);
        
        // Get AI response
        $response = $provider->chat($prompt);
        
        // Parse and return fix suggestions
        return $this->parseFixResponse($response, $violations, $code);
    }

    /**
     * Prepare data for fix generation
     */
    private function prepareFixData(array $violations, string $code, array $context): array
    {
        $violationData = [];

        foreach ($violations as $index => $violation) {
            $codeSnippet = $this->extractExpandedCodeSnippet($code, $violation->getLine());
            
            $violationData[] = [
                'index' => $index,
                'rule_id' => $violation->getRuleId(),
                'message' => $violation->getMessage(),
                'severity' => $violation->getSeverity(),
                'type' => $violation->getType(),
                'file_path' => $violation->getFilePath(),
                'line' => $violation->getLine(),
                'column' => $violation->getColumn(),
                'code_snippet' => $codeSnippet,
                'surrounding_context' => $this->getMethodContext($code, $violation->getLine()),
                'file_type' => $this->determineFileType($violation->getFilePath()),
                'magento_context' => $this->getMagentoContext($violation->getFilePath())
            ];
        }

        return [
            'violations' => $violationData,
            'full_code' => strlen($code) < 3000 ? $code : '', // Include full code if manageable
            'project_context' => $context,
            'fix_style' => $context['fix_style'] ?? 'comprehensive', // comprehensive, minimal, or educational
            'include_explanations' => $context['include_explanations'] ?? true,
            'target_magento_version' => $context['magento_version'] ?? '2.4.6',
            'coding_standards' => $context['coding_standards'] ?? 'PSR-12'
        ];
    }

    /**
     * Extract expanded code snippet for better context
     */
    private function extractExpandedCodeSnippet(string $code, ?int $line, int $contextLines = 8): string
    {
        if (!$line || empty($code)) {
            return '';
        }

        $lines = explode("\n", $code);
        $startLine = max(0, $line - 1 - $contextLines);
        $endLine = min(count($lines) - 1, $line - 1 + $contextLines);

        $snippet = [];
        for ($i = $startLine; $i <= $endLine; $i++) {
            $lineNumber = $i + 1;
            $marker = ($lineNumber === $line) ? '>>> ' : '    ';
            $snippet[] = sprintf('%s%3d: %s', $marker, $lineNumber, $lines[$i] ?? '');
        }

        return implode("\n", $snippet);
    }

    /**
     * Get method context around violation
     */
    private function getMethodContext(string $code, ?int $line): string
    {
        if (!$line) {
            return '';
        }

        $lines = explode("\n", $code);
        $lineIndex = $line - 1;

        // Find method/function boundaries
        $methodStart = $lineIndex;
        $methodEnd = $lineIndex;

        // Search backwards for method start
        for ($i = $lineIndex; $i >= 0; $i--) {
            if (preg_match('/^\s*(public|private|protected)?\s*(static\s+)?function\s+/', $lines[$i])) {
                $methodStart = $i;
                break;
            }
            if (preg_match('/^\s*(class|interface|trait)\s+/', $lines[$i])) {
                break;
            }
        }

        // Search forwards for method end
        $braceCount = 0;
        $inMethod = false;
        for ($i = $methodStart; $i < count($lines); $i++) {
            $line = $lines[$i];
            if (str_contains($line, '{')) {
                $braceCount += substr_count($line, '{');
                $inMethod = true;
            }
            if (str_contains($line, '}')) {
                $braceCount -= substr_count($line, '}');
                if ($inMethod && $braceCount <= 0) {
                    $methodEnd = $i;
                    break;
                }
            }
        }

        $methodLines = array_slice($lines, $methodStart, $methodEnd - $methodStart + 1);
        return implode("\n", $methodLines);
    }

    /**
     * Determine file type from path
     */
    private function determineFileType(string $filePath): string
    {
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
        } elseif (str_ends_with($filePath, '.phtml')) {
            return 'template';
        } elseif (str_ends_with($filePath, '.xml')) {
            return 'xml_config';
        } else {
            return 'php_class';
        }
    }

    /**
     * Get Magento-specific context
     */
    private function getMagentoContext(string $filePath): array
    {
        $context = ['is_magento' => false];

        if (preg_match('#app/code/([^/]+)/([^/]+)/#', $filePath, $matches)) {
            $context = [
                'is_magento' => true,
                'vendor' => $matches[1],
                'module' => $matches[2],
                'namespace' => $matches[1] . '\\' . $matches[2]
            ];
        }

        // Determine area
        if (str_contains($filePath, '/view/frontend/')) {
            $context['area'] = 'frontend';
        } elseif (str_contains($filePath, '/view/adminhtml/')) {
            $context['area'] = 'adminhtml';
        }

        return $context;
    }

    /**
     * Parse AI fix response
     */
    private function parseFixResponse(string $response, array $violations, string $originalCode): array
    {
        try {
            $data = json_decode($response, true);
            
            if (!$data || !isset($data['fixes'])) {
                throw new \Exception('Invalid fix response format');
            }

            $fixSuggestions = [];
            foreach ($data['fixes'] as $index => $fixData) {
                if (isset($violations[$index])) {
                    $fixSuggestions[] = new FixSuggestion(
                        $violations[$index],
                        $fixData['summary'] ?? 'Code fix available',
                        $fixData['fix_type'] ?? 'refactor',
                        $fixData['original_code'] ?? '',
                        $fixData['fixed_code'] ?? '',
                        $fixData['diff'] ?? '',
                        $fixData['instructions'] ?? [],
                        $fixData['explanation'] ?? '',
                        $fixData['best_practices'] ?? [],
                        $fixData['testing_notes'] ?? '',
                        $fixData['risk_level'] ?? 'low',
                        $fixData['estimated_time'] ?? 'Unknown',
                        $fixData['confidence'] ?? 0.8,
                        $fixData['dependencies'] ?? [],
                        $fixData['alternative_approaches'] ?? []
                    );
                }
            }

            return $fixSuggestions;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to parse fix suggestions', [
                'error' => $e->getMessage(),
                'response' => substr($response, 0, 500)
            ]);
            
            // Fallback to template fixes
            return $this->getTemplateFixes($violations, $originalCode);
        }
    }

    /**
     * Get template-based fixes as fallback
     */
    private function getTemplateFixes(array $violations, string $code): array
    {
        $fixes = [];
        foreach ($violations as $violation) {
            $fixes[] = $this->getTemplateFix($violation, $code);
        }
        return $fixes;
    }

    /**
     * Get template fix for a violation
     */
    private function getTemplateFix(Violation $violation, string $code): FixSuggestion
    {
        $ruleId = $violation->getRuleId();
        $template = $this->getFixTemplate($ruleId);
        
        $originalCode = $this->extractExpandedCodeSnippet($code, $violation->getLine(), 5);
        $fixedCode = $this->applyTemplateFix($originalCode, $template);

        return new FixSuggestion(
            $violation,
            $template['summary'],
            $template['fix_type'],
            $originalCode,
            $fixedCode,
            $this->generateDiff($originalCode, $fixedCode),
            $template['instructions'],
            $template['explanation'],
            $template['best_practices'],
            $template['testing_notes'],
            $template['risk_level'],
            $template['estimated_time'],
            $template['confidence'],
            $template['dependencies'],
            $template['alternatives']
        );
    }

    /**
     * Initialize fix templates
     */
    private function initializeFixTemplates(): void
    {
        $this->fixTemplates = [
            'magento-di-object-manager-usage' => [
                'summary' => 'Replace ObjectManager with dependency injection',
                'fix_type' => 'refactor',
                'instructions' => [
                    '1. Add the dependency to the constructor parameters',
                    '2. Store the dependency as a private property',
                    '3. Remove ObjectManager::getInstance() calls',
                    '4. Update di.xml if necessary'
                ],
                'explanation' => 'Dependency injection improves testability and follows Magento best practices',
                'best_practices' => [
                    'Always inject dependencies through constructor',
                    'Use interfaces instead of concrete classes when possible',
                    'Keep constructor parameters under 10 for better maintainability'
                ],
                'testing_notes' => 'Mock the injected dependency in unit tests',
                'risk_level' => 'low',
                'estimated_time' => '15-30 minutes',
                'confidence' => 0.9,
                'dependencies' => ['di.xml configuration might need updates'],
                'alternatives' => ['Use factory pattern if object creation is conditional']
            ],
            'magento-security-sql-injection' => [
                'summary' => 'Replace raw SQL with parameterized queries',
                'fix_type' => 'security_fix',
                'instructions' => [
                    '1. Replace concatenated SQL with parameterized queries',
                    '2. Use collection methods when possible',
                    '3. Validate and sanitize all user inputs',
                    '4. Test with various input scenarios'
                ],
                'explanation' => 'Parameterized queries prevent SQL injection attacks',
                'best_practices' => [
                    'Never concatenate user input directly into SQL',
                    'Use Magento collections for complex queries',
                    'Validate input data types and ranges'
                ],
                'testing_notes' => 'Test with malicious input to ensure security',
                'risk_level' => 'high',
                'estimated_time' => '30-60 minutes',
                'confidence' => 0.95,
                'dependencies' => ['Database schema knowledge required'],
                'alternatives' => ['Use Magento Repository pattern', 'Use SearchCriteria for filtering']
            ]
        ];
    }

    /**
     * Get fix template for rule
     */
    private function getFixTemplate(string $ruleId): array
    {
        if (isset($this->fixTemplates[$ruleId])) {
            return $this->fixTemplates[$ruleId];
        }

        // Generate generic template based on rule category
        return $this->getGenericFixTemplate($ruleId);
    }

    /**
     * Get generic fix template
     */
    private function getGenericFixTemplate(string $ruleId): array
    {
        return [
            'summary' => 'Fix code quality issue',
            'fix_type' => 'refactor',
            'instructions' => ['Review and refactor the code according to best practices'],
            'explanation' => 'This change improves code quality and maintainability',
            'best_practices' => ['Follow coding standards', 'Write clean, readable code'],
            'testing_notes' => 'Test the functionality after applying the fix',
            'risk_level' => 'medium',
            'estimated_time' => '20-45 minutes',
            'confidence' => 0.7,
            'dependencies' => [],
            'alternatives' => []
        ];
    }

    /**
     * Apply template-based fix to code
     */
    private function applyTemplateFix(string $originalCode, array $template): string
    {
        // This is a simplified template-based fix
        // In a real implementation, you'd have more sophisticated pattern matching and replacement
        
        $fixedCode = $originalCode;
        
        // Apply common fixes based on template type
        switch ($template['fix_type']) {
            case 'refactor':
                $fixedCode = $this->applyRefactorFix($originalCode);
                break;
            case 'security_fix':
                $fixedCode = $this->applySecurityFix($originalCode);
                break;
            default:
                $fixedCode = $originalCode . "\n// TODO: Apply fix according to instructions above";
        }

        return $fixedCode;
    }

    /**
     * Apply refactor fix
     */
    private function applyRefactorFix(string $code): string
    {
        // Replace ObjectManager usage with dependency injection placeholder
        if (str_contains($code, 'ObjectManager::getInstance()')) {
            $code = str_replace(
                'ObjectManager::getInstance()',
                '$this->injectedDependency',
                $code
            );
            $code = "// TODO: Inject dependency in constructor\n" . $code;
        }

        return $code;
    }

    /**
     * Apply security fix
     */
    private function applySecurityFix(string $code): string
    {
        // Add placeholder for parameterized query
        if (str_contains($code, 'WHERE') && str_contains($code, '$')) {
            $code .= "\n// TODO: Use parameterized query instead of string concatenation";
        }

        return $code;
    }

    /**
     * Generate diff between original and fixed code
     */
    private function generateDiff(string $original, string $fixed): string
    {
        $originalLines = explode("\n", $original);
        $fixedLines = explode("\n", $fixed);

        $diff = [];
        $diff[] = '--- Original';
        $diff[] = '+++ Fixed';

        // Simple line-by-line diff
        $maxLines = max(count($originalLines), count($fixedLines));
        
        for ($i = 0; $i < $maxLines; $i++) {
            $originalLine = $originalLines[$i] ?? '';
            $fixedLine = $fixedLines[$i] ?? '';

            if ($originalLine !== $fixedLine) {
                if (!empty($originalLine)) {
                    $diff[] = '- ' . $originalLine;
                }
                if (!empty($fixedLine)) {
                    $diff[] = '+ ' . $fixedLine;
                }
            }
        }

        return implode("\n", $diff);
    }

    /**
     * Generate comprehensive fix report
     */
    public function generateFixReport(array $fixSuggestions): string
    {
        if (empty($fixSuggestions)) {
            return 'No fix suggestions available.';
        }

        $report = [];
        $report[] = sprintf('## Fix Suggestions Report (%d fixes)', count($fixSuggestions));
        $report[] = '';

        $riskCounts = ['low' => 0, 'medium' => 0, 'high' => 0];
        $typeCounts = [];

        foreach ($fixSuggestions as $fix) {
            $riskCounts[$fix->getRiskLevel()]++;
            $typeCounts[$fix->getFixType()] = ($typeCounts[$fix->getFixType()] ?? 0) + 1;
        }

        $report[] = '### Summary';
        $report[] = sprintf('- Low risk fixes: %d', $riskCounts['low']);
        $report[] = sprintf('- Medium risk fixes: %d', $riskCounts['medium']);
        $report[] = sprintf('- High risk fixes: %d', $riskCounts['high']);
        $report[] = '';

        $report[] = '### Recommendations';
        $report[] = '1. Start with low-risk fixes to build confidence';
        $report[] = '2. Test thoroughly after each fix, especially high-risk ones';
        $report[] = '3. Review security fixes with extra attention';
        $report[] = '4. Consider pair programming for complex refactoring';

        return implode("\n", $report);
    }
} 
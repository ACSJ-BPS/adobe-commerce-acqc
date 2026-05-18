<?php

declare(strict_types=1);

namespace ACQC\Core\AI;

use ACQC\Core\Rules\Violation;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * AI-powered violation explanation generator
 * 
 * Provides clear, educational explanations for code violations
 * using natural language processing and contextual understanding.
 */
class ViolationExplainer
{
    private AIEngine $aiEngine;
    private LoggerInterface $logger;
    private array $templateCache = [];
    private array $explanationCache = [];

    public function __construct(AIEngine $aiEngine, ?LoggerInterface $logger = null)
    {
        $this->aiEngine = $aiEngine;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Explain violations using AI-generated natural language
     */
    public function explainViolations(
        array $violations, 
        string $code = '', 
        array $context = []
    ): array {
        if (empty($violations)) {
            return [];
        }

        $explanations = [];
        $batchSize = 5; // Process violations in batches to manage token usage

        $violationBatches = array_chunk($violations, $batchSize);

        foreach ($violationBatches as $batch) {
            try {
                $batchExplanations = $this->explainViolationBatch($batch, $code, $context);
                $explanations = array_merge($explanations, $batchExplanations);
            } catch (\Exception $e) {
                $this->logger->error('Failed to explain violation batch', [
                    'error' => $e->getMessage(),
                    'batch_size' => count($batch)
                ]);
                
                // Fallback to template-based explanations
                foreach ($batch as $violation) {
                    $explanations[] = $this->getTemplateExplanation($violation);
                }
            }
        }

        return $explanations;
    }

    /**
     * Explain a batch of violations
     */
    private function explainViolationBatch(
        array $violations, 
        string $code, 
        array $context
    ): array {
        $provider = $this->aiEngine->getProvider();
        if (!$provider) {
            return $this->getTemplateExplanations($violations);
        }

        // Prepare violation data for AI analysis
        $violationData = $this->prepareViolationData($violations, $code, $context);
        
        // Get explanation prompt
        $prompt = $this->aiEngine->getPromptManager()->getPrompt('violation_explanation', $violationData);
        
        // Get AI response
        $response = $provider->chat($prompt);
        
        // Parse and return explanations
        return $this->parseExplanationResponse($response, $violations);
    }

    /**
     * Prepare violation data for AI processing
     */
    private function prepareViolationData(array $violations, string $code, array $context): array
    {
        $violationData = [];

        foreach ($violations as $index => $violation) {
            $violationData[] = [
                'index' => $index,
                'rule_id' => $violation->getRuleId(),
                'message' => $violation->getMessage(),
                'severity' => $violation->getSeverity(),
                'type' => $violation->getType(),
                'file_path' => $violation->getFilePath(),
                'line' => $violation->getLine(),
                'column' => $violation->getColumn(),
                'code_snippet' => $this->extractCodeSnippet($code, $violation->getLine()),
                'context' => $this->getViolationContext($violation, $context)
            ];
        }

        return [
            'violations' => $violationData,
            'full_code' => strlen($code) < 2000 ? $code : '', // Include full code if not too large
            'file_context' => $context,
            'explanation_level' => $context['explanation_level'] ?? 'intermediate',
            'include_examples' => $context['include_examples'] ?? true,
            'include_fixes' => $context['include_fixes'] ?? true
        ];
    }

    /**
     * Extract code snippet around violation line
     */
    private function extractCodeSnippet(string $code, ?int $line, int $contextLines = 3): string
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
     * Get violation-specific context
     */
    private function getViolationContext(Violation $violation, array $context): array
    {
        $violationContext = [
            'rule_category' => $this->getRuleCategory($violation->getRuleId()),
            'severity_level' => $violation->getSeverity(),
            'magento_specific' => $this->isMagentoSpecificRule($violation->getRuleId()),
        ];

        // Add file-specific context
        $filePath = $violation->getFilePath();
        if ($filePath) {
            $violationContext['file_type'] = $this->determineFileType($filePath);
            $violationContext['magento_component'] = $this->determineMagentoComponent($filePath);
        }

        return array_merge($violationContext, $context);
    }

    /**
     * Get rule category
     */
    private function getRuleCategory(string $ruleId): string
    {
        if (str_contains($ruleId, 'security')) {
            return 'security';
        } elseif (str_contains($ruleId, 'performance')) {
            return 'performance';
        } elseif (str_contains($ruleId, 'di') || str_contains($ruleId, 'dependency')) {
            return 'dependency_injection';
        } elseif (str_contains($ruleId, 'plugin')) {
            return 'plugin';
        } elseif (str_contains($ruleId, 'observer')) {
            return 'observer';
        } elseif (str_contains($ruleId, 'database') || str_contains($ruleId, 'db')) {
            return 'database';
        } elseif (str_contains($ruleId, 'upgrade') || str_contains($ruleId, 'compatibility')) {
            return 'upgrade_compatibility';
        } elseif (str_contains($ruleId, 'structure') || str_contains($ruleId, 'module')) {
            return 'module_structure';
        } else {
            return 'general';
        }
    }

    /**
     * Check if rule is Magento-specific
     */
    private function isMagentoSpecificRule(string $ruleId): bool
    {
        return str_starts_with($ruleId, 'magento-') || 
               str_contains($ruleId, 'magento') ||
               str_contains($ruleId, 'm2-');
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
     * Determine Magento component from path
     */
    private function determineMagentoComponent(string $filePath): string
    {
        if (preg_match('#app/code/([^/]+)/([^/]+)/#', $filePath, $matches)) {
            return $matches[1] . '_' . $matches[2];
        } elseif (preg_match('#vendor/([^/]+)/module-([^/]+)/#', $filePath, $matches)) {
            return $matches[1] . '_' . $matches[2];
        }
        return 'unknown';
    }

    /**
     * Parse AI explanation response
     */
    private function parseExplanationResponse(string $response, array $violations): array
    {
        try {
            $data = json_decode($response, true);
            
            if (!$data || !isset($data['explanations'])) {
                throw new \Exception('Invalid response format');
            }

            $explanations = [];
            foreach ($data['explanations'] as $index => $explanationData) {
                if (isset($violations[$index])) {
                    $explanations[] = new ViolationExplanation(
                        $violations[$index],
                        $explanationData['summary'] ?? 'Code quality violation detected',
                        $explanationData['detailed_explanation'] ?? '',
                        $explanationData['why_its_a_problem'] ?? '',
                        $explanationData['how_to_fix'] ?? '',
                        $explanationData['example_code'] ?? '',
                        $explanationData['learning_resources'] ?? [],
                        $explanationData['difficulty_level'] ?? 'intermediate',
                        $explanationData['estimated_fix_time'] ?? 'Unknown'
                    );
                }
            }

            return $explanations;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to parse violation explanations', [
                'error' => $e->getMessage(),
                'response' => substr($response, 0, 500)
            ]);
            
            // Fallback to template explanations
            return $this->getTemplateExplanations($violations);
        }
    }

    /**
     * Get template-based explanations as fallback
     */
    private function getTemplateExplanations(array $violations): array
    {
        $explanations = [];
        foreach ($violations as $violation) {
            $explanations[] = $this->getTemplateExplanation($violation);
        }
        return $explanations;
    }

    /**
     * Get template explanation for a violation
     */
    private function getTemplateExplanation(Violation $violation): ViolationExplanation
    {
        $ruleId = $violation->getRuleId();
        $template = $this->getExplanationTemplate($ruleId);

        return new ViolationExplanation(
            $violation,
            $template['summary'],
            $template['explanation'],
            $template['problem'],
            $template['fix'],
            $template['example'] ?? '',
            $template['resources'] ?? [],
            $template['difficulty'] ?? 'intermediate',
            $template['fix_time'] ?? 'Unknown'
        );
    }

    /**
     * Get explanation template for rule
     */
    private function getExplanationTemplate(string $ruleId): array
    {
        if (isset($this->templateCache[$ruleId])) {
            return $this->templateCache[$ruleId];
        }

        $template = $this->loadExplanationTemplate($ruleId);
        $this->templateCache[$ruleId] = $template;
        return $template;
    }

    /**
     * Load explanation template from configuration or defaults
     */
    private function loadExplanationTemplate(string $ruleId): array
    {
        // Define default templates for common rule patterns
        $templates = [
            'magento-di-object-manager-usage' => [
                'summary' => 'Direct ObjectManager usage detected',
                'explanation' => 'Using ObjectManager directly violates Magento\'s dependency injection principles and makes code harder to test and maintain.',
                'problem' => 'Direct ObjectManager usage creates tight coupling, makes unit testing difficult, and bypasses Magento\'s dependency injection container.',
                'fix' => 'Inject dependencies through the constructor instead of using ObjectManager::getInstance()',
                'difficulty' => 'intermediate',
                'fix_time' => '10-30 minutes'
            ],
            'magento-security-sql-injection' => [
                'summary' => 'Potential SQL injection vulnerability',
                'explanation' => 'Raw SQL queries with user input can lead to SQL injection attacks, compromising database security.',
                'problem' => 'Unescaped user input in SQL queries allows attackers to manipulate database queries and potentially access or modify sensitive data.',
                'fix' => 'Use parameterized queries, prepared statements, or Magento\'s collection methods with proper filtering.',
                'difficulty' => 'intermediate',
                'fix_time' => '15-45 minutes'
            ],
            'magento-performance-n-plus-one' => [
                'summary' => 'N+1 query problem detected',
                'explanation' => 'Loading related data in a loop creates multiple database queries instead of one efficient query.',
                'problem' => 'Each iteration of the loop triggers a separate database query, leading to poor performance and increased server load.',
                'fix' => 'Use collection methods with joins or eager loading to fetch all required data in a single query.',
                'difficulty' => 'intermediate',
                'fix_time' => '20-60 minutes'
            ]
        ];

        if (isset($templates[$ruleId])) {
            return $templates[$ruleId];
        }

        // Generate generic template based on rule category
        $category = $this->getRuleCategory($ruleId);
        return $this->getGenericTemplate($category, $ruleId);
    }

    /**
     * Get generic template based on category
     */
    private function getGenericTemplate(string $category, string $ruleId): array
    {
        $categoryTemplates = [
            'security' => [
                'summary' => 'Security vulnerability detected',
                'explanation' => 'This code pattern may introduce security risks to your application.',
                'problem' => 'Security vulnerabilities can be exploited by attackers to compromise your application.',
                'fix' => 'Review the code and apply appropriate security measures.',
                'difficulty' => 'intermediate',
                'fix_time' => '15-60 minutes'
            ],
            'performance' => [
                'summary' => 'Performance issue detected',
                'explanation' => 'This code pattern may negatively impact application performance.',
                'problem' => 'Performance issues can lead to slow response times and poor user experience.',
                'fix' => 'Optimize the code to improve performance.',
                'difficulty' => 'intermediate',
                'fix_time' => '20-90 minutes'
            ],
            'dependency_injection' => [
                'summary' => 'Dependency injection violation',
                'explanation' => 'This code doesn\'t follow Magento\'s dependency injection best practices.',
                'problem' => 'Poor dependency injection makes code harder to test and maintain.',
                'fix' => 'Use proper dependency injection through constructor parameters.',
                'difficulty' => 'intermediate',
                'fix_time' => '10-30 minutes'
            ]
        ];

        return $categoryTemplates[$category] ?? [
            'summary' => 'Code quality issue detected',
            'explanation' => 'This code pattern may impact code quality or maintainability.',
            'problem' => 'Code quality issues can make the codebase harder to maintain.',
            'fix' => 'Review and refactor the code according to best practices.',
            'difficulty' => 'intermediate',
            'fix_time' => '15-45 minutes'
        ];
    }

    /**
     * Generate summary explanation for multiple violations
     */
    public function generateSummaryExplanation(array $explanations): string
    {
        if (empty($explanations)) {
            return 'No violations found.';
        }

        $severityCount = ['critical' => 0, 'error' => 0, 'warning' => 0, 'info' => 0];
        $categoryCount = [];

        foreach ($explanations as $explanation) {
            $violation = $explanation->getViolation();
            $severityCount[$violation->getSeverity()]++;
            
            $category = $this->getRuleCategory($violation->getRuleId());
            $categoryCount[$category] = ($categoryCount[$category] ?? 0) + 1;
        }

        $summary = sprintf(
            'Found %d code quality issues: %d critical, %d errors, %d warnings, %d info. ',
            count($explanations),
            $severityCount['critical'],
            $severityCount['error'],
            $severityCount['warning'],
            $severityCount['info']
        );

        $topCategories = array_slice(
            array_keys(arsort($categoryCount) ? $categoryCount : []), 
            0, 
            3
        );

        if (!empty($topCategories)) {
            $summary .= 'Main areas of concern: ' . implode(', ', $topCategories) . '.';
        }

        return $summary;
    }
} 
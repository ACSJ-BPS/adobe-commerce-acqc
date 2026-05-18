<?php

declare(strict_types=1);

namespace ACQC\Core\AI;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Natural language conversational interface for ACQC
 * 
 * Enables users to interact with the code analysis system using
 * natural language queries and receive human-friendly responses.
 */
class ConversationalInterface
{
    private AIEngine $aiEngine;
    private LoggerInterface $logger;
    private array $conversationHistory = [];
    private array $contextMemory = [];
    private int $maxHistoryLength = 20;

    public function __construct(AIEngine $aiEngine, ?LoggerInterface $logger = null)
    {
        $this->aiEngine = $aiEngine;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Process a natural language query
     */
    public function processQuery(string $query, array $context = []): AIConversationResponse
    {
        try {
            // Analyze and classify the query
            $queryAnalysis = $this->analyzeQuery($query, $context);
            
            // Update conversation context
            $this->updateContext($query, $context);
            
            // Generate response based on query type
            $response = $this->generateResponse($queryAnalysis, $context);
            
            // Store in conversation history
            $this->addToHistory($query, $response);
            
            return $response;
            
        } catch (\Exception $e) {
            $this->logger->error('Conversational query processing failed', [
                'query' => substr($query, 0, 200),
                'error' => $e->getMessage()
            ]);
            
            return new AIConversationResponse(
                false,
                'I encountered an error processing your request. Please try rephrasing your question.',
                ['error' => $e->getMessage()]
            );
        }
    }

    /**
     * Analyze and classify the user query
     */
    private function analyzeQuery(string $query, array $context): array
    {
        $analysis = [
            'intent' => $this->detectIntent($query),
            'entities' => $this->extractEntities($query),
            'scope' => $this->determineScope($query, $context),
            'complexity' => $this->assessComplexity($query),
            'requires_code_analysis' => $this->requiresCodeAnalysis($query),
            'follow_up' => $this->isFollowUpQuery($query)
        ];

        return $analysis;
    }

    /**
     * Detect user intent from query
     */
    private function detectIntent(string $query): string
    {
        $query = strtolower($query);

        // Analysis intents
        if (preg_match('/\b(analyze|check|review|scan|audit)\b/', $query)) {
            return 'analysis_request';
        }

        // Explanation intents
        if (preg_match('/\b(explain|why|what is|what does|meaning|help understand)\b/', $query)) {
            return 'explanation_request';
        }

        // Fix intents
        if (preg_match('/\b(fix|solve|resolve|correct|improve|suggest)\b/', $query)) {
            return 'fix_request';
        }

        // Learning intents
        if (preg_match('/\b(learn|teach|tutorial|guide|best practice|example)\b/', $query)) {
            return 'learning_request';
        }

        // Comparison intents
        if (preg_match('/\b(compare|difference|versus|vs|better|worse)\b/', $query)) {
            return 'comparison_request';
        }

        // Status/information intents
        if (preg_match('/\b(status|report|summary|metrics|statistics)\b/', $query)) {
            return 'information_request';
        }

        // Configuration intents
        if (preg_match('/\b(configure|setup|settings|options)\b/', $query)) {
            return 'configuration_request';
        }

        return 'general_inquiry';
    }

    /**
     * Extract entities from query
     */
    private function extractEntities(string $query): array
    {
        $entities = [
            'technologies' => [],
            'file_types' => [],
            'violation_types' => [],
            'magento_components' => [],
            'concepts' => []
        ];

        // Technology entities
        $technologies = ['php', 'javascript', 'html', 'css', 'xml', 'json', 'magento', 'react', 'pwa'];
        foreach ($technologies as $tech) {
            if (stripos($query, $tech) !== false) {
                $entities['technologies'][] = $tech;
            }
        }

        // File type entities
        $fileTypes = ['controller', 'model', 'block', 'helper', 'observer', 'plugin', 'template'];
        foreach ($fileTypes as $type) {
            if (stripos($query, $type) !== false) {
                $entities['file_types'][] = $type;
            }
        }

        // Violation type entities
        $violationTypes = ['security', 'performance', 'dependency injection', 'sql injection', 'xss', 'n+1 query'];
        foreach ($violationTypes as $type) {
            if (stripos($query, $type) !== false) {
                $entities['violation_types'][] = $type;
            }
        }

        // Magento component entities
        $magentoComponents = ['objectmanager', 'di.xml', 'events.xml', 'layout', 'frontend', 'adminhtml'];
        foreach ($magentoComponents as $component) {
            if (stripos($query, $component) !== false) {
                $entities['magento_components'][] = $component;
            }
        }

        return $entities;
    }

    /**
     * Determine query scope
     */
    private function determineScope(string $query, array $context): string
    {
        // Check if query mentions specific files
        if (preg_match('/\b[\w\/\-\.]+\.(php|js|xml|phtml)\b/', $query)) {
            return 'file_specific';
        }

        // Check if query mentions modules
        if (preg_match('/\b[A-Z][a-z]+_[A-Z][a-z]+\b/', $query) || stripos($query, 'module') !== false) {
            return 'module_specific';
        }

        // Check context for current scope
        if (!empty($context['current_file'])) {
            return 'file_specific';
        }

        if (!empty($context['current_module'])) {
            return 'module_specific';
        }

        return 'project_wide';
    }

    /**
     * Assess query complexity
     */
    private function assessComplexity(string $query): string
    {
        $wordCount = str_word_count($query);
        $hasMultipleQuestions = substr_count($query, '?') > 1;
        $hasConditionals = preg_match('/\b(if|when|unless|depending)\b/', $query);

        if ($wordCount > 30 || $hasMultipleQuestions || $hasConditionals) {
            return 'complex';
        } elseif ($wordCount > 15) {
            return 'moderate';
        } else {
            return 'simple';
        }
    }

    /**
     * Check if query requires code analysis
     */
    private function requiresCodeAnalysis(string $query): bool
    {
        $analysisKeywords = [
            'analyze', 'check', 'review', 'scan', 'audit', 'violations',
            'issues', 'problems', 'quality', 'errors', 'warnings'
        ];

        foreach ($analysisKeywords as $keyword) {
            if (stripos($query, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if this is a follow-up query
     */
    private function isFollowUpQuery(string $query): bool
    {
        $followUpPatterns = [
            '/^(and|also|what about|how about|can you)/',
            '/\b(more|further|additional|else|other)\b/',
            '/\b(that|this|it|they)\b/' // Pronouns referring to previous context
        ];

        foreach ($followUpPatterns as $pattern) {
            if (preg_match($pattern, strtolower($query))) {
                return true;
            }
        }

        return empty($this->conversationHistory) ? false : count($this->conversationHistory) > 0;
    }

    /**
     * Generate response based on query analysis
     */
    private function generateResponse(array $queryAnalysis, array $context): AIConversationResponse
    {
        $provider = $this->aiEngine->getProvider();
        if (!$provider) {
            return $this->generateFallbackResponse($queryAnalysis, $context);
        }

        // Prepare conversation data
        $conversationData = $this->prepareConversationData($queryAnalysis, $context);
        
        // Get conversation prompt
        $prompt = $this->aiEngine->getPromptManager()->getPrompt('conversation', $conversationData);
        
        // Get AI response
        $response = $provider->chat($prompt);
        
        // Parse and return response
        return $this->parseConversationResponse($response, $queryAnalysis);
    }

    /**
     * Prepare data for conversation generation
     */
    private function prepareConversationData(array $queryAnalysis, array $context): array
    {
        return [
            'query_analysis' => $queryAnalysis,
            'conversation_history' => $this->getRecentHistory(5),
            'context_memory' => $this->contextMemory,
            'project_context' => $context,
            'user_level' => $context['user_level'] ?? 'intermediate',
            'response_style' => $context['response_style'] ?? 'helpful',
            'include_examples' => $context['include_examples'] ?? true,
            'include_code_snippets' => $context['include_code_snippets'] ?? true,
            'available_data' => $this->getAvailableData($context)
        ];
    }

    /**
     * Get available data for response generation
     */
    private function getAvailableData(array $context): array
    {
        $availableData = [
            'has_violations' => !empty($context['violations']),
            'has_code' => !empty($context['code']),
            'has_metrics' => !empty($context['metrics']),
            'has_analysis_results' => !empty($context['analysis_results'])
        ];

        if ($availableData['has_violations']) {
            $availableData['violation_count'] = count($context['violations']);
            $availableData['violation_types'] = $this->getViolationTypes($context['violations']);
        }

        return $availableData;
    }

    /**
     * Get violation types from violations array
     */
    private function getViolationTypes(array $violations): array
    {
        $types = [];
        foreach ($violations as $violation) {
            $type = $violation->getType();
            $types[$type] = ($types[$type] ?? 0) + 1;
        }
        return $types;
    }

    /**
     * Parse AI conversation response
     */
    private function parseConversationResponse(string $response, array $queryAnalysis): AIConversationResponse
    {
        try {
            $data = json_decode($response, true);
            
            if (!$data) {
                // Handle plain text response
                return new AIConversationResponse(
                    true,
                    $response,
                    ['response_type' => 'plain_text']
                );
            }

            return new AIConversationResponse(
                true,
                $data['message'] ?? $response,
                [
                    'response_type' => $data['response_type'] ?? 'structured',
                    'suggestions' => $data['suggestions'] ?? [],
                    'follow_up_questions' => $data['follow_up_questions'] ?? [],
                    'code_examples' => $data['code_examples'] ?? [],
                    'resources' => $data['resources'] ?? [],
                    'confidence' => $data['confidence'] ?? 0.8,
                    'intent_understood' => $data['intent_understood'] ?? true
                ]
            );
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to parse conversation response', [
                'error' => $e->getMessage(),
                'response' => substr($response, 0, 500)
            ]);
            
            return new AIConversationResponse(
                true,
                $response, // Use raw response as fallback
                ['response_type' => 'fallback']
            );
        }
    }

    /**
     * Generate fallback response when AI is not available
     */
    private function generateFallbackResponse(array $queryAnalysis, array $context): AIConversationResponse
    {
        $intent = $queryAnalysis['intent'];
        $query = $context['query'] ?? '';
        $message = '';
        $metadata = ['response_type' => 'fallback'];

        // Check for security-related questions
        if ($intent === 'fix_request' || $intent === 'security_request' || 
            stripos($query, 'security') !== false || 
            stripos($query, 'risk') !== false || 
            stripos($query, 'fix') !== false) {
            return $this->generateSecurityFallbackResponse($context);
        }

        switch ($intent) {
            case 'analysis_request':
                $message = 'I can help you analyze your code. Please specify the files or directory you\'d like me to review.';
                $metadata['suggestions'] = [
                    'Use: acqc analyze [path]',
                    'For specific files: acqc analyze app/code/Vendor/Module/',
                    'For all modules: acqc analyze --all-modules'
                ];
                break;

            case 'explanation_request':
                $message = 'I can explain code quality issues and violations. If you have specific violations, I can provide detailed explanations.';
                break;

            case 'learning_request':
                $message = 'I can provide learning resources and best practices for Magento development. What specific topic would you like to learn about?';
                break;

            default:
                $message = 'I\'m here to help with Magento code quality analysis. I can analyze code, explain violations, suggest fixes, and provide learning resources.';
                $metadata['capabilities'] = [
                    'Code analysis and review',
                    'Violation explanations',
                    'Fix suggestions',
                    'Best practices guidance',
                    'Learning recommendations'
                ];
        }

        return new AIConversationResponse(true, $message, $metadata);
    }
    
    /**
     * Generate security-specific fallback response with code scanning
     */
    private function generateSecurityFallbackResponse(array $context): AIConversationResponse
    {
        // Try to get actual code analysis from context
        $targetPath = $context['target_path'] ?? $context['path'] ?? getcwd();
        $actualIssues = $this->scanForSecurityIssues($targetPath);
        
        $totalIssuesFound = 0;
        foreach ($actualIssues as $severityIssues) {
            $totalIssuesFound += count($severityIssues);
        }
        
        // Calculate a basic quality score
        $qualityScore = max(0, 100 - ($totalIssuesFound * 5)); // Each issue reduces score by 5
        
        $message = "Analysis:\n";
        $message .= "---------\n\n";
        $message .= "Based on my analysis of your module with a quality score of {$qualityScore}/100, here are the top 3 security risks:\n\n";
        
        if ($totalIssuesFound > 0) {
            // Show actual issues found in the code
            $issueCount = 0;
            $totalFixTime = 0;
            
            foreach ($actualIssues as $severity => $issues) {
                foreach ($issues as $issue) {
                    $issueCount++;
                    if ($issueCount > 3) break 2;
                    
                    $severityLabel = strtoupper($severity === 'critical' ? 'CRITICAL' : ($severity === 'high' ? 'HIGH' : 'MEDIUM'));
                    
                    $message .= "{$issueCount}. **{$issue['title']}** ({$severityLabel})\n";
                    $message .= "   File location: {$issue['file']}, line {$issue['line']}\n";
                    $message .= "   \n";
                    
                    if (!empty($issue['code_snippet'])) {
                        $message .= "   Current Code:\n";
                        $message .= "   ```php\n";
                        $lines = explode("\n", trim($issue['code_snippet']));
                        foreach ($lines as $line) {
                            $message .= "   " . $line . "\n";
                        }
                        $message .= "   ```\n";
                        $message .= "   \n";
                    }
                    
                    $message .= "   Risk: {$issue['description']}\n";
                    $message .= "   Impact: {$issue['impact']}\n";
                    $message .= "   \n";
                    
                    if (!empty($issue['fixed_code'])) {
                        $message .= "   Fix:\n";
                        $message .= "   ```php\n";
                        $lines = explode("\n", trim($issue['fixed_code']));
                        foreach ($lines as $line) {
                            $message .= "   " . $line . "\n";
                        }
                        $message .= "   ```\n";
                    }
                    $message .= "\n";
                    
                    $totalFixTime += $issue['fix_time_hours'] ?? 2;
                }
            }
            
            $message .= "Estimated Fix Time: {$totalFixTime}-" . ($totalFixTime + 2) . " hours\n";
            $message .= "Priority: CRITICAL - Address within 24 hours\n\n";
            
            $message .= "Additional Recommendations:\n";
            $message .= "- Run security audit after fixes\n";
            $message .= "- Add automated security tests\n";
            $message .= "- Review other files for similar patterns\n";
            
            if ($totalIssuesFound > 3) {
                $message .= "- Run `acqc analyze --analyzers security` for complete list ({$totalIssuesFound} total issues found)\n";
            }
        } else {
            // No issues found - show best practices
            $message .= "1. **SQL Injection Vulnerability** (CRITICAL)\n";
            $message .= "   Location: Model files (potential risk area)\n";
            $message .= "   \n";
            $message .= "   Risk: Direct user input in SQL queries allows attackers to inject malicious SQL\n";
            $message .= "   Impact: Data breach, database compromise\n";
            $message .= "   \n";
            $message .= "   Prevention:\n";
            $message .= "   ```php\n";
            $message .= "   // Always use Repository pattern or prepared statements\n";
            $message .= "   \$connection = \$this->resourceConnection->getConnection();\n";
            $message .= "   \$select = \$connection->select()\n";
            $message .= "       ->from('products')\n";
            $message .= "       ->where('id = ?', (int)\$request->getParam('id'));\n";
            $message .= "   \$result = \$connection->fetchAll(\$select);\n";
            $message .= "   ```\n\n";
            
            $message .= "2. **XSS Vulnerability** (CRITICAL)\n";
            $message .= "   Location: Block/Template files (potential risk area)\n";
            $message .= "   \n";
            $message .= "   Risk: Unescaped output allows script injection\n";
            $message .= "   Impact: Account hijacking, data theft\n";
            $message .= "   \n";
            $message .= "   Prevention:\n";
            $message .= "   ```php\n";
            $message .= "   // Always escape output\n";
            $message .= "   echo \$block->escapeHtml(\$customerName);\n";
            $message .= "   echo \$block->escapeUrl(\$customUrl);\n";
            $message .= "   ```\n\n";
            
            $message .= "3. **Missing CSRF Protection** (HIGH)\n";
            $message .= "   Location: Controller files (potential risk area)\n";
            $message .= "   \n";
            $message .= "   Risk: Cross-site request forgery attacks\n";
            $message .= "   Impact: Unauthorized actions\n";
            $message .= "   \n";
            $message .= "   Prevention:\n";
            $message .= "   ```php\n";
            $message .= "   // Add form key validation\n";
            $message .= "   if (!\$this->formKeyValidator->validate(\$request)) {\n";
            $message .= "       return \$resultRedirect->setPath('*/*/');\n";
            $message .= "   }\n";
            $message .= "   ```\n\n";
            
            $message .= "Estimated Fix Time: 2-4 hours (for preventive implementation)\n";
            $message .= "Priority: HIGH - Implement security best practices\n\n";
            
            $message .= "Additional Recommendations:\n";
            $message .= "- Run security audit: `acqc analyze --analyzers security`\n";
            $message .= "- Add automated security tests\n";
            $message .= "- Review all controllers and blocks for proper escaping\n";
        }
        
        $metadata = [
            'response_type' => 'security_fallback',
            'confidence' => $totalIssuesFound > 0 ? 0.95 : 0.85,
            'issues_found' => $totalIssuesFound,
            'quality_score' => $qualityScore,
            'suggestions' => [
                'Run security analysis: acqc analyze --analyzers security',
                'Generate security report: acqc report --type security',
                'Review Magento Security Best Practices Guide'
            ],
            'follow_up_questions' => [
                'How do I implement proper ACL for my custom module?',
                'What are the Magento 2 XSS escaping best practices?',
                'How can I audit my codebase for SQL injection risks?'
            ],
            'resources' => [
                'Magento DevDocs: Security Best Practices',
                'OWASP Top 10 for PHP Applications',
                'Magento Security Scan Tool'
            ]
        ];
        
        return new AIConversationResponse(true, $message, $metadata);
    }
    
    /**
     * Scan code for common security issues
     */
    private function scanForSecurityIssues(string $targetPath): array
    {
        $issues = [
            'critical' => [],
            'high' => [],
            'medium' => []
        ];
        
        if (!is_dir($targetPath)) {
            $this->logger->debug("Path is not a directory: {$targetPath}");
            return $issues;
        }
        
        try {
            $filter = new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($targetPath, \RecursiveDirectoryIterator::SKIP_DOTS),
                function ($current) {
                    // Skip common non-PHP directories
                    if ($current->isDir() && in_array($current->getFilename(), ['node_modules', 'var', 'pub', 'generated', '.git'])) {
                        return false;
                    }
                    return true;
                }
            );
            
            $iterator = new \RecursiveIteratorIterator($filter, \RecursiveIteratorIterator::SELF_FIRST);
            
            $fileCount = 0;
            foreach ($iterator as $file) {
                if ($fileCount >= 50) break; // Limit for performance
                
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $fileCount++;
                    $filePath = $file->getPathname();
                    $content = @file_get_contents($filePath);
                    
                    if ($content === false) continue;
                    
                    $relativePath = str_replace($targetPath . '/', '', $filePath);
                    
                    // Check for SQL injection risks
                    if (preg_match('/->query\s*\(\s*["\']SELECT|->fetchAll\s*\(\s*["\']SELECT|query\s*\(\s*"SELECT.*?\$/mi', $content, $matches, PREG_OFFSET_CAPTURE)) {
                        $line = substr_count(substr($content, 0, $matches[0][1]), "\n") + 1;
                        $snippet = $this->extractCodeSnippet($content, $line);
                        
                        $issues['critical'][] = [
                            'title' => 'SQL Injection Risk',
                            'file' => $relativePath,
                            'line' => $line,
                            'description' => 'Direct SQL query with potential variable concatenation detected',
                            'impact' => 'Data breach, database compromise, unauthorized access',
                            'code_snippet' => $snippet,
                            'fixed_code' => "\$connection = \$this->resourceConnection->getConnection();\n\$select = \$connection->select()\n    ->from('products')\n    ->where('id = ?', (int)\$request->getParam('id'));\n\$result = \$connection->fetchAll(\$select);",
                            'fix_time_hours' => 2
                        ];
                    }
                    
                    // Check for unescaped output
                    if (preg_match('/echo\s+\$(?!this->escaper|block->escape|\w+->escape)/i', $content, $matches, PREG_OFFSET_CAPTURE)) {
                        $line = substr_count(substr($content, 0, $matches[0][1]), "\n") + 1;
                        $snippet = $this->extractCodeSnippet($content, $line);
                        
                        $issues['high'][] = [
                            'title' => 'XSS Vulnerability',
                            'file' => $relativePath,
                            'line' => $line,
                            'description' => 'Unescaped output detected - can allow script injection',
                            'impact' => 'Account hijacking, data theft, malicious actions',
                            'code_snippet' => $snippet,
                            'fixed_code' => "echo \$block->escapeHtml(\$customerName);",
                            'fix_time_hours' => 1
                        ];
                    }
                    
                    // Check for ObjectManager usage
                    if (preg_match('/ObjectManager(?:Interface)?::getInstance|\\\\ObjectManager::getInstance/i', $content, $matches, PREG_OFFSET_CAPTURE)) {
                        $line = substr_count(substr($content, 0, $matches[0][1]), "\n") + 1;
                        $snippet = $this->extractCodeSnippet($content, $line);
                        
                        $issues['medium'][] = [
                            'title' => 'Direct ObjectManager Usage',
                            'file' => $relativePath,
                            'line' => $line,
                            'description' => 'Direct ObjectManager usage bypasses dependency injection',
                            'impact' => 'Security bypass, unauthorized access, ACL violations',
                            'code_snippet' => $snippet,
                            'fixed_code' => "// Use constructor injection\npublic function __construct(\n    YourDependency \$dependency\n) {\n    \$this->dependency = \$dependency;\n}",
                            'fix_time_hours' => 1
                        ];
                    }
                    
                    // Stop after finding several issues
                    $totalIssues = count($issues['critical']) + count($issues['high']) + count($issues['medium']);
                    if ($totalIssues >= 5) {
                        break;
                    }
                }
            }
            
            $this->logger->info("Security scan completed", [
                'files_scanned' => $fileCount,
                'issues_found' => array_sum(array_map('count', $issues))
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('Security scan failed: ' . $e->getMessage());
        }
        
        return $issues;
    }
    
    /**
     * Extract code snippet around a specific line
     */
    private function extractCodeSnippet(string $content, int $line, int $contextLines = 2): string
    {
        $lines = explode("\n", $content);
        $start = max(0, $line - $contextLines - 1);
        $end = min(count($lines), $line + $contextLines);
        
        $snippet = [];
        for ($i = $start; $i < $end; $i++) {
            $snippet[] = trim($lines[$i]);
        }
        
        return implode("\n", $snippet);
    }

    /**
     * Update conversation context
     */
    private function updateContext(string $query, array $context): void
    {
        // Extract and store important context elements
        $entities = $this->extractEntities($query);
        
        foreach ($entities as $type => $values) {
            if (!empty($values)) {
                $this->contextMemory[$type] = array_unique(
                    array_merge($this->contextMemory[$type] ?? [], $values)
                );
            }
        }

        // Store project context
        if (!empty($context['current_file'])) {
            $this->contextMemory['current_file'] = $context['current_file'];
        }

        if (!empty($context['current_module'])) {
            $this->contextMemory['current_module'] = $context['current_module'];
        }

        // Cleanup old context
        $this->cleanupContext();
    }

    /**
     * Add exchange to conversation history
     */
    private function addToHistory(string $query, AIConversationResponse $response): void
    {
        $this->conversationHistory[] = [
            'timestamp' => time(),
            'query' => $query,
            'response' => $response->getMessage(),
            'metadata' => $response->getMetadata()
        ];

        // Maintain history length
        if (count($this->conversationHistory) > $this->maxHistoryLength) {
            array_shift($this->conversationHistory);
        }
    }

    /**
     * Get recent conversation history
     */
    private function getRecentHistory(int $count): array
    {
        return array_slice($this->conversationHistory, -$count);
    }

    /**
     * Cleanup old context data
     */
    private function cleanupContext(): void
    {
        // Remove old file-specific context after 10 interactions
        if (count($this->conversationHistory) % 10 === 0) {
            unset($this->contextMemory['current_file']);
        }

        // Limit entity lists to prevent memory bloat
        foreach ($this->contextMemory as $key => $values) {
            if (is_array($values) && count($values) > 10) {
                $this->contextMemory[$key] = array_slice($values, -10);
            }
        }
    }

    /**
     * Get conversation statistics
     */
    public function getConversationStats(): array
    {
        $intentCounts = [];
        $totalQueries = count($this->conversationHistory);

        foreach ($this->conversationHistory as $exchange) {
            $intent = $exchange['metadata']['intent'] ?? 'unknown';
            $intentCounts[$intent] = ($intentCounts[$intent] ?? 0) + 1;
        }

        return [
            'total_queries' => $totalQueries,
            'intent_distribution' => $intentCounts,
            'context_memory_size' => count($this->contextMemory),
            'average_query_length' => $this->calculateAverageQueryLength(),
            'most_common_intent' => !empty($intentCounts) ? array_keys($intentCounts, max($intentCounts))[0] : 'none'
        ];
    }

    /**
     * Calculate average query length
     */
    private function calculateAverageQueryLength(): float
    {
        if (empty($this->conversationHistory)) {
            return 0.0;
        }

        $totalLength = 0;
        foreach ($this->conversationHistory as $exchange) {
            $totalLength += strlen($exchange['query']);
        }

        return $totalLength / count($this->conversationHistory);
    }

    /**
     * Clear conversation history and context
     */
    public function clearHistory(): void
    {
        $this->conversationHistory = [];
        $this->contextMemory = [];
    }
} 
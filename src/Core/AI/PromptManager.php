<?php

declare(strict_types=1);

namespace ACQC\Core\AI;

use ACQC\Core\Config\ConfigManager;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * AI Prompt Manager for ACQC
 * 
 * Manages AI prompts, templates, and context injection for
 * consistent and effective AI interactions across the system.
 */
class PromptManager
{
    private ConfigManager $configManager;
    private LoggerInterface $logger;
    private array $prompts = [];
    private array $templates = [];
    private string $promptDirectory = 'config/ai/prompts';

    public function __construct(ConfigManager $configManager, ?LoggerInterface $logger = null)
    {
        $this->configManager = $configManager;
        $this->logger = $logger ?? new NullLogger();
        $this->initializePrompts();
    }

    /**
     * Get prompt with context injection
     */
    public function getPrompt(string $promptName, array $context = []): string
    {
        if (!isset($this->prompts[$promptName])) {
            $this->logger->warning("Prompt '{$promptName}' not found, using fallback");
            return $this->getFallbackPrompt($promptName, $context);
        }

        $template = $this->prompts[$promptName];
        return $this->renderPrompt($template, $context);
    }

    /**
     * Initialize built-in prompts
     */
    private function initializePrompts(): void
    {
        $this->prompts = [
            'code_understanding' => $this->getCodeUnderstandingPrompt(),
            'violation_explanation' => $this->getViolationExplanationPrompt(),
            'fix_suggestion' => $this->getFixSuggestionPrompt(),
            'conversation' => $this->getConversationPrompt(),
            'quality_prediction' => $this->getQualityPredictionPrompt(),
            'learning_path' => $this->getLearningPathPrompt()
        ];

        // Load custom prompts from configuration
        $this->loadCustomPrompts();
    }

    /**
     * Code understanding prompt template
     */
    private function getCodeUnderstandingPrompt(): string
    {
        return <<<PROMPT
You are an expert Magento 2 code analyzer. Analyze the following code and provide comprehensive insights.

**Code Information:**
- File: {{file_path}}
- Type: {{file_info.type}}
- Magento Module: {{file_info.magento_context.vendor_name}}_{{file_info.magento_context.module_name}}
- Area: {{file_info.magento_context.area}}

**Code Metrics:**
- Lines of Code: {{code_metrics.code_lines}}
- Complexity: {{code_metrics.estimated_complexity}}
- Maintainability: {{code_metrics.estimated_maintainability}}

**Detected Patterns:**
{{#each detected_patterns}}
- {{this}}
{{/each}}

**Code to Analyze:**
```php
{{code}}
```

Please provide a JSON response with the following structure:
{
  "summary": "Brief summary of the code analysis",
  "insights": ["Key insights about the code structure and quality"],
  "quality_score": 0.85,
  "complexity_assessment": "Low/Medium/High",
  "maintainability_score": 0.75,
  "architecture_feedback": "Feedback on architectural patterns",
  "best_practices": ["Best practices being followed"],
  "potential_issues": ["Potential issues or concerns"],
  "improvement_suggestions": ["Specific suggestions for improvement"]
}

Focus on Magento 2 best practices, performance, security, and maintainability.
PROMPT;
    }

    /**
     * Violation explanation prompt template
     */
    private function getViolationExplanationPrompt(): string
    {
        return <<<PROMPT
You are a helpful Magento 2 code quality expert. Explain the following code violations in clear, educational language.

**Context:**
- Explanation Level: {{explanation_level}}
- Include Examples: {{include_examples}}
- Include Fixes: {{include_fixes}}

**Violations to Explain:**
{{#each violations}}
**Violation {{index}}:**
- Rule: {{rule_id}}
- Message: {{message}}
- Severity: {{severity}}
- File: {{file_path}} (Line {{line}})
- Code Snippet:
```php
{{code_snippet}}
```
{{/each}}

Provide a JSON response with explanations array:
{
  "explanations": [
    {
      "summary": "Brief explanation of the violation",
      "detailed_explanation": "Detailed explanation of why this is a problem",
      "why_its_a_problem": "Impact and consequences",
      "how_to_fix": "Step-by-step fix instructions",
      "example_code": "Fixed code example",
      "learning_resources": ["Relevant documentation links"],
      "difficulty_level": "beginner/intermediate/advanced",
      "estimated_fix_time": "5-15 minutes"
    }
  ]
}

Make explanations educational and actionable for Magento developers.
PROMPT;
    }

    /**
     * Fix suggestion prompt template
     */
    private function getFixSuggestionPrompt(): string
    {
        return <<<PROMPT
You are an expert Magento 2 developer. Provide detailed fix suggestions for the following code violations.

**Fix Parameters:**
- Style: {{fix_style}}
- Include Explanations: {{include_explanations}}
- Target Magento Version: {{target_magento_version}}
- Coding Standards: {{coding_standards}}

**Violations to Fix:**
{{#each violations}}
**Violation {{index}}:**
- Rule: {{rule_id}}
- Message: {{message}}
- Severity: {{severity}}
- File Type: {{file_type}}
- Current Code:
```php
{{code_snippet}}
```

**Method Context:**
```php
{{surrounding_context}}
```
{{/each}}

Provide JSON response with fixes array:
{
  "fixes": [
    {
      "summary": "Brief description of the fix",
      "fix_type": "refactor/security_fix/performance_improvement",
      "original_code": "Original problematic code",
      "fixed_code": "Corrected code",
      "diff": "Unified diff format",
      "instructions": ["Step-by-step fix instructions"],
      "explanation": "Why this fix works",
      "best_practices": ["Related best practices"],
      "testing_notes": "How to test the fix",
      "risk_level": "low/medium/high",
      "estimated_time": "15-30 minutes",
      "confidence": 0.9,
      "dependencies": ["Files that might need updates"],
      "alternative_approaches": ["Other ways to solve this"]
    }
  ]
}

Ensure fixes follow Magento 2 best practices and are production-ready.
PROMPT;
    }

    /**
     * Conversation prompt template
     */
    private function getConversationPrompt(): string
    {
        return <<<PROMPT
You are a knowledgeable Magento 2 code quality assistant. Help users with their questions about code analysis, violations, and best practices.

**Query Analysis:**
- Intent: {{query_analysis.intent}}
- Scope: {{query_analysis.scope}}
- Complexity: {{query_analysis.complexity}}
- Requires Code Analysis: {{query_analysis.requires_code_analysis}}

**User Context:**
- Level: {{user_level}}
- Response Style: {{response_style}}

**Available Data:**
{{#if available_data.has_violations}}
- {{available_data.violation_count}} violations found
- Types: {{#each available_data.violation_types}}{{@key}}: {{this}} {{/each}}
{{/if}}
{{#if available_data.has_code}}
- Code available for analysis
{{/if}}

**Recent Conversation:**
{{#each conversation_history}}
User: {{query}}
Assistant: {{response}}
---
{{/each}}

Provide a helpful JSON response:
{
  "message": "Your helpful response to the user",
  "response_type": "answer/guidance/analysis/clarification",
  "suggestions": ["Actionable suggestions"],
  "follow_up_questions": ["Questions to help user further"],
  "code_examples": ["Relevant code examples if needed"],
  "resources": ["Helpful links or documentation"],
  "confidence": 0.85,
  "intent_understood": true
}

Be helpful, educational, and focused on Magento 2 development.
PROMPT;
    }

    /**
     * Quality prediction prompt template
     */
    private function getQualityPredictionPrompt(): string
    {
        return <<<PROMPT
Analyze the following code and predict its quality score based on Magento 2 standards.

**Code to Analyze:**
```php
{{code}}
```

**File:** {{file_path}}

**Existing Violations:**
{{#each existing_violations}}
- {{rule_id}}: {{message}} ({{severity}})
{{/each}}

Provide JSON response:
{
  "score": 0.75,
  "explanation": "Detailed explanation of the score",
  "recommendations": ["Specific improvement recommendations"],
  "confidence": 0.8
}

Score should be 0.0-1.0 based on code quality, security, performance, and maintainability.
PROMPT;
    }

    /**
     * Learning path prompt template
     */
    private function getLearningPathPrompt(): string
    {
        return <<<PROMPT
Create a personalized learning path based on the code violations found.

**User Level:** {{user_level}}

**Violations Found:**
{{#each violations}}
- {{rule_id}}: {{message}} ({{severity}})
{{/each}}

Provide JSON response:
{
  "path": [
    {
      "topic": "Topic name",
      "description": "What to learn",
      "resources": ["Learning resources"],
      "estimated_time": "2 hours",
      "priority": "high/medium/low"
    }
  ],
  "explanation": "Why this learning path is recommended",
  "estimated_time": "1-2 weeks",
  "difficulty": "beginner/intermediate/advanced"
}

Focus on practical Magento 2 skills that address the specific violations.
PROMPT;
    }

    /**
     * Load custom prompts from configuration
     */
    private function loadCustomPrompts(): void
    {
        $customPrompts = $this->configManager->get('ai.prompts', []);
        
        foreach ($customPrompts as $name => $prompt) {
            $this->prompts[$name] = $prompt;
        }

        // Load prompts from files if directory exists
        if (is_dir($this->promptDirectory)) {
            $this->loadPromptsFromDirectory();
        }
    }

    /**
     * Load prompts from directory
     */
    private function loadPromptsFromDirectory(): void
    {
        $files = glob($this->promptDirectory . '/*.txt');
        
        foreach ($files as $file) {
            $name = basename($file, '.txt');
            $content = file_get_contents($file);
            
            if ($content) {
                $this->prompts[$name] = $content;
            }
        }
    }

    /**
     * Render prompt with context
     */
    private function renderPrompt(string $template, array $context): string
    {
        $rendered = $template;

        // Simple template rendering (replace {{variable}} with values)
        foreach ($context as $key => $value) {
            $rendered = $this->replaceTemplateVar($rendered, $key, $value);
        }

        // Handle conditional blocks and loops
        $rendered = $this->processConditionals($rendered, $context);
        $rendered = $this->processLoops($rendered, $context);

        return $rendered;
    }

    /**
     * Replace template variable
     */
    private function replaceTemplateVar(string $template, string $key, $value): string
    {
        if (is_scalar($value)) {
            $template = str_replace('{{' . $key . '}}', (string)$value, $template);
        } elseif (is_array($value)) {
            // Handle nested object access like {{file_info.type}}
            $template = $this->replaceNestedVars($template, $key, $value);
        }

        return $template;
    }

    /**
     * Replace nested variables
     */
    private function replaceNestedVars(string $template, string $prefix, array $data): string
    {
        foreach ($data as $key => $value) {
            $fullKey = $prefix . '.' . $key;
            
            if (is_scalar($value)) {
                $template = str_replace('{{' . $fullKey . '}}', (string)$value, $template);
            } elseif (is_array($value)) {
                $template = $this->replaceNestedVars($template, $fullKey, $value);
            }
        }

        return $template;
    }

    /**
     * Process conditional blocks
     */
    private function processConditionals(string $template, array $context): string
    {
        // Handle {{#if condition}} blocks
        $pattern = '/\{\{#if\s+([^}]+)\}\}(.*?)\{\{\/if\}\}/s';
        
        return preg_replace_callback($pattern, function ($matches) use ($context) {
            $condition = trim($matches[1]);
            $content = $matches[2];
            
            if ($this->evaluateCondition($condition, $context)) {
                return $content;
            }
            
            return '';
        }, $template);
    }

    /**
     * Process loop blocks
     */
    private function processLoops(string $template, array $context): string
    {
        // Handle {{#each array}} blocks
        $pattern = '/\{\{#each\s+([^}]+)\}\}(.*?)\{\{\/each\}\}/s';
        
        return preg_replace_callback($pattern, function ($matches) use ($context) {
            $arrayPath = trim($matches[1]);
            $itemTemplate = $matches[2];
            
            $array = $this->getNestedValue($context, $arrayPath);
            if (!is_array($array)) {
                return '';
            }
            
            $result = '';
            foreach ($array as $index => $item) {
                $itemContext = array_merge($context, [
                    'this' => $item,
                    '@index' => $index,
                    '@key' => $index
                ]);
                
                $renderedItem = $this->renderPrompt($itemTemplate, $itemContext);
                $result .= $renderedItem;
            }
            
            return $result;
        }, $template);
    }

    /**
     * Evaluate condition for conditionals
     */
    private function evaluateCondition(string $condition, array $context): bool
    {
        $value = $this->getNestedValue($context, $condition);
        
        if (is_bool($value)) {
            return $value;
        } elseif (is_array($value)) {
            return !empty($value);
        } elseif (is_string($value)) {
            return !empty($value);
        } elseif (is_numeric($value)) {
            return $value > 0;
        }
        
        return false;
    }

    /**
     * Get nested value from context
     */
    private function getNestedValue(array $context, string $path)
    {
        $keys = explode('.', $path);
        $value = $context;
        
        foreach ($keys as $key) {
            if (is_array($value) && isset($value[$key])) {
                $value = $value[$key];
            } else {
                return null;
            }
        }
        
        return $value;
    }

    /**
     * Get fallback prompt
     */
    private function getFallbackPrompt(string $promptName, array $context): string
    {
        return "You are a helpful AI assistant. Please help with the following request: " . 
               ($context['query'] ?? 'Analyze the provided information and give helpful advice.');
    }

    /**
     * Add custom prompt
     */
    public function addPrompt(string $name, string $template): void
    {
        $this->prompts[$name] = $template;
    }

    /**
     * Get available prompts
     */
    public function getAvailablePrompts(): array
    {
        return array_keys($this->prompts);
    }
} 
<?php

declare(strict_types=1);

namespace ACQC\Core\Config;

class RuleConfig
{
    private array $rules;
    private array $rulePriorities = [];
    private array $ruleCategories = [];

    public function __construct(array $rules = [])
    {
        $this->rules = $rules;
        $this->initializeRuleCategories();
        $this->calculateRulePriorities();
    }

    /**
     * Get all rules with their configurations
     */
    public function getAllRules(): array
    {
        return $this->rules;
    }

    /**
     * Get rules filtered by category
     */
    public function getRulesByCategory(string $category): array
    {
        return array_filter($this->rules, function($rule) use ($category) {
            return ($rule['category'] ?? 'general') === $category;
        });
    }

    /**
     * Get rules filtered by severity
     */
    public function getRulesBySeverity(string $severity): array
    {
        return array_filter($this->rules, function($rule) use ($severity) {
            return ($rule['severity'] ?? 'warning') === $severity;
        });
    }

    /**
     * Get rules filtered by complexity level
     */
    public function getRulesByComplexity(string $complexity): array
    {
        return array_filter($this->rules, function($rule) use ($complexity) {
            return ($rule['complexity'] ?? 'medium') === $complexity;
        });
    }

    /**
     * Get rule by name
     */
    public function getRule(string $ruleName): ?array
    {
        return $this->rules[$ruleName] ?? null;
    }

    /**
     * Add or update a rule
     */
    public function setRule(string $ruleName, array $ruleConfig): void
    {
        $validatedRule = $this->validateRule($ruleConfig);
        $this->rules[$ruleName] = $validatedRule;
        $this->calculateRulePriorities();
    }

    /**
     * Remove a rule
     */
    public function removeRule(string $ruleName): bool
    {
        if (isset($this->rules[$ruleName])) {
            unset($this->rules[$ruleName]);
            $this->calculateRulePriorities();
            return true;
        }
        return false;
    }

    /**
     * Get rules sorted by priority
     */
    public function getRulesByPriority(): array
    {
        $rulesWithPriority = [];
        foreach ($this->rules as $name => $rule) {
            $rulesWithPriority[$name] = array_merge($rule, [
                'priority_score' => $this->rulePriorities[$name] ?? 0
            ]);
        }

        uasort($rulesWithPriority, function($a, $b) {
            return ($b['priority_score'] ?? 0) <=> ($a['priority_score'] ?? 0);
        });

        return $rulesWithPriority;
    }

    /**
     * Get rule categories with counts
     */
    public function getRuleCategories(): array
    {
        $categories = [];
        foreach ($this->ruleCategories as $category => $info) {
            $categories[$category] = array_merge($info, [
                'rule_count' => count($this->getRulesByCategory($category))
            ]);
        }
        return $categories;
    }

    /**
     * Validate rule configuration
     */
    public function validateRule(array $ruleConfig): array
    {
        $errors = [];

        // Required fields
        $requiredFields = ['enabled', 'severity'];
        foreach ($requiredFields as $field) {
            if (!isset($ruleConfig[$field])) {
                $errors[] = "Missing required field: {$field}";
            }
        }

        // Validate severity
        if (isset($ruleConfig['severity'])) {
            $validSeverities = ['critical', 'error', 'warning', 'info'];
            if (!in_array($ruleConfig['severity'], $validSeverities)) {
                $errors[] = "Invalid severity: {$ruleConfig['severity']}";
            }
        }

        // Validate complexity
        if (isset($ruleConfig['complexity'])) {
            $validComplexities = ['basic', 'medium', 'advanced', 'expert'];
            if (!in_array($ruleConfig['complexity'], $validComplexities)) {
                $errors[] = "Invalid complexity: {$ruleConfig['complexity']}";
            }
        }

        // Validate category
        if (isset($ruleConfig['category'])) {
            if (!isset($this->ruleCategories[$ruleConfig['category']])) {
                $errors[] = "Invalid category: {$ruleConfig['category']}";
            }
        }

        if (!empty($errors)) {
            throw new \InvalidArgumentException('Rule validation failed: ' . implode(', ', $errors));
        }

        // Set defaults
        $ruleConfig = array_merge([
            'enabled' => true,
            'severity' => 'warning',
            'complexity' => 'medium',
            'category' => 'general',
            'auto_fixable' => false,
            'performance_impact' => 'low',
        ], $ruleConfig);

        return $ruleConfig;
    }

    /**
     * Get rules that can be auto-fixed
     */
    public function getAutoFixableRules(): array
    {
        return array_filter($this->rules, function($rule) {
            return $rule['auto_fixable'] ?? false;
        });
    }

    /**
     * Get performance impact of rules
     */
    public function getPerformanceImpact(): array
    {
        $impact = ['low' => 0, 'medium' => 0, 'high' => 0];
        
        foreach ($this->rules as $rule) {
            $level = $rule['performance_impact'] ?? 'low';
            if (isset($impact[$level])) {
                $impact[$level]++;
            }
        }

        return $impact;
    }

    /**
     * Get rule statistics
     */
    public function getRuleStatistics(): array
    {
        $stats = [
            'total_rules' => count($this->rules),
            'enabled_rules' => 0,
            'auto_fixable_rules' => 0,
            'by_severity' => ['critical' => 0, 'error' => 0, 'warning' => 0, 'info' => 0],
            'by_complexity' => ['basic' => 0, 'medium' => 0, 'advanced' => 0, 'expert' => 0],
            'by_category' => [],
        ];

        foreach ($this->rules as $rule) {
            if ($rule['enabled'] ?? true) {
                $stats['enabled_rules']++;
            }

            if ($rule['auto_fixable'] ?? false) {
                $stats['auto_fixable_rules']++;
            }

            $severity = $rule['severity'] ?? 'warning';
            if (isset($stats['by_severity'][$severity])) {
                $stats['by_severity'][$severity]++;
            }

            $complexity = $rule['complexity'] ?? 'medium';
            if (isset($stats['by_complexity'][$complexity])) {
                $stats['by_complexity'][$complexity]++;
            }

            $category = $rule['category'] ?? 'general';
            $stats['by_category'][$category] = ($stats['by_category'][$category] ?? 0) + 1;
        }

        return $stats;
    }

    /**
     * Export rules to array format
     */
    public function exportRules(): array
    {
        return [
            'rules' => $this->rules,
            'priorities' => $this->rulePriorities,
            'categories' => $this->ruleCategories,
            'statistics' => $this->getRuleStatistics(),
        ];
    }

    /**
     * Import rules from array format
     */
    public function importRules(array $data): void
    {
        if (isset($data['rules'])) {
            foreach ($data['rules'] as $name => $rule) {
                $this->setRule($name, $rule);
            }
        }
    }

    /**
     * Create a rule preset for specific scenarios
     */
    public function createPreset(string $presetName): array
    {
        $presets = [
            'strict' => [
                'severity_override' => 'error',
                'complexity_filter' => ['basic', 'medium'],
                'auto_fix_enabled' => false,
            ],
            'lenient' => [
                'severity_override' => 'warning',
                'complexity_filter' => ['medium', 'advanced', 'expert'],
                'auto_fix_enabled' => true,
            ],
            'security_focused' => [
                'category_filter' => ['security', 'magento-security'],
                'severity_override' => 'error',
                'auto_fix_enabled' => false,
            ],
            'performance_focused' => [
                'category_filter' => ['performance', 'magento-performance'],
                'complexity_filter' => ['medium', 'advanced', 'expert'],
                'auto_fix_enabled' => true,
            ],
            'beginner_friendly' => [
                'severity_filter' => ['critical', 'error'],
                'complexity_filter' => ['basic', 'medium'],
                'show_examples' => true,
                'detailed_explanations' => true,
            ],
        ];

        return $presets[$presetName] ?? [];
    }

    /**
     * Initialize rule categories
     */
    private function initializeRuleCategories(): void
    {
        $this->ruleCategories = [
            'general' => [
                'name' => 'General',
                'description' => 'General code quality rules',
                'priority' => 3,
            ],
            'security' => [
                'name' => 'Security',
                'description' => 'Security-related rules',
                'priority' => 10,
            ],
            'performance' => [
                'name' => 'Performance',
                'description' => 'Performance optimization rules',
                'priority' => 7,
            ],
            'magento-core' => [
                'name' => 'Magento Core',
                'description' => 'Magento core best practices',
                'priority' => 8,
            ],
            'magento-security' => [
                'name' => 'Magento Security',
                'description' => 'Magento-specific security rules',
                'priority' => 9,
            ],
            'magento-performance' => [
                'name' => 'Magento Performance',
                'description' => 'Magento performance optimizations',
                'priority' => 6,
            ],
            'code-style' => [
                'name' => 'Code Style',
                'description' => 'Code formatting and style rules',
                'priority' => 2,
            ],
            'documentation' => [
                'name' => 'Documentation',
                'description' => 'Documentation requirements',
                'priority' => 1,
            ],
            'testing' => [
                'name' => 'Testing',
                'description' => 'Test-related rules',
                'priority' => 4,
            ],
            'architecture' => [
                'name' => 'Architecture',
                'description' => 'Architectural and design rules',
                'priority' => 5,
            ],
        ];
    }

    /**
     * Calculate rule priorities based on severity, category, and complexity
     */
    private function calculateRulePriorities(): void
    {
        $severityWeights = [
            'critical' => 40,
            'error' => 30,
            'warning' => 20,
            'info' => 10,
        ];

        $complexityWeights = [
            'basic' => 10,
            'medium' => 20,
            'advanced' => 30,
            'expert' => 40,
        ];

        foreach ($this->rules as $name => $rule) {
            $priority = 0;

            // Add severity weight
            $severity = $rule['severity'] ?? 'warning';
            $priority += $severityWeights[$severity] ?? 20;

            // Add category weight
            $category = $rule['category'] ?? 'general';
            $categoryPriority = $this->ruleCategories[$category]['priority'] ?? 3;
            $priority += $categoryPriority * 5;

            // Add complexity weight
            $complexity = $rule['complexity'] ?? 'medium';
            $priority += $complexityWeights[$complexity] ?? 20;

            // Boost priority for auto-fixable rules
            if ($rule['auto_fixable'] ?? false) {
                $priority += 10;
            }

            // Reduce priority for disabled rules
            if (!($rule['enabled'] ?? true)) {
                $priority = 0;
            }

            $this->rulePriorities[$name] = $priority;
        }
    }
} 
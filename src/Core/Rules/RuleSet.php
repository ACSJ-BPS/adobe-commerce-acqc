<?php

declare(strict_types=1);

namespace ACQC\Core\Rules;

class RuleSet implements \Countable, \IteratorAggregate
{
    private array $rules = [];
    private array $rulesByCategory = [];
    private array $rulesByTag = [];
    private bool $sorted = false;

    public function __construct(array $rules = [])
    {
        foreach ($rules as $rule) {
            $this->addRule($rule);
        }
    }

    /**
     * Add a rule to the set
     */
    public function addRule(Rule $rule): self
    {
        $id = $rule->getId();
        
        if (isset($this->rules[$id])) {
            throw new \InvalidArgumentException("Rule with ID '{$id}' already exists");
        }

        $this->rules[$id] = $rule;
        $this->indexRule($rule);
        $this->sorted = false;

        return $this;
    }

    /**
     * Remove a rule from the set
     */
    public function removeRule(string $ruleId): self
    {
        if (!isset($this->rules[$ruleId])) {
            return $this;
        }

        $rule = $this->rules[$ruleId];
        unset($this->rules[$ruleId]);
        $this->unindexRule($rule);
        $this->sorted = false;

        return $this;
    }

    /**
     * Get a rule by ID
     */
    public function getRule(string $ruleId): ?Rule
    {
        return $this->rules[$ruleId] ?? null;
    }

    /**
     * Check if a rule exists
     */
    public function hasRule(string $ruleId): bool
    {
        return isset($this->rules[$ruleId]);
    }

    /**
     * Get all rules
     */
    public function getRules(): array
    {
        return $this->rules;
    }

    /**
     * Get enabled rules only
     */
    public function getEnabledRules(): array
    {
        return array_filter($this->rules, fn(Rule $rule) => $rule->isEnabled());
    }

    /**
     * Get rules by category
     */
    public function getRulesByCategory(string $category): array
    {
        return $this->rulesByCategory[$category] ?? [];
    }

    /**
     * Get rules by tag
     */
    public function getRulesByTag(string $tag): array
    {
        return $this->rulesByTag[$tag] ?? [];
    }

    /**
     * Get rules by severity
     */
    public function getRulesBySeverity(string $severity): array
    {
        return array_filter($this->rules, fn(Rule $rule) => $rule->getSeverity() === $severity);
    }

    /**
     * Get rules by file extension
     */
    public function getRulesForFile(string $filePath): array
    {
        return array_filter($this->rules, fn(Rule $rule) => $rule->appliesTo($filePath));
    }

    /**
     * Get rules sorted by priority
     */
    public function getSortedRules(): array
    {
        if (!$this->sorted) {
            uasort($this->rules, function(Rule $a, Rule $b) {
                // Primary sort by priority (lower = higher priority)
                $priorityDiff = $a->getPriority() <=> $b->getPriority();
                if ($priorityDiff !== 0) {
                    return $priorityDiff;
                }

                // Secondary sort by severity weight (higher = higher priority)
                $severityDiff = $this->getSeverityWeight($b->getSeverity()) <=> $this->getSeverityWeight($a->getSeverity());
                if ($severityDiff !== 0) {
                    return $severityDiff;
                }

                // Tertiary sort by rule ID for consistency
                return $a->getId() <=> $b->getId();
            });
            $this->sorted = true;
        }

        return $this->rules;
    }

    /**
     * Filter rules by criteria
     */
    public function filter(array $criteria): self
    {
        $filtered = new self();

        foreach ($this->rules as $rule) {
            if ($this->matchesCriteria($rule, $criteria)) {
                $filtered->addRule($rule);
            }
        }

        return $filtered;
    }

    /**
     * Enable/disable rules by criteria
     */
    public function setEnabledByCriteria(array $criteria, bool $enabled): self
    {
        foreach ($this->rules as $rule) {
            if ($this->matchesCriteria($rule, $criteria)) {
                $rule->setEnabled($enabled);
            }
        }

        return $this;
    }

    /**
     * Resolve rule dependencies and return rules in execution order
     */
    public function resolveDependencies(): array
    {
        $resolved = [];
        $visited = [];
        $visiting = [];

        foreach ($this->rules as $rule) {
            if (!$rule->isEnabled()) {
                continue;
            }
            $this->resolveDependency($rule, $resolved, $visited, $visiting);
        }

        return $resolved;
    }

    /**
     * Validate all rules in the set
     */
    public function validate(): array
    {
        $errors = [];

        foreach ($this->rules as $rule) {
            try {
                // Check if dependencies are satisfied
                $availableRules = array_keys($this->rules);
                if (!$rule->areDependenciesSatisfied($availableRules)) {
                    $missing = array_diff($rule->getDependencies(), $availableRules);
                    $errors[] = "Rule {$rule->getId()} has unsatisfied dependencies: " . implode(', ', $missing);
                }
            } catch (\Exception $e) {
                $errors[] = "Rule {$rule->getId()} validation failed: " . $e->getMessage();
            }
        }

        return $errors;
    }

    /**
     * Get rule statistics
     */
    public function getStatistics(): array
    {
        $stats = [
            'total_rules' => count($this->rules),
            'enabled_rules' => count($this->getEnabledRules()),
            'disabled_rules' => count($this->rules) - count($this->getEnabledRules()),
            'by_severity' => [],
            'by_category' => [],
            'by_type' => [],
            'fixable_rules' => 0,
            'categories' => array_keys($this->rulesByCategory),
            'tags' => array_keys($this->rulesByTag),
        ];

        foreach ($this->rules as $rule) {
            // Count by severity
            $severity = $rule->getSeverity();
            $stats['by_severity'][$severity] = ($stats['by_severity'][$severity] ?? 0) + 1;

            // Count by category
            $category = $rule->getCategory() ?? 'uncategorized';
            $stats['by_category'][$category] = ($stats['by_category'][$category] ?? 0) + 1;

            // Count by type
            $type = $rule->getType();
            $stats['by_type'][$type] = ($stats['by_type'][$type] ?? 0) + 1;

            // Count fixable rules
            if ($rule->isFixable()) {
                $stats['fixable_rules']++;
            }
        }

        return $stats;
    }

    /**
     * Export rules to array
     */
    public function toArray(): array
    {
        $data = [];
        foreach ($this->rules as $rule) {
            $data[$rule->getId()] = $rule->getMetadata();
        }
        return $data;
    }

    /**
     * Get performance estimate for the rule set
     */
    public function getPerformanceEstimate(): float
    {
        $totalWeight = 0.0;
        foreach ($this->getEnabledRules() as $rule) {
            $totalWeight += $rule->getPerformance();
        }
        return $totalWeight;
    }

    /**
     * Merge another rule set into this one
     */
    public function merge(RuleSet $other): self
    {
        foreach ($other->getRules() as $rule) {
            if (!$this->hasRule($rule->getId())) {
                $this->addRule($rule);
            }
        }
        return $this;
    }

    /**
     * Create a subset with only the specified rule IDs
     */
    public function subset(array $ruleIds): self
    {
        $subset = new self();
        foreach ($ruleIds as $ruleId) {
            if ($this->hasRule($ruleId)) {
                $subset->addRule($this->getRule($ruleId));
            }
        }
        return $subset;
    }

    /**
     * Count interface implementation
     */
    public function count(): int
    {
        return count($this->rules);
    }

    /**
     * IteratorAggregate interface implementation
     */
    public function getIterator(): \Iterator
    {
        return new \ArrayIterator($this->rules);
    }

    /**
     * Index a rule for fast lookups
     */
    private function indexRule(Rule $rule): void
    {
        // Index by category
        $category = $rule->getCategory();
        if ($category !== null) {
            if (!isset($this->rulesByCategory[$category])) {
                $this->rulesByCategory[$category] = [];
            }
            $this->rulesByCategory[$category][$rule->getId()] = $rule;
        }

        // Index by tags
        foreach ($rule->getTags() as $tag) {
            if (!isset($this->rulesByTag[$tag])) {
                $this->rulesByTag[$tag] = [];
            }
            $this->rulesByTag[$tag][$rule->getId()] = $rule;
        }
    }

    /**
     * Remove rule from indices
     */
    private function unindexRule(Rule $rule): void
    {
        // Remove from category index
        $category = $rule->getCategory();
        if ($category !== null && isset($this->rulesByCategory[$category])) {
            unset($this->rulesByCategory[$category][$rule->getId()]);
            if (empty($this->rulesByCategory[$category])) {
                unset($this->rulesByCategory[$category]);
            }
        }

        // Remove from tag indices
        foreach ($rule->getTags() as $tag) {
            if (isset($this->rulesByTag[$tag])) {
                unset($this->rulesByTag[$tag][$rule->getId()]);
                if (empty($this->rulesByTag[$tag])) {
                    unset($this->rulesByTag[$tag]);
                }
            }
        }
    }

    /**
     * Check if a rule matches the given criteria
     */
    private function matchesCriteria(Rule $rule, array $criteria): bool
    {
        // Check enabled status
        if (isset($criteria['enabled']) && $rule->isEnabled() !== $criteria['enabled']) {
            return false;
        }

        // Check severity
        if (isset($criteria['severity'])) {
            $severities = is_array($criteria['severity']) ? $criteria['severity'] : [$criteria['severity']];
            if (!in_array($rule->getSeverity(), $severities)) {
                return false;
            }
        }

        // Check category
        if (isset($criteria['category'])) {
            $categories = is_array($criteria['category']) ? $criteria['category'] : [$criteria['category']];
            if (!in_array($rule->getCategory(), $categories)) {
                return false;
            }
        }

        // Check type
        if (isset($criteria['type'])) {
            $types = is_array($criteria['type']) ? $criteria['type'] : [$criteria['type']];
            if (!in_array($rule->getType(), $types)) {
                return false;
            }
        }

        // Check tags
        if (isset($criteria['tags'])) {
            $requiredTags = is_array($criteria['tags']) ? $criteria['tags'] : [$criteria['tags']];
            $ruleTags = $rule->getTags();
            foreach ($requiredTags as $tag) {
                if (!in_array($tag, $ruleTags)) {
                    return false;
                }
            }
        }

        // Check fixable
        if (isset($criteria['fixable']) && $rule->isFixable() !== $criteria['fixable']) {
            return false;
        }

        // Check file extension
        if (isset($criteria['file_extension'])) {
            $extensions = is_array($criteria['file_extension']) ? $criteria['file_extension'] : [$criteria['file_extension']];
            $ruleExtensions = $rule->getFileExtensions();
            $hasCommonExtension = !empty(array_intersect($extensions, $ruleExtensions));
            if (!$hasCommonExtension) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resolve dependencies recursively
     */
    private function resolveDependency(Rule $rule, array &$resolved, array &$visited, array &$visiting): void
    {
        $ruleId = $rule->getId();

        if (isset($visited[$ruleId])) {
            return;
        }

        if (isset($visiting[$ruleId])) {
            throw new \RuntimeException("Circular dependency detected involving rule: {$ruleId}");
        }

        $visiting[$ruleId] = true;

        // Resolve dependencies first
        foreach ($rule->getDependencies() as $dependencyId) {
            if (!$this->hasRule($dependencyId)) {
                throw new \RuntimeException("Missing dependency '{$dependencyId}' for rule '{$ruleId}'");
            }

            $dependency = $this->getRule($dependencyId);
            if ($dependency->isEnabled()) {
                $this->resolveDependency($dependency, $resolved, $visited, $visiting);
            }
        }

        unset($visiting[$ruleId]);
        $visited[$ruleId] = true;
        $resolved[$ruleId] = $rule;
    }

    /**
     * Get severity weight for sorting
     */
    private function getSeverityWeight(string $severity): int
    {
        return match ($severity) {
            Violation::SEVERITY_CRITICAL => 4,
            Violation::SEVERITY_ERROR => 3,
            Violation::SEVERITY_WARNING => 2,
            Violation::SEVERITY_INFO => 1,
            default => 0,
        };
    }
} 
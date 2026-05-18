<?php

declare(strict_types=1);

namespace ACQC\Core\Rules;

abstract class Rule
{
    protected string $id;
    protected string $name;
    protected string $description;
    protected string $severity = Violation::SEVERITY_WARNING;
    protected string $type = Violation::TYPE_STYLE;
    protected ?string $category = null;
    protected int $priority = 100;
    protected bool $enabled = true;
    protected array $tags = [];
    protected array $fileExtensions = ['php'];
    protected array $excludePatterns = [];
    protected array $dependencies = [];
    protected array $configuration = [];
    protected ?string $documentationUrl = null;
    protected bool $fixable = false;
    protected float $performance = 1.0; // Performance weight (1.0 = normal)

    public function __construct(array $config = [])
    {
        $this->configure($config);
        $this->validate();
    }

    /**
     * Get the unique rule identifier
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Get the human-readable rule name
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get the rule description
     */
    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * Get the default severity level
     */
    public function getSeverity(): string
    {
        return $this->severity;
    }

    /**
     * Get the violation type
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Get the rule category
     */
    public function getCategory(): ?string
    {
        return $this->category;
    }

    /**
     * Get the rule priority (lower = higher priority)
     */
    public function getPriority(): int
    {
        return $this->priority;
    }

    /**
     * Check if the rule is enabled
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Enable or disable the rule
     */
    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;
        return $this;
    }

    /**
     * Get rule tags
     */
    public function getTags(): array
    {
        return $this->tags;
    }

    /**
     * Get supported file extensions
     */
    public function getFileExtensions(): array
    {
        return $this->fileExtensions;
    }

    /**
     * Get exclude patterns
     */
    public function getExcludePatterns(): array
    {
        return $this->excludePatterns;
    }

    /**
     * Get rule dependencies
     */
    public function getDependencies(): array
    {
        return $this->dependencies;
    }

    /**
     * Get rule configuration
     */
    public function getConfiguration(): array
    {
        return $this->configuration;
    }

    /**
     * Set rule configuration
     */
    public function setConfiguration(array $configuration): self
    {
        $this->configuration = array_merge($this->configuration, $configuration);
        return $this;
    }

    /**
     * Get documentation URL
     */
    public function getDocumentationUrl(): ?string
    {
        return $this->documentationUrl;
    }

    /**
     * Check if the rule can auto-fix violations
     */
    public function isFixable(): bool
    {
        return $this->fixable;
    }

    /**
     * Get performance weight
     */
    public function getPerformance(): float
    {
        return $this->performance;
    }

    /**
     * Check if this rule applies to the given file
     */
    public function appliesTo(string $filePath): bool
    {
        // Check file extension
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if (!in_array($extension, $this->fileExtensions)) {
            return false;
        }

        // Check exclude patterns
        foreach ($this->excludePatterns as $pattern) {
            if (fnmatch($pattern, $filePath)) {
                return false;
            }
        }

        // Check if rule is enabled
        if (!$this->enabled) {
            return false;
        }

        // Additional custom checks
        return $this->customAppliesTo($filePath);
    }

    /**
     * Custom file applicability check (can be overridden)
     */
    protected function customAppliesTo(string $filePath): bool
    {
        return true;
    }

    /**
     * Check if dependencies are satisfied
     */
    public function areDependenciesSatisfied(array $availableRules): bool
    {
        foreach ($this->dependencies as $dependency) {
            if (!in_array($dependency, $availableRules)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Execute the rule on the given file content
     */
    abstract public function execute(string $filePath, string $content): array;

    /**
     * Fix violations in the given content (if fixable)
     */
    public function fix(string $content, array $violations): string
    {
        if (!$this->fixable) {
            throw new \RuntimeException("Rule {$this->id} is not fixable");
        }

        return $this->performFix($content, $violations);
    }

    /**
     * Perform the actual fixing (override in fixable rules)
     */
    protected function performFix(string $content, array $violations): string
    {
        throw new \RuntimeException("Fix method not implemented for rule {$this->id}");
    }

    /**
     * Create a violation for this rule
     */
    protected function createViolation(
        string $message,
        string $filePath,
        int $line = 0,
        int $column = 0,
        ?string $source = null
    ): Violation {
        $violation = new Violation(
            $this->id,
            $message,
            $filePath,
            $line,
            $column,
            $this->severity,
            $this->type
        );

        if ($source !== null) {
            $violation->setSource($source);
        }

        if ($this->category !== null) {
            $violation->setCategory($this->category);
        }

        if ($this->documentationUrl !== null) {
            $violation->setRuleUrl($this->documentationUrl);
        }

        if ($this->fixable) {
            $violation->setFixable(true);
        }

        return $violation;
    }

    /**
     * Validate rule configuration
     */
    protected function validate(): void
    {
        if (empty($this->id)) {
            throw new \InvalidArgumentException("Rule ID cannot be empty");
        }

        if (empty($this->name)) {
            throw new \InvalidArgumentException("Rule name cannot be empty");
        }

        if (!in_array($this->severity, Violation::getSeverityLevels())) {
            throw new \InvalidArgumentException("Invalid severity: {$this->severity}");
        }

        if (!in_array($this->type, Violation::getViolationTypes())) {
            throw new \InvalidArgumentException("Invalid type: {$this->type}");
        }

        if ($this->priority < 0) {
            throw new \InvalidArgumentException("Priority must be non-negative");
        }

        if ($this->performance <= 0) {
            throw new \InvalidArgumentException("Performance weight must be positive");
        }
    }

    /**
     * Configure rule from array
     */
    protected function configure(array $config): void
    {
        if (isset($config['enabled'])) {
            $this->enabled = (bool) $config['enabled'];
        }

        if (isset($config['severity'])) {
            $this->severity = $config['severity'];
        }

        if (isset($config['priority'])) {
            $this->priority = (int) $config['priority'];
        }

        if (isset($config['category'])) {
            $this->category = $config['category'];
        }

        if (isset($config['tags'])) {
            $this->tags = (array) $config['tags'];
        }

        if (isset($config['file_extensions'])) {
            $this->fileExtensions = (array) $config['file_extensions'];
        }

        if (isset($config['exclude_patterns'])) {
            $this->excludePatterns = (array) $config['exclude_patterns'];
        }

        if (isset($config['configuration'])) {
            $this->configuration = array_merge($this->configuration, $config['configuration']);
        }

        if (isset($config['documentation_url'])) {
            $this->documentationUrl = $config['documentation_url'];
        }

        if (isset($config['performance'])) {
            $this->performance = (float) $config['performance'];
        }
    }

    /**
     * Get rule metadata
     */
    public function getMetadata(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'severity' => $this->severity,
            'type' => $this->type,
            'category' => $this->category,
            'priority' => $this->priority,
            'enabled' => $this->enabled,
            'tags' => $this->tags,
            'file_extensions' => $this->fileExtensions,
            'exclude_patterns' => $this->excludePatterns,
            'dependencies' => $this->dependencies,
            'configuration' => $this->configuration,
            'documentation_url' => $this->documentationUrl,
            'fixable' => $this->fixable,
            'performance' => $this->performance,
        ];
    }

    /**
     * Check if rule has a specific tag
     */
    public function hasTag(string $tag): bool
    {
        return in_array($tag, $this->tags);
    }

    /**
     * Add a tag to the rule
     */
    public function addTag(string $tag): self
    {
        if (!$this->hasTag($tag)) {
            $this->tags[] = $tag;
        }
        return $this;
    }

    /**
     * Remove a tag from the rule
     */
    public function removeTag(string $tag): self
    {
        $this->tags = array_values(array_filter($this->tags, fn($t) => $t !== $tag));
        return $this;
    }

    /**
     * Get configuration value
     */
    protected function getConfigValue(string $key, $default = null)
    {
        return $this->configuration[$key] ?? $default;
    }

    /**
     * Set configuration value
     */
    protected function setConfigValue(string $key, $value): self
    {
        $this->configuration[$key] = $value;
        return $this;
    }

    /**
     * Extract source code line from content
     */
    protected function extractSourceLine(string $content, int $lineNumber): ?string
    {
        $lines = explode("\n", $content);
        return $lines[$lineNumber - 1] ?? null;
    }

    /**
     * Count lines in content
     */
    protected function countLines(string $content): int
    {
        return substr_count($content, "\n") + 1;
    }

    /**
     * Find pattern in content with line and column information
     */
    protected function findPattern(string $content, string $pattern, int $flags = 0): array
    {
        $matches = [];
        $result = [];

        if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE | $flags)) {
            foreach ($matches[0] as $match) {
                $offset = $match[1];
                $beforeMatch = substr($content, 0, $offset);
                $line = substr_count($beforeMatch, "\n") + 1;
                $column = $offset - strrpos($beforeMatch, "\n");

                $result[] = [
                    'match' => $match[0],
                    'offset' => $offset,
                    'line' => $line,
                    'column' => $column,
                ];
            }
        }

        return $result;
    }

    /**
     * String representation
     */
    public function __toString(): string
    {
        return "{$this->id} ({$this->name})";
    }
} 
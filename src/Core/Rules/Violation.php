<?php

declare(strict_types=1);

namespace ACQC\Core\Rules;

class Violation
{
    public const SEVERITY_BLOCKER = 'blocker';
    public const SEVERITY_CRITICAL = 'critical';
    public const SEVERITY_ERROR = 'error';
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_INFO = 'info';
    public const SEVERITY_MINOR = 'minor';

    public const TYPE_SYNTAX = 'syntax';
    public const TYPE_STYLE = 'style';
    public const TYPE_LOGIC = 'logic';
    public const TYPE_SECURITY = 'security';
    public const TYPE_PERFORMANCE = 'performance';
    public const TYPE_MAINTAINABILITY = 'maintainability';
    public const TYPE_SEO = 'seo';
    public const TYPE_ACCESSIBILITY = 'accessibility';
    public const TYPE_DOCUMENTATION = 'documentation';
    public const TYPE_API = 'api';
    public const TYPE_GRAPHQL = 'graphql';
    public const TYPE_DATABASE = 'database';

    private string $ruleId;
    private string $message;
    private string $severity;
    private string $type;
    private string $filePath;
    private int $line;
    private int $column;
    private ?string $source = null;
    private array $context = [];
    private ?string $suggestion = null;
    private bool $fixable = false;
    private ?string $fixCode = null;
    private array $metadata = [];
    private float $confidence = 1.0;
    private ?string $category = null;
    private ?string $ruleUrl = null;

    public function __construct(
        string $ruleId,
        string $message,
        string $filePath,
        int $line = 0,
        int $column = 0,
        string $severity = self::SEVERITY_WARNING,
        string $type = self::TYPE_STYLE
    ) {
        $this->ruleId = $ruleId;
        $this->message = $message;
        $this->filePath = $filePath;
        $this->line = max(0, $line);
        $this->column = max(0, $column);
        $this->setSeverity($severity);
        $this->setType($type);
    }

    /**
     * Get the rule ID that generated this violation
     */
    public function getRuleId(): string
    {
        return $this->ruleId;
    }

    /**
     * Get the violation message
     */
    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * Set the violation message
     */
    public function setMessage(string $message): self
    {
        $this->message = $message;
        return $this;
    }

    /**
     * Get the severity level
     */
    public function getSeverity(): string
    {
        return $this->severity;
    }

    /**
     * Set the severity level
     */
    public function setSeverity(string $severity): self
    {
        $validSeverities = [
            self::SEVERITY_BLOCKER,
            self::SEVERITY_CRITICAL,
            self::SEVERITY_ERROR,
            self::SEVERITY_WARNING,
            self::SEVERITY_INFO,
            self::SEVERITY_MINOR
        ];

        if (!in_array($severity, $validSeverities)) {
            throw new \InvalidArgumentException("Invalid severity: {$severity}");
        }

        $this->severity = $severity;
        return $this;
    }

    /**
     * Get the violation type
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Set the violation type
     */
    public function setType(string $type): self
    {
        $validTypes = [
            self::TYPE_SYNTAX,
            self::TYPE_STYLE,
            self::TYPE_LOGIC,
            self::TYPE_SECURITY,
            self::TYPE_PERFORMANCE,
            self::TYPE_MAINTAINABILITY,
            self::TYPE_SEO,
            self::TYPE_ACCESSIBILITY,
            self::TYPE_DOCUMENTATION,
            self::TYPE_API,
            self::TYPE_GRAPHQL,
            self::TYPE_DATABASE
        ];

        if (!in_array($type, $validTypes)) {
            throw new \InvalidArgumentException("Invalid type: {$type}");
        }

        $this->type = $type;
        return $this;
    }

    /**
     * Get the file path
     */
    public function getFilePath(): string
    {
        return $this->filePath;
    }

    /**
     * Get the line number
     */
    public function getLine(): int
    {
        return $this->line;
    }

    /**
     * Set the line number
     */
    public function setLine(int $line): self
    {
        $this->line = max(0, $line);
        return $this;
    }

    /**
     * Get the column number
     */
    public function getColumn(): int
    {
        return $this->column;
    }

    /**
     * Set the column number
     */
    public function setColumn(int $column): self
    {
        $this->column = max(0, $column);
        return $this;
    }

    /**
     * Get the source code that caused the violation
     */
    public function getSource(): ?string
    {
        return $this->source;
    }

    /**
     * Set the source code that caused the violation
     */
    public function setSource(?string $source): self
    {
        $this->source = $source;
        return $this;
    }

    /**
     * Get additional context information
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Set additional context information
     */
    public function setContext(array $context): self
    {
        $this->context = $context;
        return $this;
    }

    /**
     * Add context information
     */
    public function addContext(string $key, $value): self
    {
        $this->context[$key] = $value;
        return $this;
    }

    /**
     * Get improvement suggestion
     */
    public function getSuggestion(): ?string
    {
        return $this->suggestion;
    }

    /**
     * Set improvement suggestion
     */
    public function setSuggestion(?string $suggestion): self
    {
        $this->suggestion = $suggestion;
        return $this;
    }

    /**
     * Check if the violation is fixable
     */
    public function isFixable(): bool
    {
        return $this->fixable;
    }

    /**
     * Set whether the violation is fixable
     */
    public function setFixable(bool $fixable): self
    {
        $this->fixable = $fixable;
        return $this;
    }

    /**
     * Get the fix code
     */
    public function getFixCode(): ?string
    {
        return $this->fixCode;
    }

    /**
     * Set the fix code
     */
    public function setFixCode(?string $fixCode): self
    {
        $this->fixCode = $fixCode;
        if ($fixCode !== null) {
            $this->fixable = true;
        }
        return $this;
    }

    /**
     * Get metadata
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Set metadata
     */
    public function setMetadata(array $metadata): self
    {
        $this->metadata = $metadata;
        return $this;
    }

    /**
     * Add metadata
     */
    public function addMetadata(string $key, $value): self
    {
        $this->metadata[$key] = $value;
        return $this;
    }

    /**
     * Get a specific metadata value
     */
    public function getMetadataValue(string $key, $default = null)
    {
        return $this->metadata[$key] ?? $default;
    }

    /**
     * Get the confidence level (0.0 to 1.0)
     */
    public function getConfidence(): float
    {
        return $this->confidence;
    }

    /**
     * Set the confidence level (0.0 to 1.0)
     */
    public function setConfidence(float $confidence): self
    {
        $this->confidence = max(0.0, min(1.0, $confidence));
        return $this;
    }

    /**
     * Get the category
     */
    public function getCategory(): ?string
    {
        return $this->category;
    }

    /**
     * Set the category
     */
    public function setCategory(?string $category): self
    {
        $this->category = $category;
        return $this;
    }

    /**
     * Get the rule documentation URL
     */
    public function getRuleUrl(): ?string
    {
        return $this->ruleUrl;
    }

    /**
     * Set the rule documentation URL
     */
    public function setRuleUrl(?string $ruleUrl): self
    {
        $this->ruleUrl = $ruleUrl;
        return $this;
    }

    /**
     * Get severity weight for sorting
     */
    public function getSeverityWeight(): int
    {
        return match ($this->severity) {
            self::SEVERITY_BLOCKER => 6,
            self::SEVERITY_CRITICAL => 5,
            self::SEVERITY_ERROR => 4,
            self::SEVERITY_WARNING => 3,
            self::SEVERITY_INFO => 2,
            self::SEVERITY_MINOR => 1,
            default => 0,
        };
    }

    /**
     * Check if this is a blocker violation
     */
    public function isBlocker(): bool
    {
        return $this->severity === self::SEVERITY_BLOCKER;
    }

    /**
     * Check if this is a critical violation
     */
    public function isCritical(): bool
    {
        return $this->severity === self::SEVERITY_CRITICAL;
    }

    /**
     * Check if this is an error violation
     */
    public function isError(): bool
    {
        return $this->severity === self::SEVERITY_ERROR;
    }

    /**
     * Check if this is a warning violation
     */
    public function isWarning(): bool
    {
        return $this->severity === self::SEVERITY_WARNING;
    }

    /**
     * Check if this is an info violation
     */
    public function isInfo(): bool
    {
        return $this->severity === self::SEVERITY_INFO;
    }

    /**
     * Check if this is a minor violation
     */
    public function isMinor(): bool
    {
        return $this->severity === self::SEVERITY_MINOR;
    }

    /**
     * Create a unique fingerprint for this violation
     */
    public function getFingerprint(): string
    {
        return md5(sprintf(
            '%s:%s:%d:%d:%s',
            $this->ruleId,
            $this->filePath,
            $this->line,
            $this->column,
            $this->message
        ));
    }

    /**
     * Get violation position as string
     */
    public function getPosition(): string
    {
        if ($this->column > 0) {
            return "{$this->line}:{$this->column}";
        }
        return (string) $this->line;
    }

    /**
     * Get short file path (relative to working directory)
     */
    public function getShortFilePath(): string
    {
        $cwd = getcwd();
        if ($cwd && strpos($this->filePath, $cwd) === 0) {
            return substr($this->filePath, strlen($cwd) + 1);
        }
        return basename($this->filePath);
    }

    /**
     * Convert violation to array
     */
    public function toArray(): array
    {
        return [
            'rule_id' => $this->ruleId,
            'message' => $this->message,
            'severity' => $this->severity,
            'type' => $this->type,
            'file_path' => $this->filePath,
            'line' => $this->line,
            'column' => $this->column,
            'source' => $this->source,
            'context' => $this->context,
            'suggestion' => $this->suggestion,
            'fixable' => $this->fixable,
            'fix_code' => $this->fixCode,
            'metadata' => $this->metadata,
            'confidence' => $this->confidence,
            'category' => $this->category,
            'rule_url' => $this->ruleUrl,
            'fingerprint' => $this->getFingerprint(),
            'position' => $this->getPosition(),
        ];
    }

    /**
     * Create violation from array
     */
    public static function fromArray(array $data): self
    {
        $violation = new self(
            $data['rule_id'] ?? '',
            $data['message'] ?? '',
            $data['file_path'] ?? '',
            $data['line'] ?? 0,
            $data['column'] ?? 0,
            $data['severity'] ?? self::SEVERITY_WARNING,
            $data['type'] ?? self::TYPE_STYLE
        );

        if (isset($data['source'])) {
            $violation->setSource($data['source']);
        }

        if (isset($data['context'])) {
            $violation->setContext($data['context']);
        }

        if (isset($data['suggestion'])) {
            $violation->setSuggestion($data['suggestion']);
        }

        if (isset($data['fixable'])) {
            $violation->setFixable($data['fixable']);
        }

        if (isset($data['fix_code'])) {
            $violation->setFixCode($data['fix_code']);
        }

        if (isset($data['metadata'])) {
            $violation->setMetadata($data['metadata']);
        }

        if (isset($data['confidence'])) {
            $violation->setConfidence($data['confidence']);
        }

        if (isset($data['category'])) {
            $violation->setCategory($data['category']);
        }

        if (isset($data['rule_url'])) {
            $violation->setRuleUrl($data['rule_url']);
        }

        return $violation;
    }

    /**
     * Convert violation to string representation
     */
    public function __toString(): string
    {
        return sprintf(
            '[%s] %s at %s:%s - %s',
            strtoupper($this->severity),
            $this->ruleId,
            $this->getShortFilePath(),
            $this->getPosition(),
            $this->message
        );
    }

    /**
     * Get all available severity levels
     */
    public static function getSeverityLevels(): array
    {
        return [
            self::SEVERITY_BLOCKER,
            self::SEVERITY_CRITICAL,
            self::SEVERITY_ERROR,
            self::SEVERITY_WARNING,
            self::SEVERITY_INFO,
            self::SEVERITY_MINOR,
        ];
    }

    /**
     * Get all available violation types
     */
    public static function getViolationTypes(): array
    {
        return [
            self::TYPE_SYNTAX,
            self::TYPE_STYLE,
            self::TYPE_LOGIC,
            self::TYPE_SECURITY,
            self::TYPE_PERFORMANCE,
            self::TYPE_MAINTAINABILITY,
            self::TYPE_SEO,
            self::TYPE_ACCESSIBILITY,
        ];
    }
} 
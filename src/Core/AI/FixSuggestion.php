<?php

declare(strict_types=1);

namespace ACQC\Core\AI;

use ACQC\Core\Rules\Violation;

/**
 * AI-generated fix suggestion
 */
class FixSuggestion
{
    private Violation $violation;
    private string $summary;
    private string $fixType;
    private string $originalCode;
    private string $fixedCode;
    private string $diff;
    private array $instructions;
    private string $explanation;
    private array $bestPractices;
    private string $testingNotes;
    private string $riskLevel;
    private string $estimatedTime;
    private float $confidence;
    private array $dependencies;
    private array $alternativeApproaches;

    public function __construct(
        Violation $violation,
        string $summary,
        string $fixType,
        string $originalCode,
        string $fixedCode,
        string $diff,
        array $instructions,
        string $explanation,
        array $bestPractices = [],
        string $testingNotes = '',
        string $riskLevel = 'low',
        string $estimatedTime = 'Unknown',
        float $confidence = 0.8,
        array $dependencies = [],
        array $alternativeApproaches = []
    ) {
        $this->violation = $violation;
        $this->summary = $summary;
        $this->fixType = $fixType;
        $this->originalCode = $originalCode;
        $this->fixedCode = $fixedCode;
        $this->diff = $diff;
        $this->instructions = $instructions;
        $this->explanation = $explanation;
        $this->bestPractices = $bestPractices;
        $this->testingNotes = $testingNotes;
        $this->riskLevel = $riskLevel;
        $this->estimatedTime = $estimatedTime;
        $this->confidence = $confidence;
        $this->dependencies = $dependencies;
        $this->alternativeApproaches = $alternativeApproaches;
    }

    public function getViolation(): Violation { return $this->violation; }
    public function getSummary(): string { return $this->summary; }
    public function getFixType(): string { return $this->fixType; }
    public function getOriginalCode(): string { return $this->originalCode; }
    public function getFixedCode(): string { return $this->fixedCode; }
    public function getDiff(): string { return $this->diff; }
    public function getInstructions(): array { return $this->instructions; }
    public function getExplanation(): string { return $this->explanation; }
    public function getBestPractices(): array { return $this->bestPractices; }
    public function getTestingNotes(): string { return $this->testingNotes; }
    public function getRiskLevel(): string { return $this->riskLevel; }
    public function getEstimatedTime(): string { return $this->estimatedTime; }
    public function getConfidence(): float { return $this->confidence; }
    public function getDependencies(): array { return $this->dependencies; }
    public function getAlternativeApproaches(): array { return $this->alternativeApproaches; }
} 
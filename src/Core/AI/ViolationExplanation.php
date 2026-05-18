<?php

declare(strict_types=1);

namespace ACQC\Core\AI;

use ACQC\Core\Rules\Violation;

/**
 * AI-generated violation explanation
 */
class ViolationExplanation
{
    private Violation $violation;
    private string $summary;
    private string $detailedExplanation;
    private string $whyItsProblem;
    private string $howToFix;
    private string $exampleCode;
    private array $learningResources;
    private string $difficultyLevel;
    private string $estimatedFixTime;

    public function __construct(
        Violation $violation,
        string $summary,
        string $detailedExplanation,
        string $whyItsProblem,
        string $howToFix,
        string $exampleCode = '',
        array $learningResources = [],
        string $difficultyLevel = 'intermediate',
        string $estimatedFixTime = 'Unknown'
    ) {
        $this->violation = $violation;
        $this->summary = $summary;
        $this->detailedExplanation = $detailedExplanation;
        $this->whyItsProblem = $whyItsProblem;
        $this->howToFix = $howToFix;
        $this->exampleCode = $exampleCode;
        $this->learningResources = $learningResources;
        $this->difficultyLevel = $difficultyLevel;
        $this->estimatedFixTime = $estimatedFixTime;
    }

    public function getViolation(): Violation { return $this->violation; }
    public function getSummary(): string { return $this->summary; }
    public function getDetailedExplanation(): string { return $this->detailedExplanation; }
    public function getWhyItsProblem(): string { return $this->whyItsProblem; }
    public function getHowToFix(): string { return $this->howToFix; }
    public function getExampleCode(): string { return $this->exampleCode; }
    public function getLearningResources(): array { return $this->learningResources; }
    public function getDifficultyLevel(): string { return $this->difficultyLevel; }
    public function getEstimatedFixTime(): string { return $this->estimatedFixTime; }
} 
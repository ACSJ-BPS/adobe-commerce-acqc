<?php

declare(strict_types=1);

namespace ACQC\Core\AI;

/**
 * AI Code Analysis Result
 */
class AICodeAnalysis
{
    private bool $success;
    private string $summary;
    private array $insights;
    private float $qualityScore;
    private string $complexityAssessment;
    private float $maintainabilityScore;
    private string $architectureFeedback;
    private array $bestPractices;
    private array $potentialIssues;
    private array $improvementSuggestions;

    public function __construct(
        bool $success,
        string $summary,
        array $insights = [],
        float $qualityScore = 0.0,
        string $complexityAssessment = 'Unknown',
        float $maintainabilityScore = 0.0,
        string $architectureFeedback = '',
        array $bestPractices = [],
        array $potentialIssues = [],
        array $improvementSuggestions = []
    ) {
        $this->success = $success;
        $this->summary = $summary;
        $this->insights = $insights;
        $this->qualityScore = $qualityScore;
        $this->complexityAssessment = $complexityAssessment;
        $this->maintainabilityScore = $maintainabilityScore;
        $this->architectureFeedback = $architectureFeedback;
        $this->bestPractices = $bestPractices;
        $this->potentialIssues = $potentialIssues;
        $this->improvementSuggestions = $improvementSuggestions;
    }

    public function isSuccess(): bool { return $this->success; }
    public function getSummary(): string { return $this->summary; }
    public function getInsights(): array { return $this->insights; }
    public function getQualityScore(): float { return $this->qualityScore; }
    public function getComplexityAssessment(): string { return $this->complexityAssessment; }
    public function getMaintainabilityScore(): float { return $this->maintainabilityScore; }
    public function getArchitectureFeedback(): string { return $this->architectureFeedback; }
    public function getBestPractices(): array { return $this->bestPractices; }
    public function getPotentialIssues(): array { return $this->potentialIssues; }
    public function getImprovementSuggestions(): array { return $this->improvementSuggestions; }
} 
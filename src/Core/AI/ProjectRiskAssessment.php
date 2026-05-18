<?php

declare(strict_types=1);

namespace ACQC\Core\AI;

/**
 * Project Risk Assessment Result
 */
class ProjectRiskAssessment
{
    private bool $success;
    private float $overallRisk;
    private array $riskScores;
    private array $criticalRisks;
    private array $riskTrends;
    private array $mitigationStrategies;
    private array $aiInsights;
    private float $confidence;
    private string $errorMessage;

    public function __construct(
        bool $success,
        float $overallRisk = 0.0,
        array $riskScores = [],
        array $criticalRisks = [],
        array $riskTrends = [],
        array $mitigationStrategies = [],
        array $aiInsights = [],
        float $confidence = 0.0,
        string $errorMessage = ''
    ) {
        $this->success = $success;
        $this->overallRisk = $overallRisk;
        $this->riskScores = $riskScores;
        $this->criticalRisks = $criticalRisks;
        $this->riskTrends = $riskTrends;
        $this->mitigationStrategies = $mitigationStrategies;
        $this->aiInsights = $aiInsights;
        $this->confidence = $confidence;
        $this->errorMessage = $errorMessage;
    }

    public function isSuccess(): bool { return $this->success; }
    public function getOverallRisk(): float { return $this->overallRisk; }
    public function getRiskScores(): array { return $this->riskScores; }
    public function getCriticalRisks(): array { return $this->criticalRisks; }
    public function getRiskTrends(): array { return $this->riskTrends; }
    public function getMitigationStrategies(): array { return $this->mitigationStrategies; }
    public function getAiInsights(): array { return $this->aiInsights; }
    public function getConfidence(): float { return $this->confidence; }
    public function getErrorMessage(): string { return $this->errorMessage; }

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'overall_risk' => $this->overallRisk,
            'risk_scores' => $this->riskScores,
            'critical_risks' => $this->criticalRisks,
            'risk_trends' => $this->riskTrends,
            'mitigation_strategies' => $this->mitigationStrategies,
            'ai_insights' => $this->aiInsights,
            'confidence' => $this->confidence,
            'error_message' => $this->errorMessage,
        ];
    }
}

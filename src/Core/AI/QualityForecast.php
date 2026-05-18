<?php

declare(strict_types=1);

namespace ACQC\Core\AI;

/**
 * Quality Forecast Result
 * 
 * Contains comprehensive quality predictions including trends,
 * technical debt forecasts, and risk assessments.
 */
class QualityForecast
{
    private bool $success;
    private ?QualityTrendAnalysis $trendAnalysis;
    private ?TechnicalDebtPrediction $debtPrediction;
    private ?ProjectRiskAssessment $riskAssessment;
    private array $aiInsights;
    private array $recommendations;
    private float $confidence;
    private string $errorMessage;

    public function __construct(
        bool $success,
        ?QualityTrendAnalysis $trendAnalysis,
        ?TechnicalDebtPrediction $debtPrediction,
        ?ProjectRiskAssessment $riskAssessment,
        array $aiInsights = [],
        array $recommendations = [],
        float $confidence = 0.0,
        string $errorMessage = ''
    ) {
        $this->success = $success;
        $this->trendAnalysis = $trendAnalysis;
        $this->debtPrediction = $debtPrediction;
        $this->riskAssessment = $riskAssessment;
        $this->aiInsights = $aiInsights;
        $this->recommendations = $recommendations;
        $this->confidence = $confidence;
        $this->errorMessage = $errorMessage;
    }

    public function isSuccess(): bool { return $this->success; }
    public function getTrendAnalysis(): ?QualityTrendAnalysis { return $this->trendAnalysis; }
    public function getDebtPrediction(): ?TechnicalDebtPrediction { return $this->debtPrediction; }
    public function getRiskAssessment(): ?ProjectRiskAssessment { return $this->riskAssessment; }
    public function getAiInsights(): array { return $this->aiInsights; }
    public function getRecommendations(): array { return $this->recommendations; }
    public function getConfidence(): float { return $this->confidence; }
    public function getErrorMessage(): string { return $this->errorMessage; }

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'trend_analysis' => $this->trendAnalysis ? $this->trendAnalysis->toArray() : null,
            'debt_prediction' => $this->debtPrediction ? $this->debtPrediction->toArray() : null,
            'risk_assessment' => $this->riskAssessment ? $this->riskAssessment->toArray() : null,
            'ai_insights' => $this->aiInsights,
            'recommendations' => $this->recommendations,
            'confidence' => $this->confidence,
            'error_message' => $this->errorMessage
        ];
    }
}

/**
 * Team Productivity Analysis Result
 */
class TeamProductivityAnalysis
{
    private array $metrics;
    private array $trends;
    private array $insights;
    private array $recommendations;
    private float $productivityScore;

    public function __construct(
        array $metrics,
        array $trends,
        array $insights,
        array $recommendations,
        float $productivityScore
    ) {
        $this->metrics = $metrics;
        $this->trends = $trends;
        $this->insights = $insights;
        $this->recommendations = $recommendations;
        $this->productivityScore = $productivityScore;
    }

    public function getMetrics(): array { return $this->metrics; }
    public function getTrends(): array { return $this->trends; }
    public function getInsights(): array { return $this->insights; }
    public function getRecommendations(): array { return $this->recommendations; }
    public function getProductivityScore(): float { return $this->productivityScore; }
}

/**
 * Performance Forecast Result
 */
class PerformanceForecast
{
    private array $forecast;
    private array $degradationPatterns;
    private array $alerts;
    private array $recommendations;
    private float $performanceRisk;

    public function __construct(
        array $forecast,
        array $degradationPatterns,
        array $alerts,
        array $recommendations,
        float $performanceRisk
    ) {
        $this->forecast = $forecast;
        $this->degradationPatterns = $degradationPatterns;
        $this->alerts = $alerts;
        $this->recommendations = $recommendations;
        $this->performanceRisk = $performanceRisk;
    }

    public function getForecast(): array { return $this->forecast; }
    public function getDegradationPatterns(): array { return $this->degradationPatterns; }
    public function getAlerts(): array { return $this->alerts; }
    public function getRecommendations(): array { return $this->recommendations; }
    public function getPerformanceRisk(): float { return $this->performanceRisk; }
} 
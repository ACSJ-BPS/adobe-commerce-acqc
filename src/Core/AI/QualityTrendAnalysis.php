<?php

declare(strict_types=1);

namespace ACQC\Core\AI;

/**
 * Quality Trend Analysis Result
 */
class QualityTrendAnalysis
{
    private bool $success;
    private array $overallTrends;
    private array $metricTrends;
    private array $seasonalPatterns;
    private array $anomalies;
    private array $forecasts;
    private array $insights;
    private float $confidence;
    private string $errorMessage;

    public function __construct(
        bool $success,
        array $overallTrends = [],
        array $metricTrends = [],
        array $seasonalPatterns = [],
        array $anomalies = [],
        array $forecasts = [],
        array $insights = [],
        float $confidence = 0.0,
        string $errorMessage = ''
    ) {
        $this->success = $success;
        $this->overallTrends = $overallTrends;
        $this->metricTrends = $metricTrends;
        $this->seasonalPatterns = $seasonalPatterns;
        $this->anomalies = $anomalies;
        $this->forecasts = $forecasts;
        $this->insights = $insights;
        $this->confidence = $confidence;
        $this->errorMessage = $errorMessage;
    }

    public function isSuccess(): bool { return $this->success; }
    public function getOverallTrends(): array { return $this->overallTrends; }
    public function getMetricTrends(): array { return $this->metricTrends; }
    public function getSeasonalPatterns(): array { return $this->seasonalPatterns; }
    public function getAnomalies(): array { return $this->anomalies; }
    public function getForecasts(): array { return $this->forecasts; }
    public function getInsights(): array { return $this->insights; }
    public function getConfidence(): float { return $this->confidence; }
    public function getErrorMessage(): string { return $this->errorMessage; }

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'overall_trends' => $this->overallTrends,
            'metric_trends' => $this->metricTrends,
            'seasonal_patterns' => $this->seasonalPatterns,
            'anomalies' => $this->anomalies,
            'forecasts' => $this->forecasts,
            'insights' => $this->insights,
            'confidence' => $this->confidence,
            'error_message' => $this->errorMessage,
        ];
    }
}

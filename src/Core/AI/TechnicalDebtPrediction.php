<?php

declare(strict_types=1);

namespace ACQC\Core\AI;

/**
 * Technical Debt Prediction Result
 */
class TechnicalDebtPrediction
{
    private bool $success;
    private array $debtForecast;
    private float $debtVelocity;
    private array $debtDrivers;
    private float $riskScore;
    private array $aiInsights;
    private array $recommendations;
    private float $confidence;
    private string $errorMessage;

    public function __construct(
        bool $success,
        array $debtForecast = [],
        float $debtVelocity = 0.0,
        array $debtDrivers = [],
        float $riskScore = 0.0,
        array $aiInsights = [],
        array $recommendations = [],
        float $confidence = 0.0,
        string $errorMessage = ''
    ) {
        $this->success = $success;
        $this->debtForecast = $debtForecast;
        $this->debtVelocity = $debtVelocity;
        $this->debtDrivers = $debtDrivers;
        $this->riskScore = $riskScore;
        $this->aiInsights = $aiInsights;
        $this->recommendations = $recommendations;
        $this->confidence = $confidence;
        $this->errorMessage = $errorMessage;
    }

    public function isSuccess(): bool { return $this->success; }
    public function getDebtForecast(): array { return $this->debtForecast; }
    public function getDebtVelocity(): float { return $this->debtVelocity; }
    public function getDebtDrivers(): array { return $this->debtDrivers; }
    public function getRiskScore(): float { return $this->riskScore; }
    public function getAiInsights(): array { return $this->aiInsights; }
    public function getRecommendations(): array { return $this->recommendations; }
    public function getConfidence(): float { return $this->confidence; }
    public function getErrorMessage(): string { return $this->errorMessage; }

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'debt_forecast' => $this->debtForecast,
            'debt_velocity' => $this->debtVelocity,
            'debt_drivers' => $this->debtDrivers,
            'risk_score' => $this->riskScore,
            'ai_insights' => $this->aiInsights,
            'recommendations' => $this->recommendations,
            'confidence' => $this->confidence,
            'error_message' => $this->errorMessage,
        ];
    }
}

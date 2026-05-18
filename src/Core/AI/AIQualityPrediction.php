<?php

declare(strict_types=1);

namespace ACQC\Core\AI;

/**
 * AI Quality Prediction Result
 */
class AIQualityPrediction
{
    private bool $success;
    private float $score;
    private string $explanation;
    private array $recommendations;
    private float $confidence;

    public function __construct(
        bool $success,
        float $score,
        string $explanation,
        array $recommendations = [],
        float $confidence = 0.0
    ) {
        $this->success = $success;
        $this->score = $score;
        $this->explanation = $explanation;
        $this->recommendations = $recommendations;
        $this->confidence = $confidence;
    }

    public function isSuccess(): bool { return $this->success; }
    public function getScore(): float { return $this->score; }
    public function getExplanation(): string { return $this->explanation; }
    public function getRecommendations(): array { return $this->recommendations; }
    public function getConfidence(): float { return $this->confidence; }
} 
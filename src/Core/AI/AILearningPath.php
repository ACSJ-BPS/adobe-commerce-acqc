<?php

declare(strict_types=1);

namespace ACQC\Core\AI;

/**
 * AI Learning Path Recommendation
 */
class AILearningPath
{
    private bool $success;
    private array $path;
    private string $explanation;
    private string $estimatedTime;
    private string $difficulty;

    public function __construct(
        bool $success,
        array $path,
        string $explanation,
        string $estimatedTime = '',
        string $difficulty = 'intermediate'
    ) {
        $this->success = $success;
        $this->path = $path;
        $this->explanation = $explanation;
        $this->estimatedTime = $estimatedTime;
        $this->difficulty = $difficulty;
    }

    public function isSuccess(): bool { return $this->success; }
    public function getPath(): array { return $this->path; }
    public function getExplanation(): string { return $this->explanation; }
    public function getEstimatedTime(): string { return $this->estimatedTime; }
    public function getDifficulty(): string { return $this->difficulty; }
} 
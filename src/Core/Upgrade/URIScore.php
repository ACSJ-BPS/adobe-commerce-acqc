<?php

declare(strict_types=1);

namespace ACQC\Core\Upgrade;

readonly class URIScore
{
    public function __construct(
        public float  $score,
        public string $grade,
        public string $gradeDescription,
        public int    $engineeringHours,
        public int    $qaHours,
        public int    $infraHours,
        public int    $totalHoursWithContingency,
        public string $recommendedTimeline,
        public array  $sprintBreakdown,
        public string $goLiveRecommendation,
    ) {}

    public function gradeColor(): string
    {
        return match ($this->grade) {
            'A'     => '#16a34a',
            'B'     => '#2563eb',
            'C'     => '#d97706',
            'D'     => '#ea580c',
            default => '#dc2626',
        };
    }
}

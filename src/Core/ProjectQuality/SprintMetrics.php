<?php

declare(strict_types=1);

namespace ACQC\Core\ProjectQuality;

/**
 * Value object representing metrics for a single sprint
 */
class SprintMetrics
{
    private int $sprintNumber;
    private float $testCoverage;
    private float $codeQuality;
    private float $effortDeviation;
    private float $velocity;
    private float $defectDensity;
    private int $filesAnalyzed;
    private int $totalIssues;
    private int $criticalIssues;
    private int $linesOfCode;
    private string $status;
    private array $details;

    public function __construct(
        int $sprintNumber,
        float $testCoverage = 0.0,
        float $codeQuality = 0.0,
        float $effortDeviation = 0.0,
        float $velocity = 0.0,
        float $defectDensity = 0.0,
        int $filesAnalyzed = 0,
        int $totalIssues = 0,
        int $criticalIssues = 0,
        int $linesOfCode = 0,
        array $details = []
    ) {
        $this->sprintNumber = $sprintNumber;
        $this->testCoverage = $testCoverage;
        $this->codeQuality = $codeQuality;
        $this->effortDeviation = $effortDeviation;
        $this->velocity = $velocity;
        $this->defectDensity = $defectDensity;
        $this->filesAnalyzed = $filesAnalyzed;
        $this->totalIssues = $totalIssues;
        $this->criticalIssues = $criticalIssues;
        $this->linesOfCode = $linesOfCode;
        $this->details = $details;
        $this->status = $this->calculateStatus();
    }

    public function getSprintNumber(): int
    {
        return $this->sprintNumber;
    }

    public function getTestCoverage(): float
    {
        return $this->testCoverage;
    }

    public function getCodeQuality(): float
    {
        return $this->codeQuality;
    }

    public function getEffortDeviation(): float
    {
        return $this->effortDeviation;
    }

    public function getVelocity(): float
    {
        return $this->velocity;
    }

    public function getDefectDensity(): float
    {
        return $this->defectDensity;
    }

    public function getFilesAnalyzed(): int
    {
        return $this->filesAnalyzed;
    }

    public function getTotalIssues(): int
    {
        return $this->totalIssues;
    }

    public function getCriticalIssues(): int
    {
        return $this->criticalIssues;
    }

    public function getLinesOfCode(): int
    {
        return $this->linesOfCode;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getDetails(): array
    {
        return $this->details;
    }

    public function getTestCoverageStatus(): string
    {
        if ($this->testCoverage >= 90) return 'excellent';
        if ($this->testCoverage >= 80) return 'good';
        if ($this->testCoverage >= 50) return 'warning';
        return 'critical';
    }

    public function getCodeQualityStatus(): string
    {
        if ($this->codeQuality >= 90) return 'excellent';
        if ($this->codeQuality >= 80) return 'good';
        if ($this->codeQuality >= 60) return 'warning';
        return 'critical';
    }

    public function getEffortDeviationStatus(): string
    {
        $deviation = abs($this->effortDeviation);
        if ($deviation <= 10) return 'good';
        if ($deviation <= 20) return 'warning';
        return 'critical';
    }

    public function getDefectDensityStatus(): string
    {
        if ($this->defectDensity <= 1.0) return 'good';
        if ($this->defectDensity <= 2.0) return 'warning';
        return 'critical';
    }

    public function getVelocityStatus(): string
    {
        if ($this->velocity >= 90) return 'excellent';
        if ($this->velocity >= 70) return 'warning';
        return 'critical';
    }

    private function calculateStatus(): string
    {
        $statuses = [
            $this->getTestCoverageStatus(),
            $this->getCodeQualityStatus(),
            $this->getEffortDeviationStatus(),
            $this->getDefectDensityStatus(),
            $this->getVelocityStatus()
        ];

        if (in_array('critical', $statuses)) {
            return 'critical';
        }
        if (in_array('warning', $statuses)) {
            return 'warning';
        }
        if (in_array('excellent', array_count_values($statuses))) {
            return 'excellent';
        }
        return 'good';
    }

    public function toArray(): array
    {
        return [
            'sprint_number' => $this->sprintNumber,
            'test_coverage' => round($this->testCoverage, 1),
            'code_quality' => round($this->codeQuality, 1),
            'effort_deviation' => round($this->effortDeviation, 1),
            'velocity' => round($this->velocity, 1),
            'defect_density' => round($this->defectDensity, 2),
            'files_analyzed' => $this->filesAnalyzed,
            'total_issues' => $this->totalIssues,
            'critical_issues' => $this->criticalIssues,
            'lines_of_code' => $this->linesOfCode,
            'status' => $this->status,
            'test_coverage_status' => $this->getTestCoverageStatus(),
            'code_quality_status' => $this->getCodeQualityStatus(),
            'effort_deviation_status' => $this->getEffortDeviationStatus(),
            'defect_density_status' => $this->getDefectDensityStatus(),
            'velocity_status' => $this->getVelocityStatus(),
            'details' => $this->details
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            $data['sprint_number'] ?? 1,
            $data['test_coverage'] ?? 0.0,
            $data['code_quality'] ?? 0.0,
            $data['effort_deviation'] ?? 0.0,
            $data['velocity'] ?? 0.0,
            $data['defect_density'] ?? 0.0,
            $data['files_analyzed'] ?? 0,
            $data['total_issues'] ?? 0,
            $data['critical_issues'] ?? 0,
            $data['lines_of_code'] ?? 0,
            $data['details'] ?? []
        );
    }
}


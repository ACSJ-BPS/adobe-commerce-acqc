<?php

declare(strict_types=1);

namespace ACQC\Core\Analysis;

/**
 * Calculate letter grades from numeric quality scores
 * 
 * Converts numeric scores (0-100) to letter grades (A+ to F)
 * with contextual recommendations and emoji indicators.
 */
class GradeCalculator
{
    private const GRADE_THRESHOLDS = [
        'A+' => 95,
        'A'  => 90,
        'B'  => 80,
        'C'  => 70,
        'D'  => 60,
        'F'  => 0,
    ];
    
    private array $customThresholds = [];
    
    /**
     * @param array<string, int> $customThresholds Optional custom grade thresholds
     */
    public function __construct(array $customThresholds = [])
    {
        $this->customThresholds = array_merge(self::GRADE_THRESHOLDS, $customThresholds);
        
        // Sort thresholds in descending order
        arsort($this->customThresholds);
    }
    
    /**
     * Calculate letter grade from numeric score
     * 
     * @param int $numericScore Numeric score (0-100)
     * @return array{
     *   grade: string,
     *   numeric_score: int,
     *   label: string,
     *   emoji: string,
     *   description: string,
     *   color: string,
     *   recommendations: array<string>
     * } Grade information
     */
    public function calculate(int $numericScore): array
    {
        // Clamp score to 0-100
        $numericScore = max(0, min(100, $numericScore));
        
        $grade = $this->getGrade($numericScore);
        
        return [
            'grade' => $grade,
            'numeric_score' => $numericScore,
            'label' => $this->getLabel($grade),
            'emoji' => $this->getEmoji($grade),
            'description' => $this->getDescription($grade),
            'color' => $this->getColor($grade),
            'recommendations' => $this->getRecommendations($grade),
            'status' => $this->getStatus($grade),
            'threshold' => $this->customThresholds[$grade],
        ];
    }
    
    /**
     * Get letter grade for numeric score
     */
    private function getGrade(int $score): string
    {
        foreach ($this->customThresholds as $grade => $threshold) {
            if ($score >= $threshold) {
                return $grade;
            }
        }
        return 'F';
    }
    
    /**
     * Get descriptive label for grade
     */
    private function getLabel(string $grade): string
    {
        return match($grade) {
            'A+' => 'Exceptional',
            'A'  => 'Excellent',
            'B'  => 'Good',
            'C'  => 'Fair',
            'D'  => 'Poor',
            'F'  => 'Critical',
            default => 'Unknown'
        };
    }
    
    /**
     * Get emoji indicator for grade
     */
    private function getEmoji(string $grade): string
    {
        return match($grade) {
            'A+' => '🏆',
            'A'  => '⭐',
            'B'  => '👍',
            'C'  => '⚠️',
            'D'  => '⚠️⚠️',
            'F'  => '🔴',
            default => '❓'
        };
    }
    
    /**
     * Get detailed description for grade
     */
    private function getDescription(string $grade): string
    {
        return match($grade) {
            'A+' => 'Exceptional - Production optimized. Your code quality is outstanding!',
            'A'  => 'Excellent - Minor optimizations possible. Great work!',
            'B'  => 'Good - Several improvements recommended. Solid codebase.',
            'C'  => 'Fair - Notable quality gaps. Focus on addressing warnings.',
            'D'  => 'Poor - Significant issues present. Immediate attention required.',
            'F'  => 'Critical - Major quality problems. Urgent action needed.',
            default => 'Unknown grade'
        };
    }
    
    /**
     * Get color code for grade (for console/UI)
     */
    private function getColor(string $grade): string
    {
        return match($grade) {
            'A+', 'A' => 'green',
            'B'       => 'cyan',
            'C'       => 'yellow',
            'D'       => 'orange',
            'F'       => 'red',
            default   => 'gray'
        };
    }
    
    /**
     * Get status indicator
     */
    private function getStatus(string $grade): string
    {
        return match($grade) {
            'A+', 'A' => 'passing',
            'B'       => 'acceptable',
            'C'       => 'needs_improvement',
            'D', 'F'  => 'failing',
            default   => 'unknown'
        };
    }
    
    /**
     * Get actionable recommendations for grade
     * 
     * @return array<string>
     */
    private function getRecommendations(string $grade): array
    {
        return match($grade) {
            'A+' => [
                'Maintain your high standards',
                'Consider contributing to open source',
                'Share your best practices with the team',
                'Focus on innovation and new features',
            ],
            'A' => [
                'Review remaining warnings and info violations',
                'Consider refactoring complex methods',
                'Update documentation where needed',
                'Great job - keep it up!',
            ],
            'B' => [
                'Focus on fixing error-level violations first',
                'Review and fix warning violations systematically',
                'Consider improving test coverage',
                'Refactor complex or duplicate code',
            ],
            'C' => [
                'Address all error violations immediately',
                'Create plan to systematically fix warnings',
                'Review security violations carefully',
                'Consider automated fixes (--generate-fix)',
            ],
            'D' => [
                'Stop and address critical and error violations NOW',
                'Use --generate-fix to auto-fix configuration issues',
                'Focus on security and performance violations',
                'Consider code review with senior developer',
                'May require significant refactoring',
            ],
            'F' => [
                'URGENT: Critical issues require immediate attention',
                'Stop adding new features until quality improves',
                'Use --generate-fix for quick wins',
                'Address all critical and error violations first',
                'Consider pair programming for complex issues',
                'May need architectural review',
            ],
            default => ['Review violations and improve code quality']
        };
    }
    
    /**
     * Calculate exponential penalty for multiple violations
     * 
     * Multiple violations compound performance/quality impact
     * 
     * @param int $violationCount Number of violations
     * @param float $baseImpact Base impact per violation (0-1)
     * @return float Penalty multiplier (1.0 = no penalty, >1.0 = penalty)
     */
    public function calculateExponentialPenalty(int $violationCount, float $baseImpact = 0.1): float
    {
        if ($violationCount === 0) {
            return 1.0;
        }
        
        // Exponential penalty: penalty = 1 + (impact * violations^1.2)
        // This means 10 violations hurt more than 10x a single violation
        $penalty = 1.0 + ($baseImpact * pow($violationCount, 1.2));
        
        return $penalty;
    }
    
    /**
     * Calculate grade with violation-based adjustments
     * 
     * @param int $baseScore Base numeric score
     * @param array{
     *   critical?: int,
     *   error?: int,
     *   warning?: int,
     *   info?: int
     * } $violationCounts Violations by severity
     * @return array Grade information
     */
    public function calculateWithViolations(int $baseScore, array $violationCounts): array
    {
        // Calculate exponential penalties
        $criticalPenalty = $this->calculateExponentialPenalty($violationCounts['critical'] ?? 0, 0.5);
        $errorPenalty = $this->calculateExponentialPenalty($violationCounts['error'] ?? 0, 0.2);
        $warningPenalty = $this->calculateExponentialPenalty($violationCounts['warning'] ?? 0, 0.1);
        
        // Apply penalties
        $adjustedScore = $baseScore;
        $adjustedScore = (int)($adjustedScore / $criticalPenalty);
        $adjustedScore = (int)($adjustedScore / $errorPenalty);
        $adjustedScore = (int)($adjustedScore / $warningPenalty);
        
        // Calculate grade
        $gradeInfo = $this->calculate($adjustedScore);
        
        // Add penalty information
        $gradeInfo['base_score'] = $baseScore;
        $gradeInfo['adjusted_score'] = $adjustedScore;
        $gradeInfo['penalties'] = [
            'critical' => $criticalPenalty,
            'error' => $errorPenalty,
            'warning' => $warningPenalty,
        ];
        $gradeInfo['violation_counts'] = $violationCounts;
        
        return $gradeInfo;
    }
    
    /**
     * Get grade comparison (for trend analysis)
     * 
     * @param string $oldGrade Previous grade
     * @param string $newGrade Current grade
     * @return array{
     *   improved: bool,
     *   degraded: bool,
     *   stable: bool,
     *   change: string,
     *   message: string
     * } Comparison result
     */
    public function compareGrades(string $oldGrade, string $newGrade): array
    {
        $oldValue = array_search($oldGrade, array_keys($this->customThresholds));
        $newValue = array_search($newGrade, array_keys($this->customThresholds));
        
        if ($oldValue === false || $newValue === false) {
            return [
                'improved' => false,
                'degraded' => false,
                'stable' => false,
                'change' => 'unknown',
                'message' => 'Unable to compare grades'
            ];
        }
        
        $improved = $newValue < $oldValue;  // Lower index = better grade
        $degraded = $newValue > $oldValue;
        $stable = $newValue === $oldValue;
        
        return [
            'improved' => $improved,
            'degraded' => $degraded,
            'stable' => $stable,
            'change' => $improved ? '⬆️' : ($degraded ? '⬇️' : '➡️'),
            'message' => match(true) {
                $improved => "Improved from {$oldGrade} to {$newGrade} 🎉",
                $degraded => "Degraded from {$oldGrade} to {$newGrade} ⚠️",
                $stable => "Maintained {$newGrade} grade ✓",
                default => 'No change'
            }
        ];
    }
    
    /**
     * Format grade for console output
     * 
     * @param array $gradeInfo Grade information from calculate()
     * @param bool $includeEmoji Whether to include emoji
     * @return string Formatted grade string
     */
    public function formatForConsole(array $gradeInfo, bool $includeEmoji = true): string
    {
        $emoji = $includeEmoji ? $gradeInfo['emoji'] . ' ' : '';
        
        return sprintf(
            '%sGrade: %s (%s) - %d/100',
            $emoji,
            $gradeInfo['grade'],
            $gradeInfo['label'],
            $gradeInfo['numeric_score']
        );
    }
}

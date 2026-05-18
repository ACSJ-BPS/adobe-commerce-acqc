<?php

declare(strict_types=1);

namespace ACQC\Analyzers\Version;

use ACQC\Core\Version\MagentoVersion;
use ACQC\Core\Version\PhpVersion;

/**
 * Upgrade Analysis Result
 * 
 * Contains complete upgrade analysis with all findings,
 * effort estimation, and upgrade roadmap.
 */
class UpgradeAnalysisResult
{
    private MagentoVersion $currentMagentoVersion;
    private MagentoVersion $targetMagentoVersion;
    private PhpVersion $currentPhpVersion;
    private PhpVersion $targetPhpVersion;
    
    private array $codeIssues = [];
    private array $phpIssues = [];
    private array $databaseIssues = [];
    private array $prioritizedIssues = [];
    private array $effortEstimation = [];
    private array $roi = [];
    private array $riskAssessment = [];
    private array $enhancedTimeline = [];
    private array $upgradeRoadmap = [];
    private array $databaseMigrations = [];
    private array $fullScanStats = [];

    public function __construct(
        MagentoVersion $currentMagento,
        MagentoVersion $targetMagento,
        PhpVersion $currentPhp,
        PhpVersion $targetPhp
    ) {
        $this->currentMagentoVersion = $currentMagento;
        $this->targetMagentoVersion = $targetMagento;
        $this->currentPhpVersion = $currentPhp;
        $this->targetPhpVersion = $targetPhp;
    }

    // Getters
    public function getCurrentMagentoVersion(): MagentoVersion
    {
        return $this->currentMagentoVersion;
    }

    public function getTargetMagentoVersion(): MagentoVersion
    {
        return $this->targetMagentoVersion;
    }

    public function getCurrentPhpVersion(): PhpVersion
    {
        return $this->currentPhpVersion;
    }

    public function getTargetPhpVersion(): PhpVersion
    {
        return $this->targetPhpVersion;
    }

    public function getCodeIssues(): array
    {
        return $this->codeIssues;
    }

    public function getPhpIssues(): array
    {
        return $this->phpIssues;
    }

    public function getDatabaseIssues(): array
    {
        return $this->databaseIssues;
    }

    public function getPrioritizedIssues(): array
    {
        return $this->prioritizedIssues;
    }

    public function getEffortEstimation(): array
    {
        return $this->effortEstimation;
    }

    public function getUpgradeRoadmap(): array
    {
        return $this->upgradeRoadmap;
    }

    public function getDatabaseMigrations(): array
    {
        return $this->databaseMigrations;
    }

    // Setters
    public function setCodeIssues(array $issues): void
    {
        $this->codeIssues = $issues;
    }

    public function setPhpIssues(array $issues): void
    {
        $this->phpIssues = $issues;
    }

    public function setDatabaseIssues(array $issues): void
    {
        $this->databaseIssues = $issues;
    }

    public function setPrioritizedIssues(array $issues): void
    {
        $this->prioritizedIssues = $issues;
    }

    public function setEffortEstimation(array $estimation): void
    {
        $this->effortEstimation = $estimation;
    }

    public function setUpgradeRoadmap(array $roadmap): void
    {
        $this->upgradeRoadmap = $roadmap;
    }

    public function getROI(): array
    {
        return $this->roi;
    }

    public function setROI(array $roi): void
    {
        $this->roi = $roi;
    }

    public function getRiskAssessment(): array
    {
        return $this->riskAssessment;
    }

    public function setRiskAssessment(array $riskAssessment): void
    {
        $this->riskAssessment = $riskAssessment;
    }

    public function getEnhancedTimeline(): array
    {
        return $this->enhancedTimeline;
    }

    public function setEnhancedTimeline(array $enhancedTimeline): void
    {
        $this->enhancedTimeline = $enhancedTimeline;
    }

    public function setDatabaseMigrations(array $migrations): void
    {
        $this->databaseMigrations = $migrations;
    }

    public function setFullScanStats(array $stats): void
    {
        $this->fullScanStats = $stats;
    }

    public function getFullScanStats(): array
    {
        return $this->fullScanStats;
    }

    /**
     * Get total issue count
     */
    public function getTotalIssueCount(): int
    {
        return count($this->codeIssues) + count($this->phpIssues) + count($this->databaseIssues);
    }

    /**
     * Get issue count by priority
     */
    public function getIssueCountByPriority(string $priority): int
    {
        return count($this->prioritizedIssues[$priority] ?? []);
    }

    /**
     * Check if upgrade is possible
     */
    public function isUpgradePossible(): bool
    {
        // Upgrade is possible if there are no critical blockers
        // or if all critical issues have known fixes
        $criticalIssues = $this->prioritizedIssues['critical'] ?? [];
        
        foreach ($criticalIssues as $issue) {
            if (empty($issue['fix'])) {
                return false; // No known fix for critical issue
            }
        }
        
        return true;
    }

    /**
     * Get upgrade readiness score (0-100)
     */
    public function getUpgradeReadinessScore(): int
    {
        $totalIssues = $this->getTotalIssueCount();
        
        if ($totalIssues === 0) {
            return 100;
        }

        $criticalCount = $this->getIssueCountByPriority('critical');
        $highCount = $this->getIssueCountByPriority('high');
        $mediumCount = $this->getIssueCountByPriority('medium');
        $lowCount = $this->getIssueCountByPriority('low');

        // Weight: Critical = 10, High = 5, Medium = 2, Low = 1
        $weightedIssues = ($criticalCount * 10) + ($highCount * 5) + 
                         ($mediumCount * 2) + ($lowCount * 1);

        // Calculate score (inverse relationship)
        $maxPossibleScore = $totalIssues * 10; // If all were critical
        $score = 100 - (($weightedIssues / $maxPossibleScore) * 100);

        return max(0, min(100, (int)$score));
    }

    /**
     * Get upgrade risk level
     */
    public function getUpgradeRiskLevel(): string
    {
        $criticalCount = $this->getIssueCountByPriority('critical');
        $highCount = $this->getIssueCountByPriority('high');

        if ($criticalCount > 20 || $highCount > 50) {
            return 'HIGH';
        } elseif ($criticalCount > 10 || $highCount > 25) {
            return 'MEDIUM';
        } elseif ($criticalCount > 0 || $highCount > 10) {
            return 'MEDIUM-LOW';
        } else {
            return 'LOW';
        }
    }

    /**
     * Export to array
     */
    public function toArray(): array
    {
        return [
            'version_info' => [
                'current_magento' => $this->currentMagentoVersion->toString(),
                'target_magento' => $this->targetMagentoVersion->toString(),
                'current_php' => $this->currentPhpVersion->toString(),
                'target_php' => $this->targetPhpVersion->toString(),
            ],
            'summary' => [
                'total_issues' => $this->getTotalIssueCount(),
                'code_issues' => count($this->codeIssues),
                'php_issues' => count($this->phpIssues),
                'database_issues' => count($this->databaseIssues),
                'critical' => $this->getIssueCountByPriority('critical'),
                'high' => $this->getIssueCountByPriority('high'),
                'medium' => $this->getIssueCountByPriority('medium'),
                'low' => $this->getIssueCountByPriority('low'),
                'readiness_score' => $this->getUpgradeReadinessScore(),
                'risk_level' => $this->getUpgradeRiskLevel(),
                'upgrade_possible' => $this->isUpgradePossible(),
            ],
            'issues' => [
                'code' => $this->codeIssues,
                'php' => $this->phpIssues,
                'database' => $this->databaseIssues,
                'prioritized' => $this->prioritizedIssues,
            ],
            'effort_estimation' => $this->effortEstimation,
            'upgrade_roadmap' => $this->upgradeRoadmap,
            'database_migrations' => $this->databaseMigrations,
        ];
    }
}


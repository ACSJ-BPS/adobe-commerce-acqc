<?php

declare(strict_types=1);

namespace ACQC\Core\Upgrade;

class UpgradeRiskIndex
{
    // Per-finding-type weights (overrides severity default when matched)
    private const TYPE_WEIGHTS = [
        'class_removed'                  => 10.0,
        'method_removed'                 => 10.0,
        'db_schema_blocker'              => 8.0,
        'legacy_setup_script_detected'   => 6.0,
        'composer_security_advisory'     => 7.0,
        'composer_transitive_conflict'   => 5.0,
        'platform_php_mismatch'          => 4.0,
        'composer_package_abandoned'     => 3.0,
        'platform_extension_missing'     => 2.0,
        // B2B / Cloud specific
        'b2b_version_incompatible'       => 12.0,
        'b2b_api_contract_changed'       => 6.0,
        'b2b_module_deprecated'          => 4.0,
        'cloud_search_engine_incompatible' => 8.0,
        'cloud_php_version_incompatible'   => 6.0,
        'cloud_ece_tools_outdated'         => 4.0,
    ];

    // BRD §4.9 severity weights: Blocker x25, Critical x15, Error x8, Warning x3, Notice x1
    private const SEVERITY_WEIGHTS = [
        'blocker'  => 25.0,
        'critical' => 15.0,
        'error'    => 8.0,
        'warning'  => 3.0,
        'notice'   => 1.0,
        'info'     => 0.5,
    ];

    private const DB_RISK_TYPES = [
        'db_schema_blocker',
        'legacy_setup_script_detected',
    ];

    private const COMPOSER_RISK_TYPES = [
        'composer_package_abandoned',
        'composer_security_advisory',
    ];

    // BRD §4.9 grade thresholds: A=0-15, B=16-35, C=36-65, D=66-85, F=86-100
    private const GRADES = [
        'A' => [0,   15,  'Minimal risk — upgrade is straightforward'],
        'B' => [16,  35,  'Low risk — minor remediation needed'],
        'C' => [36,  65,  'Moderate risk — planned upgrade effort required'],
        'D' => [66,  85,  'High risk — significant remediation needed'],
        'F' => [86,  100, 'Critical risk — major upgrade blockers present'],
    ];

    public function calculate(array $allFindings, int $totalLoc = 0, int $customLoc = 0): URIScore
    {
        $baseScore = $this->computeBaseScore($allFindings);

        // Normalise by custom LOC (treat missing LOC as 1 KLOC = neutral)
        $klocFactor = max(1.0, $customLoc / 1000.0);
        $normalized = $baseScore / $klocFactor;

        $dbFactor       = $this->hasDbRisk($allFindings) ? 1.5 : 1.0;
        $composerFactor = $this->hasComposerRisk($allFindings) ? 1.2 : 1.0;

        $uriScore = min(100.0, $normalized * $dbFactor * $composerFactor);
        [$grade, $gradeDesc] = $this->assignGrade($uriScore);

        [$engHours, $qaHours, $infraHours, $total] = $this->computeEffort($allFindings);

        $weeks                 = max(1, (int)ceil($total / 70));
        $recommendedTimeline   = "{$weeks}–" . ($weeks + 2) . ' weeks';
        $goLiveRecommendation  = date('Y-m-d', strtotime("+{$weeks} weeks"));

        $sprintBreakdown = (new SprintPlanner($allFindings))->plan();

        return new URIScore(
            score: round($uriScore, 1),
            grade: $grade,
            gradeDescription: $gradeDesc,
            engineeringHours: $engHours,
            qaHours: $qaHours,
            infraHours: $infraHours,
            totalHoursWithContingency: $total,
            recommendedTimeline: $recommendedTimeline,
            sprintBreakdown: $sprintBreakdown,
            goLiveRecommendation: $goLiveRecommendation,
        );
    }

    private function computeBaseScore(array $findings): float
    {
        $score = 0.0;
        foreach ($findings as $f) {
            $type   = $f['type'] ?? '';
            $weight = self::TYPE_WEIGHTS[$type]
                ?? self::SEVERITY_WEIGHTS[$f['severity'] ?? 'info']
                ?? 0.5;
            $score += $weight;
        }
        return $score;
    }

    private function hasDbRisk(array $findings): bool
    {
        foreach ($findings as $f) {
            if (in_array($f['type'] ?? '', self::DB_RISK_TYPES, true)) {
                return true;
            }
            if (($f['severity'] ?? '') === 'error' && str_contains(strtolower($f['message'] ?? ''), 'schema')) {
                return true;
            }
        }
        return false;
    }

    private function hasComposerRisk(array $findings): bool
    {
        foreach ($findings as $f) {
            if (in_array($f['type'] ?? '', self::COMPOSER_RISK_TYPES, true)) {
                return true;
            }
        }
        return false;
    }

    private function assignGrade(float $score): array
    {
        foreach (self::GRADES as $grade => [$min, $max, $desc]) {
            if ($score >= $min && $score <= $max) {
                return [$grade, $desc];
            }
        }
        return ['F', 'Critical risk — major upgrade blockers present'];
    }

    private function computeEffort(array $findings): array
    {
        $engHours = 0;
        foreach ($findings as $f) {
            $min = (int)($f['effort_hours_min'] ?? 0);
            $max = (int)($f['effort_hours_max'] ?? 0);
            // Pessimistic-optimistic average
            $engHours += $max > 0 ? (int)ceil(($min + $max) / 2) : ($min ?: 2);
        }

        $qaHours    = (int)ceil($engHours * 0.4);
        $infraHours = (int)max(24, min(80, $engHours * 0.15));
        $subtotal   = $engHours + $qaHours + $infraHours;
        $contingency = (int)ceil($subtotal * 0.25);
        $total      = $subtotal + $contingency;

        return [$engHours, $qaHours, $infraHours, $total];
    }
}

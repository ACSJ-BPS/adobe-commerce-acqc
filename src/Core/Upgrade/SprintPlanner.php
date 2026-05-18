<?php

declare(strict_types=1);

namespace ACQC\Core\Upgrade;

class SprintPlanner
{
    // Structural blockers that must ship before any other work can start
    private const BLOCKER_TYPES = [
        'class_removed',
        'db_schema_blocker',
        'legacy_setup_script_detected',
        'platform_php_mismatch',
        'composer_security_advisory',
    ];

    public function __construct(private readonly array $findings) {}

    public function plan(): array
    {
        if (empty($this->findings)) {
            return [];
        }

        $blockers         = [];
        $highEffortErrors = [];
        $sprint3Findings  = [];

        foreach ($this->findings as $f) {
            $severity = $f['severity'] ?? 'info';
            $type     = $f['type'] ?? '';
            $hoursMin = (int)($f['effort_hours_min'] ?? 0);

            if ($severity === 'error' && (in_array($type, self::BLOCKER_TYPES, true) || $this->isBlocker($f))) {
                $blockers[] = $f;
            } elseif ($severity === 'error' && $hoursMin >= 16) {
                $highEffortErrors[] = $f;
            } else {
                // low-effort errors + warnings + notices
                $sprint3Findings[] = $f;
            }
        }

        $qaHours = max(16, (int)(array_sum(array_column($this->findings, 'effort_hours_min')) * 0.4));

        // Always emit sprints 1–4 (fixed semantic numbers); Sprint 5 added when findings > 20
        $sprints = [
            [
                'sprint'      => 1,
                'name'        => 'Critical Blockers & Upgrade Prerequisites',
                'findings'    => $blockers,
                'total_hours' => $this->sumHours($blockers),
            ],
            [
                'sprint'      => 2,
                'name'        => 'High-Effort Remediation',
                'findings'    => $highEffortErrors,
                'total_hours' => $this->sumHours($highEffortErrors),
            ],
            [
                'sprint'      => 3,
                'name'        => 'Warnings & Low-Effort Fixes',
                'findings'    => $sprint3Findings,
                'total_hours' => $this->sumHours($sprint3Findings),
            ],
            [
                'sprint'      => 4,
                'name'        => 'QA Hardening & Regression Testing',
                'findings'    => [],
                'total_hours' => $qaHours,
                'description' => 'Automated regression suite, UAT, performance benchmarks.',
            ],
        ];

        if (count($this->findings) > 20) {
            $sprints[] = [
                'sprint'      => 5,
                'name'        => 'Performance Validation & Cutover Prep',
                'findings'    => [],
                'total_hours' => 24,
                'description' => 'Load testing, cutover runbook rehearsal, go-live readiness check.',
            ];
        }

        return $sprints;
    }

    private function isBlocker(array $finding): bool
    {
        $msg = strtolower($finding['message'] ?? '');
        return str_contains($msg, 'blocker') || str_contains($msg, 'critical');
    }

    private function sumHours(array $findings): int
    {
        $total = 0;
        foreach ($findings as $f) {
            $min    = (int)($f['effort_hours_min'] ?? 0);
            $max    = (int)($f['effort_hours_max'] ?? 0);
            $total += $max > 0 ? (int)ceil(($min + $max) / 2) : ($min ?: 0);
        }
        return $total;
    }
}

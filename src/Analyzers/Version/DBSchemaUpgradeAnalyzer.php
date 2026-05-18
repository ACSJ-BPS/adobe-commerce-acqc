<?php

declare(strict_types=1);

namespace ACQC\Analyzers\Version;

use ACQC\Analyzers\Version\DB\DataPatchOrderAnalyzer;
use ACQC\Analyzers\Version\DB\DeclarativeSchemaParser;
use ACQC\Analyzers\Version\DB\EavAttributeChangeDetector;
use ACQC\Analyzers\Version\DB\ForeignKeyConflictDetector;
use ACQC\Analyzers\Version\DB\LegacyScriptDetector;
use ACQC\Analyzers\Version\DB\TableSizeRiskScorer;
use ACQC\Analyzers\Version\DB\WhitelistValidator;

class DBSchemaUpgradeAnalyzer
{
    public function __construct(
        private readonly LegacyScriptDetector      $legacyDetector,
        private readonly WhitelistValidator        $whitelistValidator,
        private readonly TableSizeRiskScorer       $riskScorer,
        private readonly DeclarativeSchemaParser   $schemaParser,
        private readonly DataPatchOrderAnalyzer    $patchOrderAnalyzer,
        private readonly ForeignKeyConflictDetector $fkConflictDetector,
        private readonly EavAttributeChangeDetector $eavAttributeDetector,
    ) {}

    public function analyze(string $projectRoot, string $sourceVersion, string $targetVersion): DBSchemaAnalysisResult
    {
        $allFindings = [];

        $allFindings = array_merge($allFindings, $this->legacyDetector->detect());
        $allFindings = array_merge($allFindings, $this->whitelistValidator->validate());
        $allFindings = array_merge($allFindings, $this->riskScorer->scoreDbChanges($sourceVersion, $targetVersion));
        $allFindings = array_merge($allFindings, $this->schemaParser->parse($sourceVersion, $targetVersion));
        $allFindings = array_merge($allFindings, $this->patchOrderAnalyzer->analyze());
        $allFindings = array_merge($allFindings, $this->fkConflictDetector->detect($sourceVersion, $targetVersion));
        $allFindings = array_merge($allFindings, $this->eavAttributeDetector->detect());

        $summaryByType = $this->buildSummaryByType($allFindings);

        [$hoursMin, $hoursMax] = $this->estimateEffort($allFindings);

        return new DBSchemaAnalysisResult(
            findings:           $allFindings,
            summaryByType:      $summaryByType,
            estimatedHoursMin:  $hoursMin,
            estimatedHoursMax:  $hoursMax,
        );
    }

    private function buildSummaryByType(array $findings): array
    {
        $summary = [];
        foreach ($findings as $f) {
            $type     = $f['type'] ?? 'unknown';
            $severity = $f['severity'] ?? 'info';

            if (!isset($summary[$type])) {
                $summary[$type] = ['count' => 0, 'severity' => $severity];
            }
            $summary[$type]['count']++;
        }
        return $summary;
    }

    private function estimateEffort(array $findings): array
    {
        $min = 0;
        $max = 0;
        foreach ($findings as $f) {
            $min += (int)($f['effort_hours_min'] ?? 0);
            $max += (int)($f['effort_hours_max'] ?? 0);
        }
        return [$min, $max];
    }
}

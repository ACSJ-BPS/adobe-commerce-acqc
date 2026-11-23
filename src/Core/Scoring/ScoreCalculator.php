<?php

declare(strict_types=1);

namespace ACQC\Core\Scoring;

/**
 * Computes Overall quality score, dimension rings, caps, release readiness,
 * technical debt hours, grade/label, Excel KPI scorecard, assessment text, and gates.
 */
final class ScoreCalculator
{
    private ConditionEvaluator $evaluator;

    public function __construct(?ConditionEvaluator $evaluator = null)
    {
        $this->evaluator = $evaluator ?? new ConditionEvaluator();
    }

    /**
     * Calculate the full report `scores` payload from report `$data` and `ScoringConfig`.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function calculateReportScores(array $data, ScoringConfig $config): array
    {
        $inputs = ScoreInputs::fromReportData($data, $config);
        $debtHours = $this->calculateDebtHours($inputs, $config->getDebt());
        $bands = $config->getBands();

        // 1. Dimensions
        $dimensions = [];
        $dimensionScoreMap = [];
        foreach ($config->getDimensions() as $dimId => $dimCfg) {
            $dimResult = $this->calculateDimension($dimId, $dimCfg, $inputs, $bands, $debtHours);
            $dimensions[$dimId] = $dimResult;
            $dimensionScoreMap[$dimId] = $dimResult['score'];
        }

        // 2. Overall
        $overallResult = $this->calculateOverall($config->getOverall(), $inputs, $debtHours, $dimensionScoreMap);
        $overallScore = $overallResult['score'];
        $overallRounded = (int) round($overallScore);

        // 3. Variables map (includes final overall, debt_hours, and dimension scores)
        $vars = $inputs->toVariablesMap($overallScore, $debtHours, $dimensionScoreMap);

        // 4. Readiness
        $readiness = $this->evaluateReadiness($config->getReadinessRules(), $vars, $bands);

        // 5. Grade & label
        $gradeInfo = $this->resolveGrade($overallScore, $config->getGrades());

        // 6. Assessment (Excel E11 / E12)
        $assessment = $this->buildAssessment($overallScore, $inputs, $debtHours, $config->getAssessmentBands());

        // 7. Excel KPIs (E1-E9)
        $kpis = $this->calculateKpis($config->getKpis(), $vars, $config->getPlaceholdersMode());

        // 8. Extra JUnit / CI gates
        $gates = $this->evaluateGates($config->getGates(), $readiness, $vars);

        return [
            'overall' => $overallScore,
            'overall_rounded' => $overallRounded,
            'overall_raw' => $overallResult['raw_score'],
            'overall_color' => $this->resolveBandColor($overallRounded, $bands),
            'caps_applied' => $overallResult['caps_applied'],
            'dimensions' => $dimensions,
            'readiness' => $readiness,
            'grade' => $gradeInfo['grade'],
            'grade_label' => $gradeInfo['label'],
            'bands' => $bands,
            'debt_hours' => $debtHours,
            'debt_days' => round($debtHours / 8.0, 1),
            'assessment' => $assessment,
            'kpis' => $kpis,
            'status' => $config->getStatus(),
            'placeholders' => $config->getPlaceholdersMode(),
            'gates' => $gates,
            'unmapped_categories' => $inputs->getUnmappedCategories(),
            'config_sources' => $config->getSources(),
            'inputs' => [
                'raw_total' => $inputs->getRawTotal(),
                'weighted_total' => round($inputs->getWeightedTotal(), 2),
                'raw_by_severity' => $inputs->getRawBySeverity(),
                'weighted_by_severity' => array_map(static fn(float $v): float => round($v, 2), $inputs->getWeightedBySeverity()),
                'raw_by_category' => $inputs->getRawByCategory(),
                'weighted_by_category' => array_map(static fn(float $v): float => round($v, 2), $inputs->getWeightedByCategory()),
                'files' => $inputs->getFilesCount(),
                'lines' => $inputs->getLinesOfCode(),
            ],
        ];
    }

    /**
     * Calculate the `audit` score, grade, label, caps, and human-readable formula description (Q2-a).
     *
     * @param array<int, array<string, mixed>> $issues
     * @return array{
     *   score: int,
     *   raw_score: float,
     *   grade: string,
     *   label: string,
     *   caps_applied: list<string>,
     *   formula_description: string,
     *   config_sources: list<string>
     * }
     */
    public function calculateAuditScore(array $issues, int $filesScanned, ScoringConfig $config): array
    {
        $auditCfg = $config->getAuditOverall();
        $emptyScore = (int) ($auditCfg['empty_scope_score'] ?? 100);

        if ($filesScanned <= 0 && $issues === []) {
            $gradeInfo = $this->resolveGrade((float) $emptyScore, $config->getGrades());
            return [
                'score' => $emptyScore,
                'raw_score' => (float) $emptyScore,
                'grade' => $gradeInfo['grade'],
                'label' => $gradeInfo['label'],
                'caps_applied' => [],
                'formula_description' => $this->describeAuditFormula($auditCfg),
                'config_sources' => $config->getSources(),
            ];
        }

        // Audit has its own severity mapping: YAML rules use high/medium/low as a scale that sits
        // below `critical` (high = error, medium = warning, low = info). See scoring.audit.severity_aliases.
        $auditAliases = $config->getAuditSeverityAliases();
        $issues = array_map(static function (mixed $issue) use ($auditAliases): mixed {
            if (is_array($issue)) {
                $sev = strtolower(trim((string) ($issue['severity'] ?? '')));
                if (isset($auditAliases[$sev])) {
                    $issue['severity'] = $auditAliases[$sev];
                }
            }
            return $issue;
        }, $issues);

        $inputs = ScoreInputs::fromReportData([
            'issues' => $issues,
            'files_scanned' => max(1, $filesScanned),
        ], $config);

        if ($inputs->getRawTotal() === 0) {
            $gradeInfo = $this->resolveGrade(100.0, $config->getGrades());
            return [
                'score' => 100,
                'raw_score' => 100.0,
                'grade' => $gradeInfo['grade'],
                'label' => $gradeInfo['label'],
                'caps_applied' => [],
                'formula_description' => $this->describeAuditFormula($auditCfg),
                'config_sources' => $config->getSources(),
            ];
        }

        $normalize = (string) ($auditCfg['normalize'] ?? 'per_file');
        $per = (float) ($auditCfg['per'] ?? 1000.0);
        $floor = (float) ($auditCfg['floor'] ?? 0.0);
        $weights = is_array($auditCfg['weights'] ?? null) ? $auditCfg['weights'] : [];

        $penalty = 0.0;
        $weightedSev = $inputs->getWeightedBySeverity();

        foreach ($weights as $sev => $spec) {
            $count = (float) ($weightedSev[strtolower((string) $sev)] ?? 0.0);
            $normVal = $this->normalizeValue($count, $normalize, $inputs, $per);
            if (is_array($spec)) {
                $mult = (float) ($spec['mult'] ?? 0.0);
                $term = $normVal * $mult;
                if (isset($spec['cap'])) {
                    $term = min((float) $spec['cap'], $term);
                }
                $penalty += $term;
            } else {
                $penalty += $normVal * (float) $spec;
            }
        }

        $rawScore = max($floor, min(100.0, 100.0 - $penalty));
        $vars = $inputs->toVariablesMap($rawScore, 0.0);
        [$cappedScore, $capsApplied] = $this->applyCaps($rawScore, $auditCfg['caps'] ?? [], $vars, 'scoring.audit.overall.caps');

        $finalScore = (int) round(max($floor, min(100.0, $cappedScore)));
        $gradeInfo = $this->resolveGrade((float) $finalScore, $config->getGrades());

        return [
            'score' => $finalScore,
            'raw_score' => round($rawScore, 1),
            'grade' => $gradeInfo['grade'],
            'label' => $gradeInfo['label'],
            'caps_applied' => $capsApplied,
            'formula_description' => $this->describeAuditFormula($auditCfg),
            'config_sources' => $config->getSources(),
        ];
    }

    /**
     * Calculate technical debt hours from inputs and `scoring.debt` config.
     *
     * @param array<string, mixed> $debtCfg
     */
    public function calculateDebtHours(ScoreInputs $inputs, array $debtCfg): float
    {
        if ($inputs->getRawTotal() === 0) {
            return 0.0;
        }

        $hoursMap = $debtCfg['hours'] ?? [
            'blocker' => 8.0,
            'critical' => 2.0,
            'error' => 1.0,
            'warning' => 0.5,
            'info' => 0.17,
            'minor' => 0.083,
        ];
        $useWeighted = (bool) ($debtCfg['apply_category_weights'] ?? false);
        $counts = $useWeighted ? $inputs->getWeightedBySeverity() : $inputs->getRawBySeverity();

        $rawHours = 0.0;
        foreach (ScoreInputs::CANONICAL_SEVERITIES as $sev) {
            $rawHours += ((float) ($counts[$sev] ?? 0)) * ((float) ($hoursMap[$sev] ?? 0.0));
        }

        $scaling = (string) ($debtCfg['scaling'] ?? 'whole_total');
        $tiers = is_array($debtCfg['tiers'] ?? null) ? $debtCfg['tiers'] : [];

        if ($tiers === []) {
            return round($rawHours, 1);
        }

        if ($scaling === 'marginal') {
            // Sort tiers ascending by `above` so brackets are piecewise-linear and strictly monotonic
            usort($tiers, static fn(array $a, array $b): int => ((float) ($a['above'] ?? 0)) <=> ((float) ($b['above'] ?? 0)));
            $scaled = 0.0;
            $prevAbove = 0.0;
            $prevFactor = 1.0;

            foreach ($tiers as $tier) {
                $above = (float) ($tier['above'] ?? 0.0);
                if ($rawHours <= $above) {
                    $scaled += max(0.0, $rawHours - $prevAbove) * $prevFactor;
                    return round($scaled, 1);
                }
                $scaled += max(0.0, $above - $prevAbove) * $prevFactor;
                $prevAbove = $above;
                $prevFactor = (float) ($tier['factor'] ?? 1.0);
            }

            $scaled += max(0.0, $rawHours - $prevAbove) * $prevFactor;
            return round($scaled, 1);
        }

        // Default: whole_total (today's step function)
        usort($tiers, static fn(array $a, array $b): int => ((float) ($b['above'] ?? 0)) <=> ((float) ($a['above'] ?? 0)));
        foreach ($tiers as $tier) {
            $above = (float) ($tier['above'] ?? 0.0);
            if ($rawHours > $above) {
                return round($rawHours * (float) ($tier['factor'] ?? 1.0), 1);
            }
        }

        return round($rawHours, 1);
    }

    /**
     * Resolve grade and label for any 0-100 score.
     *
     * @param list<array<string, mixed>> $grades
     * @return array{grade: string, label: string}
     */
    public function resolveGrade(float $score, array $grades): array
    {
        foreach ($grades as $band) {
            if ($score >= (float) ($band['min'] ?? 0)) {
                return [
                    'grade' => (string) ($band['grade'] ?? 'F'),
                    'label' => (string) ($band['label'] ?? 'Needs Improvement'),
                ];
            }
        }

        return ['grade' => 'F', 'label' => 'Needs Improvement'];
    }

    /**
     * @param array<string, mixed> $overallCfg
     * @param array<string, int> $dimensionScoreMap
     * @return array{score: float, raw_score: float, caps_applied: list<string>}
     */
    private function calculateOverall(
        array $overallCfg,
        ScoreInputs $inputs,
        float $debtHours,
        array $dimensionScoreMap
    ): array {
        if ($inputs->getRawTotal() === 0) {
            return [
                'score' => 100.0,
                'raw_score' => 100.0,
                'caps_applied' => [],
            ];
        }

        $normalize = (string) ($overallCfg['normalize'] ?? 'per_kloc');
        $per = max(0.0001, (float) ($overallCfg['per'] ?? 1000.0));
        $floor = (float) ($overallCfg['floor'] ?? 0.0);
        $weights = is_array($overallCfg['weights'] ?? null) ? $overallCfg['weights'] : [];

        $weightedSev = $inputs->getWeightedBySeverity();
        $penalty = 0.0;

        foreach (ScoreInputs::CANONICAL_SEVERITIES as $sev) {
            $w = (float) ($weights[$sev] ?? 0.0);
            if ($w == 0.0) {
                continue;
            }
            $count = $weightedSev[$sev] ?? 0.0;
            $normVal = $this->normalizeValue($count, $normalize, $inputs, $per);
            $penalty += $normVal * $w;
        }

        $rawScore = max($floor, min(100.0, round(100.0 - $penalty, 1)));
        $vars = $inputs->toVariablesMap($rawScore, $debtHours, $dimensionScoreMap);
        [$cappedScore, $capsApplied] = $this->applyCaps($rawScore, $overallCfg['caps'] ?? [], $vars, 'scoring.overall.caps');

        return [
            'score' => max($floor, min(100.0, round($cappedScore, 1))),
            'raw_score' => $rawScore,
            'caps_applied' => $capsApplied,
        ];
    }

    /**
     * @param array<string, mixed> $dimCfg
     * @param array{healthy: int, attention: int} $bands
     * @return array<string, mixed>
     */
    private function calculateDimension(
        string $dimId,
        array $dimCfg,
        ScoreInputs $inputs,
        array $bands,
        float $debtHours
    ): array {
        $label = (string) ($dimCfg['label'] ?? ucfirst(str_replace('_', ' ', $dimId)));
        $subtitle = (string) ($dimCfg['subtitle'] ?? '');
        $normalize = (string) ($dimCfg['normalize'] ?? 'share_of_total');
        $floor = (int) ($dimCfg['floor'] ?? 0);
        $mult = (float) ($dimCfg['mult'] ?? 1.0);
        $per = max(0.0001, (float) ($dimCfg['per'] ?? 1.0));

        if ($inputs->getRawTotal() === 0) {
            return [
                'id' => $dimId,
                'label' => $label,
                'subtitle' => $subtitle,
                'score' => 100,
                'color' => $this->resolveBandColor(100, $bands),
                'count' => 0,
                'weighted_count' => 0.0,
            ];
        }

        $hasCategories = !empty($dimCfg['categories']) || !empty($dimCfg['rule_ids']);
        $hasSeverities = !empty($dimCfg['severities']) && is_array($dimCfg['severities']);
        $weightedSev = $inputs->getWeightedBySeverity();

        if (!$hasCategories && $hasSeverities) {
            // Pure severity-driven ring (e.g. Reliability)
            $penalty = 0.0;
            foreach ($dimCfg['severities'] as $sev => $sevWeight) {
                $cnt = (float) ($weightedSev[strtolower((string) $sev)] ?? 0.0);
                $normVal = $this->normalizeValue($cnt, $normalize, $inputs, $per);
                $penalty += $normVal * (float) $sevWeight;
            }
            $penalty *= $mult;
            $rawDimCount = 0;
            foreach (array_keys($dimCfg['severities']) as $sev) {
                $rawDimCount += $inputs->getRawBySeverity()[strtolower((string) $sev)] ?? 0;
            }
            $weightedDimCount = (float) $rawDimCount;
        } else {
            // Category/rule_id-driven ring, optionally with extra severity terms (e.g. Code Quality warning * 0.5)
            $weightedDimCount = $inputs->getWeightedDimensionCount($dimId);
            $rawDimCount = $inputs->getRawDimensionCount($dimId);
            $effectiveCount = $weightedDimCount;

            if ($hasSeverities) {
                foreach ($dimCfg['severities'] as $sev => $sevFactor) {
                    $sevCnt = (float) ($weightedSev[strtolower((string) $sev)] ?? 0.0);
                    $effectiveCount += floor($sevCnt * (float) $sevFactor);
                }
            }

            $normVal = $this->normalizeValue($effectiveCount, $normalize, $inputs, $per);
            $penalty = $normVal * $mult;
        }

        $rawScore = max((float) $floor, min(100.0, (float) round(100.0 - $penalty)));
        if (!empty($dimCfg['caps']) && is_array($dimCfg['caps'])) {
            $vars = $inputs->toVariablesMap(100.0, $debtHours);
            [$rawScore] = $this->applyCaps($rawScore, $dimCfg['caps'], $vars, "scoring.dimensions.{$dimId}.caps");
        }

        $score = (int) max($floor, min(100, (int) round($rawScore)));

        return [
            'id' => $dimId,
            'label' => $label,
            'subtitle' => $subtitle,
            'score' => $score,
            'color' => $this->resolveBandColor($score, $bands),
            'count' => $rawDimCount,
            'weighted_count' => round($weightedDimCount, 2),
        ];
    }

    private function normalizeValue(float $count, string $normalize, ScoreInputs $inputs, float $per = 1.0): float
    {
        return match ($normalize) {
            'share_of_total' => ($count / max(1.0, $inputs->getWeightedTotal())) * 100.0,
            'per_file'       => $count / max(1, $inputs->getFilesCount()),
            'per_kloc'       => $count / max(0.001, $inputs->getLinesOfCode() / 1000.0),
            default          => $per > 1.0 ? ($count / $per) : $count,
        };
    }

    /**
     * @param array<int, mixed> $caps
     * @param array<string, float|int> $vars
     * @return array{0: float, 1: list<string>}
     */
    private function applyCaps(float $score, array $caps, array $vars, string $contextPath): array
    {
        $capped = $score;
        $applied = [];

        foreach ($caps as $idx => $cap) {
            if (!is_array($cap) || empty($cap['when']) || !is_array($cap['when'])) {
                continue;
            }
            $maxScore = (float) ($cap['max_score'] ?? 100.0);
            $eval = $this->evaluator->evaluateMap($cap['when'], $vars, 'any', "{$contextPath}[{$idx}].when");
            if ($eval['matched'] && $maxScore < $capped) {
                $capped = $maxScore;
                $applied[] = sprintf(
                    'Capped at %s (%s)',
                    (int) round($maxScore) == $maxScore ? (string) (int) round($maxScore) : number_format($maxScore, 1),
                    implode(', ', $eval['reasons'])
                );
            }
        }

        return [$capped, $applied];
    }

    /**
     * @param list<array<string, mixed>> $rules
     * @param array<string, float|int> $vars
     * @param array{healthy: int, attention: int} $bands
     * @return array<string, mixed>
     */
    private function evaluateReadiness(array $rules, array $vars, array $bands): array
    {
        foreach ($rules as $idx => $rule) {
            $verdict = (string) ($rule['verdict'] ?? 'READY TO SHIP');
            $gate = (string) ($rule['gate'] ?? $this->defaultGateForVerdict($verdict));
            $hasWhenAny = isset($rule['when_any']) && is_array($rule['when_any']) && $rule['when_any'] !== [];
            $hasWhenAll = isset($rule['when_all']) && is_array($rule['when_all']) && $rule['when_all'] !== [];

            if (!$hasWhenAny && !$hasWhenAll) {
                return $this->formatReadinessResult($verdict, $gate, $rule, [], $vars);
            }

            $matched = false;
            $reasons = [];

            if ($hasWhenAny) {
                $res = $this->evaluator->evaluateMap($rule['when_any'], $vars, 'any', "scoring.readiness[{$idx}].when_any");
                if ($res['matched']) {
                    $matched = true;
                    $reasons = array_merge($reasons, $res['reasons']);
                }
            }

            if ($hasWhenAll) {
                $res = $this->evaluator->evaluateMap($rule['when_all'], $vars, 'all', "scoring.readiness[{$idx}].when_all");
                if ($res['matched']) {
                    $matched = $hasWhenAny ? $matched : true;
                    $reasons = array_merge($reasons, $res['reasons']);
                } else {
                    $matched = false;
                }
            }

            if ($matched) {
                return $this->formatReadinessResult($verdict, $gate, $rule, $reasons, $vars);
            }
        }

        return $this->formatReadinessResult('READY TO SHIP', 'PASS', [], [], $vars);
    }

    /**
     * @param array<string, mixed> $rule
     * @param list<string> $reasons
     * @param array<string, float|int> $vars
     * @return array<string, mixed>
     */
    private function formatReadinessResult(
        string $verdict,
        string $gate,
        array $rule,
        array $reasons,
        array $vars
    ): array {
        $upper = strtoupper($verdict);
        $cssClass = str_contains($upper, 'BLOCK')
            ? 'r-blocked'
            : (str_contains($upper, 'RISK') || str_contains($upper, 'WARN') || str_contains($upper, 'REVIEW') ? 'r-risky' : 'r-ready');
        $icon = $cssClass === 'r-blocked' ? '✗' : ($cssClass === 'r-risky' ? '⚠' : '✓');

        $summary = (string) ($rule['summary'] ?? ($gate === 'FAIL'
            ? 'Block merge and notify team lead'
            : ($gate === 'WARN' ? 'Proceed with mandatory review gate' : 'Safe to merge and deploy')));

        $reasonStr = $reasons !== [] ? implode('; ', $reasons) : 'All readiness thresholds met';

        $defaultExcelText = $cssClass === 'r-blocked'
            ? sprintf('BLOCKED — %d blocker(s) must be resolved before deployment (%s)', (int) ($vars['blocker'] ?? 0), $reasonStr)
            : ($cssClass === 'r-risky'
                ? sprintf('REVIEW REQUIRED — %s', $reasonStr)
                : 'READY — No blockers detected; standard release process applies');

        $excelTemplate = (string) ($rule['excel_text'] ?? $defaultExcelText);
        $excelText = preg_replace_callback('/\{([a-zA-Z0-9_]+)\}/', static function (array $m) use ($vars): string {
            $k = $m[1];
            if (!array_key_exists($k, $vars)) {
                return $m[0];
            }
            $v = $vars[$k];
            return is_int($v) || floor((float) $v) === (float) $v
                ? (string) (int) round((float) $v)
                : number_format((float) $v, 1, '.', '');
        }, $excelTemplate) ?? $defaultExcelText;

        // If RISKY was triggered by a custom condition (e.g. error_weighted > 50) while critical == 0,
        // append the matched reason so Excel never says "0 critical issue(s)".
        if ($cssClass === 'r-risky' && (int) ($vars['critical'] ?? 0) === 0 && $reasons !== []) {
            $excelText = 'REVIEW REQUIRED — ' . $reasonStr;
        }

        return [
            'verdict' => $verdict,
            'gate' => $gate,
            'css_class' => $cssClass,
            'icon' => $icon,
            'summary' => $summary,
            'reason' => $reasonStr,
            'reasons' => $reasons,
            'excel_text' => $excelText,
        ];
    }

    private function defaultGateForVerdict(string $verdict): string
    {
        $u = strtoupper($verdict);
        if (str_contains($u, 'BLOCK') || str_contains($u, 'FAIL')) {
            return 'FAIL';
        }
        if (str_contains($u, 'RISK') || str_contains($u, 'WARN') || str_contains($u, 'REVIEW')) {
            return 'WARN';
        }
        return 'PASS';
    }

    /**
     * Calculate Excel Executive KPI Scorecard rows (E1-E9).
     *
     * @param array<string, array<string, mixed>> $kpisConfig
     * @param array<string, float|int> $vars
     * @return array<string, array{id: string, label: string, value: float|int, display: string, status: string, note: string}>
     */
    private function calculateKpis(array $kpisConfig, array $vars, string $placeholders = 'show_na'): array
    {
        $results = [];

        foreach ($kpisConfig as $id => $cfg) {
            $label = (string) ($cfg['label'] ?? $id);
            $note = (string) ($cfg['note'] ?? '');
            $unit = (string) ($cfg['unit'] ?? '/100');
            $format = (string) ($cfg['format'] ?? 'int');

            // Unmeasured maintainability (no modules analysed) is N/A, not a FAIL of an invented 0
            if (($cfg['source'] ?? null) === 'maintainability_index'
                && $placeholders !== 'legacy'
                && (int) ($vars['maintainability_measured'] ?? 1) === 0
            ) {
                $results[$id] = [
                    'id' => $id,
                    'label' => $label,
                    'value' => null,
                    'display' => 'N/A',
                    'status' => 'N/A',
                    'note' => $note,
                ];
                continue;
            }

            if (isset($cfg['source'])) {
                $srcKey = (string) $cfg['source'];
                $rawVal = (float) ($vars[$srcKey] ?? 0.0);
                $numVal = $format === 'float1' ? round($rawVal, 1) : (int) min(100, max(0, round($rawVal)));
            } else {
                $base = (float) ($cfg['base'] ?? 100.0);
                $direction = (string) ($cfg['direction'] ?? 'deduct');
                $sumTerms = 0.0;

                foreach (($cfg['terms'] ?? []) as $tIdx => $term) {
                    if (isset($term['when']) && is_array($term['when'])) {
                        $res = $this->evaluator->evaluateMap($term['when'], $vars, 'any', "scoring.kpis.{$id}.terms[{$tIdx}].when");
                        if ($res['matched']) {
                            $sumTerms += (float) ($term['add'] ?? 0.0);
                        }
                        continue;
                    }

                    $inputKey = (string) ($term['input'] ?? '');
                    $inputVal = (float) ($vars[$inputKey] ?? 0.0);
                    if (isset($term['round_input'])) {
                        $inputVal = round($inputVal, (int) $term['round_input']);
                    }
                    $val = $inputVal * (float) ($term['mult'] ?? 1.0);
                    if (!empty($term['cast_int'])) {
                        $val = (float) ((int) $val);
                    }
                    if (isset($term['cap'])) {
                        $val = min((float) $term['cap'], $val);
                    }
                    $sumTerms += $val;
                }

                $computed = $direction === 'accumulate' ? ($base + $sumTerms) : ($base - $sumTerms);
                $clamped = min(100.0, max(0.0, $computed));
                $numVal = $format === 'float1' ? round($clamped, 1) : (int) round($clamped);
            }

            $status = 'Fail';
            if (isset($cfg['pass']) && is_string($cfg['pass']) && $this->evaluator->evaluateValue($numVal, $cfg['pass'], "scoring.kpis.{$id}.pass")) {
                $status = 'Pass';
            } elseif (isset($cfg['warn']) && is_string($cfg['warn']) && $this->evaluator->evaluateValue($numVal, $cfg['warn'], "scoring.kpis.{$id}.warn")) {
                $status = 'Warn';
            }

            $display = $format === 'float1'
                ? number_format((float) $numVal, 1, '.', '') . $unit
                : ((int) $numVal) . $unit;

            $results[$id] = [
                'id' => $id,
                'label' => $label,
                'value' => $numVal,
                'display' => $display,
                'status' => $status,
                'note' => $note,
            ];
        }

        return $results;
    }

    /**
     * Build Overall Assessment and Business Impact strings (Excel E11 / E12).
     *
     * @param list<array<string, mixed>> $bands
     * @return array{label: string, text: string, business_impact: string}
     */
    private function buildAssessment(float $overallScore, ScoreInputs $inputs, float $debtHours, array $bands): array
    {
        $scoreInt = (int) round($overallScore);
        $matchedBand = [
            'label' => 'Critical Risk',
            'detail' => 'Major quality issues detected; significant remediation required before any production deployment.',
            'impact' => 'Critical risk - Major technical debt, security vulnerabilities, high maintenance costs',
        ];

        foreach ($bands as $band) {
            if ($overallScore >= (float) ($band['min'] ?? 0)) {
                $matchedBand = [
                    'label' => (string) ($band['label'] ?? 'Fair'),
                    'detail' => (string) ($band['detail'] ?? ''),
                    'impact' => (string) ($band['impact'] ?? ''),
                ];
                break;
            }
        }

        $rawSev = $inputs->getRawBySeverity();
        $blockers = $rawSev['blocker'] ?? 0;
        $critical = $rawSev['critical'] ?? 0;
        $secIssues = $inputs->getRawDimensionCount('security') ?: ($inputs->getRawByCategory()['security'] ?? 0);

        $notes = [];
        if ($blockers > 0) {
            $notes[] = "Release is currently BLOCKED by {$blockers} blocker(s)";
        }
        if ($critical > 0) {
            $notes[] = "{$critical} critical issue(s) require urgent attention";
        }
        if ($secIssues > 0) {
            $notes[] = "{$secIssues} security vulnerability/ies require immediate remediation";
        }
        if ($debtHours > 500) {
            $notes[] = 'technical debt exceeds 500 hrs — plan a dedicated remediation sprint';
        } elseif ($debtHours > 200) {
            $notes[] = 'technical debt above 200 hrs — prioritise high-severity fixes';
        }

        $suffix = $notes !== [] ? ' · ' . implode('; ', $notes) . '.' : '';
        $text = sprintf('%s (%d/100) — %s%s', $matchedBand['label'], $scoreInt, $matchedBand['detail'], $suffix);

        return [
            'label' => $matchedBand['label'],
            'text' => $text,
            'business_impact' => $matchedBand['impact'],
        ];
    }

    /**
     * Evaluate configured `scoring.gates` plus the readiness gate for JUnit / CI consumers.
     *
     * @param list<array<string, mixed>> $configuredGates
     * @param array<string, mixed> $readiness
     * @param array<string, float|int> $vars
     * @return list<array{name: string, passed: bool, reason: string}>
     */
    private function evaluateGates(array $configuredGates, array $readiness, array $vars): array
    {
        $results = [
            [
                'name' => 'release_readiness',
                'passed' => ($readiness['gate'] ?? 'PASS') !== 'FAIL',
                'reason' => sprintf('Verdict: %s (%s)', (string) ($readiness['verdict'] ?? 'READY TO SHIP'), (string) ($readiness['reason'] ?? '')),
            ],
        ];

        foreach ($configuredGates as $idx => $gate) {
            $name = (string) ($gate['name'] ?? "gate_{$idx}");
            $when = is_array($gate['when'] ?? null) ? $gate['when'] : [];
            $mode = (string) ($gate['mode'] ?? 'any');
            $eval = $this->evaluator->evaluateMap($when, $vars, $mode === 'all' ? 'all' : 'any', "scoring.gates[{$idx}].when");
            // In `scoring.gates`, `when` specifies the failure trigger (e.g. `when: { error: "> 50" }`)
            $failed = $eval['matched'];
            $results[] = [
                'name' => $name,
                'passed' => !$failed,
                'reason' => $failed ? implode('; ', $eval['reasons']) : 'Passed',
            ];
        }

        return $results;
    }

    /**
     * @param array{healthy: int, attention: int} $bands
     */
    private function resolveBandColor(int|float $score, array $bands): string
    {
        if ($score >= $bands['healthy']) {
            return '#34d399';
        }
        if ($score >= $bands['attention']) {
            return '#fbbf24';
        }
        return '#f87171';
    }

    /**
     * @param array<string, mixed> $auditCfg
     */
    private function describeAuditFormula(array $auditCfg): string
    {
        $norm = (string) ($auditCfg['normalize'] ?? 'per_file');
        $unit = match ($norm) {
            'per_file' => '/file',
            'per_kloc' => '/KLOC',
            'share_of_total' => '%',
            default => '',
        };

        $parts = [];
        foreach (($auditCfg['weights'] ?? []) as $sev => $spec) {
            if (is_array($spec)) {
                $mult = (float) ($spec['mult'] ?? 0);
                $cap = isset($spec['cap']) ? sprintf(' (max -%s)', (string) $spec['cap']) : '';
                $parts[] = sprintf('-%spt per %s%s%s', (string) $mult, (string) $sev, $unit, $cap);
            } else {
                $parts[] = sprintf('-%spt per %s%s', (string) $spec, (string) $sev, $unit);
            }
        }

        $capParts = [];
        foreach (($auditCfg['caps'] ?? []) as $cap) {
            if (is_array($cap) && isset($cap['when'], $cap['max_score']) && is_array($cap['when'])) {
                foreach ($cap['when'] as $k => $expr) {
                    $capParts[] = sprintf('%s %s -> max %s', (string) $k, (string) $expr, (string) $cap['max_score']);
                }
            }
        }

        $desc = '100 ' . implode(', ', $parts);
        if ($capParts !== []) {
            $desc .= '; caps: ' . implode(', ', $capParts);
        }

        return $desc;
    }
}

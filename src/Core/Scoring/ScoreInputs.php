<?php

declare(strict_types=1);

namespace ACQC\Core\Scoring;

/**
 * Normalizes violation counts, category weights, rule_id/category ring mappings,
 * and density/rate inputs into a single deterministic map used across all calculators and evaluators.
 */
final class ScoreInputs
{
    public const CANONICAL_SEVERITIES = ['blocker', 'critical', 'error', 'warning', 'info', 'minor'];

    /** @var array<string, int> */
    private array $rawBySeverity = [
        'blocker' => 0,
        'critical' => 0,
        'error' => 0,
        'warning' => 0,
        'info' => 0,
        'minor' => 0,
    ];

    /** @var array<string, float> */
    private array $weightedBySeverity = [
        'blocker' => 0.0,
        'critical' => 0.0,
        'error' => 0.0,
        'warning' => 0.0,
        'info' => 0.0,
        'minor' => 0.0,
    ];

    /** @var array<string, int> */
    private array $rawByCategory = [];

    /** @var array<string, float> */
    private array $weightedByCategory = [];

    /** @var array<string, int> */
    private array $rawByDimension = [];

    /** @var array<string, float> */
    private array $weightedByDimension = [];

    private int $rawTotal = 0;
    private float $weightedTotal = 0.0;
    private int $filesCount = 0;
    private int $linesOfCode = 0;
    private float $maintainabilityIndex = 0.0;
    private bool $maintainabilityMeasured = false;

    /** @var list<string> */
    private array $unmappedCategories = [];

    /**
     * Categories named in the config (category_weights + dimension categories). They are always
     * exposed as condition inputs (0 when absent from the scan) so a valid config never fails at runtime.
     *
     * @var list<string>
     */
    private array $knownCategories = [];

    /**
     * Build inputs from either a full report `$data` structure or an array of violations + metadata.
     *
     * @param array<string, mixed> $data
     */
    public static function fromReportData(array $data, ScoringConfig $config): self
    {
        $instance = new self();
        $aliases = $config->getSeverityAliases();
        $catWeights = $config->getCategoryWeights();
        $dimensions = $config->getDimensions();

        $known = array_keys($catWeights);
        foreach ($dimensions as $dimId => $dimCfg) {
            $instance->rawByDimension[$dimId] = 0;
            $instance->weightedByDimension[$dimId] = 0.0;
            foreach (($dimCfg['categories'] ?? []) as $cat) {
                $known[] = strtolower((string) $cat);
            }
        }
        $instance->knownCategories = array_values(array_unique($known));

        $instance->filesCount = (int) (
            $data['code_size']['file_count']
            ?? $data['total_files']
            ?? $data['metrics']['total_files']
            ?? $data['files_scanned']
            ?? 0
        );
        $instance->linesOfCode = (int) (
            $data['code_size']['total_lines']
            ?? $data['total_lines']
            ?? $data['metrics']['total_lines']
            ?? 0
        );
        $instance->maintainabilityIndex = (float) ($data['metrics']['maintainability']['index'] ?? 0.0);
        $instance->maintainabilityMeasured = isset($data['metrics']['maintainability']['index']);

        $violationsList = $data['violations']['violations'] ?? $data['issues'] ?? null;

        if (is_array($violationsList) && $violationsList !== []) {
            $instance->populateFromViolationsList($violationsList, $aliases, $catWeights, $dimensions);
        } else {
            $bySev = $data['violations']['by_severity'] ?? $data['by_severity'] ?? [];
            $byCat = $data['violations']['by_category'] ?? $data['by_category'] ?? [];
            $byCatSev = $data['violations']['by_category_severity'] ?? $data['by_category_severity'] ?? [];
            $byRuleId = $data['violations']['by_rule_id'] ?? $data['by_rule_id'] ?? [];
            $instance->populateFromAggregates($bySev, $byCat, $byCatSev, $byRuleId, $aliases, $catWeights, $dimensions);
        }

        return $instance;
    }

    /**
     * Normalize a severity string using configured aliases.
     *
     * @param array<string, string> $aliases
     */
    public static function normalizeSeverity(string $severity, array $aliases = []): string
    {
        $sev = strtolower(trim($severity));
        if (isset($aliases[$sev])) {
            $sev = strtolower($aliases[$sev]);
        } elseif ($sev === 'high') {
            $sev = 'critical';
        } elseif ($sev === 'medium') {
            $sev = 'warning';
        } elseif ($sev === 'low') {
            $sev = 'info';
        }

        return in_array($sev, self::CANONICAL_SEVERITIES, true) ? $sev : 'info';
    }

    /**
     * @param array<int, mixed> $violationsList
     * @param array<string, string> $aliases
     * @param array<string, float> $catWeights
     * @param array<string, array<string, mixed>> $dimensions
     */
    private function populateFromViolationsList(
        array $violationsList,
        array $aliases,
        array $catWeights,
        array $dimensions
    ): void {
        $unmapped = [];

        foreach ($violationsList as $item) {
            [$sev, $cat, $ruleId] = $this->extractViolationTuple($item, $aliases);
            $weight = $catWeights[$cat] ?? 1.0;

            $this->rawBySeverity[$sev]++;
            $this->weightedBySeverity[$sev] += $weight;
            $this->rawTotal++;
            $this->weightedTotal += $weight;

            $this->rawByCategory[$cat] = ($this->rawByCategory[$cat] ?? 0) + 1;
            $this->weightedByCategory[$cat] = ($this->weightedByCategory[$cat] ?? 0.0) + $weight;

            $matchedDim = $this->matchDimension($cat, $ruleId, $dimensions);
            if ($matchedDim !== null) {
                $this->rawByDimension[$matchedDim] = ($this->rawByDimension[$matchedDim] ?? 0) + 1;
                $this->weightedByDimension[$matchedDim] = ($this->weightedByDimension[$matchedDim] ?? 0.0) + $weight;
                // Ensure deprecation rules matched by rule_id also count toward deprecation KPIs
                if ($matchedDim === 'compliance' && $cat !== 'deprecation' && $cat !== 'deprecated') {
                    $this->rawByCategory['deprecation'] = ($this->rawByCategory['deprecation'] ?? 0) + 1;
                    $this->weightedByCategory['deprecation'] = ($this->weightedByCategory['deprecation'] ?? 0.0) + $weight;
                }
            } elseif (!array_key_exists($cat, $catWeights)) {
                $unmapped[$cat] = true;
            }
        }

        $this->unmappedCategories = array_keys($unmapped);
        sort($this->unmappedCategories);
    }

    /**
     * Populate when only summary counts (`by_severity` and `by_category`) are given.
     *
     * @param array<string, int|float> $bySev
     * @param array<string, int|float> $byCat
     * @param array<string, array<string, int|float>> $byCatSev
     * @param array<string, int|array{count?: int, category?: string, severity?: string}> $byRuleId
     * @param array<string, string> $aliases
     * @param array<string, float> $catWeights
     * @param array<string, array<string, mixed>> $dimensions
     */
    private function populateFromAggregates(
        array $bySev,
        array $byCat,
        array $byCatSev,
        array $byRuleId,
        array $aliases,
        array $catWeights,
        array $dimensions
    ): void {
        foreach ($bySev as $sev => $count) {
            $normSev = self::normalizeSeverity((string) $sev, $aliases);
            $this->rawBySeverity[$normSev] += (int) round((float) $count);
        }
        $this->rawTotal = array_sum($this->rawBySeverity);

        foreach ($byCat as $cat => $count) {
            $normCat = strtolower((string) $cat);
            $c = (int) round((float) $count);
            $w = $catWeights[$normCat] ?? 1.0;
            $this->rawByCategory[$normCat] = ($this->rawByCategory[$normCat] ?? 0) + $c;
            $this->weightedByCategory[$normCat] = ($this->weightedByCategory[$normCat] ?? 0.0) + ($c * $w);
        }

        // Compute weighted severities: use exact per-category severity breakdown if supplied
        if ($byCatSev !== []) {
            foreach ($byCatSev as $cat => $sevMap) {
                $normCat = strtolower((string) $cat);
                $w = $catWeights[$normCat] ?? 1.0;
                if (is_array($sevMap)) {
                    foreach ($sevMap as $sev => $cnt) {
                        $normSev = self::normalizeSeverity((string) $sev, $aliases);
                        $this->weightedBySeverity[$normSev] += ((float) $cnt) * $w;
                    }
                }
            }
            $this->weightedTotal = array_sum($this->weightedBySeverity);
        } else {
            // If no per-category severity breakdown is supplied, keep raw severity counts when all weights are 1.0,
            // or scale proportionally to weighted category total.
            $catRawSum = array_sum($this->rawByCategory);
            $catWeightedSum = array_sum($this->weightedByCategory);
            $scale = ($catRawSum > 0 && $this->rawTotal > 0) ? ($catWeightedSum / max($catRawSum, $this->rawTotal)) : 1.0;
            foreach ($this->rawBySeverity as $sev => $cnt) {
                $this->weightedBySeverity[$sev] = $cnt * $scale;
            }
            $this->weightedTotal = array_sum($this->weightedBySeverity);
        }

        // Map categories and optional rule_id overrides to dimensions
        $remainingRawCat = $this->rawByCategory;
        $remainingWeightedCat = $this->weightedByCategory;

        foreach ($byRuleId as $ruleId => $info) {
            $count = is_array($info) ? (int) ($info['count'] ?? 0) : (int) $info;
            $srcCat = strtolower(is_array($info) ? (string) ($info['category'] ?? 'logic') : 'logic');
            if ($count <= 0) {
                continue;
            }
            $w = $catWeights[$srcCat] ?? 1.0;
            $dimByRule = $this->matchDimensionByRuleId((string) $ruleId, $dimensions);
            if ($dimByRule !== null) {
                $this->rawByDimension[$dimByRule] = ($this->rawByDimension[$dimByRule] ?? 0) + $count;
                $this->weightedByDimension[$dimByRule] = ($this->weightedByDimension[$dimByRule] ?? 0.0) + ($count * $w);
                if (isset($remainingRawCat[$srcCat])) {
                    $remainingRawCat[$srcCat] = max(0, $remainingRawCat[$srcCat] - $count);
                    $remainingWeightedCat[$srcCat] = max(0.0, $remainingWeightedCat[$srcCat] - ($count * $w));
                }
                if ($dimByRule === 'compliance' && $srcCat !== 'deprecation' && $srcCat !== 'deprecated') {
                    $this->rawByCategory['deprecation'] = ($this->rawByCategory['deprecation'] ?? 0) + $count;
                    $this->weightedByCategory['deprecation'] = ($this->weightedByCategory['deprecation'] ?? 0.0) + ($count * $w);
                }
            }
        }

        $unmapped = [];
        foreach ($remainingRawCat as $cat => $rawCnt) {
            if ($rawCnt <= 0 && ($this->rawByCategory[$cat] ?? 0) <= 0) {
                continue;
            }
            $wCnt = $remainingWeightedCat[$cat] ?? 0.0;
            $matchedDim = $this->matchDimensionByCategory($cat, $dimensions);
            if ($matchedDim !== null) {
                $this->rawByDimension[$matchedDim] = ($this->rawByDimension[$matchedDim] ?? 0) + $rawCnt;
                $this->weightedByDimension[$matchedDim] = ($this->weightedByDimension[$matchedDim] ?? 0.0) + $wCnt;
            } elseif (!array_key_exists($cat, $catWeights)) {
                $unmapped[$cat] = true;
            }
        }

        $this->unmappedCategories = array_keys($unmapped);
        sort($this->unmappedCategories);
    }

    /**
     * @param array<string, string> $aliases
     * @return array{0: string, 1: string, 2: string} [severity, category, ruleId]
     */
    private function extractViolationTuple(mixed $item, array $aliases): array
    {
        if (is_array($item)) {
            $sev = self::normalizeSeverity((string) ($item['severity'] ?? 'info'), $aliases);
            $cat = strtolower(trim((string) ($item['category'] ?? $item['type'] ?? 'logic')));
            $ruleId = trim((string) ($item['rule_id'] ?? $item['rule'] ?? $item['id'] ?? $item['source'] ?? ''));
            return [$sev, $cat !== '' ? $cat : 'logic', $ruleId];
        }

        if (is_object($item)) {
            $rawSev = method_exists($item, 'getSeverity') ? (string) $item->getSeverity() : 'info';
            $sev = self::normalizeSeverity($rawSev, $aliases);

            $cat = '';
            if (method_exists($item, 'getCategory') && $item->getCategory()) {
                $cat = (string) $item->getCategory();
            } elseif (method_exists($item, 'getType') && $item->getType()) {
                $cat = (string) $item->getType();
            }
            $cat = strtolower(trim($cat)) ?: 'logic';

            $ruleId = '';
            if (method_exists($item, 'getRuleId') && $item->getRuleId()) {
                $ruleId = (string) $item->getRuleId();
            } elseif (method_exists($item, 'getId') && $item->getId()) {
                $ruleId = (string) $item->getId();
            } elseif (method_exists($item, 'getSource') && $item->getSource()) {
                $ruleId = (string) $item->getSource();
            }

            return [$sev, $cat, trim($ruleId)];
        }

        return ['info', 'logic', ''];
    }

    /**
     * First-match dimension lookup: rule_ids globs take priority over categories.
     *
     * @param array<string, array<string, mixed>> $dimensions
     */
    public function matchDimension(string $category, string $ruleId, array $dimensions): ?string
    {
        if ($ruleId !== '') {
            $byRule = $this->matchDimensionByRuleId($ruleId, $dimensions);
            if ($byRule !== null) {
                return $byRule;
            }
        }

        return $this->matchDimensionByCategory($category, $dimensions);
    }

    /**
     * @param array<string, array<string, mixed>> $dimensions
     */
    private function matchDimensionByRuleId(string $ruleId, array $dimensions): ?string
    {
        if ($ruleId === '') {
            return null;
        }
        foreach ($dimensions as $dimId => $dimCfg) {
            foreach (($dimCfg['rule_ids'] ?? []) as $pattern) {
                if (fnmatch((string) $pattern, $ruleId, FNM_CASEFOLD)) {
                    return (string) $dimId;
                }
            }
        }
        return null;
    }

    /**
     * @param array<string, array<string, mixed>> $dimensions
     */
    private function matchDimensionByCategory(string $category, array $dimensions): ?string
    {
        $cat = strtolower($category);
        foreach ($dimensions as $dimId => $dimCfg) {
            foreach (($dimCfg['categories'] ?? []) as $candidate) {
                if (strtolower((string) $candidate) === $cat) {
                    return (string) $dimId;
                }
            }
        }
        return null;
    }

    public function getRawTotal(): int
    {
        return $this->rawTotal;
    }

    public function getWeightedTotal(): float
    {
        return $this->weightedTotal;
    }

    /**
     * @return array<string, int>
     */
    public function getRawBySeverity(): array
    {
        return $this->rawBySeverity;
    }

    /**
     * @return array<string, float>
     */
    public function getWeightedBySeverity(): array
    {
        return $this->weightedBySeverity;
    }

    /**
     * @return array<string, int>
     */
    public function getRawByCategory(): array
    {
        return $this->rawByCategory;
    }

    /**
     * @return array<string, float>
     */
    public function getWeightedByCategory(): array
    {
        return $this->weightedByCategory;
    }

    public function getRawDimensionCount(string $dimId): int
    {
        return $this->rawByDimension[$dimId] ?? 0;
    }

    public function getWeightedDimensionCount(string $dimId): float
    {
        return $this->weightedByDimension[$dimId] ?? 0.0;
    }

    public function getFilesCount(): int
    {
        return $this->filesCount;
    }

    public function getLinesOfCode(): int
    {
        return $this->linesOfCode;
    }

    public function getMaintainabilityIndex(): float
    {
        return $this->maintainabilityIndex;
    }

    /** False when no modules were analysed (index is null/absent, not a real 0). */
    public function isMaintainabilityMeasured(): bool
    {
        return $this->maintainabilityMeasured;
    }

    /**
     * @return list<string>
     */
    public function getUnmappedCategories(): array
    {
        return $this->unmappedCategories;
    }

    /**
     * Build the flat map of all condition/KPI input variables.
     *
     * - Raw severity/category counts are under `<name>` (so readiness/caps use raw counts by default).
     * - Weighted counts are under `<name>_weighted`.
     * - Percentage densities (`<name>_pct`) use weighted counts divided by `max(1, weightedTotal) * 100`.
     * - Per-file (`<name>_per_file`) and per-kloc (`<name>_per_kloc`) rates are also provided.
     *
     * @param array<string, float|int> $dimensionScores
     * @return array<string, float|int>
     */
    public function toVariablesMap(float $overallScore = 100.0, float $debtHours = 0.0, array $dimensionScores = []): array
    {
        $denom = max(1.0, $this->weightedTotal);
        $files = max(1, $this->filesCount);
        $kloc = max(0.001, $this->linesOfCode / 1000.0);

        $vars = [
            'total' => $this->rawTotal,
            'total_weighted' => round($this->weightedTotal, 2),
            'overall' => $overallScore,
            'debt_hours' => $debtHours,
            'files' => $this->filesCount,
            'kloc' => round($this->linesOfCode / 1000.0, 2),
            'maintainability_index' => $this->maintainabilityIndex,
            'maintainability_measured' => $this->maintainabilityMeasured ? 1 : 0,
        ];

        foreach (self::CANONICAL_SEVERITIES as $sev) {
            $raw = $this->rawBySeverity[$sev] ?? 0;
            $w = $this->weightedBySeverity[$sev] ?? 0.0;
            $vars[$sev] = $raw;
            $vars["{$sev}_weighted"] = round($w, 2);
            $vars["{$sev}_pct"] = ($w / $denom) * 100.0;
            $vars["{$sev}_per_file"] = $w / $files;
            $vars["{$sev}_per_kloc"] = $w / $kloc;
        }

        $allCats = array_unique(array_merge(
            array_keys($this->rawByCategory),
            $this->knownCategories,
            ['security', 'performance', 'maintainability', 'deprecation', 'api', 'logic', 'documentation', 'frontend', 'seo', 'accessibility']
        ));

        foreach ($allCats as $cat) {
            $raw = $this->rawByCategory[$cat] ?? 0;
            $w = $this->weightedByCategory[$cat] ?? 0.0;
            $vars[$cat] = $raw;
            $vars["{$cat}_weighted"] = round($w, 2);
            $vars["{$cat}_pct"] = ($w / $denom) * 100.0;
            $vars["{$cat}_per_file"] = $w / $files;
            $vars["{$cat}_per_kloc"] = $w / $kloc;
        }

        foreach ($this->rawByDimension as $dimId => $raw) {
            $w = $this->weightedByDimension[$dimId] ?? 0.0;
            if (!isset($vars[$dimId])) {
                $vars[$dimId] = $raw;
                $vars["{$dimId}_weighted"] = round($w, 2);
                $vars["{$dimId}_pct"] = ($w / $denom) * 100.0;
                $vars["{$dimId}_per_file"] = $w / $files;
                $vars["{$dimId}_per_kloc"] = $w / $kloc;
            }
            // Ring scores are only known after all rings are calculated; default 100 (no penalty)
            // where they are not available yet (ring caps, audit caps) instead of failing.
            $vars["{$dimId}_score"] = $dimensionScores[$dimId] ?? 100;
        }

        return $vars;
    }
}

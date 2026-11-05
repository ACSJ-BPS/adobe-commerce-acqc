<?php

declare(strict_types=1);

namespace ACQC\Core\Scoring;

use ACQC\Core\Config\ProjectConfigLocator;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Loads the built-in `config/scoring.yaml` and merges optional `scoring:` overrides
 * from the nearest `.acqc/project.yaml`.
 *
 * Associative maps deep-merge; sequential lists (readiness, caps, grades, tiers,
 * gates, assessment, categories, rule_ids, terms) replace.
 */
final class ScoringConfigLoader
{
    private const VALID_NORMALIZE = ['absolute', 'per_file', 'per_kloc', 'share_of_total'];
    private const VALID_DEBT_SCALING = ['whole_total', 'marginal'];
    private const VALID_PLACEHOLDERS = ['show_na', 'legacy'];

    private const LIST_KEYS = [
        'readiness',
        'caps',
        'grades',
        'tiers',
        'gates',
        'assessment',
        'categories',
        'rule_ids',
        'terms',
    ];

    private const BASE_CONDITION_KEYS = [
        'blocker', 'critical', 'error', 'warning', 'info', 'minor',
        'blocker_weighted', 'critical_weighted', 'error_weighted', 'warning_weighted', 'info_weighted', 'minor_weighted',
        'blocker_pct', 'critical_pct', 'error_pct', 'warning_pct', 'info_pct', 'minor_pct',
        'blocker_per_file', 'critical_per_file', 'error_per_file', 'warning_per_file', 'info_per_file', 'minor_per_file',
        'blocker_per_kloc', 'critical_per_kloc', 'error_per_kloc', 'warning_per_kloc', 'info_per_kloc', 'minor_per_kloc',
        'total', 'total_weighted', 'overall', 'debt_hours', 'files', 'kloc', 'maintainability_index',
        'deprecation', 'deprecation_pct', 'deprecation_weighted',
        'frontend', 'frontend_pct', 'frontend_weighted',
        'security', 'security_pct', 'security_weighted',
        'performance', 'performance_pct', 'performance_weighted',
        'maintainability', 'maintainability_pct', 'maintainability_weighted',
        'api', 'api_pct', 'api_weighted',
        'logic', 'logic_pct', 'logic_weighted',
        'documentation', 'documentation_pct', 'documentation_weighted',
        'seo', 'seo_pct', 'seo_weighted',
        'accessibility', 'accessibility_pct', 'accessibility_weighted',
    ];

    private ConditionEvaluator $evaluator;
    private ProjectConfigLocator $locator;
    private string $defaultConfigPath;

    public function __construct(
        ?string $defaultConfigPath = null,
        ?ProjectConfigLocator $locator = null,
        ?ConditionEvaluator $evaluator = null
    ) {
        $this->defaultConfigPath = $defaultConfigPath ?? dirname(__DIR__, 3) . '/config/scoring.yaml';
        $this->locator = $locator ?? new ProjectConfigLocator();
        $this->evaluator = $evaluator ?? new ConditionEvaluator();
    }

    /**
     * Load scoring config for a target directory or file, merging nearest `.acqc/project.yaml`.
     *
     * @param array<string, mixed>|null $inlineOverrides Optional overrides (e.g. from unit tests)
     */
    public function load(?string $targetPath = null, ?array $inlineOverrides = null): ScoringConfig
    {
        if (!is_file($this->defaultConfigPath)) {
            throw new RuntimeException("Built-in scoring config not found at {$this->defaultConfigPath}");
        }

        $parsedDefault = Yaml::parseFile($this->defaultConfigPath);
        $base = is_array($parsedDefault['scoring'] ?? null) ? $parsedDefault['scoring'] : (is_array($parsedDefault) ? $parsedDefault : []);
        $sources = ['config/scoring.yaml'];

        if ($targetPath !== null && $targetPath !== '') {
            $located = $this->locator->loadNearest($targetPath);
            if ($located['path'] !== null && isset($located['config']['scoring'])) {
                if (!is_array($located['config']['scoring'])) {
                    throw new InvalidArgumentException(sprintf(
                        'Invalid "scoring" section in %s: expected a YAML mapping.',
                        $located['path']
                    ));
                }
                $base = $this->mergeScoringArrays($base, $located['config']['scoring']);
                $sources[] = $located['path'];
            }
        }

        if ($inlineOverrides !== null) {
            $overrideRoot = isset($inlineOverrides['scoring']) && is_array($inlineOverrides['scoring'])
                ? $inlineOverrides['scoring']
                : $inlineOverrides;
            $base = $this->mergeScoringArrays($base, $overrideRoot);
            $sources[] = 'inline';
        }

        $this->validate($base);

        return new ScoringConfig($base, $sources);
    }

    /**
     * Deep-merge associative arrays while replacing sequential lists.
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    public function mergeScoringArrays(array $base, array $override): array
    {
        $result = $base;

        foreach ($override as $key => $value) {
            if ($value === null || $value === false) {
                if (is_bool($value)) {
                    $result[$key] = false;
                } else {
                    unset($result[$key]);
                }
                continue;
            }

            if (in_array((string) $key, self::LIST_KEYS, true)) {
                if (!is_array($value) || (!array_is_list($value) && $value !== [])) {
                    throw new InvalidArgumentException(sprintf(
                        'Scoring config key "%s" must be a list.',
                        (string) $key
                    ));
                }
                $result[$key] = array_values($value);
                continue;
            }

            if (is_array($value) && isset($result[$key]) && is_array($result[$key])) {
                if (array_is_list($value) || array_is_list($result[$key])) {
                    $result[$key] = $value;
                } else {
                    $result[$key] = $this->mergeScoringArrays($result[$key], $value);
                }
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Validate merged scoring config, failing fast with clear paths on bad operators or unknown keys.
     *
     * @param array<string, mixed> $data
     */
    private function validate(array $data): void
    {
        $knownInputKeys = $this->buildKnownInputKeys($data);

        $this->validateOverall($data, $knownInputKeys);
        $this->validateDimensions($data, $knownInputKeys);
        $this->validateReadiness($data, $knownInputKeys);
        $this->validateAudit($data, $knownInputKeys);
        $this->validateDebtAndPlaceholders($data);
        $this->validateGates($data, $knownInputKeys);
        $this->validateKpis($data, $knownInputKeys);
        $this->validateNumbers($data);
    }

    /**
     * Weights, hours and tier values must be numbers >= 0. A string such as "abc" would
     * silently become 0 and a negative weight would raise the score as findings grow.
     *
     * @param array<string, mixed> $data
     */
    private function validateNumbers(array $data): void
    {
        $maps = [
            'scoring.category_weights' => $data['category_weights'] ?? [],
            'scoring.overall.weights' => $data['overall']['weights'] ?? [],
            'scoring.debt.hours' => $data['debt']['hours'] ?? [],
        ];
        foreach ($data['dimensions'] ?? [] as $dimId => $dimCfg) {
            if (is_array($dimCfg)) {
                $maps["scoring.dimensions.{$dimId}.severities"] = $dimCfg['severities'] ?? [];
                foreach (['mult', 'floor', 'per'] as $key) {
                    if (array_key_exists($key, $dimCfg)) {
                        $maps["scoring.dimensions.{$dimId}"][$key] = $dimCfg[$key];
                    }
                }
            }
        }
        foreach ($data['audit']['overall']['weights'] ?? [] as $sev => $spec) {
            $maps['scoring.audit.overall.weights.' . $sev] = is_array($spec) ? $spec : ['mult' => $spec];
        }
        foreach ($data['debt']['tiers'] ?? [] as $idx => $tier) {
            $maps["scoring.debt.tiers[{$idx}]"] = is_array($tier) ? $tier : ['above' => $tier];
        }

        foreach ($maps as $path => $map) {
            if (!is_array($map)) {
                throw new InvalidArgumentException("{$path} must be a map of numbers.");
            }
            foreach ($map as $key => $value) {
                if (!is_int($value) && !is_float($value)) {
                    throw new InvalidArgumentException(sprintf(
                        'Invalid value %s at %s.%s: expected a number >= 0.',
                        var_export($value, true),
                        $path,
                        (string) $key
                    ));
                }
                if ($value < 0) {
                    throw new InvalidArgumentException(sprintf(
                        'Invalid value %s at %s.%s: must be >= 0.',
                        (string) $value,
                        $path,
                        (string) $key
                    ));
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $knownInputKeys
     */
    private function validateOverall(array $data, array $knownInputKeys): void
    {
        $overallNorm = (string) ($data['overall']['normalize'] ?? 'absolute');
        if (!in_array($overallNorm, self::VALID_NORMALIZE, true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid normalize mode "%s" at scoring.overall.normalize. Allowed: %s',
                $overallNorm,
                implode(', ', self::VALID_NORMALIZE)
            ));
        }
        $this->validateCaps($data['overall']['caps'] ?? [], $knownInputKeys, 'scoring.overall.caps');
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $knownInputKeys
     */
    private function validateDimensions(array $data, array $knownInputKeys): void
    {
        foreach (($data['dimensions'] ?? []) as $dimId => $dimCfg) {
            if (!is_array($dimCfg)) {
                continue;
            }
            $dimNorm = (string) ($dimCfg['normalize'] ?? 'share_of_total');
            if (!in_array($dimNorm, self::VALID_NORMALIZE, true)) {
                throw new InvalidArgumentException(sprintf(
                    'Invalid normalize mode "%s" at scoring.dimensions.%s.normalize. Allowed: %s',
                    $dimNorm,
                    (string) $dimId,
                    implode(', ', self::VALID_NORMALIZE)
                ));
            }
            $this->validateCaps($dimCfg['caps'] ?? [], $knownInputKeys, "scoring.dimensions.{$dimId}.caps");
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $knownInputKeys
     */
    private function validateReadiness(array $data, array $knownInputKeys): void
    {
        foreach (($data['readiness'] ?? []) as $idx => $rule) {
            if (!is_array($rule) || empty($rule['verdict'])) {
                throw new InvalidArgumentException("Invalid readiness rule at scoring.readiness[{$idx}]: missing 'verdict'.");
            }
            foreach (['when_any', 'when_all'] as $clause) {
                if (isset($rule[$clause])) {
                    if (!is_array($rule[$clause])) {
                        throw new InvalidArgumentException("scoring.readiness[{$idx}].{$clause} must be a map.");
                    }
                    $this->validateConditionMap($rule[$clause], $knownInputKeys, "scoring.readiness[{$idx}].{$clause}");
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $knownInputKeys
     */
    private function validateAudit(array $data, array $knownInputKeys): void
    {
        if (!isset($data['audit']['overall']) || !is_array($data['audit']['overall'])) {
            return;
        }
        $auditNorm = (string) ($data['audit']['overall']['normalize'] ?? 'per_file');
        if (!in_array($auditNorm, self::VALID_NORMALIZE, true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid normalize mode "%s" at scoring.audit.overall.normalize.',
                $auditNorm
            ));
        }
        $this->validateCaps($data['audit']['overall']['caps'] ?? [], $knownInputKeys, 'scoring.audit.overall.caps');

        foreach (($data['audit']['severity_aliases'] ?? []) as $from => $to) {
            if (!in_array(strtolower((string) $to), ScoreInputs::CANONICAL_SEVERITIES, true)) {
                throw new InvalidArgumentException(sprintf(
                    'Invalid severity "%s" at scoring.audit.severity_aliases.%s. Allowed: %s',
                    (string) $to,
                    (string) $from,
                    implode(', ', ScoreInputs::CANONICAL_SEVERITIES)
                ));
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function validateDebtAndPlaceholders(array $data): void
    {
        $debtScaling = (string) ($data['debt']['scaling'] ?? 'whole_total');
        if (!in_array($debtScaling, self::VALID_DEBT_SCALING, true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid debt scaling "%s" at scoring.debt.scaling. Allowed: %s',
                $debtScaling,
                implode(', ', self::VALID_DEBT_SCALING)
            ));
        }

        $placeholders = (string) ($data['placeholders'] ?? 'show_na');
        if (!in_array($placeholders, self::VALID_PLACEHOLDERS, true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid placeholders mode "%s" at scoring.placeholders. Allowed: %s',
                $placeholders,
                implode(', ', self::VALID_PLACEHOLDERS)
            ));
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $knownInputKeys
     */
    private function validateGates(array $data, array $knownInputKeys): void
    {
        foreach (($data['gates'] ?? []) as $idx => $gate) {
            if (!is_array($gate) || empty($gate['name']) || empty($gate['when']) || !is_array($gate['when'])) {
                throw new InvalidArgumentException("Invalid gate at scoring.gates[{$idx}]: requires 'name' and 'when' map.");
            }
            $this->validateConditionMap($gate['when'], $knownInputKeys, "scoring.gates[{$idx}].when");
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $knownInputKeys
     */
    private function validateKpis(array $data, array $knownInputKeys): void
    {
        foreach (($data['kpis'] ?? []) as $kpiId => $kpiCfg) {
            if (!is_array($kpiCfg)) {
                continue;
            }
            foreach (['pass', 'warn'] as $bandKey) {
                if (isset($kpiCfg[$bandKey]) && is_string($kpiCfg[$bandKey])) {
                    $this->evaluator->parseExpression($kpiCfg[$bandKey], "scoring.kpis.{$kpiId}.{$bandKey}");
                }
            }
            $this->validateKpiTerms((string) $kpiId, $kpiCfg['terms'] ?? [], $knownInputKeys);
        }
    }

    /**
     * @param array<int, mixed> $terms
     * @param list<string> $knownInputKeys
     */
    private function validateKpiTerms(string $kpiId, array $terms, array $knownInputKeys): void
    {
        foreach ($terms as $tIdx => $term) {
            if (!is_array($term)) {
                throw new InvalidArgumentException("scoring.kpis.{$kpiId}.terms[{$tIdx}] must be a map.");
            }
            if (isset($term['input']) && !in_array((string) $term['input'], $knownInputKeys, true)) {
                throw new InvalidArgumentException(sprintf(
                    'Unknown KPI term input "%s" at scoring.kpis.%s.terms[%d].input.',
                    (string) $term['input'],
                    $kpiId,
                    $tIdx
                ));
            }
            if (isset($term['when']) && is_array($term['when'])) {
                $this->validateConditionMap($term['when'], $knownInputKeys, "scoring.kpis.{$kpiId}.terms[{$tIdx}].when");
            }
        }
    }

    /**
     * @param array<int, mixed> $caps
     * @param list<string> $knownInputKeys
     */
    private function validateCaps(array $caps, array $knownInputKeys, string $path): void
    {
        foreach ($caps as $idx => $cap) {
            if (!is_array($cap) || !isset($cap['when']) || !is_array($cap['when']) || !isset($cap['max_score'])) {
                throw new InvalidArgumentException("Invalid cap at {$path}[{$idx}]: requires 'when' map and 'max_score'.");
            }
            $this->validateConditionMap($cap['when'], $knownInputKeys, "{$path}[{$idx}].when");
        }
    }

    /**
     * @param array<string, mixed> $conditionMap
     * @param list<string> $knownInputKeys
     */
    private function validateConditionMap(array $conditionMap, array $knownInputKeys, string $path): void
    {
        foreach ($conditionMap as $key => $expr) {
            if (!in_array((string) $key, $knownInputKeys, true)) {
                throw new InvalidArgumentException(sprintf(
                    'Unknown scoring condition input "%s" at %s.%s.',
                    (string) $key,
                    $path,
                    (string) $key
                ));
            }
            if (!is_string($expr)) {
                throw new InvalidArgumentException(sprintf(
                    'Condition expression at %s.%s must be a string "<op> <number>".',
                    $path,
                    (string) $key
                ));
            }
            $this->evaluator->parseExpression($expr, "{$path}.{$key}");
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return list<string>
     */
    private function buildKnownInputKeys(array $data): array
    {
        $keys = self::BASE_CONDITION_KEYS;

        foreach (array_keys($data['category_weights'] ?? []) as $cat) {
            $c = strtolower((string) $cat);
            $keys[] = $c;
            $keys[] = "{$c}_pct";
            $keys[] = "{$c}_weighted";
            $keys[] = "{$c}_per_file";
            $keys[] = "{$c}_per_kloc";
        }

        foreach (($data['dimensions'] ?? []) as $dimId => $dimCfg) {
            $d = strtolower((string) $dimId);
            $keys[] = $d;
            $keys[] = "{$d}_score";
            $keys[] = "{$d}_pct";
            $keys[] = "{$d}_weighted";
            $keys[] = "{$d}_per_file";
            $keys[] = "{$d}_per_kloc";
            if (is_array($dimCfg)) {
                foreach (($dimCfg['categories'] ?? []) as $cat) {
                    $c = strtolower((string) $cat);
                    $keys[] = $c;
                    $keys[] = "{$c}_pct";
                    $keys[] = "{$c}_weighted";
                    $keys[] = "{$c}_per_file";
                    $keys[] = "{$c}_per_kloc";
                }
            }
        }

        return array_values(array_unique($keys));
    }
}

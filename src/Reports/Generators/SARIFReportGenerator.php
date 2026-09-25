<?php

declare(strict_types=1);

namespace ACQC\Reports\Generators;

use ACQC\Core\AI\PredictiveAnalytics;
use ACQC\Core\Config\ConfigManager;
use ACQC\Core\Rules\RuleDefinition;
use ACQC\Core\Rules\RuleRegistry;
use Psr\Log\LoggerInterface;

/**
 * Generates SARIF 2.1.0 output for GitHub Code Scanning, Azure DevOps,
 * VS Code SARIF Viewer and other SARIF consumers.
 *
 * Accepted input shapes for issues:
 *   $data['issues']                     flat list (AuditEngine / `acqc audit`)
 *   $data['violations']                 flat list
 *   $data['violations']['violations']   nested (ReportCommand)
 *   $data['violations']['items']        nested (legacy)
 * Each issue may be an array or an object exposing getters (getFilePath, getLine, ...).
 *
 * Options:
 *   project_path  repository root; artifact URIs are made relative to it (SRCROOT)
 *   source_root   directory to read source lines from for fingerprints
 *                 (defaults to project_path; the staged snapshot dir for --staged)
 *   command_line  recorded in invocations[0].commandLine
 */
class SARIFReportGenerator extends AbstractReportGenerator
{
    public const FINGERPRINT_KEY = 'acqcFingerprint/v1';

    private const SARIF_VERSION = '2.1.0';
    private const SARIF_SCHEMA  = 'https://json.schemastore.org/sarif-2.1.0.json';
    private const HELP_BASE_URI = 'https://github.com/OneAdobe/adobe-commerce-acqc/tree/main/config/rules';

    /** Numeric scores GitHub uses to bucket security alerts (critical >= 9, high >= 7, medium >= 4). */
    private const SECURITY_SEVERITY = [
        'blocker' => '9.8', 'critical' => '9.0', 'error' => '7.5',
        'warning' => '5.0', 'info' => '2.0', 'minor' => '2.0',
    ];

    /** @var array<string, string[]> cache of file lines, keyed by absolute path */
    private array $lineCache = [];

    public function __construct(
        ?ConfigManager $configManager = null,
        ?LoggerInterface $logger = null,
        ?PredictiveAnalytics $predictiveAnalytics = null,
        private readonly ?RuleRegistry $registry = null,
    ) {
        parent::__construct($configManager, $logger, $predictiveAnalytics);
    }

    public function getSupportedTypes(): array
    {
        return ['summary', 'detailed', 'security', 'performance'];
    }

    public function getFileExtension(): string
    {
        return 'sarif';
    }

    public function getMimeType(): string
    {
        return 'application/sarif+json';
    }

    public function generate(array $data, array $options = []): ReportResult
    {
        try {
            $violations  = $this->extractIssues($data);
            $projectPath = (string) ($options['project_path'] ?? $data['project_path'] ?? getcwd());
            $sourceRoot  = (string) ($options['source_root'] ?? $projectPath);
            $sarif       = $this->buildSarif($data, $violations, $projectPath, $sourceRoot, $options);
            $json        = json_encode($sarif, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);

            return new ReportResult(true, $json, 'acqc-' . date('Y-m-d_H-i-s') . '.sarif', $this->getMimeType());
        } catch (\Throwable $e) {
            return new ReportResult(false, '', '', '', [], $e->getMessage());
        } finally {
            $this->lineCache = [];
        }
    }

    // ── input normalisation ────────────────────────────────────────────────────

    /** @return list<array<string,mixed>|object> */
    private function extractIssues(array $data): array
    {
        $raw = $data['issues'] ?? $data['violations'] ?? [];
        if (!is_array($raw)) {
            return [];
        }
        foreach (['violations', 'items'] as $nestedKey) {
            if (isset($raw[$nestedKey]) && is_array($raw[$nestedKey])) {
                $raw = $raw[$nestedKey];
                break;
            }
        }

        // Only list entries are issues; summary keys (total_count, by_severity, ...) are dropped.
        $issues = [];
        foreach ($raw as $key => $v) {
            if (is_int($key) && (is_object($v) || (is_array($v) && $this->looksLikeIssue($v)))) {
                $issues[] = $v;
            }
        }
        return $issues;
    }

    private function looksLikeIssue(array $v): bool
    {
        foreach (['message', 'description', 'rule_id', 'rule', 'type', 'check', 'file', 'file_path'] as $k) {
            if (isset($v[$k]) && $v[$k] !== '') {
                return true;
            }
        }
        return false;
    }

    // ── document ───────────────────────────────────────────────────────────────

    private function buildSarif(array $data, array $violations, string $projectPath, string $sourceRoot, array $options): array
    {
        $root       = $this->normalizeRoot($projectPath);
        $sourceBase = $this->normalizeRoot($sourceRoot);

        $rules = $this->extractRules($violations);
        $rules = $this->mergeRegistryRules($rules, (string) ($data['profile'] ?? 'developer'));
        $rules = array_values($rules);

        $ruleIndex = [];
        foreach ($rules as $i => $rule) {
            $ruleIndex[$rule['id']] = $i;
        }

        $run = [
            'tool' => [
                'driver' => [
                    'name'           => 'ACQC',
                    'fullName'       => 'Adobe Commerce Quality Control',
                    'version'        => \ACQC\Core\Application::VERSION,
                    'semanticVersion'=> \ACQC\Core\Application::VERSION,
                    'informationUri' => 'https://github.com/OneAdobe/adobe-commerce-acqc',
                    'rules'          => $rules,
                ],
            ],
            'automationDetails' => ['id' => 'acqc/' . ($data['scope'] ?? 'project') . '/'],
            'originalUriBaseIds' => [
                'SRCROOT' => ['uri' => $this->toFileUri($root) . '/'],
            ],
            'columnKind' => 'unicodeCodePoints',
            'results'    => $this->buildResults($violations, $root, $sourceBase, $ruleIndex),
            'invocations' => [
                [
                    'executionSuccessful' => true,
                    'commandLine'         => (string) ($options['command_line'] ?? 'acqc'),
                    'workingDirectory'    => ['uri' => $this->toFileUri($root) . '/'],
                ],
            ],
            'properties' => [
                'score'         => $data['score'] ?? null,
                'filesAnalyzed' => $data['files_analyzed'] ?? 0,
                'scope'         => $data['scope'] ?? 'project',
                'gate'          => $data['gate'] ?? null,
                'failOn'        => $data['fail_on'] ?? null,
            ],
        ];

        return [
            '$schema' => self::SARIF_SCHEMA,
            'version' => self::SARIF_VERSION,
            'runs'    => [$run],
        ];
    }

    private function extractRules(array $violations): array
    {
        $rules = [];
        foreach ($violations as $v) {
            $ruleId = $this->getRuleId($v);
            if (isset($rules[$ruleId])) {
                continue;
            }
            $severity = $this->normalizeSeverity($v);
            $category = $this->getCategory($v);
            $rules[$ruleId] = $this->ruleDescriptor(
                $ruleId,
                $this->getRuleName($v),
                $this->getRuleDescription($v),
                $this->getRuleDescription($v),
                null,
                $severity,
                [$category],
                null
            );
        }
        return $rules;
    }

    /** @param string[] $tags */
    private function ruleDescriptor(
        string $id,
        string $name,
        string $short,
        string $full,
        ?string $help,
        string $severity,
        array $tags,
        ?string $precision
    ): array {
        $short = $short !== '' ? $short : $id;
        $tags  = array_values(array_unique(array_filter(array_map('strval', $tags))));

        $properties = [
            'tags'             => $tags,
            'severity'         => $severity,
            'problem.severity' => $this->mapSeverityToProblemSeverity($severity),
        ];
        if ($precision !== null) {
            $properties['precision'] = $precision;
        }
        if ($this->isSecurity($tags)) {
            $properties['security-severity'] = self::SECURITY_SEVERITY[$severity] ?? '5.0';
            if (!in_array('security', $tags, true)) {
                $properties['tags'][] = 'security';
            }
        }

        $descriptor = [
            'id'               => $id,
            'name'             => $this->toPascalName($name !== '' ? $name : $id),
            'shortDescription' => ['text' => $this->truncate($short, 1000)],
            'fullDescription'  => ['text' => $full !== '' ? $full : $short],
            'defaultConfiguration' => ['level' => $this->mapSeverityToSarifLevel($severity)],
            'helpUri'          => self::HELP_BASE_URI,
            'properties'       => $properties,
        ];
        if ($help !== null && $help !== '') {
            $descriptor['help'] = ['text' => $help];
        }
        return $descriptor;
    }

    /** @param array<string,int> $ruleIndex */
    private function buildResults(array $violations, string $root, string $sourceBase, array $ruleIndex): array
    {
        $results     = [];
        $occurrences = [];

        foreach ($violations as $v) {
            $ruleId   = $this->getRuleId($v);
            $severity = $this->normalizeSeverity($v);
            [$uri, $inRoot] = $this->relativeUri($this->getFilePath($v), $root);
            $line     = max(1, (int) $this->field($v, ['line'], 'getLine', 1));
            $column   = (int) $this->field($v, ['column'], 'getColumn', 0);

            $message = trim((string) $this->field($v, ['message', 'description'], 'getMessage', ''));
            if ($message === '') {
                $message = $ruleId;
            }
            $fix = $this->field($v, ['fix', 'suggestion', 'recommendation'], 'getSuggestion', null);
            if (is_string($fix) && trim($fix) !== '' && !str_contains($message, trim($fix))) {
                $message .= ' Suggestion: ' . trim($fix);
            }

            $region = ['startLine' => $line];
            if ($column > 0) {
                $region['startColumn'] = $column;
            }

            $artifact = ['uri' => $uri];
            if ($inRoot) {
                $artifact['uriBaseId'] = 'SRCROOT';
            }

            // Fingerprint: rule + path + normalised text of the flagged line (+ occurrence index
            // for identical lines). Stable when code above the issue moves.
            $lineText = $inRoot && $uri !== '' ? $this->readLine($sourceBase . '/' . $uri, $line) : null;
            $basis    = $ruleId . '|' . $uri . '|' . ($lineText !== null ? $this->normalizeLine($lineText) : 'L' . $line);
            $occ      = $occurrences[$basis] = ($occurrences[$basis] ?? 0) + 1;

            $result = [
                'ruleId'  => $ruleId,
                'level'   => $this->mapSeverityToSarifLevel($severity),
                'message' => ['text' => $message],
                'locations' => [
                    ['physicalLocation' => ['artifactLocation' => $artifact, 'region' => $region]],
                ],
                'partialFingerprints' => [
                    self::FINGERPRINT_KEY => hash('sha256', $basis) . ':' . $occ,
                ],
                'properties' => [
                    'category' => $this->getCategory($v),
                    'severity' => $severity,
                ],
            ];
            if (isset($ruleIndex[$ruleId])) {
                $result['ruleIndex'] = $ruleIndex[$ruleId];
            }

            $results[] = $result;
        }

        return $results;
    }

    // ── paths ──────────────────────────────────────────────────────────────────

    private function normalizeRoot(string $path): string
    {
        $real = realpath($path);
        return rtrim(str_replace('\\', '/', $real !== false ? $real : $path), '/');
    }

    /** @return array{0:string,1:bool} [uri, isRelativeToRoot] */
    private function relativeUri(string $file, string $root): array
    {
        if ($file === '') {
            return ['', false];
        }
        $file = str_replace('\\', '/', $file);

        if (!$this->isAbsolute($file)) {
            return [$this->encodePath(ltrim(preg_replace('#^\./#', '', $file), '/')), true];
        }

        $real = realpath($file);
        $abs  = str_replace('\\', '/', $real !== false ? $real : $file);
        foreach ([$abs, $file] as $candidate) {
            if ($root !== '' && str_starts_with($candidate, $root . '/')) {
                return [$this->encodePath(substr($candidate, strlen($root) + 1)), true];
            }
        }
        return [$this->toFileUri($abs), false];
    }

    private function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || (bool) preg_match('#^[A-Za-z]:/#', $path);
    }

    /** file:///abs/path  (POSIX)  or  file:///C:/abs/path  (Windows) */
    private function toFileUri(string $absPath): string
    {
        $absPath = str_replace('\\', '/', $absPath);
        return 'file://' . (str_starts_with($absPath, '/') ? '' : '/') . $this->encodePath($absPath);
    }

    private function encodePath(string $path): string
    {
        return implode('/', array_map(
            static fn (string $seg): string => preg_match('/^[A-Za-z]:$/', $seg) ? $seg : rawurlencode($seg),
            explode('/', $path)
        ));
    }

    private function readLine(string $file, int $line): ?string
    {
        if (!array_key_exists($file, $this->lineCache)) {
            $lines = is_file($file) && is_readable($file) ? @file($file, FILE_IGNORE_NEW_LINES) : false;
            $this->lineCache[$file] = $lines === false ? [] : $lines;
        }
        return $this->lineCache[$file][$line - 1] ?? null;
    }

    private function normalizeLine(string $text): string
    {
        return (string) preg_replace('/\s+/', ' ', trim($text));
    }

    // ── field access ───────────────────────────────────────────────────────────

    /** @param string[] $keys */
    private function field(mixed $v, array $keys, ?string $getter, mixed $default): mixed
    {
        if (is_array($v)) {
            foreach ($keys as $k) {
                if (isset($v[$k]) && $v[$k] !== '') {
                    return $v[$k];
                }
            }
            return $default;
        }
        if (is_object($v) && $getter !== null && method_exists($v, $getter)) {
            return $v->$getter() ?? $default;
        }
        return $default;
    }

    private function getFilePath(mixed $v): string
    {
        return (string) $this->field($v, ['file', 'file_path', 'path'], 'getFilePath', '');
    }

    private function getRuleId(mixed $v): string
    {
        if (is_array($v)) {
            $id = $v['rule_id'] ?? $v['rule'] ?? $v['check'] ?? $v['type'] ?? null;
            return is_string($id) && $id !== '' ? $id : 'ACQC-GENERIC';
        }
        foreach (['getRuleId', 'getRule', 'getType'] as $m) {
            if (is_object($v) && method_exists($v, $m) && is_string($v->$m()) && $v->$m() !== '') {
                return $v->$m();
            }
        }
        return 'ACQC-GENERIC';
    }

    private function getRuleName(mixed $v): string
    {
        return (string) $this->field($v, ['rule_name', 'type', 'check', 'rule_id'], 'getRuleName', '');
    }

    private function getRuleDescription(mixed $v): string
    {
        return (string) $this->field($v, ['rule_description', 'description', 'message'], 'getDescription', '');
    }

    private function getCategory(mixed $v): string
    {
        return (string) $this->field($v, ['category'], 'getCategory', 'general');
    }

    private function normalizeSeverity(mixed $v): string
    {
        $raw = strtolower((string) $this->field($v, ['severity'], 'getSeverity', ''));
        $map = [
            'blocker' => 'blocker', 'critical' => 'critical', 'high' => 'critical',
            'error' => 'error', 'major' => 'error', 'medium' => 'warning',
            'warning' => 'warning', 'low' => 'info',
            'info' => 'info', 'minor' => 'minor', 'notice' => 'info',
            '6' => 'blocker', '5' => 'critical', '4' => 'error',
            '3' => 'warning', '2' => 'info', '1' => 'minor',
        ];
        return $map[$raw] ?? 'warning';
    }

    // ── mappings ───────────────────────────────────────────────────────────────

    private function mapSeverityToSarifLevel(string $severity): string
    {
        return match ($severity) {
            'blocker', 'critical', 'error' => 'error',
            'info', 'minor'                => 'note',
            default                        => 'warning',
        };
    }

    private function mapSeverityToProblemSeverity(string $severity): string
    {
        return match ($severity) {
            'blocker', 'critical', 'error' => 'error',
            'info', 'minor'                => 'recommendation',
            default                        => 'warning',
        };
    }

    /** @param string[] $tags */
    private function isSecurity(array $tags): bool
    {
        foreach ($tags as $t) {
            if (stripos($t, 'security') !== false) {
                return true;
            }
        }
        return false;
    }

    private function toPascalName(string $name): string
    {
        $parts = preg_split('/[^A-Za-z0-9]+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [$name];
        return implode('', array_map(static fn (string $p): string => ucfirst($p), $parts));
    }

    private function truncate(string $text, int $max): string
    {
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1) . '…' : $text;
    }

    /**
     * Merge violation-derived rule stubs with richer definitions from the registry.
     * Registry entries overwrite stubs for matching IDs; additional registry rules are appended.
     */
    private function mergeRegistryRules(array $rules, string $profile): array
    {
        if ($this->registry === null) {
            return $rules;
        }

        foreach ($this->registry->forProfile($profile) as $def) {
            /** @var RuleDefinition $def */
            $rules[$def->id] = $this->ruleDescriptor(
                $def->id,
                $def->name,
                $def->title,
                $def->description,
                $def->recommendation ?? '',
                $this->normalizeSeverity(['severity' => $def->severity]),
                array_merge($def->tags, [$def->category]),
                match ($def->tier) { 1 => 'high', 2 => 'medium', default => 'low' }
            );
        }

        return $rules;
    }
}

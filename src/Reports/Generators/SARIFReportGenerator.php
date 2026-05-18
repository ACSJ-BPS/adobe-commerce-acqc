<?php

declare(strict_types=1);

namespace ACQC\Reports\Generators;

use ACQC\Core\AI\PredictiveAnalytics;
use ACQC\Core\Config\ConfigManager;
use ACQC\Core\Rules\RuleDefinition;
use ACQC\Core\Rules\RuleRegistry;
use Psr\Log\LoggerInterface;

/**
 * Generates SARIF 2.1.0 output for integration with GitHub Code Scanning,
 * Azure DevOps, VS Code Problems panel, and other SARIF consumers.
 */
class SARIFReportGenerator extends AbstractReportGenerator
{
    public function __construct(
        ?ConfigManager $configManager = null,
        ?LoggerInterface $logger = null,
        ?PredictiveAnalytics $predictiveAnalytics = null,
        private readonly ?RuleRegistry $registry = null,
    ) {
        parent::__construct($configManager, $logger, $predictiveAnalytics);
    }

    private const SARIF_VERSION = '2.1.0';
    private const SARIF_SCHEMA = 'https://raw.githubusercontent.com/oasis-tcs/sarif-spec/master/Schemata/sarif-schema-2.1.0.json';

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
        return 'application/json';
    }

    public function generate(array $data, array $options = []): ReportResult
    {
        try {
            $raw = $data['violations'] ?? $data['issues'] ?? [];
            $violations = $this->flattenViolations($raw);
            $projectPath = $options['project_path'] ?? $data['project_path'] ?? getcwd();
            $sarifJson = $this->buildSarif($data, $violations, $projectPath);
            $filename = 'acqc-' . date('Y-m-d_H-i-s') . '.sarif';
            return new ReportResult(true, $sarifJson, $filename, $this->getMimeType());
        } catch (\Exception $e) {
            return new ReportResult(false, '', '', '', [], $e->getMessage());
        }
    }

    private function flattenViolations(array $raw): array
    {
        // Handle nested structure: {total_count, by_severity, items:[...]}
        if (isset($raw['items']) && is_array($raw['items'])) {
            return array_filter($raw['items'], fn($v) => is_array($v) || is_object($v));
        }
        // Handle flat list — filter out non-violation scalars
        return array_values(array_filter($raw, fn($v) => is_array($v) || is_object($v)));
    }

    private function buildSarif(array $data, array $violations, string $projectPath): string
    {
        $rules   = $this->extractRules($violations);
        $rules   = $this->mergeRegistryRules($rules, $data['profile'] ?? 'developer');
        $results = $this->buildResults($violations, $projectPath);

        $sarif = [
            '$schema' => self::SARIF_SCHEMA,
            'version' => self::SARIF_VERSION,
            'runs' => [
                [
                    'tool' => [
                        'driver' => [
                            'name' => 'ACQC',
                            'fullName' => 'Adobe Commerce Quality Control',
                            'version' => \ACQC\Core\Application::VERSION,
                            'informationUri' => 'https://github.com/adobe-commerce-quality/acqc',
                            'rules' => array_values($rules),
                        ],
                    ],
                    'originalUriBaseIds' => [
                        'SRCROOT' => ['uri' => 'file:///' . rtrim(str_replace('\\', '/', $projectPath), '/') . '/'],
                    ],
                    'results' => $results,
                    'invocations' => [
                        [
                            'executionSuccessful' => true,
                            'commandLine' => 'acqc report',
                            'workingDirectory' => ['uri' => 'file:///' . rtrim(str_replace('\\', '/', $projectPath), '/') . '/'],
                        ],
                    ],
                    'properties' => [
                        'score' => $data['score'] ?? null,
                        'filesAnalyzed' => $data['files_analyzed'] ?? 0,
                        'generatedAt' => date('c'),
                    ],
                ],
            ],
        ];

        return json_encode($sarif, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function extractRules(array $violations): array
    {
        $rules = [];
        foreach ($violations as $v) {
            $ruleId = $this->getRuleId($v);
            if (isset($rules[$ruleId])) continue;

            $severity = $this->normalizeSeverity($v);
            $rules[$ruleId] = [
                'id' => $ruleId,
                'name' => $this->getRuleName($v),
                'shortDescription' => ['text' => $this->getRuleDescription($v)],
                'fullDescription' => ['text' => $this->getRuleDescription($v)],
                'defaultConfiguration' => [
                    'level' => $this->mapSeverityToSarifLevel($severity),
                ],
                'properties' => [
                    'tags' => [$this->getCategory($v)],
                    'severity' => $severity,
                ],
                'helpUri' => 'https://github.com/adobe-commerce-quality/acqc/wiki/Rules#' . strtolower(str_replace([' ', '_'], '-', $ruleId)),
            ];
        }
        return $rules;
    }

    private function buildResults(array $violations, string $projectPath): array
    {
        $results = [];
        $projectPath = rtrim(str_replace('\\', '/', realpath($projectPath) ?: $projectPath), '/');

        foreach ($violations as $v) {
            $filePath = is_array($v) ? ($v['file'] ?? $v['file_path'] ?? '') : (method_exists($v, 'getFilePath') ? $v->getFilePath() : '');
            $filePath = str_replace('\\', '/', $filePath);

            // Make relative to project root
            if (str_starts_with($filePath, $projectPath)) {
                $relPath = ltrim(substr($filePath, strlen($projectPath)), '/');
            } else {
                $relPath = $filePath;
            }

            $line = (int)(is_array($v) ? ($v['line'] ?? 1) : (method_exists($v, 'getLine') ? $v->getLine() : 1));
            $line = max(1, $line);

            $severity = $this->normalizeSeverity($v);
            $message = is_array($v) ? ($v['message'] ?? $v['description'] ?? '') : (method_exists($v, 'getMessage') ? $v->getMessage() : '');

            $result = [
                'ruleId' => $this->getRuleId($v),
                'level' => $this->mapSeverityToSarifLevel($severity),
                'message' => ['text' => $message],
                'locations' => [
                    [
                        'physicalLocation' => [
                            'artifactLocation' => [
                                'uri' => $relPath,
                                'uriBaseId' => 'SRCROOT',
                            ],
                            'region' => [
                                'startLine' => $line,
                                'startColumn' => (int)(is_array($v) ? ($v['column'] ?? 1) : 1),
                            ],
                        ],
                    ],
                ],
                'properties' => [
                    'category' => $this->getCategory($v),
                    'severity' => $severity,
                ],
            ];

            // Add fix suggestion if available
            $fix = is_array($v) ? ($v['fix'] ?? $v['suggestion'] ?? null) : null;
            if ($fix) {
                $result['fixes'] = [
                    [
                        'description' => ['text' => $fix],
                        'artifactChanges' => [],
                    ],
                ];
            }

            $results[] = $result;
        }

        return $results;
    }

    private function getRuleId($v): string
    {
        if (is_array($v)) {
            return $v['rule_id'] ?? $v['rule'] ?? $v['check'] ?? ('ACQC-' . strtoupper(str_replace([' ', '-'], '_', $v['type'] ?? 'GENERIC')));
        }
        if (method_exists($v, 'getRuleId')) return $v->getRuleId();
        if (method_exists($v, 'getRule')) return $v->getRule();
        return 'ACQC-GENERIC';
    }

    private function getRuleName($v): string
    {
        if (is_array($v)) {
            return $v['rule_name'] ?? $v['type'] ?? $v['check'] ?? 'Generic Rule';
        }
        if (method_exists($v, 'getRuleName')) return $v->getRuleName();
        return 'Generic Rule';
    }

    private function getRuleDescription($v): string
    {
        if (is_array($v)) {
            return $v['description'] ?? $v['message'] ?? '';
        }
        if (method_exists($v, 'getDescription')) return $v->getDescription();
        if (method_exists($v, 'getMessage')) return $v->getMessage();
        return '';
    }

    private function getCategory($v): string
    {
        if (is_array($v)) {
            return $v['category'] ?? $v['type'] ?? 'general';
        }
        if (method_exists($v, 'getCategory')) return $v->getCategory();
        return 'general';
    }

    private function normalizeSeverity($v): string
    {
        $raw = is_array($v) ? ($v['severity'] ?? '') : (method_exists($v, 'getSeverity') ? $v->getSeverity() : '');
        $raw = strtolower((string)$raw);
        $map = [
            'blocker' => 'blocker', 'critical' => 'critical',
            'error' => 'error', 'warning' => 'warning',
            'info' => 'info', 'minor' => 'minor',
            '6' => 'blocker', '5' => 'critical', '4' => 'error',
            '3' => 'warning', '2' => 'info', '1' => 'minor',
        ];
        return $map[$raw] ?? 'warning';
    }

    private function mapSeverityToSarifLevel(string $severity): string
    {
        return match($severity) {
            'blocker', 'critical' => 'error',
            'error' => 'error',
            'warning' => 'warning',
            'info', 'minor' => 'note',
            default => 'warning',
        };
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
            $precision = match ($def->tier) {
                1 => 'high',
                2 => 'medium',
                default => 'low',
            };

            $rules[$def->id] = [
                'id'               => $def->id,
                'name'             => $def->name,
                'shortDescription' => ['text' => $def->title],
                'fullDescription'  => ['text' => $def->description],
                'help'             => ['text' => $def->recommendation ?? ''],
                'defaultConfiguration' => [
                    'level' => $this->mapSeverityToSarifLevel($def->severity),
                ],
                'properties' => [
                    'tags'      => $def->tags,
                    'precision' => $precision,
                    'severity'  => $def->severity,
                ],
                'helpUri' => 'https://github.com/adobe-commerce-quality/acqc/wiki/Rules#' . strtolower($def->id),
            ];
        }

        return $rules;
    }
}

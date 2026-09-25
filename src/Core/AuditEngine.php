<?php

declare(strict_types=1);

namespace ACQC\Core;

use ACQC\Analyzers\AnalyzerInterface;
use ACQC\Analyzers\MagentoSpecificAnalyzer;
use ACQC\Analyzers\SecurityAnalyzer;
use ACQC\Analyzers\PerformanceAnalyzer;
use ACQC\Analyzers\CodeQualityAnalyzer;
use ACQC\Analyzers\AppBuilder\AbqcAnalyzer;
use ACQC\Core\Git\ScopePatterns;
use ACQC\Core\Platform\PlatformDetector;
use ACQC\Core\Platform\PlatformRegistry;
use ACQC\Core\Rules\RuleRegistry;
use ACQC\Core\Rules\CheckerRegistry;
use ACQC\Core\Rules\YamlRuleLoader;
use ACQC\Core\Rules\RuleValidator;
use ACQC\Utils\FileScanner;
use ACQC\Utils\ConfigLoader;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

class AuditEngine
{
    private array $analyzers = [];
    private FileScanner $fileScanner;
    private ConfigLoader $configLoader;
    private CheckerRegistry $checkerRegistry;

    public function __construct()
    {
        $this->fileScanner = new FileScanner();
        $this->configLoader = new ConfigLoader();
        $this->checkerRegistry = new CheckerRegistry();
        $this->initializeAnalyzers();
    }

    public function runAudit(string $projectPath, array $options = []): array
    {
        $startTime = microtime(true);

        // Load configuration
        $config = $this->configLoader->load($options['config'] ?? null);

        // Scan files — or, in explicit-file mode (pre-commit / --files / --diff-from),
        // analyse only the supplied list. An empty list means "nothing to check",
        // never "scan everything".
        $includePatterns = $config['include_patterns'] ?? ConfigLoader::DEFAULT_INCLUDE_PATTERNS;
        $explicitMode    = array_key_exists('files', $options) && is_array($options['files']);

        if ($explicitMode) {
            $files = $this->fileScanner->filterFiles(
                $options['files'],
                (string) ($options['base_dir'] ?? $projectPath),
                $includePatterns,
                $options['exclude'] ?? []
            );
        } else {
            $files = $this->fileScanner->scanProject(
                $projectPath,
                $includePatterns,
                $options['exclude'] ?? []
            );
        }

        $results = [
            'project_path' => $projectPath,
            'scan_time' => date('Y-m-d H:i:s'),
            'files_analyzed' => count($files),
            'issues' => [],
            'metrics' => [],
            'recommendations' => [],
        ];

        // Run legacy PHP analyzers
        foreach ($this->analyzers as $analyzer) {
            if ($this->shouldRunAnalyzer($analyzer, $options)) {
                $analyzerResults = $analyzer->analyze($files, $config);
                $results['issues'] = array_merge($results['issues'], $analyzerResults['issues'] ?? []);
                $results['metrics'] = array_merge($results['metrics'], $analyzerResults['metrics'] ?? []);
                $results['recommendations'] = array_merge($results['recommendations'], $analyzerResults['recommendations'] ?? []);
            }
        }

        // Run YAML rules engine
        $profile = $options['profile'] ?? 'developer';
        $ruleRegistry = $this->buildRuleRegistry($projectPath);
        $yamlIssues = $this->runYamlRules($files, $profile, $ruleRegistry);
        $results['issues'] = array_merge($results['issues'], $yamlIssues);
        $results['rules_evaluated'] = count($ruleRegistry->forProfile($profile));

        // App Builder / Node.js projects: the PHP-oriented analyzers/rules above only
        // ever see incidental .php files (App Builder's runtime is Node.js-only), so
        // fold in abqc's ESLint-backed findings against the real JS/TS source. Same
        // rationale as ReportCommand's abqc integration.
        $abqcScope = $options['changed_relative_paths'] ?? null;
        if (!$explicitMode) {
            if ($this->isAppBuilderProject($projectPath)) {
                $abqcResult = (new AbqcAnalyzer())->analyze([], ['project_path' => $projectPath]);
                $results['issues'] = array_merge($results['issues'], $this->normalizeAbqcIssues($abqcResult['issues'] ?? []));
            }
        } elseif (is_array($abqcScope)) {
            // Scoped gate. App Builder roots may be the repo root or sub-folders (mixed
            // Commerce + App Builder repo). Lint each root that has changed files, in the
            // snapshot (staged mode) or the tree; fail loudly if abqc cannot run; keep only
            // findings in the changed files.
            // Only real App Builder roots (package.json + app.config.yaml / ext.config.yaml);
            // a bare package.json (e.g. Magento's Grunt setup) must not route JS to abqc.
            $roots = ScopePatterns::appBuilderRoots($projectPath);
            $lintBase = rtrim((string) ($options['abqc_path'] ?? $projectPath), '/');
            $byRoot = [];
            foreach ($abqcScope as $rel) {
                $rel = str_replace('\\', '/', (string) $rel);
                if (!ScopePatterns::isAppBuilderRelevant($rel)) {
                    continue;
                }
                $root = ScopePatterns::rootFor($rel, $roots);
                if ($root !== null) {
                    $byRoot[$root][] = $rel;
                }
            }
            $abqcChanged = [];
            foreach ($byRoot as $root => $changed) {
                $lintDir = $root === '' ? $lintBase : $lintBase . '/' . $root;
                $explicit = [];
                foreach ($changed as $rel) {
                    $abs = $lintBase . '/' . $rel;
                    if (ScopePatterns::isJsLike($rel) && is_file($abs)) {
                        $explicit[] = $abs;
                    }
                }
                $abqcIssues = (new AbqcAnalyzer())->analyzeStrict($lintDir, $explicit);
                $results['issues'] = array_merge(
                    $results['issues'],
                    $this->scopeAbqcIssues($this->normalizeAbqcIssues($abqcIssues), $lintDir, (string) $root, $changed, $lintBase),
                    $this->scanYamlSecrets($changed, $lintBase)
                );
                $abqcChanged = array_merge($abqcChanged, $changed);
            }
            if ($abqcChanged !== []) {
                $results['files_analyzed'] = $this->countScopedFiles(
                    $files,
                    (string) ($options['base_dir'] ?? $projectPath),
                    $abqcChanged
                );
            }
        }

        // Calculate summary metrics
        $results['issues_found'] = count($results['issues']);
        $results['critical'] = count(array_filter($results['issues'], fn($issue) => ($issue['severity'] ?? '') === 'critical'));
        $results['errors'] = count(array_filter($results['issues'], fn($issue) => $issue['severity'] === 'error'));
        $results['warnings'] = count(array_filter($results['issues'], fn($issue) => $issue['severity'] === 'warning'));
        $results['score'] = $this->calculateQualityScore($results);
        $results['execution_time'] = round(microtime(true) - $startTime, 2);

        return $results;
    }

    private function initializeAnalyzers(): void
    {
        $this->analyzers = [
            new MagentoSpecificAnalyzer(),
            new SecurityAnalyzer(),
            new PerformanceAnalyzer(),
            new CodeQualityAnalyzer(),
        ];
    }

    private function buildRuleRegistry(string $projectPath): RuleRegistry
    {
        $acqcRoot  = dirname(__DIR__, 2);
        $validator = new RuleValidator();
        $cache     = new FilesystemAdapter('acqc_rules', 3600, sys_get_temp_dir() . '/acqc_cache');

        return new RuleRegistry(new YamlRuleLoader($validator, $projectPath, $acqcRoot, $cache));
    }

    private function runYamlRules(array $files, string $profile, RuleRegistry $ruleRegistry): array
    {
        $issues = [];

        foreach ($files as $filePath) {
            $applicableRules = $ruleRegistry->forFile($filePath, $profile);

            foreach ($applicableRules as $rule) {
                if (!$rule->isActive()) {
                    continue;
                }

                $checkType = $rule->check['type'] ?? 'guidance';
                if (!$this->checkerRegistry->has($checkType)) {
                    continue;
                }

                $violations = $this->checkerRegistry->getChecker($checkType)->check($rule, $filePath);

                foreach ($violations as $violation) {
                    $issues[] = [
                        'rule_id'        => $violation->ruleId,
                        'severity'       => $violation->severity,
                        'file'           => $violation->filePath,
                        'line'           => $violation->lineNumber,
                        'message'        => $violation->message,
                        'recommendation' => $violation->recommendation,
                        'category'       => $rule->category,
                        'source'         => 'yaml_rules',
                    ];
                }
            }
        }

        return $issues;
    }

    private function shouldRunAnalyzer(AnalyzerInterface $analyzer, array $options): bool
    {
        // Add logic to determine if analyzer should run based on options
        return true;
    }

    private function isAppBuilderProject(string $projectPath): bool
    {
        $platforms = array_map(
            static fn ($p) => $p->id,
            (new PlatformDetector(new PlatformRegistry(AcqcPaths::resourceRoot())))->detect($projectPath)
        );

        return array_intersect(['app-builder', 'nodejs'], $platforms) !== [];
    }

    /**
     * Distinct files checked in a scoped run: PHP-side files plus abqc-checked files.
     *
     * @param string[] $phpFiles      absolute paths
     * @param string[] $abqcRelatives repo-relative paths
     */
    private function countScopedFiles(array $phpFiles, string $baseDir, array $abqcRelatives): int
    {
        $baseDir = rtrim($baseDir, '/');
        $seen = [];
        foreach ($phpFiles as $f) {
            $f = (string) $f;
            $seen[str_starts_with($f, $baseDir . '/') ? substr($f, strlen($baseDir) + 1) : $f] = true;
        }
        foreach ($abqcRelatives as $rel) {
            $seen[$rel] = true;
        }
        return count($seen);
    }

    /**
     * Map abqc findings from one App Builder root back to repo-relative paths, keep only
     * those in $changed, and rewrite 'file' as "<lintBase>/<repo-relative>" (the snapshot
     * in staged mode, later remapped; the repo root otherwise).
     *
     * @param string[] $changed repo-relative paths
     */
    private function scopeAbqcIssues(array $issues, string $lintDir, string $root, array $changed, string $lintBase): array
    {
        $prefixes = array_unique(array_filter([rtrim($lintDir, '/'), rtrim((string) realpath($lintDir), '/')]));
        $wanted = array_flip($changed);
        $out = [];
        foreach ($issues as $issue) {
            $file = str_replace('\\', '/', (string) ($issue['file'] ?? ''));
            $relToRoot = null;
            foreach ($prefixes as $p) {
                if (str_starts_with($file, $p . '/')) {
                    $relToRoot = substr($file, strlen($p) + 1);
                    break;
                }
            }
            if ($relToRoot === null && !str_starts_with($file, '/')) {
                $relToRoot = (string) preg_replace('#^\./#', '', $file);
            }
            $repoRel = $relToRoot === null ? null : ($root === '' ? $relToRoot : $root . '/' . $relToRoot);
            if ($repoRel === null || !isset($wanted[$repoRel])) {
                // Fallback: legacy suffix match against the changed list.
                $match = null;
                foreach ($changed as $rel) {
                    if ($file === $rel || str_ends_with($file, '/' . $rel)) {
                        $match = $rel;
                        break;
                    }
                }
                if ($match === null) {
                    continue;
                }
                $repoRel = $match;
            }
            $issue['file'] = $lintBase . '/' . $repoRel;
            $out[] = $issue;
        }
        return $out;
    }

    /** High-confidence credential formats. The matched value is never echoed. */
    private const YAML_SECRET_PATTERNS = [
        'AWS access key ID'  => '/\b(?:AKIA|ASIA)[0-9A-Z]{16}\b/',
        'private key'        => '/-----BEGIN (?:RSA |EC |OPENSSH |DSA )?PRIVATE KEY-----/',
        'GitHub token'       => '/\bgh[pousr]_[A-Za-z0-9]{36,}\b/',
        'Slack token'        => '/\bxox[abposr]-[A-Za-z0-9-]{10,}\b/',
        'Google API key'     => '/\bAIza[0-9A-Za-z_\-]{35}\b/',
    ];

    /**
     * Scoped gate only: abqc lints JS/TS, not App Builder YAML (manifest inputs,
     * app.config.yaml). Flag hard-coded credentials in changed App Builder YAML (QJ-3).
     * .env files are intentionally out of scope (they hold secrets by design).
     *
     * @param string[] $changed repo-relative paths
     */
    private function scanYamlSecrets(array $changed, string $lintBase): array
    {
        $issues = [];
        foreach ($changed as $rel) {
            if (!preg_match('/\.ya?ml$/i', $rel)) {
                continue;
            }
            $abs = $lintBase . '/' . $rel;
            $lines = is_file($abs) ? @file($abs, FILE_IGNORE_NEW_LINES) : false;
            if (!is_array($lines)) {
                continue;
            }
            foreach ($lines as $i => $line) {
                foreach (self::YAML_SECRET_PATTERNS as $label => $re) {
                    if (preg_match($re, $line)) {
                        $issues[] = [
                            'rule'     => 'ACQC:appbuilder/yaml-hardcoded-secret',
                            'rule_id'  => 'ACQC:appbuilder/yaml-hardcoded-secret',
                            'severity' => 'critical',
                            'file'     => $abs,
                            'line'     => $i + 1,
                            'message'  => sprintf('Hard-coded %s in App Builder config; use an input bound to an environment variable ($VAR) instead.', $label),
                        ];
                        break;
                    }
                }
            }
        }
        return $issues;
    }

    /**
     * Keep issues whose file equals, or ends with "/<relative>", one of $relativePaths.
     *
     * @param string[] $relativePaths
     */
    private function filterIssuesToPaths(array $issues, array $relativePaths): array
    {
        return array_values(array_filter($issues, static function (array $issue) use ($relativePaths): bool {
            $file = str_replace('\\', '/', (string) ($issue['file'] ?? ''));
            foreach ($relativePaths as $rel) {
                if ($file === $rel || str_ends_with($file, '/' . $rel)) {
                    return true;
                }
            }
            return false;
        }));
    }

    /** AbqcAnalyzer emits 'rule_id'; the rest of this codebase's issue arrays use 'rule'. */
    private function normalizeAbqcIssues(array $issues): array
    {
        return array_map(static function (array $issue): array {
            if (!isset($issue['rule']) && isset($issue['rule_id'])) {
                $issue['rule'] = $issue['rule_id'];
            }
            return $issue;
        }, $issues);
    }

    private function calculateQualityScore(array $results): int
    {
        $filesAnalyzed = $results['files_analyzed'];
        $errors = $results['errors'];
        $warnings = $results['warnings'];
        
        // Avoid division by zero
        if ($filesAnalyzed === 0) {
            return 0;
        }
        
        // Calculate violations per file
        $errorsPerFile = $errors / $filesAnalyzed;
        $warningsPerFile = $warnings / $filesAnalyzed;
        
        // Base score
        $baseScore = 100;
        
        // More reasonable penalty calculation based on violations per file
        // Penalty scales with severity and density of issues
        $errorPenalty = min(50, $errorsPerFile * 10); // Max 50 points penalty from errors
        $warningPenalty = min(30, $warningsPerFile * 5); // Max 30 points penalty from warnings
        
        $score = max(0, $baseScore - $errorPenalty - $warningPenalty);
        
        return (int)round(min(100, $score));
    }
} 
<?php

declare(strict_types=1);

namespace ACQC\Core;

use ACQC\Analyzers\AnalyzerInterface;
use ACQC\Analyzers\MagentoSpecificAnalyzer;
use ACQC\Analyzers\SecurityAnalyzer;
use ACQC\Analyzers\PerformanceAnalyzer;
use ACQC\Analyzers\CodeQualityAnalyzer;
use ACQC\Analyzers\AppBuilder\AbqcAnalyzer;
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

        // Scan files
        $files = $this->fileScanner->scanProject(
            $projectPath,
            $config['include_patterns'] ?? ['*.php', '*.graphqls', '*.xml'],
            $options['exclude'] ?? []
        );

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
        if ($this->isAppBuilderProject($projectPath)) {
            $abqcResult = (new AbqcAnalyzer())->analyze([], ['project_path' => $projectPath]);
            $results['issues'] = array_merge($results['issues'], $this->normalizeAbqcIssues($abqcResult['issues'] ?? []));
        }

        // Calculate summary metrics
        $results['issues_found'] = count($results['issues']);
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
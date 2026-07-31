<?php

declare(strict_types=1);

namespace ACQC\Analyzers\Version;

use ACQC\Core\Version\MagentoVersion;
use ACQC\Core\Version\PhpVersion;
use ACQC\Core\Rules\Violation;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Comprehensive Upgrade Compatibility Analyzer
 * 
 * Analyzes code for upgrade compatibility from current version to target version.
 * Provides detailed analysis with priority levels and effort estimation.
 */
class UpgradeAnalyzer
{
    private LoggerInterface $logger;
    private array $deprecationPatterns = [];
    private array $breakingChanges = [];
    private array $codeIssues = [];

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
        $this->initializePatterns();
    }

    /**
     * Analyze code for upgrade from current to target version
     */
    public function analyzeUpgrade(
        string $projectPath,
        MagentoVersion $currentVersion,
        MagentoVersion $targetVersion,
        PhpVersion $currentPhp,
        PhpVersion $targetPhp
    ): UpgradeAnalysisResult {
        $this->logger->info('Starting upgrade analysis', [
            'current_magento' => $currentVersion->toString(),
            'target_magento' => $targetVersion->toString(),
            'current_php' => $currentPhp->toString(),
            'target_php' => $targetPhp->toString(),
        ]);

        $result = new UpgradeAnalysisResult(
            $currentVersion,
            $targetVersion,
            $currentPhp,
            $targetPhp
        );

        // 1. Analyze code compatibility
        $codeIssues = $this->analyzeCodeCompatibility(
            $projectPath,
            $currentVersion,
            $targetVersion
        );
        $result->setCodeIssues($codeIssues);

        // 2. Analyze PHP compatibility
        $phpIssues = $this->analyzePhpCompatibility(
            $projectPath,
            $currentPhp,
            $targetPhp
        );
        $result->setPhpIssues($phpIssues);

        // 3. Categorize by priority
        $prioritized = $this->categorizeByPriority($codeIssues, $phpIssues);
        $result->setPrioritizedIssues($prioritized);

        // 4. Calculate granular effort estimation
        $effort = $this->calculateGranularEffortEstimation($projectPath, $prioritized, $currentVersion, $targetVersion);
        $result->setEffortEstimation($effort);

        // 5. Calculate comprehensive risk assessment
        $riskAssessment = $this->calculateRiskAssessment(
            $prioritized,
            $effort,
            $currentVersion,
            $targetVersion
        );
        $result->setRiskAssessment($riskAssessment);

        // 7. Generate enhanced timeline with phases and milestones
        $enhancedTimeline = $this->generateEnhancedTimeline(
            $prioritized,
            $effort,
            $riskAssessment
        );
        $result->setEnhancedTimeline($enhancedTimeline);

        // 8. Generate upgrade roadmap
        $roadmap = $this->generateUpgradeRoadmap(
            $prioritized,
            $currentVersion,
            $targetVersion
        );
        $result->setUpgradeRoadmap($roadmap);

        $this->logger->info('Upgrade analysis completed', [
            'total_issues' => count($codeIssues) + count($phpIssues),
            'critical' => count($prioritized['critical'] ?? []),
            'high' => count($prioritized['high'] ?? []),
            'medium' => count($prioritized['medium'] ?? []),
            'low' => count($prioritized['low'] ?? []),
        ]);

        return $result;
    }

    /**
     * Analyze code compatibility issues
     */
    private function analyzeCodeCompatibility(
        string $projectPath,
        MagentoVersion $currentVersion,
        MagentoVersion $targetVersion
    ): array {
        $issues = [];

        // Get all PHP files with enhanced filtering
        $files = $this->scanPhpFiles($projectPath);
        $totalFiles = count($files);
        
        $this->logger->info('Scanning files for compatibility issues', [
            'file_count' => $totalFiles,
            'project_path' => $projectPath
        ]);

        // Progress tracking
        $processedFiles = 0;
        $filesWithIssues = 0;
        $startTime = microtime(true);
        $lastProgressUpdate = 0;

        foreach ($files as $file) {
            $fileIssues = $this->analyzeFile(
                $file,
                $currentVersion,
                $targetVersion
            );
            
            if (!empty($fileIssues)) {
                $filesWithIssues++;
            }
            
            $issues = array_merge($issues, $fileIssues);
            $processedFiles++;
            
            // Log progress every 10% or every 50 files
            $progress = ($processedFiles / $totalFiles) * 100;
            if ($progress - $lastProgressUpdate >= 10 || $processedFiles % 50 === 0) {
                $elapsed = microtime(true) - $startTime;
                $avgTimePerFile = $elapsed / $processedFiles;
                $estimatedRemaining = ($totalFiles - $processedFiles) * $avgTimePerFile;
                
                $this->logger->info('Scan progress', [
                    'progress' => round($progress, 1) . '%',
                    'files_processed' => $processedFiles,
                    'files_total' => $totalFiles,
                    'files_with_issues' => $filesWithIssues,
                    'issues_found' => count($issues),
                    'estimated_remaining_seconds' => round($estimatedRemaining, 1)
                ]);
                
                $lastProgressUpdate = floor($progress / 10) * 10;
            }
        }

        $totalTime = microtime(true) - $startTime;
        $this->logger->info('Scan completed', [
            'total_files' => $totalFiles,
            'files_with_issues' => $filesWithIssues,
            'total_issues' => count($issues),
            'scan_time_seconds' => round($totalTime, 2),
            'avg_time_per_file_ms' => $totalFiles > 0 ? round(($totalTime / $totalFiles) * 1000, 2) : 0.0
        ]);

        return $issues;
    }

    /**
     * Analyze single file for compatibility
     */
    private function analyzeFile(
        string $filePath,
        MagentoVersion $currentVersion,
        MagentoVersion $targetVersion
    ): array {
        $issues = [];
        
        if (!file_exists($filePath)) {
            return $issues;
        }

        $content = file_get_contents($filePath);
        $lines = explode("\n", $content);

        // Check each pattern
        foreach ($this->deprecationPatterns as $pattern => $info) {
            if ($this->isPatternApplicable($info, $currentVersion, $targetVersion)) {
                $matches = $this->findPatternInFile($content, $lines, $pattern, $filePath);
                foreach ($matches as $match) {
                    $issues[] = $this->createUpgradeIssue(
                        $filePath,
                        $match['line'],
                        $match['code'],
                        $pattern,
                        $info,
                        $currentVersion,
                        $targetVersion
                    );
                }
            }
        }

        return $issues;
    }

    /**
     * Find pattern matches in file
     */
    private function findPatternInFile(
        string $content,
        array $lines,
        string $pattern,
        string $filePath
    ): array {
        $matches = [];
        
        foreach ($lines as $lineNum => $lineContent) {
            // Use ~ as delimiter to avoid conflicts with / in patterns
            if (@preg_match("~$pattern~", $lineContent)) {
                $matches[] = [
                    'line' => $lineNum + 1,
                    'code' => trim($lineContent),
                ];
            }
        }

        return $matches;
    }

    /**
     * Check if pattern is applicable for version range
     */
    private function isPatternApplicable(
        array $info,
        MagentoVersion $currentVersion,
        MagentoVersion $targetVersion
    ): bool {
        if (!isset($info['introduced_in'])) {
            return true;
        }

        $introducedVersion = $this->parseVersion($info['introduced_in']);
        return $targetVersion->isGreaterThanOrEqual($introducedVersion);
    }

    /**
     * Analyze PHP compatibility issues
     */
    private function analyzePhpCompatibility(
        string $projectPath,
        PhpVersion $currentPhp,
        PhpVersion $targetPhp
    ): array {
        $issues = [];

        if ($currentPhp->isEqual($targetPhp)) {
            return $issues;
        }

        $files = $this->scanPhpFiles($projectPath);

        foreach ($files as $file) {
            $phpIssues = $this->analyzePhpFile($file, $currentPhp, $targetPhp);
            $issues = array_merge($issues, $phpIssues);
        }

        return $issues;
    }

    /**
     * Analyze file for PHP compatibility
     */
    private function analyzePhpFile(
        string $filePath,
        PhpVersion $currentPhp,
        PhpVersion $targetPhp
    ): array {
        $issues = [];
        
        if (!file_exists($filePath)) {
            return $issues;
        }

        $content = file_get_contents($filePath);
        
        // Check for PHP version-specific issues
        $phpPatterns = $this->getPhpCompatibilityPatterns($currentPhp, $targetPhp);
        
        foreach ($phpPatterns as $pattern => $info) {
            if (preg_match_all("/$pattern/m", $content, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $match) {
                    $lineNum = substr_count(substr($content, 0, $match[1]), "\n") + 1;
                    $issues[] = [
                        'file' => $filePath,
                        'line' => $lineNum,
                        'type' => 'php_compatibility',
                        'pattern' => $pattern,
                        'message' => $info['message'],
                        'severity' => $info['severity'],
                        'fix' => $info['fix'] ?? null,
                    ];
                }
            }
        }

        return $issues;
    }

    /**
     * Get PHP compatibility patterns for version upgrade
     */
    private function getPhpCompatibilityPatterns(
        PhpVersion $currentPhp,
        PhpVersion $targetPhp
    ): array {
        $patterns = [];

        // PHP 8.0+ issues
        if ($currentPhp->isLessThan(new PhpVersion(8, 0)) && 
            $targetPhp->isGreaterThanOrEqual(new PhpVersion(8, 0))) {
            $patterns = array_merge($patterns, [
                'function\s+\w+\s*\([^)]*\)\s*:\s*\w+\s*\{[^}]*return\s+null' => [
                    'message' => 'Nullable return type must be explicit in PHP 8.0+',
                    'severity' => 'critical',
                    'fix' => 'Add ? before return type or use |null',
                ],
                'create_function\s*\(' => [
                    'message' => 'create_function() removed in PHP 8.0',
                    'severity' => 'critical',
                    'fix' => 'Use anonymous functions (closures)',
                ],
            ]);
        }

        // PHP 8.1+ issues
        if ($currentPhp->isLessThan(new PhpVersion(8, 1)) && 
            $targetPhp->isGreaterThanOrEqual(new PhpVersion(8, 1))) {
            $patterns = array_merge($patterns, [
                'implements\s+.*Serializable' => [
                    'message' => 'Serializable interface deprecated in PHP 8.1',
                    'severity' => 'high',
                    'fix' => 'Implement __serialize() and __unserialize()',
                ],
            ]);
        }

        // PHP 8.2+ issues
        if ($currentPhp->isLessThan(new PhpVersion(8, 2)) && 
            $targetPhp->isGreaterThanOrEqual(new PhpVersion(8, 2))) {
            $patterns = array_merge($patterns, [
                '\$this->\w+\s*=' => [
                    'message' => 'Dynamic properties deprecated in PHP 8.2',
                    'severity' => 'medium',
                    'fix' => 'Declare all properties or use #[AllowDynamicProperties]',
                ],
                'utf8_encode|utf8_decode' => [
                    'message' => 'utf8_encode/decode deprecated in PHP 8.2',
                    'severity' => 'medium',
                    'fix' => 'Use mb_convert_encoding()',
                ],
            ]);
        }

        return $patterns;
    }

    /**
     * Categorize issues by priority
     */
    private function categorizeByPriority(array $codeIssues, array $phpIssues): array
    {
        $prioritized = [
            'critical' => [],
            'high' => [],
            'medium' => [],
            'low' => [],
        ];

        $allIssues = array_merge($codeIssues, $phpIssues);

        foreach ($allIssues as $issue) {
            $priority = $this->determinePriority($issue);
            $prioritized[$priority][] = $issue;
        }

        return $prioritized;
    }

    /**
     * Determine issue priority
     */
    private function determinePriority(array $issue): string
    {
        $severity = $issue['severity'] ?? 'medium';

        // Map severity to priority
        $mapping = [
            'critical' => 'critical',
            'error' => 'high',
            'warning' => 'medium',
            'info' => 'low',
        ];

        return $mapping[$severity] ?? 'medium';
    }

    // ==================== GRANULAR EFFORT ESTIMATION ENGINE ====================

    /**
     * Build a 10-category granular effort estimate driven entirely by scan results.
     */
    private function calculateGranularEffortEstimation(
        string $projectPath,
        array $prioritized,
        MagentoVersion $currentVersion,
        MagentoVersion $targetVersion
    ): array {
        $allIssues    = array_merge(...array_values($prioritized));
        $critCount    = count($prioritized['critical'] ?? []);
        $highCount    = count($prioritized['high'] ?? []);
        $medCount     = count($prioritized['medium'] ?? []);
        $lowCount     = count($prioritized['low'] ?? []);
        $totalIssues  = $critCount + $highCount + $medCount + $lowCount;

        // Version gap factor: 0.25 per minor version, 2.0 per major version
        $versionGap = (($targetVersion->getMajor() - $currentVersion->getMajor()) * 2.0)
            + (abs($targetVersion->getMinor() - $currentVersion->getMinor()) * 0.25)
            + (abs($targetVersion->getPatch() - $currentVersion->getPatch()) * 0.05);
        $versionGap  = max(0.1, $versionGap);

        // Classify issues by type
        $byType = $this->classifyIssuesByType($allIssues);

        // Scan modules and files
        $modules       = $this->extractDistinctModules($allIssues, $projectPath);
        $moduleCount   = max(1, count($modules));
        $pluginCount   = $byType['plugin'] ?? 0;
        $prefCount     = $byType['preference'] ?? 0;
        $observerCount = $byType['observer'] ?? 0;
        $graphqlCount  = $byType['graphql'] ?? 0;
        $dbCount       = $byType['database'] ?? 0;
        $phpCount      = $byType['php'] ?? 0;
        $themeCount    = $byType['theme'] ?? 0;
        $extensionCount = $this->countThirdPartyExtensions($projectPath);

        // Confidence degrades with complexity
        $confidence = $this->calculateConfidenceLevel($prioritized, $totalIssues);

        // ── 1. Code Remediation ───────────────────────────────────────────────
        $codeRemBase  = ($critCount * 6) + ($highCount * 3) + ($medCount * 1.5) + ($lowCount * 0.5);
        $codeRemIdeal = round($codeRemBase * max(1.0, $versionGap), 1);
        $categories['code_remediation'] = [
            'name'             => 'Code Remediation',
            'description'      => 'Fix deprecated APIs, broken interfaces, anti-patterns, and PHP incompatibilities',
            'complexity'       => $this->complexityLabel($critCount + $highCount),
            'ideal_hours'      => $codeRemIdeal,
            'range_min'        => round($codeRemIdeal * 0.75, 1),
            'range_max'        => round($codeRemIdeal * 1.6, 1),
            'team_dependency'  => 'Backend developers (2–3 senior)',
            'risk_multiplier'  => $critCount > 10 ? 1.5 : ($critCount > 3 ? 1.25 : 1.0),
            'priority_sequence'=> 1,
            'sub_tasks'        => $this->codeRemediationSubtasks($prioritized),
        ];

        // ── 2. Custom Module Upgrade ──────────────────────────────────────────
        $modBase  = ($moduleCount * 8) + ($pluginCount * 2) + ($prefCount * 3) + ($observerCount * 1.5);
        $modIdeal = round($modBase * max(1.0, $versionGap * 0.7), 1);
        $categories['custom_module_upgrade'] = [
            'name'             => 'Custom Module Upgrade',
            'description'      => 'Update module.xml, registration.php, DI wiring, events, plugins, and preferences',
            'complexity'       => $this->complexityLabel($moduleCount),
            'ideal_hours'      => $modIdeal,
            'range_min'        => round($modIdeal * 0.8, 1),
            'range_max'        => round($modIdeal * 1.5, 1),
            'team_dependency'  => 'Backend developers; module owners',
            'risk_multiplier'  => ($pluginCount + $prefCount) > 20 ? 1.4 : 1.1,
            'priority_sequence'=> 2,
            'sub_tasks'        => [
                ['task' => "Module registration & composer.json updates ({$moduleCount} modules)", 'hours' => round($moduleCount * 1.5, 1)],
                ['task' => "Plugin/interceptor review ({$pluginCount} plugins)", 'hours' => round($pluginCount * 2, 1)],
                ['task' => "Preference/override audit ({$prefCount} preferences)", 'hours' => round($prefCount * 3, 1)],
                ['task' => "Observer/event wiring ({$observerCount} observers)", 'hours' => round($observerCount * 1.5, 1)],
            ],
        ];

        // ── 3. Third-Party Extension Fixes ───────────────────────────────────
        $extBase  = $extensionCount * 3;
        $extIdeal = round($extBase * max(1.0, $versionGap * 0.5), 1);
        $categories['third_party_extensions'] = [
            'name'             => 'Third-Party Extension Fixes',
            'description'      => 'Verify compatibility, apply patches, or replace end-of-life extensions',
            'complexity'       => $this->complexityLabel($extensionCount),
            'ideal_hours'      => $extIdeal,
            'range_min'        => round($extIdeal * 0.6, 1),
            'range_max'        => round($extIdeal * 2.5, 1),
            'team_dependency'  => 'Backend developers; vendor coordination',
            'risk_multiplier'  => $extensionCount > 15 ? 1.6 : ($extensionCount > 5 ? 1.3 : 1.0),
            'priority_sequence'=> 3,
            'sub_tasks'        => [
                ['task' => "Compatibility matrix review ({$extensionCount} extensions)", 'hours' => round($extensionCount * 0.5, 1)],
                ['task' => 'Patch / upgrade per incompatible extension', 'hours' => round($extensionCount * 2, 1)],
                ['task' => 'End-of-life extension replacement research', 'hours' => round($extensionCount * 0.5, 1)],
            ],
        ];

        // ── 4. Theme / Frontend Upgrade ───────────────────────────────────────
        $themeBase  = max(8, $themeCount * 2 + 12);
        $themeIdeal = round($themeBase * max(1.0, $versionGap * 0.4), 1);
        $categories['theme_frontend'] = [
            'name'             => 'Theme / Frontend Upgrade',
            'description'      => 'Update Less/CSS variables, template overrides, layout XML, and JS components',
            'complexity'       => $this->complexityLabel($themeCount),
            'ideal_hours'      => $themeIdeal,
            'range_min'        => round($themeIdeal * 0.7, 1),
            'range_max'        => round($themeIdeal * 1.8, 1),
            'team_dependency'  => 'Frontend developer; UI/UX review',
            'risk_multiplier'  => $themeCount > 10 ? 1.4 : 1.1,
            'priority_sequence'=> 7,
            'sub_tasks'        => [
                ['task' => 'Layout XML compatibility review', 'hours' => round($themeIdeal * 0.25, 1)],
                ['task' => 'Less/CSS variable updates', 'hours' => round($themeIdeal * 0.30, 1)],
                ['task' => 'JavaScript / RequireJS compatibility', 'hours' => round($themeIdeal * 0.30, 1)],
                ['task' => 'Template override audit', 'hours' => round($themeIdeal * 0.15, 1)],
            ],
        ];

        // ── 5. GraphQL / API Compatibility ───────────────────────────────────
        $gqlBase  = max(0, ($graphqlCount * 2) + 4);
        $gqlIdeal = round($gqlBase * max(1.0, $versionGap * 0.3), 1);
        $categories['graphql_api'] = [
            'name'             => 'GraphQL / API Compatibility',
            'description'      => 'Resolve breaking schema changes, missing resolvers, REST endpoint renames',
            'complexity'       => $this->complexityLabel($graphqlCount),
            'ideal_hours'      => $gqlIdeal,
            'range_min'        => round($gqlIdeal * 0.7, 1),
            'range_max'        => round($gqlIdeal * 1.6, 1),
            'team_dependency'  => 'Backend developer; API consumer teams',
            'risk_multiplier'  => $graphqlCount > 5 ? 1.3 : 1.0,
            'priority_sequence'=> 4,
            'sub_tasks'        => [
                ['task' => "GraphQL schema breaking changes ({$graphqlCount} issues)", 'hours' => round($graphqlCount * 2, 1)],
                ['task' => 'REST endpoint compatibility check', 'hours' => 4],
                ['task' => 'API integration test updates', 'hours' => round($gqlIdeal * 0.2, 1)],
            ],
        ];

        // ── 6. Database / Schema Changes ──────────────────────────────────────
        $dbBase  = max(4, $dbCount * 4 + 8);
        $dbIdeal = round($dbBase, 1);
        $categories['database_schema'] = [
            'name'             => 'Database / Schema Changes',
            'description'      => 'Convert setup scripts to declarative schema, update column types, add indexes',
            'complexity'       => $this->complexityLabel($dbCount),
            'ideal_hours'      => $dbIdeal,
            'range_min'        => round($dbIdeal * 0.75, 1),
            'range_max'        => round($dbIdeal * 1.5, 1),
            'team_dependency'  => 'Backend developer; DBA review',
            'risk_multiplier'  => $dbCount > 5 ? 1.3 : 1.0,
            'priority_sequence'=> 5,
            'sub_tasks'        => [
                ['task' => 'Setup script → declarative schema conversion', 'hours' => round($dbIdeal * 0.5, 1)],
                ['task' => 'Column type / index audit', 'hours' => round($dbIdeal * 0.3, 1)],
                ['task' => 'Data patch migration', 'hours' => round($dbIdeal * 0.2, 1)],
            ],
        ];

        // ── 7. Infrastructure / Environment Readiness ─────────────────────────
        $infraIdeal = round(8 + ($versionGap * 6) + ($phpCount * 0.5), 1);
        $categories['infrastructure'] = [
            'name'             => 'Infrastructure / Environment Readiness',
            'description'      => 'PHP version upgrade, composer updates, web server config, cloud/on-prem changes',
            'complexity'       => $phpCount > 10 ? 'high' : 'medium',
            'ideal_hours'      => $infraIdeal,
            'range_min'        => round($infraIdeal * 0.75, 1),
            'range_max'        => round($infraIdeal * 1.5, 1),
            'team_dependency'  => 'DevOps / infrastructure team',
            'risk_multiplier'  => 1.2,
            'priority_sequence'=> 6,
            'sub_tasks'        => [
                ['task' => 'PHP version upgrade & compatibility testing', 'hours' => round($infraIdeal * 0.35, 1)],
                ['task' => 'Composer dependency update & conflict resolution', 'hours' => round($infraIdeal * 0.30, 1)],
                ['task' => 'Web server / Nginx / Varnish config review', 'hours' => round($infraIdeal * 0.20, 1)],
                ['task' => 'Elasticsearch / OpenSearch version alignment', 'hours' => round($infraIdeal * 0.15, 1)],
            ],
        ];

        // ── 8. Testing Effort ─────────────────────────────────────────────────
        $devTotal  = ($codeRemIdeal + $modIdeal + $extIdeal + $gqlIdeal + $dbIdeal);
        $testIdeal = round(max(24, $devTotal * 0.45), 1);
        $categories['testing'] = [
            'name'             => 'Testing Effort',
            'description'      => 'Functional, regression, performance, and UAT testing across all changed areas',
            'complexity'       => $this->complexityLabel($totalIssues),
            'ideal_hours'      => $testIdeal,
            'range_min'        => round($testIdeal * 0.8, 1),
            'range_max'        => round($testIdeal * 1.4, 1),
            'team_dependency'  => 'QA team; business analysts for UAT',
            'risk_multiplier'  => $critCount > 5 ? 1.4 : 1.1,
            'priority_sequence'=> 8,
            'sub_tasks'        => [
                ['task' => 'Regression test suite execution', 'hours' => round($testIdeal * 0.30, 1)],
                ['task' => 'Functional / smoke testing', 'hours' => round($testIdeal * 0.25, 1)],
                ['task' => 'Performance / load testing', 'hours' => round($testIdeal * 0.20, 1)],
                ['task' => 'UAT with business stakeholders', 'hours' => round($testIdeal * 0.15, 1)],
                ['task' => 'Security / penetration scan', 'hours' => round($testIdeal * 0.10, 1)],
            ],
        ];

        // ── 9. Deployment / Release Effort ────────────────────────────────────
        $deployIdeal = round(max(16, 16 + ($versionGap * 4) + ($critCount * 0.5)), 1);
        $categories['deployment_release'] = [
            'name'             => 'Deployment / Release Effort',
            'description'      => 'Staging deployment, go-live runbook, cutover execution, and post-release monitoring',
            'complexity'       => 'medium',
            'ideal_hours'      => $deployIdeal,
            'range_min'        => round($deployIdeal * 0.75, 1),
            'range_max'        => round($deployIdeal * 1.5, 1),
            'team_dependency'  => 'DevOps; release manager; backend lead',
            'risk_multiplier'  => 1.2,
            'priority_sequence'=> 9,
            'sub_tasks'        => [
                ['task' => 'Staging environment deployment & validation', 'hours' => round($deployIdeal * 0.35, 1)],
                ['task' => 'Go-live runbook & communication plan', 'hours' => round($deployIdeal * 0.25, 1)],
                ['task' => 'Production cutover execution', 'hours' => round($deployIdeal * 0.25, 1)],
                ['task' => 'Post-release monitoring (48h hypercare)', 'hours' => round($deployIdeal * 0.15, 1)],
            ],
        ];

        // ── 10. Rollback Preparedness ─────────────────────────────────────────
        $rollbackIdeal = round(max(8, 8 + ($critCount * 0.5)), 1);
        $categories['rollback_preparedness'] = [
            'name'             => 'Rollback Preparedness',
            'description'      => 'Database backups, rollback scripts, blue-green or canary strategy documentation',
            'complexity'       => $critCount > 10 ? 'high' : 'medium',
            'ideal_hours'      => $rollbackIdeal,
            'range_min'        => round($rollbackIdeal * 0.75, 1),
            'range_max'        => round($rollbackIdeal * 1.5, 1),
            'team_dependency'  => 'DevOps; DBA',
            'risk_multiplier'  => 1.1,
            'priority_sequence'=> 10,
            'sub_tasks'        => [
                ['task' => 'Full DB snapshot & validation', 'hours' => round($rollbackIdeal * 0.40, 1)],
                ['task' => 'Rollback runbook documentation', 'hours' => round($rollbackIdeal * 0.35, 1)],
                ['task' => 'Rollback test drill on staging', 'hours' => round($rollbackIdeal * 0.25, 1)],
            ],
        ];

        // ── Totals ────────────────────────────────────────────────────────────
        $idealTotal = array_sum(array_column($categories, 'ideal_hours'));
        $minTotal   = array_sum(array_column($categories, 'range_min'));
        $maxTotal   = array_sum(array_column($categories, 'range_max'));
        $productiveHoursPerDay = 6;

        // Sort by priority_sequence for display
        uasort($categories, fn($a, $b) => $a['priority_sequence'] - $b['priority_sequence']);

        return [
            'categories'        => $categories,
            'totals' => [
                'ideal_hours'      => round($idealTotal, 1),
                'range_min_hours'  => round($minTotal, 1),
                'range_max_hours'  => round($maxTotal, 1),
                'ideal_days'       => (int)ceil($idealTotal / $productiveHoursPerDay),
                'range_days_min'   => (int)ceil($minTotal / $productiveHoursPerDay),
                'range_days_max'   => (int)ceil($maxTotal / $productiveHoursPerDay),
                'ideal_weeks'      => (int)ceil($idealTotal / ($productiveHoursPerDay * 5)),
                'overall_complexity'=> $this->complexityLabel($critCount + $highCount),
                'confidence_level' => $confidence,
            ],
            // Legacy flat keys for backward-compat with console display
            'total_hours'        => round($idealTotal, 1),
            'timeline_days'      => (int)ceil($idealTotal / $productiveHoursPerDay),
            'timeline_weeks'     => (int)ceil($idealTotal / ($productiveHoursPerDay * 5)),
            'confidence_level'   => $confidence,
            'development_hours'  => round($codeRemIdeal + $modIdeal, 1),
            'testing_hours'      => round($testIdeal, 1),
            'review_hours'       => round($idealTotal * 0.10, 1),
            'buffer_hours'       => round($idealTotal * 0.15, 1),
        ];
    }

    private function complexityLabel(int $count): string
    {
        if ($count >= 20) return 'critical';
        if ($count >= 10) return 'high';
        if ($count >= 3)  return 'medium';
        return 'low';
    }

    private function classifyIssuesByType(array $issues): array
    {
        $counts = [];
        foreach ($issues as $issue) {
            $type = $issue['type'] ?? 'unknown';
            if (str_contains($type, 'graphql') || str_contains($issue['pattern'] ?? '', 'graphql')) {
                $counts['graphql'] = ($counts['graphql'] ?? 0) + 1;
            } elseif (str_contains($type, 'database') || str_contains($issue['pattern'] ?? '', 'schema')) {
                $counts['database'] = ($counts['database'] ?? 0) + 1;
            } elseif (str_contains($type, 'php') || str_contains($type, 'php_compatibility')) {
                $counts['php'] = ($counts['php'] ?? 0) + 1;
            } elseif (str_contains($issue['message'] ?? '', 'plugin') || str_contains($issue['message'] ?? '', 'Plugin')) {
                $counts['plugin'] = ($counts['plugin'] ?? 0) + 1;
            } elseif (str_contains($issue['message'] ?? '', 'preference') || str_contains($issue['message'] ?? '', 'Preference')) {
                $counts['preference'] = ($counts['preference'] ?? 0) + 1;
            } elseif (str_contains($issue['message'] ?? '', 'observer') || str_contains($issue['message'] ?? '', 'Observer')) {
                $counts['observer'] = ($counts['observer'] ?? 0) + 1;
            } elseif (str_contains($issue['file'] ?? '', 'view') || str_contains($issue['file'] ?? '', 'web') || str_contains($issue['file'] ?? '', 'less')) {
                $counts['theme'] = ($counts['theme'] ?? 0) + 1;
            } else {
                $counts['other'] = ($counts['other'] ?? 0) + 1;
            }
        }
        return $counts;
    }

    private function extractDistinctModules(array $issues, string $projectPath): array
    {
        $modules = [];
        foreach ($issues as $issue) {
            $file = $issue['file'] ?? '';
            // Extract Vendor/Module from path
            if (preg_match('#(?:app/code|app[\\\\/]code)/([A-Z][^/\\\\]+)[/\\\\]([A-Z][^/\\\\]+)#', $file, $m)) {
                $key = $m[1] . '_' . $m[2];
                $modules[$key] = ['vendor' => $m[1], 'module' => $m[2]];
            }
        }
        if (empty($modules)) {
            // Fall back to directory count
            $dirs = glob($projectPath . '/*/*/registration.php') ?: [];
            foreach ($dirs as $d) {
                $parts = explode(DIRECTORY_SEPARATOR, $d);
                $n = count($parts);
                if ($n >= 3) {
                    $modules[$parts[$n-3] . '_' . $parts[$n-2]] = ['vendor' => $parts[$n-3], 'module' => $parts[$n-2]];
                }
            }
        }
        return $modules;
    }

    private function countThirdPartyExtensions(string $projectPath): int
    {
        $composerFile = $projectPath . '/composer.json';
        // Walk up for composer.json in case $projectPath is app/code
        for ($i = 0; $i < 4; $i++) {
            if (file_exists($composerFile)) {
                $data = json_decode(file_get_contents($composerFile), true);
                $count = 0;
                foreach (array_keys($data['require'] ?? []) as $pkg) {
                    if (str_contains($pkg, 'magento/') || str_contains($pkg, 'php') || in_array($pkg, ['ext-*'])) continue;
                    if (str_contains($pkg, '/')) $count++;
                }
                return max(0, $count);
            }
            $composerFile = dirname(dirname($composerFile)) . '/composer.json';
        }
        return 0;
    }

    private function codeRemediationSubtasks(array $prioritized): array
    {
        $tasks = [];
        $typeGroups = [];
        foreach ($prioritized as $prio => $issues) {
            foreach ($issues as $issue) {
                $t = $issue['type'] ?? 'other';
                $typeGroups[$t][$prio] = ($typeGroups[$t][$prio] ?? 0) + 1;
            }
        }
        $hoursPerType = ['deprecated' => 1.5, 'anti-pattern' => 2.0, 'breaking-change' => 4.0, 'php_compatibility' => 2.0];
        foreach ($typeGroups as $type => $prioMap) {
            $count = array_sum($prioMap);
            $hrs   = round($count * ($hoursPerType[$type] ?? 1.5), 1);
            $tasks[] = ['task' => ucfirst(str_replace('_', ' ', $type)) . " fixes ({$count} issues)", 'hours' => $hrs];
        }
        return $tasks ?: [['task' => 'General code compatibility fixes', 'hours' => 8.0]];
    }

    private function calculateConfidenceLevel(array $prioritizedIssues, int $totalIssues): string
    {
        $confidence  = 95;
        if ($totalIssues === 0) return '88%';
        $criticalCount = count($prioritizedIssues['critical'] ?? []);
        $highCount     = count($prioritizedIssues['high'] ?? []);
        $volumePenalty = min(10, intdiv($totalIssues, 20) * 2);
        $critRatio     = $totalIssues > 0 ? $criticalCount / $totalIssues : 0;
        $critPenalty   = (int)round($critRatio * 20);
        $highRatio     = $totalIssues > 0 ? $highCount / $totalIssues : 0;
        $highPenalty   = (int)round($highRatio * 10);
        return max(55, $confidence - $volumePenalty - $critPenalty - $highPenalty) . '%';
    }

    // ========================================================================

    /**
     * Calculate comprehensive risk assessment
     */
    private function calculateRiskAssessment(
        array $prioritizedIssues,
        array $effort,
        MagentoVersion $currentVersion,
        MagentoVersion $targetVersion
    ): array {
        // Calculate technical risk
        $technicalRisk = $this->calculateTechnicalRisk($prioritizedIssues, $effort);
        
        // Calculate business risk
        $businessRisk = $this->calculateBusinessRisk($prioritizedIssues, $effort);
        
        // Calculate overall risk score (weighted average)
        // Technical risk: 60%, Business risk: 40%
        $overallScore = ($technicalRisk['score'] * 0.6) + ($businessRisk['score'] * 0.4);
        
        // Determine risk level
        $riskLevel = $this->getRiskLevel($overallScore);
        
        // Generate mitigation strategies
        $mitigationStrategies = $this->generateMitigationStrategies(
            $technicalRisk,
            $businessRisk,
            $prioritizedIssues
        );
        
        // Calculate risk-adjusted timeline
        $riskMultiplier = $this->getRiskTimelineMultiplier($overallScore);
        $baseTimeline = $effort['timeline_weeks'] ?? 0;
        $riskAdjustedTimeline = ceil($baseTimeline * $riskMultiplier);
        
        return [
            'overall_risk_score' => round($overallScore, 1),
            'risk_level' => $riskLevel,
            'technical_risk' => $technicalRisk,
            'business_risk' => $businessRisk,
            'risk_factors' => $this->identifyRiskFactors($prioritizedIssues, $effort),
            'mitigation_strategies' => $mitigationStrategies,
            'risk_timeline' => [
                'base_timeline_weeks' => $baseTimeline,
                'risk_multiplier' => round($riskMultiplier, 2),
                'risk_adjusted_weeks' => $riskAdjustedTimeline,
                'additional_buffer_weeks' => $riskAdjustedTimeline - $baseTimeline,
            ],
            'success_probability' => $this->calculateSuccessProbability($overallScore),
            'contingency_recommendations' => $this->getContingencyRecommendations($riskLevel),
        ];
    }

    /**
     * Calculate technical risk score (0-100)
     */
    private function calculateTechnicalRisk(array $prioritizedIssues, array $effort): array
    {
        $score = 0;
        $factors = [];
        
        $criticalCount = count($prioritizedIssues['critical'] ?? []);
        $highCount = count($prioritizedIssues['high'] ?? []);
        $totalIssues = array_sum(array_map('count', $prioritizedIssues));
        
        // Factor 1: Issue Severity (0-30 points)
        $severityScore = min(30, ($criticalCount * 3) + ($highCount * 1.5));
        $score += $severityScore;
        $factors['issue_severity'] = [
            'score' => round($severityScore, 1),
            'description' => "{$criticalCount} critical, {$highCount} high priority issues",
            'impact' => $this->getImpactLevel($severityScore, 30),
        ];
        
        // Factor 2: Code Complexity (0-25 points)
        $complexityFactor = $effort['complexity_factor'] ?? 1.0;
        $complexityScore = min(25, ($complexityFactor - 1.0) * 25);
        $score += $complexityScore;
        $factors['code_complexity'] = [
            'score' => round($complexityScore, 1),
            'description' => "Complexity factor: {$complexityFactor}x",
            'impact' => $this->getImpactLevel($complexityScore, 25),
        ];
        
        // Factor 3: Breaking Changes (0-25 points)
        $breakingChangeCount = $this->countBreakingChanges($prioritizedIssues);
        $breakingScore = min(25, $breakingChangeCount * 2.5);
        $score += $breakingScore;
        $factors['breaking_changes'] = [
            'score' => round($breakingScore, 1),
            'description' => "{$breakingChangeCount} breaking changes detected",
            'impact' => $this->getImpactLevel($breakingScore, 25),
        ];
        
        // Factor 4: Dependencies & Integration (0-20 points)
        $dependencyScore = min(20, $totalIssues * 0.2);
        $score += $dependencyScore;
        $factors['dependencies'] = [
            'score' => round($dependencyScore, 1),
            'description' => "Integration complexity based on {$totalIssues} issues",
            'impact' => $this->getImpactLevel($dependencyScore, 20),
        ];
        
        return [
            'score' => round(min(100, $score), 1),
            'level' => $this->getRiskLevel($score),
            'factors' => $factors,
            'summary' => $this->getTechnicalRiskSummary($score),
        ];
    }

    /**
     * Calculate business risk score (0-100)
     */
    private function calculateBusinessRisk(array $prioritizedIssues, array $effort): array
    {
        $score = 0;
        $factors = [];
        
        $criticalCount = count($prioritizedIssues['critical'] ?? []);
        $highCount     = count($prioritizedIssues['high'] ?? []);
        $totalHours    = (float)($effort['total_hours'] ?? 0);

        // Factor 1: Operational Impact (0-30 points)
        $operationalScore = min(30, $criticalCount * 2.5);
        $score += $operationalScore;
        $factors['operational_impact'] = [
            'score'       => round($operationalScore, 1),
            'description' => "{$criticalCount} issues affecting operations",
            'impact'      => $this->getImpactLevel($operationalScore, 30),
        ];

        // Factor 2: Effort Scope (0-25 points) — replaces cost; based on total effort hours
        $effortRiskScore = 0;
        if ($totalHours < 100) {
            $effortRiskScore = 5;
        } elseif ($totalHours < 300) {
            $effortRiskScore = 15;
        } else {
            $effortRiskScore = 25;
        }
        $score += $effortRiskScore;
        $factors['effort_scope'] = [
            'score'       => round($effortRiskScore, 1),
            'description' => sprintf('%sh total effort required', number_format($totalHours, 0)),
            'impact'      => $this->getImpactLevel($effortRiskScore, 25),
        ];
        
        // Factor 3: Timeline Risk (0-25 points)
        $timelineWeeks = $effort['timeline_weeks'] ?? 0;
        $timelineRiskScore = 0;
        if ($timelineWeeks < 8) {
            $timelineRiskScore = 5;
        } elseif ($timelineWeeks < 16) {
            $timelineRiskScore = 15;
        } else {
            $timelineRiskScore = 25;
        }
        $score += $timelineRiskScore;
        $factors['timeline_risk'] = [
            'score' => round($timelineRiskScore, 1),
            'description' => "{$timelineWeeks} week project duration",
            'impact' => $this->getImpactLevel($timelineRiskScore, 25),
        ];
        
        // Factor 4: Resource Requirements (0-20 points)
        $resourceScore = min(20, ($totalHours / 100) * 2);
        $score += $resourceScore;
        $factors['resource_requirements'] = [
            'score' => round($resourceScore, 1),
            'description' => "{$totalHours} hours of effort required",
            'impact' => $this->getImpactLevel($resourceScore, 20),
        ];
        
        return [
            'score' => round(min(100, $score), 1),
            'level' => $this->getRiskLevel($score),
            'factors' => $factors,
            'summary' => $this->getBusinessRiskSummary($score),
        ];
    }

    /**
     * Get risk level based on score
     */
    private function getRiskLevel(float $score): string
    {
        if ($score >= 75) {
            return 'CRITICAL';
        } elseif ($score >= 50) {
            return 'HIGH';
        } elseif ($score >= 25) {
            return 'MEDIUM';
        } else {
            return 'LOW';
        }
    }

    /**
     * Get impact level for a factor
     */
    private function getImpactLevel(float $score, float $maxScore): string
    {
        $percentage = ($score / $maxScore) * 100;
        if ($percentage >= 75) {
            return 'High';
        } elseif ($percentage >= 50) {
            return 'Medium-High';
        } elseif ($percentage >= 25) {
            return 'Medium';
        } else {
            return 'Low';
        }
    }

    /**
     * Count breaking changes in issues
     */
    private function countBreakingChanges(array $prioritizedIssues): int
    {
        $count = 0;
        foreach ($prioritizedIssues as $issues) {
            foreach ($issues as $issue) {
                if (isset($issue['type']) && $issue['type'] === 'breaking-change') {
                    $count++;
                }
            }
        }
        return $count;
    }

    /**
     * Identify key risk factors
     */
    private function identifyRiskFactors(array $prioritizedIssues, array $effort): array
    {
        $factors = [];
        
        $criticalCount = count($prioritizedIssues['critical'] ?? []);
        if ($criticalCount > 10) {
            $factors[] = [
                'factor' => 'High Critical Issue Count',
                'severity' => 'CRITICAL',
                'description' => "{$criticalCount} critical issues require immediate attention",
            ];
        }
        
        $complexityFactor = $effort['complexity_factor'] ?? 1.0;
        if ($complexityFactor > 2.0) {
            $factors[] = [
                'factor' => 'High Code Complexity',
                'severity' => 'HIGH',
                'description' => "Complexity factor of {$complexityFactor}x increases implementation risk",
            ];
        }
        
        $timelineWeeks = $effort['timeline_weeks'] ?? 0;
        if ($timelineWeeks > 20) {
            $factors[] = [
                'factor' => 'Extended Timeline',
                'severity' => 'MEDIUM',
                'description' => "{$timelineWeeks} week timeline increases scope creep risk",
            ];
        }
        
        return $factors;
    }

    /**
     * Generate mitigation strategies
     */
    private function generateMitigationStrategies(
        array $technicalRisk,
        array $businessRisk,
        array $prioritizedIssues
    ): array {
        $strategies = [];
        
        // Technical mitigation
        if ($technicalRisk['score'] > 50) {
            $strategies[] = [
                'category' => 'Technical',
                'priority' => 'HIGH',
                'strategy' => 'Implement phased rollout approach',
                'details' => 'Break upgrade into smaller, manageable phases to reduce technical risk',
                'timeline_impact' => '+2-4 weeks',
            ];
            
            $strategies[] = [
                'category' => 'Technical',
                'priority' => 'HIGH',
                'strategy' => 'Establish comprehensive testing strategy',
                'details' => 'Unit, integration, and regression testing for all critical paths',
                'timeline_impact' => '+1-2 weeks',
            ];
        }
        
        // Business mitigation
        if ($businessRisk['score'] > 50) {
            $strategies[] = [
                'category' => 'Business',
                'priority' => 'HIGH',
                'strategy' => 'Develop detailed rollback plan',
                'details' => 'Ensure ability to revert quickly if critical issues arise',
                'timeline_impact' => '+1 week',
            ];
            
            $strategies[] = [
                'category' => 'Business',
                'priority' => 'MEDIUM',
                'strategy' => 'Schedule upgrade during low-traffic period',
                'details' => 'Minimize business impact by timing upgrade strategically',
                'timeline_impact' => 'No impact',
            ];
        }
        
        // Critical issue mitigation
        $criticalCount = count($prioritizedIssues['critical'] ?? []);
        if ($criticalCount > 5) {
            $strategies[] = [
                'category' => 'Technical',
                'priority' => 'CRITICAL',
                'strategy' => 'Conduct code review for all critical issues',
                'details' => "Expert review of {$criticalCount} critical issues before implementation",
                'timeline_impact' => '+1-2 weeks',
            ];
        }
        
        return $strategies;
    }

    /**
     * Get risk timeline multiplier
     */
    private function getRiskTimelineMultiplier(float $riskScore): float
    {
        if ($riskScore >= 75) {
            return 1.5; // 50% buffer for critical risk
        } elseif ($riskScore >= 50) {
            return 1.3; // 30% buffer for high risk
        } elseif ($riskScore >= 25) {
            return 1.15; // 15% buffer for medium risk
        } else {
            return 1.05; // 5% buffer for low risk
        }
    }

    /**
     * Calculate success probability
     */
    private function calculateSuccessProbability(float $riskScore): array
    {
        $probability = 100 - ($riskScore * 0.8); // Inverse relationship
        $probability = max(50, min(95, $probability)); // Bound between 50-95%
        
        return [
            'percentage' => round($probability, 1),
            'confidence' => $this->getConfidenceLabel($probability),
            'factors' => $this->getSuccessFactors($probability),
        ];
    }

    /**
     * Get confidence label
     */
    private function getConfidenceLabel(float $probability): string
    {
        if ($probability >= 85) {
            return 'Very High';
        } elseif ($probability >= 75) {
            return 'High';
        } elseif ($probability >= 65) {
            return 'Moderate';
        } else {
            return 'Cautious';
        }
    }

    /**
     * Get success factors
     */
    private function getSuccessFactors(float $probability): array
    {
        if ($probability >= 80) {
            return [
                'Well-defined scope',
                'Manageable complexity',
                'Clear upgrade path',
            ];
        } elseif ($probability >= 70) {
            return [
                'Moderate complexity',
                'Some challenging areas',
                'Requires careful planning',
            ];
        } else {
            return [
                'High complexity',
                'Multiple risk factors',
                'Requires extensive mitigation',
            ];
        }
    }

    /**
     * Get contingency recommendations
     */
    private function getContingencyRecommendations(string $riskLevel): array
    {
        $recommendations = [];
        
        switch ($riskLevel) {
            case 'CRITICAL':
                $recommendations = [
                    'Allocate dedicated QA resources for entire project duration',
                    'Establish 24/7 support team for first 2 weeks post-upgrade',
                    'Prepare complete environment replication for testing',
                    'Consider hiring external Magento experts for critical phases',
                    'Set up automated monitoring and alerting',
                ];
                break;
            case 'HIGH':
                $recommendations = [
                    'Extend QA period by 30-50%',
                    'Prepare rollback procedures and test them',
                    'Schedule extra developer availability for first week',
                    'Implement feature flags for gradual rollout',
                ];
                break;
            case 'MEDIUM':
                $recommendations = [
                    'Maintain standard QA processes',
                    'Have rollback plan documented',
                    'Monitor closely for first 3 days',
                ];
                break;
            default:
                $recommendations = [
                    'Follow standard upgrade procedures',
                    'Monitor as per normal operations',
                ];
        }
        
        return $recommendations;
    }

    /**
     * Get technical risk summary
     */
    private function getTechnicalRiskSummary(float $score): string
    {
        if ($score >= 75) {
            return 'CRITICAL: Significant technical challenges requiring expert intervention';
        } elseif ($score >= 50) {
            return 'HIGH: Substantial technical complexity requiring careful planning';
        } elseif ($score >= 25) {
            return 'MEDIUM: Moderate technical challenges manageable with proper resources';
        } else {
            return 'LOW: Technical implementation is straightforward';
        }
    }

    /**
     * Get business risk summary
     */
    private function getBusinessRiskSummary(float $score): string
    {
        if ($score >= 75) {
            return 'CRITICAL: High business impact requiring executive oversight';
        } elseif ($score >= 50) {
            return 'HIGH: Significant business impact requiring management attention';
        } elseif ($score >= 25) {
            return 'MEDIUM: Moderate business impact with manageable consequences';
        } else {
            return 'LOW: Minimal business disruption expected';
        }
    }

    /**
     * Generate enhanced timeline with phases, milestones, and resource allocation
     * 
     * Timeline Structure:
     * - Phased breakdown (Planning, Development, Testing, Deployment)
     * - Key milestones with dates
     * - Resource allocation by phase
     * - Dependency mapping
     * - Critical path identification
     */
    private function generateEnhancedTimeline(
        array $prioritizedIssues,
        array $effort,
        array $riskAssessment
    ): array {
        $totalWeeks = $riskAssessment['risk_timeline']['risk_adjusted_weeks'] ?? $effort['timeline_weeks'] ?? 0;
        $totalHours = $effort['total_hours'] ?? 0;
        
        // Phase breakdown (percentage of total timeline)
        $phases = $this->calculatePhases($prioritizedIssues, $totalWeeks, $totalHours);
        
        // Generate milestones
        $milestones = $this->generateMilestones($phases, $prioritizedIssues);
        
        // Calculate resource allocation
        $resources = $this->calculateResourceAllocation($phases, $totalHours);
        
        // Identify dependencies
        $dependencies = $this->identifyDependencies($prioritizedIssues);
        
        // Calculate critical path
        $criticalPath = $this->calculateCriticalPath($phases, $dependencies);
        
        return [
            'total_duration_weeks' => $totalWeeks,
            'total_hours' => $totalHours,
            'phases' => $phases,
            'milestones' => $milestones,
            'resource_allocation' => $resources,
            'dependencies' => $dependencies,
            'critical_path' => $criticalPath,
            'gantt_data' => $this->generateGanttData($phases),
        ];
    }

    /**
     * Calculate project phases
     */
    private function calculatePhases(array $prioritizedIssues, float $totalWeeks, float $totalHours): array
    {
        $criticalCount = count($prioritizedIssues['critical'] ?? []);
        $totalIssues = array_sum(array_map('count', $prioritizedIssues));
        
        // Phase distribution (adjusts based on issue complexity)
        $planningPercent = 0.15;  // 15%
        $developmentPercent = 0.45; // 45%
        $testingPercent = 0.25;    // 25%
        $deploymentPercent = 0.15; // 15%
        
        // Adjust for high critical issue count
        if ($criticalCount > 10) {
            $testingPercent += 0.05;
            $deploymentPercent += 0.05;
            $developmentPercent -= 0.10;
        }
        
        $phases = [];
        $currentWeek = 0;
        
        // Phase 1: Planning & Analysis
        $planningWeeks = round($totalWeeks * $planningPercent, 1);
        $phases['planning'] = [
            'name' => 'Planning & Analysis',
            'duration_weeks' => $planningWeeks,
            'start_week' => $currentWeek,
            'end_week' => $currentWeek + $planningWeeks,
            'hours' => round($totalHours * $planningPercent, 0),
            'activities' => [
                'Code analysis and issue prioritization',
                'Architecture review and design',
                'Resource allocation and team setup',
                'Risk assessment and mitigation planning',
                'Test strategy development',
            ],
            'deliverables' => [
                'Detailed upgrade plan',
                'Architecture design document',
                'Test strategy',
                'Risk mitigation plan',
            ],
        ];
        $currentWeek += $planningWeeks;
        
        // Phase 2: Development & Implementation
        $devWeeks = round($totalWeeks * $developmentPercent, 1);
        $phases['development'] = [
            'name' => 'Development & Implementation',
            'duration_weeks' => $devWeeks,
            'start_week' => round($currentWeek, 1),
            'end_week' => round($currentWeek + $devWeeks, 1),
            'hours' => round($totalHours * $developmentPercent, 0),
            'activities' => [
                "Fix critical issues ({$criticalCount} issues)",
                'Implement breaking change migrations',
                'Update deprecated code patterns',
                'Refactor complex modules',
                'Code review and peer validation',
            ],
            'deliverables' => [
                'All critical issues resolved',
                'Code migration completed',
                'Unit tests updated',
                'Code review approved',
            ],
        ];
        $currentWeek += $devWeeks;
        
        // Phase 3: Testing & QA
        $testWeeks = round($totalWeeks * $testingPercent, 1);
        $phases['testing'] = [
            'name' => 'Testing & QA',
            'duration_weeks' => $testWeeks,
            'start_week' => round($currentWeek, 1),
            'end_week' => round($currentWeek + $testWeeks, 1),
            'hours' => round($totalHours * $testingPercent, 0),
            'activities' => [
                'Unit testing',
                'Integration testing',
                'Regression testing',
                'Performance testing',
                'User acceptance testing (UAT)',
                'Security audit',
            ],
            'deliverables' => [
                'All tests passing',
                'Performance benchmarks met',
                'Security audit passed',
                'UAT sign-off',
            ],
        ];
        $currentWeek += $testWeeks;
        
        // Phase 4: Deployment & Stabilization
        $deployWeeks = round($totalWeeks * $deploymentPercent, 1);
        $phases['deployment'] = [
            'name' => 'Deployment & Stabilization',
            'duration_weeks' => $deployWeeks,
            'start_week' => round($currentWeek, 1),
            'end_week' => round($currentWeek + $deployWeeks, 1),
            'hours' => round($totalHours * $deploymentPercent, 0),
            'activities' => [
                'Staging deployment',
                'Production deployment',
                'Post-deployment monitoring',
                'Issue resolution',
                'Documentation updates',
                'Team training',
            ],
            'deliverables' => [
                'Production deployment successful',
                'System stable and monitored',
                'Documentation updated',
                'Team trained',
            ],
        ];
        
        return $phases;
    }

    /**
     * Generate project milestones
     */
    private function generateMilestones(array $phases, array $prioritizedIssues): array
    {
        $milestones = [];
        $criticalCount = count($prioritizedIssues['critical'] ?? []);
        
        // Milestone 1: Planning Complete
        $milestones[] = [
            'name' => 'Planning & Design Complete',
            'week' => $phases['planning']['end_week'],
            'description' => 'Upgrade plan approved, team ready',
            'criteria' => [
                'All critical issues documented',
                'Architecture design approved',
                'Resources allocated',
            ],
            'is_critical' => true,
        ];
        
        // Milestone 2: Critical Issues Resolved
        $criticalMilestoneWeek = $phases['development']['start_week'] + 
            ($phases['development']['duration_weeks'] * 0.5);
        $milestones[] = [
            'name' => 'Critical Issues Resolved',
            'week' => round($criticalMilestoneWeek, 1),
            'description' => "All {$criticalCount} critical issues fixed and reviewed",
            'criteria' => [
                'All critical issues resolved',
                'Code review completed',
                'Unit tests passing',
            ],
            'is_critical' => true,
        ];
        
        // Milestone 3: Development Complete
        $milestones[] = [
            'name' => 'Development Complete',
            'week' => $phases['development']['end_week'],
            'description' => 'All code changes implemented and reviewed',
            'criteria' => [
                'All issues resolved',
                'Code review approved',
                'Ready for QA',
            ],
            'is_critical' => true,
        ];
        
        // Milestone 4: QA Sign-off
        $milestones[] = [
            'name' => 'QA Sign-off',
            'week' => $phases['testing']['end_week'],
            'description' => 'All testing complete, ready for deployment',
            'criteria' => [
                'All tests passing',
                'Performance validated',
                'Security approved',
                'UAT complete',
            ],
            'is_critical' => true,
        ];
        
        // Milestone 5: Go-Live
        $milestones[] = [
            'name' => 'Production Go-Live',
            'week' => $phases['deployment']['start_week'] + 1,
            'description' => 'Upgrade deployed to production',
            'criteria' => [
                'Staging validated',
                'Production deployed',
                'Monitoring active',
            ],
            'is_critical' => true,
        ];
        
        // Milestone 6: Project Complete
        $milestones[] = [
            'name' => 'Project Complete',
            'week' => $phases['deployment']['end_week'],
            'description' => 'System stable, documentation complete',
            'criteria' => [
                'System stable for 1 week',
                'Documentation updated',
                'Team trained',
                'Post-implementation review done',
            ],
            'is_critical' => false,
        ];
        
        return $milestones;
    }

    /**
     * Calculate resource allocation
     */
    private function calculateResourceAllocation(array $phases, float $totalHours): array
    {
        $resources = [];
        
        foreach ($phases as $phaseKey => $phase) {
            $phaseHours = $phase['hours'];
            $phaseWeeks = $phase['duration_weeks'];
            
            // Calculate team size: assume 25 productive hours/person/week (accounts for meetings, reviews, context-switching)
            $hoursPerWeek = $phaseWeeks > 0 ? $phaseHours / $phaseWeeks : 0;
            $teamSize = (int)ceil($hoursPerWeek / 25);
            $teamSize = max(1, min(8, $teamSize));
            
            $resources[$phaseKey] = [
                'phase' => $phase['name'],
                'total_hours' => $phaseHours,
                'duration_weeks' => $phaseWeeks,
                'hours_per_week' => round($hoursPerWeek, 1),
                'team_size' => $teamSize,
                'roles' => $this->getPhaseRoles($phaseKey, $teamSize),
            ];
        }
        
        return $resources;
    }

    /**
     * Get required roles for each phase
     */
    private function getPhaseRoles(string $phase, int $teamSize): array
    {
        $roles = [
            'planning' => [
                'Technical Lead' => 1,
                'Architects' => max(1, $teamSize - 2),
                'Project Manager' => 1,
            ],
            'development' => [
                'Senior Developers' => ceil($teamSize * 0.4),
                'Developers' => max(1, floor($teamSize * 0.6)),
                'Code Reviewers' => 1,
            ],
            'testing' => [
                'QA Lead' => 1,
                'QA Engineers' => max(1, $teamSize - 1),
                'Automation Engineers' => min(1, max(0, $teamSize - 2)),
            ],
            'deployment' => [
                'DevOps Engineer' => 1,
                'Technical Lead' => 1,
                'Support Engineers' => max(1, $teamSize - 2),
            ],
        ];
        
        return $roles[$phase] ?? [];
    }

    /**
     * Identify project dependencies
     */
    private function identifyDependencies(array $prioritizedIssues): array
    {
        return [
            [
                'task' => 'Critical Issues Resolution',
                'depends_on' => ['Planning Complete'],
                'blocks' => ['Development Complete', 'Testing'],
                'description' => 'Must complete before proceeding to full testing',
            ],
            [
                'task' => 'Code Migration',
                'depends_on' => ['Critical Issues Resolution'],
                'blocks' => ['Integration Testing'],
                'description' => 'All deprecated code must be migrated',
            ],
            [
                'task' => 'Integration Testing',
                'depends_on' => ['Development Complete', 'Code Migration'],
                'blocks' => ['UAT', 'Production Deployment'],
                'description' => 'Must validate all integrations',
            ],
            [
                'task' => 'UAT',
                'depends_on' => ['Integration Testing', 'Performance Testing'],
                'blocks' => ['Production Deployment'],
                'description' => 'Business sign-off required',
            ],
        ];
    }

    /**
     * Calculate critical path
     */
    private function calculateCriticalPath(array $phases, array $dependencies): array
    {
        return [
            'path' => [
                'Planning & Analysis',
                'Critical Issues Resolution',
                'Code Migration',
                'Integration Testing',
                'UAT',
                'Production Deployment',
            ],
            'total_duration_weeks' => array_sum(array_column($phases, 'duration_weeks')),
            'critical_activities' => [
                'Resolve all critical issues',
                'Complete code migration',
                'Pass integration testing',
                'Obtain UAT sign-off',
            ],
            'risk_areas' => [
                'Critical issue resolution may take longer than planned',
                'Integration testing may reveal additional issues',
                'UAT sign-off may be delayed',
            ],
        ];
    }

    /**
     * Generate Gantt chart data
     */
    private function generateGanttData(array $phases): array
    {
        $gantt = [];
        
        foreach ($phases as $phaseKey => $phase) {
            $gantt[] = [
                'task' => $phase['name'],
                'start' => $phase['start_week'],
                'duration' => $phase['duration_weeks'],
                'end' => $phase['end_week'],
                'progress' => 0,
                'dependencies' => $this->getPhaseGanttDependencies($phaseKey),
            ];
        }
        
        return $gantt;
    }

    /**
     * Get Gantt dependencies for a phase
     */
    private function getPhaseGanttDependencies(string $phase): array
    {
        $deps = [
            'planning' => [],
            'development' => ['planning'],
            'testing' => ['development'],
            'deployment' => ['testing'],
        ];
        
        return $deps[$phase] ?? [];
    }

    /**
     * Generate upgrade roadmap
     */
    private function generateUpgradeRoadmap(
        array $prioritizedIssues,
        MagentoVersion $currentVersion,
        MagentoVersion $targetVersion
    ): array {
        $roadmap = [];

        // Phase 1: Critical Issues (Blockers)
        if (!empty($prioritizedIssues['critical'])) {
            $roadmap[] = [
                'phase' => 1,
                'name' => 'Critical Issues (Blockers)',
                'description' => 'Must be fixed before upgrade - will cause fatal errors',
                'issues' => $prioritizedIssues['critical'],
                'priority' => 'IMMEDIATE',
                'can_deploy_without_fixing' => false,
            ];
        }

        // Phase 2: High Priority Issues
        if (!empty($prioritizedIssues['high'])) {
            $roadmap[] = [
                'phase' => 2,
                'name' => 'High Priority Issues',
                'description' => 'Should be fixed before upgrade - may cause significant problems',
                'issues' => $prioritizedIssues['high'],
                'priority' => 'HIGH',
                'can_deploy_without_fixing' => false,
            ];
        }

        // Phase 3: Medium Priority Issues
        if (!empty($prioritizedIssues['medium'])) {
            $roadmap[] = [
                'phase' => 3,
                'name' => 'Medium Priority Issues',
                'description' => 'Recommended to fix - may cause minor issues',
                'issues' => $prioritizedIssues['medium'],
                'priority' => 'MEDIUM',
                'can_deploy_without_fixing' => true,
            ];
        }

        // Phase 4: Low Priority Issues
        if (!empty($prioritizedIssues['low'])) {
            $roadmap[] = [
                'phase' => 4,
                'name' => 'Low Priority Issues',
                'description' => 'Can be fixed post-upgrade - nice to have',
                'issues' => $prioritizedIssues['low'],
                'priority' => 'LOW',
                'can_deploy_without_fixing' => true,
            ];
        }

        return $roadmap;
    }

    /**
     * Scan directory for PHP files
     */
    /**
     * Scan PHP files with enhanced filtering and exclusions
     */
    private function scanPhpFiles(string $directory): array
    {
        $files = [];
        
        if (!is_dir($directory)) {
            $this->logger->warning('Directory does not exist', ['path' => $directory]);
            return $files;
        }

        // Directories to exclude from scanning
        $excludedDirs = [
            'node_modules',
            'vendor',
            'var',
            'pub',
            'generated',
            '.git',
            'dev',
            'setup',
            'tests', // Exclude test directories from upgrade analysis
            'Test',
            'phpunit',
            'lib',    // Core Magento lib (usually not modified)
        ];

        // Patterns to exclude from scanning
        $excludedPatterns = [
            '*/generated/*',
            '*/var/*',
            '*/pub/static/*',
            '*/dev/tests/*',
            '*/setup/*',
            '*/lib/internal/*',
        ];

        $this->logger->debug('Starting file scan', [
            'directory' => $directory,
            'excluded_dirs' => count($excludedDirs),
            'excluded_patterns' => count($excludedPatterns)
        ]);

        try {
            $directoryIterator = new \RecursiveDirectoryIterator(
                $directory,
                \RecursiveDirectoryIterator::SKIP_DOTS | \RecursiveDirectoryIterator::FOLLOW_SYMLINKS
            );

            // Filter out excluded directories
            $filterIterator = new \RecursiveCallbackFilterIterator(
                $directoryIterator,
                function ($current, $key, $iterator) use ($directory, $excludedDirs, $excludedPatterns) {
                    // Skip excluded directories
                    if ($current->isDir()) {
                        $dirName = $current->getFilename();
                        if (in_array($dirName, $excludedDirs, true)) {
                            return false;
                        }
                        
                        // Also check for hidden directories (starting with .)
                        if (strpos($dirName, '.') === 0 && $dirName !== '.') {
                            return false;
                        }
                    }
                    
                    // Check against excluded patterns, relative to the scanned
                    // directory so an absolute path like /opt/homebrew/var/www/...
                    // (e.g. on Homebrew installs) doesn't falsely match a pattern
                    // like "*/var/*" meant for the project's own var/ directory.
                    $relativePath = '/' . ltrim(substr($current->getPathname(), strlen($directory)), '/');
                    foreach ($excludedPatterns as $pattern) {
                        if (fnmatch($pattern, $relativePath)) {
                            return false;
                        }
                    }

                    return true;
                }
            );

            $iterator = new \RecursiveIteratorIterator(
                $filterIterator,
                \RecursiveIteratorIterator::SELF_FIRST
            );

            $fileCount = 0;
            $skippedCount = 0;

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $filePath = $file->getPathname();
                    
                    // Additional file-level filters
                    if ($this->shouldIncludeFile($filePath)) {
                        $files[] = $filePath;
                        $fileCount++;
                    } else {
                        $skippedCount++;
                    }
                }
            }

            $this->logger->debug('File scan completed', [
                'files_found' => $fileCount,
                'files_skipped' => $skippedCount,
                'total_checked' => $fileCount + $skippedCount
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Error scanning directory', [
                'directory' => $directory,
                'error' => $e->getMessage()
            ]);
        }

        return $files;
    }

    /**
     * Check if file should be included in analysis
     */
    private function shouldIncludeFile(string $filePath): bool
    {
        // Skip very large files (> 1MB) that might be generated
        if (file_exists($filePath) && filesize($filePath) > 1048576) {
            return false;
        }

        // Skip files with certain names
        $fileName = basename($filePath);
        $skipFiles = [
            'Zend_Locale_Data.php',  // Large Zend data file
            'Zend_Translate_Plural.php',
        ];

        if (in_array($fileName, $skipFiles, true)) {
            return false;
        }

        // Include custom modules (app/code), exclude everything else by default
        // This focuses analysis on customer code, not Magento core
        if (strpos($filePath, '/app/code/') !== false) {
            return true;
        }

        // Include if it's in the project root (not in vendor/core)
        if (strpos($filePath, '/vendor/') === false &&
            strpos($filePath, '/lib/') === false &&
            strpos($filePath, '/setup/') === false) {
            return true;
        }

        return false;
    }

    /**
     * Create upgrade issue
     */
    private function createUpgradeIssue(
        string $filePath,
        int $line,
        string $code,
        string $pattern,
        array $info,
        MagentoVersion $currentVersion,
        MagentoVersion $targetVersion
    ): array {
        return [
            'file' => $filePath,
            'line' => $line,
            'code' => $code,
            'type' => $info['type'] ?? 'compatibility',
            'pattern' => $pattern,
            'message' => $info['message'] ?? 'Compatibility issue detected',
            'severity' => $info['severity'] ?? 'warning',
            'fix' => $info['fix'] ?? null,
            'migration_guide' => $info['migration_guide'] ?? null,
            'current_version' => $currentVersion->toString(),
            'target_version' => $targetVersion->toString(),
        ];
    }

    /**
     * Initialize deprecation patterns
     * 
     * Comprehensive list of deprecated APIs, breaking changes, and incompatibilities
     * for Magento 2.3+ → 2.4.x upgrades
     */
    private function initializePatterns(): void
    {
        $this->deprecationPatterns = [
            // ==================== DEPRECATED CLASSES (Critical) ====================
            
            // ObjectManager - Most common issue
            'ObjectManager::getInstance' => [
                'type' => 'anti-pattern',
                'severity' => 'critical',
                'message' => 'Direct ObjectManager usage deprecated - breaks DI principles',
                'fix' => 'Use constructor dependency injection',
                'introduced_in' => '2.0.0',
                'priority' => 'critical',
            ],
            
            // Registry
            'Magento\\\\Framework\\\\Registry' => [
                'type' => 'deprecated',
                'severity' => 'critical',
                'message' => 'Registry pattern deprecated - use alternative storage',
                'fix' => 'Use session storage, cache, or proper state management',
                'introduced_in' => '2.3.0',
                'priority' => 'critical',
            ],
            
            '->registry\(' => [
                'type' => 'deprecated',
                'severity' => 'high',
                'message' => 'Registry::registry() method deprecated',
                'fix' => 'Use dependency injection or state management',
                'introduced_in' => '2.3.0',
                'priority' => 'high',
            ],
            
            // DateTime
            'Magento\\\\Framework\\\\Stdlib\\\\DateTime[^\\\\]' => [
                'type' => 'deprecated',
                'severity' => 'high',
                'message' => 'Old DateTime class deprecated',
                'fix' => 'Use Magento\\Framework\\Stdlib\\DateTime\\DateTime',
                'introduced_in' => '2.2.0',
                'priority' => 'high',
            ],
            
            // Abstract Helper
            'extends\s+.*AbstractHelper' => [
                'type' => 'deprecated',
                'severity' => 'medium',
                'message' => 'AbstractHelper usage discouraged',
                'fix' => 'Use services and repositories instead of helpers',
                'introduced_in' => '2.3.0',
                'priority' => 'medium',
            ],
            
            // ==================== SETUP SCRIPTS (Critical - Removed in 2.3+) ====================
            
            'implements\s+InstallSchemaInterface' => [
                'type' => 'deprecated',
                'severity' => 'critical',
                'message' => 'Setup scripts deprecated - use declarative schema',
                'fix' => 'Convert to db_schema.xml',
                'migration_guide' => 'https://devdocs.magento.com/guides/v2.4/extension-dev-guide/declarative-schema/',
                'introduced_in' => '2.3.0',
                'priority' => 'critical',
            ],
            
            'implements\s+UpgradeSchemaInterface' => [
                'type' => 'deprecated',
                'severity' => 'critical',
                'message' => 'Upgrade scripts deprecated - use declarative schema with patches',
                'fix' => 'Convert to db_schema.xml and data patches',
                'introduced_in' => '2.3.0',
                'priority' => 'critical',
            ],
            
            'implements\s+InstallDataInterface' => [
                'type' => 'deprecated',
                'severity' => 'critical',
                'message' => 'InstallData deprecated - use data patches',
                'fix' => 'Convert to data patches in Setup/Patch/Data',
                'introduced_in' => '2.3.0',
                'priority' => 'critical',
            ],
            
            'implements\s+UpgradeDataInterface' => [
                'type' => 'deprecated',
                'severity' => 'critical',
                'message' => 'UpgradeData deprecated - use data patches',
                'fix' => 'Convert to data patches',
                'introduced_in' => '2.3.0',
                'priority' => 'critical',
            ],
            
            // ==================== ZEND → LAMINAS (Breaking Change in 2.4.4+) ====================
            
            'use\s+Zend\\\\' => [
                'type' => 'breaking',
                'severity' => 'critical',
                'message' => 'Zend Framework replaced with Laminas',
                'fix' => "Replace 'use Zend\\' with 'use Laminas\\'",
                'introduced_in' => '2.4.4',
                'priority' => 'critical',
            ],
            
            'Zend_' => [
                'type' => 'breaking',
                'severity' => 'critical',
                'message' => 'Zend_* classes removed',
                'fix' => 'Migrate to Laminas equivalents',
                'introduced_in' => '2.4.4',
                'priority' => 'critical',
            ],
            
            // ==================== REMOVED METHODS (High Priority) ====================
            
            '->getIdFieldName\(' => [
                'type' => 'removed',
                'severity' => 'high',
                'message' => 'AbstractDb::getIdFieldName() removed',
                'fix' => 'Use getConnection()->getAutoIncrementField()',
                'introduced_in' => '2.3.0',
                'priority' => 'high',
            ],
            
            
            // ==================== LAYOUT XML (High Priority) ====================
            
            '<action\s+method=' => [
                'type' => 'deprecated',
                'severity' => 'high',
                'message' => 'Legacy layout XML <action method=> deprecated',
                'fix' => 'Convert to <arguments> and <argument> tags',
                'introduced_in' => '2.0.0',
                'priority' => 'high',
            ],
            
            '<block.*type=' => [
                'type' => 'deprecated',
                'severity' => 'medium',
                'message' => 'type= attribute deprecated in layout XML',
                'fix' => 'Use class= attribute instead',
                'introduced_in' => '2.1.0',
                'priority' => 'medium',
            ],
            
            // ==================== PHP 7.x → 8.x INCOMPATIBILITIES ====================
            
            'create_function\(' => [
                'type' => 'removed',
                'severity' => 'critical',
                'message' => 'create_function() removed in PHP 8.0',
                'fix' => 'Use anonymous functions (closures)',
                'introduced_in' => 'PHP 7.2',
                'priority' => 'critical',
            ],
            
            '__autoload' => [
                'type' => 'removed',
                'severity' => 'critical',
                'message' => '__autoload() removed in PHP 8.0',
                'fix' => 'Use spl_autoload_register()',
                'introduced_in' => 'PHP 7.2',
                'priority' => 'critical',
            ],
            
            'each\(' => [
                'type' => 'removed',
                'severity' => 'high',
                'message' => 'each() removed in PHP 8.0',
                'fix' => 'Use foreach or array functions',
                'introduced_in' => 'PHP 7.2',
                'priority' => 'high',
            ],
            
            '\bunserialize\s*\(\s*\$' => [
                'type' => 'security',
                'severity' => 'critical',
                'message' => 'unserialize() is a security risk',
                'fix' => 'Use json_decode() or serialize framework',
                'introduced_in' => '2.2.0',
                'priority' => 'critical',
            ],
            
            '\bserialize\s*\(' => [
                'type' => 'security',
                'severity' => 'high',
                'message' => 'serialize() usage should be reviewed',
                'fix' => 'Consider using json_encode() for simple data',
                'introduced_in' => '2.2.0',
                'priority' => 'high',
            ],
            
            
            // ==================== SERVICE CONTRACTS & API ====================
            
            'getResource\(\)->load\(' => [
                'type' => 'anti-pattern',
                'severity' => 'high',
                'message' => 'Direct resource model usage - use repositories',
                'fix' => 'Use repository interface methods',
                'introduced_in' => '2.2.0',
                'priority' => 'high',
            ],
            
            '->save\(\s*\$' => [
                'type' => 'anti-pattern',
                'severity' => 'medium',
                'message' => 'Direct model save() - prefer repository pattern',
                'fix' => 'Use repository->save() for better service contract compliance',
                'introduced_in' => '2.2.0',
                'priority' => 'medium',
            ],
            
            // ==================== COLLECTIONS & QUERIES ====================
            
            '->getSelect\(\)->join' => [
                'type' => 'performance',
                'severity' => 'medium',
                'message' => 'Direct SQL joins in collections',
                'fix' => 'Consider using repositories and separate queries',
                'introduced_in' => '2.0.0',
                'priority' => 'low',
            ],
            
            '->addFieldToFilter\(\s*[\'"].*[\'"]\s*,\s*\$' => [
                'type' => 'security',
                'severity' => 'high',
                'message' => 'Potential SQL injection in collection filter',
                'fix' => 'Ensure proper input sanitization',
                'introduced_in' => '2.0.0',
                'priority' => 'high',
            ],
            
            // ==================== DEPENDENCY INJECTION ====================
            
            'new\s+\\\\Magento\\\\Framework' => [
                'type' => 'anti-pattern',
                'severity' => 'high',
                'message' => 'Direct instantiation of framework class',
                'fix' => 'Use constructor injection',
                'introduced_in' => '2.0.0',
                'priority' => 'high',
            ],
            
            // ==================== SESSION & COOKIES ====================
            
            '\$_SESSION' => [
                'type' => 'deprecated',
                'severity' => 'high',
                'message' => 'Direct $_SESSION usage not recommended',
                'fix' => 'Use Magento session objects',
                'introduced_in' => '2.0.0',
                'priority' => 'high',
            ],
            
            'setcookie\(' => [
                'type' => 'deprecated',
                'severity' => 'medium',
                'message' => 'Direct setcookie() usage',
                'fix' => 'Use Magento cookie manager',
                'introduced_in' => '2.0.0',
                'priority' => 'medium',
            ],
            
            // ==================== TEMPLATES & BLOCKS ====================
            
            '\$this->helper\(' => [
                'type' => 'deprecated',
                'severity' => 'medium',
                'message' => '$this->helper() usage in templates',
                'fix' => 'Inject helpers via block or view model',
                'introduced_in' => '2.2.0',
                'priority' => 'medium',
            ],
            
            '->toHtml\(\s*\$' => [
                'type' => 'security',
                'severity' => 'high',
                'message' => 'Unescaped output to HTML',
                'fix' => 'Use $block->escapeHtml()',
                'introduced_in' => '2.0.0',
                'priority' => 'high',
            ],
            
            // ==================== JAVASCRIPT & REQUIREJS ====================
            
            'jquery/jquery' => [
                'type' => 'deprecated',
                'severity' => 'low',
                'message' => 'Old jQuery path',
                'fix' => 'Use standard jquery reference',
                'introduced_in' => '2.2.0',
                'priority' => 'low',
            ],
            
            // ==================== COMPOSER & DEPENDENCIES ====================
            
            '"magento/framework"' => [
                'type' => 'info',
                'severity' => 'low',
                'message' => 'Check framework version compatibility',
                'fix' => 'Verify version constraints in composer.json',
                'introduced_in' => '2.0.0',
                'priority' => 'low',
            ],
            
            // ==================== CONFIGURATION FILES ====================
            
            '<type\s+name=.*preference' => [
                'type' => 'review',
                'severity' => 'medium',
                'message' => 'Preference usage should be reviewed',
                'fix' => 'Consider using plugins instead of preferences',
                'introduced_in' => '2.0.0',
                'priority' => 'medium',
            ],
            
            // ==================== DATABASE & SCHEMA ====================
            
            '->query\(' => [
                'type' => 'security',
                'severity' => 'critical',
                'message' => 'Direct database query',
                'fix' => 'Use repository pattern or prepared statements',
                'introduced_in' => '2.0.0',
                'priority' => 'critical',
            ],
            
            '->rawQuery\(' => [
                'type' => 'security',
                'severity' => 'critical',
                'message' => 'Raw SQL query detected',
                'fix' => 'Use query builder or repositories',
                'introduced_in' => '2.0.0',
                'priority' => 'critical',
            ],
            
            // ==================== PLUGIN & OBSERVER ISSUES ====================
            
            'implements\s+ObserverInterface.*execute\(' => [
                'type' => 'review',
                'severity' => 'low',
                'message' => 'Observer - review event dependencies',
                'fix' => 'Ensure observer is still needed and properly configured',
                'introduced_in' => '2.0.0',
                'priority' => 'low',
            ],
            
            // ==================== ADDITIONAL PATTERNS ====================
            
            'Magento\\\\Framework\\\\App\\\\ObjectManager' => [
                'type' => 'anti-pattern',
                'severity' => 'critical',
                'message' => 'ObjectManager direct reference',
                'fix' => 'Remove all ObjectManager references',
                'introduced_in' => '2.0.0',
                'priority' => 'critical',
            ],
            
            '->getObjectManager\(' => [
                'type' => 'anti-pattern',
                'severity' => 'critical',
                'message' => 'Getting ObjectManager instance',
                'fix' => 'Use proper dependency injection',
                'introduced_in' => '2.0.0',
                'priority' => 'critical',
            ],
        ];
        
        $this->logger->info('Initialized ' . count($this->deprecationPatterns) . ' deprecation patterns for upgrade analysis');
    }

    /**
     * Parse version string
     */
    private function parseVersion(string $versionString): MagentoVersion
    {
        $parts = explode('.', $versionString);
        return new MagentoVersion(
            (int)($parts[0] ?? 2),
            (int)($parts[1] ?? 4),
            (int)($parts[2] ?? 0)
        );
    }
}


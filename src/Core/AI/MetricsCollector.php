<?php

declare(strict_types=1);

namespace ACQC\Core\AI;

use ACQC\Core\Config\ConfigManager;
use ACQC\Core\Rules\Violation;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Metrics Collector for Predictive Analytics
 * 
 * Collects, stores, and retrieves historical code quality metrics
 * for trend analysis and predictive modeling.
 */
class MetricsCollector
{
    private ConfigManager $configManager;
    private LoggerInterface $logger;
    private string $dataDirectory;
    private array $metrics = [];
    private array $cache = [];

    public function __construct(ConfigManager $configManager, ?LoggerInterface $logger = null)
    {
        $this->configManager = $configManager;
        $this->logger = $logger ?? new NullLogger();
        $this->dataDirectory = $this->configManager->get('predictive_analytics.data_directory', 'var/metrics');
        
        $this->ensureDataDirectory();
    }

    /**
     * Collect current metrics from project
     */
    public function collectCurrentMetrics(string $projectPath): array
    {
        $this->logger->info('Collecting current metrics', ['project' => $projectPath]);

        $metrics = [
            'timestamp' => time(),
            'date' => date('Y-m-d H:i:s'),
            'project_path' => $projectPath,
            'quality_score' => $this->calculateQualityScore($projectPath),
            'complexity_metrics' => $this->calculateComplexityMetrics($projectPath),
            'technical_debt' => $this->calculateTechnicalDebt($projectPath),
            'violation_metrics' => $this->calculateViolationMetrics($projectPath),
            'performance_metrics' => $this->calculatePerformanceMetrics($projectPath),
            'maintainability_index' => $this->calculateMaintainabilityIndex($projectPath),
            'test_coverage' => $this->calculateTestCoverage($projectPath),
            'code_size' => $this->calculateCodeSize($projectPath),
            'team_metrics' => $this->calculateTeamMetrics($projectPath),
            'dependency_metrics' => $this->calculateDependencyMetrics($projectPath)
        ];

        $this->storeMetrics($projectPath, $metrics);
        return $metrics;
    }

    /**
     * Get historical data for a project
     */
    public function getHistoricalData(string $projectPath, array $options = []): array
    {
        $daysBack = $options['days_back'] ?? 90;
        $cacheKey = $this->getCacheKey($projectPath, $daysBack);

        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $cutoffDate = date('Y-m-d', strtotime("-{$daysBack} days"));
        $data = $this->loadHistoricalData($projectPath, $cutoffDate);

        $this->cache[$cacheKey] = $data;
        return $data;
    }

    /**
     * Store metrics to persistent storage
     */
    public function storeMetrics(string $projectPath, array $metrics): void
    {
        $projectKey = $this->getProjectKey($projectPath);
        $date = date('Y-m-d');
        $hour = date('H');
        
        $filename = "{$this->dataDirectory}/{$projectKey}/{$date}.json";
        $this->ensureDirectory(dirname($filename));

        $dailyData = [];
        if (file_exists($filename)) {
            $dailyData = json_decode(file_get_contents($filename), true) ?: [];
        }

        $dailyData[$hour] = $metrics;
        file_put_contents($filename, json_encode($dailyData, JSON_PRETTY_PRINT));

        $this->logger->debug('Metrics stored', [
            'project' => $projectPath,
            'file' => $filename,
            'hour' => $hour
        ]);
    }

    /**
     * Calculate overall quality score
     */
    private function calculateQualityScore(string $projectPath): float
    {
        $score = 10.0; // Start with perfect score

        // Deduct points for violations
        $violations = $this->getViolations($projectPath);
        foreach ($violations as $violation) {
            switch ($violation->getSeverity()) {
                case 'critical':
                    $score -= 2.0;
                    break;
                case 'error':
                    $score -= 1.0;
                    break;
                case 'warning':
                    $score -= 0.5;
                    break;
                case 'info':
                    $score -= 0.1;
                    break;
            }
        }

        // Adjust for code complexity
        $complexity = $this->getAverageComplexity($projectPath);
        if ($complexity > 10) {
            $score -= ($complexity - 10) * 0.1;
        }

        // Adjust for test coverage
        $coverage = $this->getTestCoverage($projectPath);
        if ($coverage < 80) {
            $score -= (80 - $coverage) * 0.05;
        }

        return max(0.0, min(10.0, $score));
    }

    /**
     * Calculate complexity metrics
     */
    private function calculateComplexityMetrics(string $projectPath): array
    {
        $files = $this->getPhpFiles($projectPath);
        $totalComplexity = 0;
        $totalFiles = 0;
        $complexityDistribution = ['low' => 0, 'medium' => 0, 'high' => 0, 'very_high' => 0];

        foreach ($files as $file) {
            $complexity = $this->calculateFileComplexity($file);
            $totalComplexity += $complexity;
            $totalFiles++;

            if ($complexity <= 5) {
                $complexityDistribution['low']++;
            } elseif ($complexity <= 10) {
                $complexityDistribution['medium']++;
            } elseif ($complexity <= 20) {
                $complexityDistribution['high']++;
            } else {
                $complexityDistribution['very_high']++;
            }
        }

        return [
            'average_complexity' => $totalFiles > 0 ? $totalComplexity / $totalFiles : 0,
            'total_complexity' => $totalComplexity,
            'file_count' => $totalFiles,
            'distribution' => $complexityDistribution,
            'complexity_density' => $this->calculateComplexityDensity($projectPath),
            'hotspots' => $this->identifyComplexityHotspots($files)
        ];
    }

    /**
     * Calculate technical debt
     */
    private function calculateTechnicalDebt(string $projectPath): array
    {
        $violations = $this->getViolations($projectPath);
        $debtMinutes = 0;
        $debtByCategory = [];

        foreach ($violations as $violation) {
            $minutes = $this->getDebtMinutes($violation);
            $debtMinutes += $minutes;
            
            $category = $violation->getType();
            $debtByCategory[$category] = ($debtByCategory[$category] ?? 0) + $minutes;
        }

        $codeSize = $this->calculateCodeSize($projectPath);
        $debtRatio = $codeSize['lines_of_code'] > 0 ? $debtMinutes / $codeSize['lines_of_code'] : 0;

        return [
            'total_debt_minutes' => $debtMinutes,
            'debt_hours' => $debtMinutes / 60,
            'debt_days' => $debtMinutes / (60 * 8), // 8-hour work day
            'debt_ratio' => $debtRatio,
            'debt_by_category' => $debtByCategory,
            'debt_density' => $this->calculateDebtDensity($projectPath),
            'principal_debt' => $this->calculatePrincipalDebt($violations),
            'interest_debt' => $this->calculateInterestDebt($violations)
        ];
    }

    /**
     * Calculate violation metrics
     */
    private function calculateViolationMetrics(string $projectPath): array
    {
        $violations = $this->getViolations($projectPath);
        
        $severityCounts = ['critical' => 0, 'error' => 0, 'warning' => 0, 'info' => 0];
        $typeCounts = [];
        $ruleCounts = [];

        foreach ($violations as $violation) {
            $severityCounts[$violation->getSeverity()]++;
            
            $type = $violation->getType();
            $typeCounts[$type] = ($typeCounts[$type] ?? 0) + 1;
            
            $rule = $violation->getRuleId();
            $ruleCounts[$rule] = ($ruleCounts[$rule] ?? 0) + 1;
        }

        $codeSize = $this->calculateCodeSize($projectPath);
        $violationDensity = $codeSize['lines_of_code'] > 0 ? count($violations) / $codeSize['lines_of_code'] * 1000 : 0;

        return [
            'total_violations' => count($violations),
            'severity_distribution' => $severityCounts,
            'type_distribution' => $typeCounts,
            'rule_distribution' => $ruleCounts,
            'violation_density' => $violationDensity,
            'top_violations' => $this->getTopViolations($ruleCounts, 10),
            'violation_trends' => $this->calculateViolationTrends($projectPath)
        ];
    }

    /**
     * Calculate performance metrics
     */
    private function calculatePerformanceMetrics(string $projectPath): array
    {
        $files = $this->getPhpFiles($projectPath);
        $performanceIssues = 0;
        $hotspots = [];

        foreach ($files as $file) {
            $issues = $this->analyzeFilePerformance($file);
            $performanceIssues += count($issues);
            
            if (!empty($issues)) {
                $hotspots[] = [
                    'file' => $file,
                    'issues' => $issues,
                    'severity' => $this->calculatePerformanceSeverity($issues)
                ];
            }
        }

        return [
            'performance_issues' => $performanceIssues,
            'hotspots' => array_slice($hotspots, 0, 20), // Top 20 hotspots
            'n_plus_one_queries' => $this->detectNPlusOneQueries($projectPath),
            'memory_intensive_operations' => $this->detectMemoryIntensiveOperations($projectPath),
            'slow_algorithms' => $this->detectSlowAlgorithms($projectPath),
            'database_performance' => $this->analyzeDatabasePerformance($projectPath)
        ];
    }

    /**
     * Calculate maintainability index
     */
    private function calculateMaintainabilityIndex(string $projectPath): array
    {
        $files = $this->getPhpFiles($projectPath);
        $totalMI = 0;
        $fileCount = 0;
        $distribution = ['excellent' => 0, 'good' => 0, 'moderate' => 0, 'difficult' => 0, 'unmaintainable' => 0];

        foreach ($files as $file) {
            $mi = $this->calculateFileMaintainability($file);
            $totalMI += $mi;
            $fileCount++;

            if ($mi >= 85) {
                $distribution['excellent']++;
            } elseif ($mi >= 70) {
                $distribution['good']++;
            } elseif ($mi >= 50) {
                $distribution['moderate']++;
            } elseif ($mi >= 25) {
                $distribution['difficult']++;
            } else {
                $distribution['unmaintainable']++;
            }
        }

        return [
            'average_maintainability' => $fileCount > 0 ? $totalMI / $fileCount : 0,
            'distribution' => $distribution,
            'maintainability_debt' => $this->calculateMaintainabilityDebt($files),
            'improvement_potential' => $this->calculateImprovementPotential($files)
        ];
    }

    /**
     * Calculate test coverage
     */
    private function calculateTestCoverage(string $projectPath): array
    {
        // This would integrate with coverage tools like PHPUnit, Xdebug, etc.
        $coverageData = $this->getCoverageData($projectPath);
        
        return [
            'line_coverage' => $coverageData['line_coverage'] ?? 0,
            'branch_coverage' => $coverageData['branch_coverage'] ?? 0,
            'function_coverage' => $coverageData['function_coverage'] ?? 0,
            'class_coverage' => $coverageData['class_coverage'] ?? 0,
            'uncovered_lines' => $coverageData['uncovered_lines'] ?? 0,
            'test_files' => $this->countTestFiles($projectPath),
            'test_ratio' => $this->calculateTestRatio($projectPath)
        ];
    }

    /**
     * Calculate code size metrics
     */
    private function calculateCodeSize(string $projectPath): array
    {
        $files = $this->getPhpFiles($projectPath);
        $totalLines = 0;
        $codeLines = 0;
        $commentLines = 0;
        $blankLines = 0;
        $fileCount = count($files);

        foreach ($files as $file) {
            $lineData = $this->analyzeFileLines($file);
            $totalLines += $lineData['total'];
            $codeLines += $lineData['code'];
            $commentLines += $lineData['comments'];
            $blankLines += $lineData['blank'];
        }

        return [
            'file_count' => $fileCount,
            'lines_of_code' => $codeLines,
            'total_lines' => $totalLines,
            'comment_lines' => $commentLines,
            'blank_lines' => $blankLines,
            'comment_ratio' => $totalLines > 0 ? $commentLines / $totalLines : 0,
            'average_file_size' => $fileCount > 0 ? $codeLines / $fileCount : 0,
            'largest_files' => $this->getLargestFiles($files, 10),
            'file_size_distribution' => $this->getFileSizeDistribution($files)
        ];
    }

    /**
     * Calculate team metrics
     */
    private function calculateTeamMetrics(string $projectPath): array
    {
        // This would integrate with VCS systems like Git
        $commitData = $this->getCommitData($projectPath);
        
        return [
            'commit_frequency' => $this->calculateCommitFrequency($commitData),
            'author_distribution' => $this->calculateAuthorDistribution($commitData),
            'change_frequency' => $this->calculateChangeFrequency($projectPath),
            'collaboration_index' => $this->calculateCollaborationIndex($commitData),
            'code_ownership' => $this->calculateCodeOwnership($projectPath),
            'review_metrics' => $this->calculateReviewMetrics($projectPath)
        ];
    }

    /**
     * Calculate dependency metrics
     */
    private function calculateDependencyMetrics(string $projectPath): array
    {
        $dependencies = $this->analyzeDependencies($projectPath);
        
        return [
            'total_dependencies' => count($dependencies),
            'direct_dependencies' => $this->countDirectDependencies($dependencies),
            'transitive_dependencies' => $this->countTransitiveDependencies($dependencies),
            'dependency_depth' => $this->calculateDependencyDepth($dependencies),
            'circular_dependencies' => $this->detectCircularDependencies($dependencies),
            'outdated_dependencies' => $this->detectOutdatedDependencies($dependencies),
            'security_vulnerabilities' => $this->checkSecurityVulnerabilities($dependencies)
        ];
    }

    /**
     * Load historical data from storage
     */
    private function loadHistoricalData(string $projectPath, string $cutoffDate): array
    {
        $projectKey = $this->getProjectKey($projectPath);
        $dataPath = "{$this->dataDirectory}/{$projectKey}";
        
        if (!is_dir($dataPath)) {
            return [];
        }

        $data = [];
        $files = glob("{$dataPath}/*.json");
        
        foreach ($files as $file) {
            $date = basename($file, '.json');
            if ($date >= $cutoffDate) {
                $dailyData = json_decode(file_get_contents($file), true);
                if ($dailyData) {
                    $data[$date] = $dailyData;
                }
            }
        }

        ksort($data);
        return $data;
    }

    /**
     * Ensure data directory exists
     */
    private function ensureDataDirectory(): void
    {
        if (!is_dir($this->dataDirectory)) {
            mkdir($this->dataDirectory, 0755, true);
        }
    }

    /**
     * Ensure directory exists
     */
    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
    }

    /**
     * Get project key for storage
     */
    private function getProjectKey(string $projectPath): string
    {
        return md5(realpath($projectPath) ?: $projectPath);
    }

    /**
     * Get cache key
     */
    private function getCacheKey(string $projectPath, int $daysBack): string
    {
        return md5($projectPath . '_' . $daysBack . '_' . date('Y-m-d'));
    }

    /**
     * Check if collector is healthy
     */
    public function isHealthy(): bool
    {
        return is_dir($this->dataDirectory) && is_writable($this->dataDirectory);
    }

    /**
     * Get total number of data points collected
     */
    public function getDataPointCount(): int
    {
        $count = 0;
        $files = glob("{$this->dataDirectory}/*/*.json");
        
        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            $count += count($data ?: []);
        }
        
        return $count;
    }

    // Placeholder methods for complex calculations that would require actual analysis
    private function getViolations(string $projectPath): array { return []; }
    private function getAverageComplexity(string $projectPath): float { return 5.0; }
    private function getTestCoverage(string $projectPath): float { return 75.0; }
    private function getPhpFiles(string $projectPath): array { return []; }
    private function calculateFileComplexity(string $file): int { return 5; }
    private function calculateComplexityDensity(string $projectPath): float { return 0.1; }
    private function identifyComplexityHotspots(array $files): array { return []; }
    private function getDebtMinutes(Violation $violation): int { return 15; }
    private function calculateDebtDensity(string $projectPath): float { return 0.5; }
    private function calculatePrincipalDebt(array $violations): int { return 100; }
    private function calculateInterestDebt(array $violations): int { return 50; }
    private function getTopViolations(array $ruleCounts, int $limit): array { return []; }
    private function calculateViolationTrends(string $projectPath): array { return []; }
    private function analyzeFilePerformance(string $file): array { return []; }
    private function calculatePerformanceSeverity(array $issues): string { return 'medium'; }
    private function detectNPlusOneQueries(string $projectPath): int { return 0; }
    private function detectMemoryIntensiveOperations(string $projectPath): int { return 0; }
    private function detectSlowAlgorithms(string $projectPath): int { return 0; }
    private function analyzeDatabasePerformance(string $projectPath): array { return []; }
    private function calculateFileMaintainability(string $file): float { return 70.0; }
    private function calculateMaintainabilityDebt(array $files): int { return 200; }
    private function calculateImprovementPotential(array $files): float { return 0.3; }
    private function getCoverageData(string $projectPath): array { return []; }
    private function countTestFiles(string $projectPath): int { return 10; }
    private function calculateTestRatio(string $projectPath): float { return 0.2; }
    private function analyzeFileLines(string $file): array { return ['total' => 100, 'code' => 70, 'comments' => 20, 'blank' => 10]; }
    private function getLargestFiles(array $files, int $limit): array { return []; }
    private function getFileSizeDistribution(array $files): array { return []; }
    private function getCommitData(string $projectPath): array { return []; }
    private function calculateCommitFrequency(array $commitData): float { return 2.5; }
    private function calculateAuthorDistribution(array $commitData): array { return []; }
    private function calculateChangeFrequency(string $projectPath): float { return 1.2; }
    private function calculateCollaborationIndex(array $commitData): float { return 0.7; }
    private function calculateCodeOwnership(string $projectPath): array { return []; }
    private function calculateReviewMetrics(string $projectPath): array { return []; }
    private function analyzeDependencies(string $projectPath): array { return []; }
    private function countDirectDependencies(array $dependencies): int { return 20; }
    private function countTransitiveDependencies(array $dependencies): int { return 80; }
    private function calculateDependencyDepth(array $dependencies): int { return 5; }
    private function detectCircularDependencies(array $dependencies): array { return []; }
    private function detectOutdatedDependencies(array $dependencies): array { return []; }
    private function checkSecurityVulnerabilities(array $dependencies): array { return []; }
} 
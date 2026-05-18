<?php

declare(strict_types=1);

namespace ACQC\Reports\Generators;

use ACQC\Core\Config\ConfigManager;
use ACQC\Core\Rules\Violation;
use ACQC\Core\AI\PredictiveAnalytics;
use ACQC\Core\AI\QualityForecast;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Abstract Report Generator
 * 
 * Base class for all report generators providing common functionality
 * for data processing, template management, and role-based customization.
 */
abstract class AbstractReportGenerator
{
    protected ConfigManager $configManager;
    protected LoggerInterface $logger;
    protected PredictiveAnalytics $predictiveAnalytics;
    protected array $reportConfig;
    protected array $templateVars = [];
    protected string $reportType = 'summary';
    protected string $userRole = 'developer';
    
    // Report types
    public const REPORT_SUMMARY = 'summary';
    public const REPORT_DETAILED = 'detailed';
    public const REPORT_EXECUTIVE = 'executive';
    public const REPORT_SECURITY = 'security';
    public const REPORT_PERFORMANCE = 'performance';
    public const REPORT_PREDICTIVE = 'predictive';
    public const REPORT_COMPREHENSIVE_MAGENTO_AUDIT = 'comprehensive_magento_audit';
    
    // User roles
    public const ROLE_DEVELOPER = 'developer';
    public const ROLE_LEAD = 'lead';
    public const ROLE_ARCHITECT = 'architect';
    public const ROLE_MANAGER = 'manager';
    public const ROLE_EXECUTIVE = 'executive';

    public function __construct(
        ?ConfigManager $configManager = null,
        ?LoggerInterface $logger = null,
        ?PredictiveAnalytics $predictiveAnalytics = null
    ) {
        $this->configManager = $configManager ?? new ConfigManager();
        $this->logger = $logger ?? new NullLogger();
        $this->predictiveAnalytics = $predictiveAnalytics ?? new PredictiveAnalytics();
        $this->loadReportConfiguration();
    }

    /**
     * Generate report with specified data and options
     */
    abstract public function generate(array $data, array $options = []): ReportResult;

    /**
     * Get supported report types for this generator
     */
    abstract public function getSupportedTypes(): array;

    /**
     * Get output file extension
     */
    abstract public function getFileExtension(): string;

    /**
     * Get MIME type for HTTP response
     */
    abstract public function getMimeType(): string;

    /**
     * Set report type
     */
    public function setReportType(string $type): self
    {
        if (!in_array($type, $this->getSupportedTypes())) {
            throw new \InvalidArgumentException("Unsupported report type: {$type}");
        }
        $this->reportType = $type;
        return $this;
    }

    /**
     * Set user role for role-based customization
     */
    public function setUserRole(string $role): self
    {
        $this->userRole = $role;
        return $this;
    }

    /**
     * Process violations data for reporting
     */
    protected function processViolationsData(array $violations): array
    {
        // Check if it's already processed (has 'total_count', 'by_severity', etc.)
        if (isset($violations['total_count']) && isset($violations['by_severity'])) {
            // Sort the violations array by severity (critical first) so HTML templates
            // show the most important items when the array is truncated for display.
            if (!empty($violations['violations']) && is_array($violations['violations'])) {
                $sevOrder = ['blocker' => 0, 'critical' => 1, 'error' => 2, 'warning' => 3, 'info' => 4, 'minor' => 5];
                usort($violations['violations'], static function (array $a, array $b) use ($sevOrder): int {
                    $sa = $a['severity'] ?? 'info';
                    $sb = $b['severity'] ?? 'info';
                    return ($sevOrder[$sa] ?? 4) <=> ($sevOrder[$sb] ?? 4);
                });
            }
            return $violations;
        }
        
        $processed = [
            'total_count' => count($violations),
            'by_severity' => [],
            'by_category' => [],
            'by_file' => [],
            'trending' => [],
            'top_violations' => []
        ];

        // Handle empty violations
        if (empty($violations)) {
            return $processed;
        }
        
        // Check the format of the first violation
        $first = reset($violations);
        
        // Group by severity
        foreach ($violations as $violation) {
            // Handle array format (from ReportCommand)
            if (is_array($violation)) {
                $severity = $violation['severity'] ?? 'info';
                $category = $violation['type'] ?? 'other';
                $file = $violation['file'] ?? 'unknown';
            }
            // Handle Violation object format
            else if (is_object($violation) && method_exists($violation, 'getSeverity')) {
                $severity = $violation->getSeverity();
                $category = $violation->getType();
                $file = $violation->getFilePath() ?? $violation->getFile();
            }
            else {
                continue; // Skip invalid formats
            }

            $processed['by_severity'][$severity] = ($processed['by_severity'][$severity] ?? 0) + 1;
            $processed['by_category'][$category] = ($processed['by_category'][$category] ?? 0) + 1;
            $processed['by_file'][$file] = ($processed['by_file'][$file] ?? 0) + 1;
        }

        // Sort and limit
        arsort($processed['by_severity']);
        arsort($processed['by_category']);
        arsort($processed['by_file']);
        $processed['by_file'] = array_slice($processed['by_file'], 0, 20, true);

        // Get top violations
        $processed['top_violations'] = $this->getTopViolations($violations, 10);

        return $processed;
    }

    /**
     * Process metrics data for reporting
     */
    protected function processMetricsData(array $metrics): array
    {
        return [
            'quality_score' => $metrics['quality_score'] ?? $metrics['code_quality_score'] ?? 0,
            'complexity' => $this->processComplexityMetrics($metrics['complexity'] ?? []),
            'technical_debt' => $this->processTechnicalDebtMetrics($metrics['technical_debt'] ?? []),
            'test_coverage' => $this->processTestCoverageMetrics($metrics['test_coverage'] ?? []),
            'performance' => $this->processPerformanceMetrics($metrics['performance'] ?? []),
            'maintainability' => $this->processMaintainabilityMetrics($metrics['maintainability'] ?? []),
            'code_size' => $this->processCodeSizeMetrics($metrics['code_size'] ?? [])
        ];
    }

    /**
     * Generate predictive analytics data
     */
    protected function generatePredictiveData(string $projectPath): array
    {
        try {
            $forecast = $this->predictiveAnalytics->generateQualityForecast($projectPath, [
                'prediction_days' => 30,
                'lookback_days' => 90
            ]);

            if ($forecast->isSuccess()) {
                return [
                    'forecast' => $forecast->toArray(),
                    'trends' => $forecast->getTrendAnalysis() ? $forecast->getTrendAnalysis()->toArray() : [],
                    'debt_prediction' => $forecast->getDebtPrediction() ? $forecast->getDebtPrediction()->toArray() : [],
                    'risk_assessment' => $forecast->getRiskAssessment() ? $forecast->getRiskAssessment()->toArray() : [],
                    'recommendations' => $forecast->getRecommendations(),
                    'confidence' => $forecast->getConfidence()
                ];
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to generate predictive data', ['error' => $e->getMessage()]);
        }

        return ['error' => 'Predictive analytics unavailable'];
    }

    /**
     * Filter data based on user role.
     *
     * Role controls which *sections* templates present, not which data is
     * available.  Stripping core keys (violations, metrics) breaks templates
     * that always need them regardless of audience.  We therefore return all
     * data unchanged and let each template decide what to render per role.
     */
    protected function filterDataByRole(array $data): array
    {
        return $data;
    }

    /**
     * Generate chart data for visualizations
     */
    protected function generateChartData(array $data): array
    {
        $charts = [];

        // Quality score trend chart
        if (isset($data['quality_score'])) {
            $charts['quality_trend'] = $this->generateQualityTrendChart($data);
        }

        // Violations by severity pie chart
        if (isset($data['violations']['by_severity'])) {
            $charts['violations_severity'] = $this->generateViolationsSeverityChart($data['violations']['by_severity']);
        }

        // Technical debt trend chart
        if (isset($data['technical_debt'])) {
            $charts['debt_trend'] = $this->generateDebtTrendChart($data['technical_debt']);
        }

        // Complexity distribution chart
        if (isset($data['complexity'])) {
            $charts['complexity_distribution'] = $this->generateComplexityChart($data['complexity']);
        }

        // Predictive forecast chart
        if (isset($data['predictive']['forecast'])) {
            $charts['quality_forecast'] = $this->generateForecastChart($data['predictive']['forecast']);
        }

        return $charts;
    }

    /**
     * Get executive summary data
     */
    protected function getExecutiveSummary(array $data): array
    {
        return [
            'overall_score' => $data['metrics']['quality_score'] ?? $data['quality_score'] ?? 0,
            'risk_level' => $this->calculateRiskLevel($data),
            'critical_issues' => $this->getCriticalIssues($data),
            'key_metrics' => $this->getKeyMetrics($data),
            'recommendations' => $this->getTopRecommendations($data),
            'trends' => $this->getTrendSummary($data),
            'action_items' => $this->getActionItems($data)
        ];
    }

    /**
     * Calculate performance metrics
     */
    protected function calculatePerformanceMetrics(array $data): array
    {
        $startTime = microtime(true);
        $memoryStart = memory_get_usage();

        // Simulate processing
        $processed = $this->processReportData($data);

        return [
            'processing_time' => round((microtime(true) - $startTime) * 1000, 2),
            'memory_usage' => round((memory_get_usage() - $memoryStart) / 1024 / 1024, 2),
            'data_points' => $this->countDataPoints($data),
            'report_size' => strlen(serialize($processed))
        ];
    }

    /**
     * Load report configuration
     */
    private function loadReportConfiguration(): void
    {
        $this->reportConfig = $this->configManager->get('reports', [
            'types' => [
                'summary' => ['sections' => ['overview', 'violations', 'metrics']],
                'detailed' => ['sections' => ['overview', 'violations', 'metrics', 'files', 'recommendations']],
                'executive' => ['sections' => ['executive_summary', 'trends', 'risks', 'action_items']]
            ],
            'roles' => [
                'developer' => ['include' => ['violations', 'metrics', 'files']],
                'lead' => ['include' => ['violations', 'metrics', 'trends', 'recommendations']],
                'manager' => ['include' => ['overview', 'trends', 'executive_summary']],
                'executive' => ['include' => ['executive_summary', 'risks', 'action_items']]
            ],
            'formats' => [
                'html' => ['templates_dir' => 'templates/reports/html'],
                'pdf' => ['templates_dir' => 'templates/reports/pdf'],
                'excel' => ['max_rows' => 100000],
                'json' => ['pretty_print' => true]
            ]
        ]);
    }

    // Helper methods for data processing
    private function processComplexityMetrics(array $metrics): array
    {
        return [
            'average' => $metrics['average_complexity'] ?? 0,
            'distribution' => $metrics['distribution'] ?? [],
            'hotspots' => $metrics['hotspots'] ?? []
        ];
    }

    private function processTechnicalDebtMetrics(array $metrics): array
    {
        return [
            'total_hours' => $metrics['debt_hours'] ?? 0,
            'total_days' => $metrics['debt_days'] ?? 0,
            'by_category' => $metrics['debt_by_category'] ?? [],
            'ratio' => $metrics['debt_ratio'] ?? 0
        ];
    }

    private function processTestCoverageMetrics(array $metrics): array
    {
        return [
            'line_coverage' => $metrics['line_coverage'] ?? 0,
            'branch_coverage' => $metrics['branch_coverage'] ?? 0,
            'function_coverage' => $metrics['function_coverage'] ?? 0
        ];
    }

    private function processPerformanceMetrics(array $metrics): array
    {
        return [
            'issues_count' => $metrics['performance_issues'] ?? 0,
            'hotspots' => array_slice($metrics['hotspots'] ?? [], 0, 10)
        ];
    }

    private function processMaintainabilityMetrics($metrics): array
    {
        // Handle both array and scalar formats
        if (is_array($metrics)) {
            return [
                'index' => $metrics['index'] ?? $metrics['average_maintainability'] ?? 0,
                'distribution' => $metrics['distribution'] ?? []
            ];
        } else {
            // Handle scalar (float/int) format
            return [
                'index' => (float)$metrics,
                'distribution' => []
            ];
        }
    }

    private function processCodeSizeMetrics(array $metrics): array
    {
        return [
            'lines_of_code' => $metrics['lines_of_code'] ?? 0,
            'file_count' => $metrics['file_count'] ?? 0,
            'average_file_size' => $metrics['average_file_size'] ?? 0
        ];
    }

    private function getTopViolations(array $violations, int $limit): array
    {
        // Sort violations by severity and impact
        usort($violations, function($a, $b) {
            $severityOrder = ['critical' => 4, 'error' => 3, 'warning' => 2, 'info' => 1];
            return ($severityOrder[$b->getSeverity()] ?? 0) <=> ($severityOrder[$a->getSeverity()] ?? 0);
        });

        return array_slice(array_map(function($violation) {
            return [
                'rule' => $violation->getRuleId(),
                'message' => $violation->getMessage(),
                'file' => $violation->getFile(),
                'line' => $violation->getLine(),
                'severity' => $violation->getSeverity()
            ];
        }, $violations), 0, $limit);
    }

    private function generateQualityTrendChart(array $data): array
    {
        return [
            'type' => 'line',
            'title' => 'Quality Score Trend',
            'data' => ['current' => $data['quality_score']],
            'config' => ['responsive' => true]
        ];
    }

    private function generateViolationsSeverityChart(array $severityData): array
    {
        return [
            'type' => 'pie',
            'title' => 'Violations by Severity',
            'data' => $severityData,
            'config' => ['responsive' => true, 'legend' => true]
        ];
    }

    private function generateDebtTrendChart(array $debtData): array
    {
        return [
            'type' => 'bar',
            'title' => 'Technical Debt Distribution',
            'data' => $debtData['by_category'] ?? [],
            'config' => ['responsive' => true]
        ];
    }

    private function generateComplexityChart(array $complexityData): array
    {
        return [
            'type' => 'histogram',
            'title' => 'Complexity Distribution',
            'data' => $complexityData['distribution'] ?? [],
            'config' => ['responsive' => true, 'bins' => 10]
        ];
    }

    private function generateForecastChart(array $forecastData): array
    {
        return [
            'type' => 'line',
            'title' => 'Quality Forecast (30 days)',
            'data' => $forecastData,
            'config' => ['responsive' => true, 'prediction' => true]
        ];
    }

    private function calculateRiskLevel(array $data): string
    {
        $score = $data['metrics']['quality_score'] ?? $data['quality_score'] ?? 0;
        if ($score >= 80) return 'Low';
        if ($score >= 60) return 'Medium';
        if ($score >= 40) return 'High';
        return 'Critical';
    }

    private function getCriticalIssues(array $data): array
    {
        $issues = [];
        
        if (isset($data['violations']['by_severity']['critical'])) {
            $issues[] = [
                'type' => 'critical_violations',
                'count' => $data['violations']['by_severity']['critical'],
                'description' => 'Critical violations requiring immediate attention'
            ];
        }

        if (isset($data['technical_debt']['total_hours']) && $data['technical_debt']['total_hours'] > 100) {
            $issues[] = [
                'type' => 'high_technical_debt',
                'value' => $data['technical_debt']['total_hours'],
                'description' => 'High technical debt accumulation'
            ];
        }

        return $issues;
    }

    private function getKeyMetrics(array $data): array
    {
        return [
            'quality_score' => $data['metrics']['quality_score'] ?? $data['quality_score'] ?? 0,
            'total_violations' => $data['violations']['total_count'] ?? 0,
            'technical_debt' => $data['technical_debt']['total_hours'] ?? 0,
            'test_coverage' => $data['test_coverage']['line_coverage'] ?? 0,
            'maintainability' => $data['maintainability']['index'] ?? 0
        ];
    }

    private function getTopRecommendations(array $data): array
    {
        $recommendations = [];
        
        if (isset($data['predictive']['recommendations'])) {
            foreach ($data['predictive']['recommendations'] as $category => $recs) {
                if (is_array($recs)) {
                    $recommendations = array_merge($recommendations, array_slice($recs, 0, 2));
                }
            }
        }
        
        return array_slice($recommendations, 0, 5);
    }

    private function getTrendSummary(array $data): array
    {
        if (isset($data['predictive']['trends'])) {
            $trends = $data['predictive']['trends'];
            return [
                'direction' => $trends['overall_trends']['direction'] ?? 'stable',
                'strength' => $trends['overall_trends']['strength'] ?? 0,
                'confidence' => $trends['confidence'] ?? 0
            ];
        }
        
        return ['direction' => 'unknown', 'strength' => 0, 'confidence' => 0];
    }

    private function getActionItems(array $data): array
    {
        $actions = [];
        
        // Critical violations
        if (isset($data['violations']['by_severity']['critical']) && $data['violations']['by_severity']['critical'] > 0) {
            $actions[] = [
                'priority' => 'high',
                'action' => 'Address critical violations',
                'timeline' => 'immediate',
                'description' => "Fix {$data['violations']['by_severity']['critical']} critical violations"
            ];
        }
        
        // Technical debt
        if (isset($data['technical_debt']['total_hours']) && $data['technical_debt']['total_hours'] > 50) {
            $actions[] = [
                'priority' => 'medium',
                'action' => 'Reduce technical debt',
                'timeline' => 'this_sprint',
                'description' => "Address {$data['technical_debt']['total_hours']} hours of technical debt"
            ];
        }
        
        return $actions;
    }

    private function applyDataTransformation(array $data, array $transform): array
    {
        // Apply role-specific data transformations
        return $data; // Placeholder implementation
    }

    private function processReportData(array $data): array
    {
        return $data; // Placeholder implementation
    }

    protected function countDataPoints(array $data): int
    {
        $total = 0;
        foreach ($data as $value) {
            if (is_array($value) || $value instanceof \Countable) {
                $total += count($value);
            }
        }
        return $total;
    }

    protected function sanitizeForFilename(string $name): string
    {
        $name = preg_replace('/\s+(project|analysis|quality)$/i', '', $name);
        $name = strtolower(trim($name));
        $name = preg_replace('/[\s_]+/', '-', $name);
        $name = preg_replace('/[^a-z0-9-]/', '', $name);
        $name = preg_replace('/-+/', '-', $name);
        return trim($name, '-') ?: 'acqc';
    }

    protected function buildFilenamePrefix(array $options, string $fallback = 'acqc-report'): string
    {
        if (!empty($options['filename_prefix'])) {
            return $options['filename_prefix'];
        }
        if (!empty($options['project_name'])) {
            return $this->sanitizeForFilename($options['project_name']) . '-report';
        }
        return $fallback;
    }

    protected function countRules(): int
    {
        $rulesDir = dirname(__DIR__, 3) . '/config/rules';
        if (!is_dir($rulesDir)) {
            return 0;
        }
        $count  = 0;
        $iter   = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($rulesDir, \RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iter as $file) {
            if (in_array($file->getExtension(), ['yaml', 'yml'], true)) {
                $count++;
            }
        }
        return $count;
    }
}

/**
 * Report Result Container
 */
class ReportResult
{
    private bool $success;
    private string $content;
    private string $filename;
    private string $mimeType;
    private array $metadata;
    private ?string $error;

    public function __construct(
        bool $success,
        string $content = '',
        string $filename = '',
        string $mimeType = '',
        array $metadata = [],
        ?string $error = null
    ) {
        $this->success = $success;
        $this->content = $content;
        $this->filename = $filename;
        $this->mimeType = $mimeType;
        $this->metadata = $metadata;
        $this->error = $error;
    }

    public function isSuccess(): bool { return $this->success; }
    public function getContent(): string { return $this->content; }
    public function getFilename(): string { return $this->filename; }
    public function getMimeType(): string { return $this->mimeType; }
    public function getMetadata(): array { return $this->metadata; }
    public function getError(): ?string { return $this->error; }
    
    public function saveToFile(string $path): bool
    {
        if (!$this->success) {
            return false;
        }
        
        return file_put_contents($path, $this->content) !== false;
    }
} 
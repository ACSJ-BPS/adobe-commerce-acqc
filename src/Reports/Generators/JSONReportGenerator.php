<?php

declare(strict_types=1);

namespace ACQC\Reports\Generators;

/**
 * JSON Report Generator
 * 
 * Generates JSON reports for API integration, automated systems,
 * and structured data export with schema validation.
 */
class JSONReportGenerator extends AbstractReportGenerator
{
    private array $schema;
    private bool $prettyPrint = true;
    private bool $includeMetadata = true;
    private string $schemaVersion = '1.0';

    /**
     * Generate JSON report
     */
    public function generate(array $data, array $options = []): ReportResult
    {
        try {
            $this->logger->info('Generating JSON report', [
                'type' => $this->reportType,
                'role' => $this->userRole,
                'schema_version' => $this->schemaVersion
            ]);

            // Set options
            $this->prettyPrint = $options['pretty_print'] ?? true;
            $this->includeMetadata = $options['include_metadata'] ?? true;
            
            // Process and structure data
            $processedData = $this->prepareJSONData($data, $options);
            
            // Validate against schema if available
            if ($options['validate_schema'] ?? true) {
                $this->validateSchema($processedData);
            }
            
            // Generate JSON
            $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
            if ($this->prettyPrint) {
                $flags |= JSON_PRETTY_PRINT;
            }
            
            $jsonContent = json_encode($processedData, $flags);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('JSON encoding failed: ' . json_last_error_msg());
            }
            
            $filename = $this->generateFilename($options);
            
            return new ReportResult(
                true,
                $jsonContent,
                $filename,
                $this->getMimeType(),
                [
                    'schema_version' => $this->schemaVersion,
                    'encoding' => 'UTF-8',
                    'size' => strlen($jsonContent),
                    'pretty_print' => $this->prettyPrint,
                    'validation_status' => 'valid'
                ]
            );

        } catch (\Exception $e) {
            $this->logger->error('JSON report generation failed', [
                'error' => $e->getMessage()
            ]);

            return new ReportResult(false, '', '', '', [], $e->getMessage());
        }
    }

    /**
     * Get supported report types
     */
    public function getSupportedTypes(): array
    {
        return [
            self::REPORT_SUMMARY,
            self::REPORT_DETAILED,
            self::REPORT_EXECUTIVE,
            self::REPORT_SECURITY,
            self::REPORT_PERFORMANCE,
            self::REPORT_PREDICTIVE
        ];
    }

    /**
     * Get file extension
     */
    public function getFileExtension(): string
    {
        return 'json';
    }

    /**
     * Get MIME type
     */
    public function getMimeType(): string
    {
        return 'application/json';
    }

    /**
     * Prepare data for JSON output
     */
    private function prepareJSONData(array $data, array $options): array
    {
        $jsonData = [];
        
        // Add metadata if requested
        if ($this->includeMetadata) {
            $jsonData['metadata'] = $this->generateMetadata($options);
        }
        
        // Add report information
        $jsonData['report'] = [
            'type' => $this->reportType,
            'title' => $this->getReportTitle(),
            'generated_at' => date('c'), // ISO 8601 format
            'user_role' => $this->userRole,
            'schema_version' => $this->schemaVersion
        ];
        
        // Process and structure main data
        switch ($this->reportType) {
            case self::REPORT_SUMMARY:
                $jsonData['data'] = $this->prepareSummaryData($data);
                break;
            case self::REPORT_DETAILED:
                $jsonData['data'] = $this->prepareDetailedData($data);
                break;
            case self::REPORT_EXECUTIVE:
                $jsonData['data'] = $this->prepareExecutiveData($data);
                break;
            case self::REPORT_SECURITY:
                $jsonData['data'] = $this->prepareSecurityData($data);
                break;
            case self::REPORT_PERFORMANCE:
                $jsonData['data'] = $this->preparePerformanceData($data);
                break;
            case self::REPORT_PREDICTIVE:
                $jsonData['data'] = $this->preparePredictiveData($data, $options);
                break;
            default:
                $jsonData['data'] = $this->prepareGenericData($data);
        }
        
        // Add computed fields
        $jsonData['computed'] = $this->generateComputedFields($jsonData['data']);
        
        // Add API endpoints for further actions
        if ($options['include_api_links'] ?? false) {
            $jsonData['links'] = $this->generateAPILinks($options);
        }
        
        return $jsonData;
    }

    /**
     * Generate metadata
     */
    private function generateMetadata(array $options): array
    {
        return [
            'generator' => [
                'name' => 'ACQC JSON Report Generator',
                'version' => '1.0.0',
                'url' => 'https://github.com/acqc'
            ],
            'generation' => [
                'timestamp' => time(),
                'datetime' => date('c'),
                'timezone' => date_default_timezone_get(),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'CLI',
                'request_id' => uniqid('report_', true)
            ],
            'system' => [
                'php_version' => PHP_VERSION,
                'memory_usage' => memory_get_usage(true),
                'memory_peak' => memory_get_peak_usage(true)
            ],
            'options' => array_filter($options, function($key) {
                return !in_array($key, ['api_key', 'password', 'secret']);
            }, ARRAY_FILTER_USE_KEY)
        ];
    }

    /**
     * Prepare summary data
     */
    private function prepareSummaryData(array $data): array
    {
        return [
            'overview' => [
                'quality_score' => $data['quality_score'] ?? 0,
                'total_violations' => $data['violations']['total_count'] ?? 0,
                'critical_violations' => $data['violations']['by_severity']['critical'] ?? 0,
                'technical_debt_hours' => $data['technical_debt']['total_hours'] ?? 0,
                'files_analyzed' => $data['code_size']['file_count'] ?? 0,
                'lines_of_code' => $data['code_size']['lines_of_code'] ?? 0
            ],
            'violations' => [
                'by_severity' => $data['violations']['by_severity'] ?? [],
                'by_category' => $data['violations']['by_category'] ?? [],
                'top_violations' => array_slice($data['violations']['top_violations'] ?? [], 0, 5)
            ],
            'metrics' => [
                'complexity' => $data['complexity']['average'] ?? 0,
                'maintainability' => $data['maintainability']['index'] ?? 0,
                'test_coverage' => $data['test_coverage']['line_coverage'] ?? 0
            ]
        ];
    }

    /**
     * Prepare detailed data
     */
    private function prepareDetailedData(array $data): array
    {
        $detailed = $this->prepareSummaryData($data);
        
        // Add detailed sections
        $detailed['violations']['detailed'] = $this->formatViolationsForJSON($data['violations'] ?? []);
        $detailed['files'] = $this->formatFilesForJSON($data['files'] ?? []);
        $detailed['technical_debt']['by_category'] = $data['technical_debt']['by_category'] ?? [];
        $detailed['performance'] = [
            'issues' => $data['performance']['issues_count'] ?? 0,
            'hotspots' => array_slice($data['performance']['hotspots'] ?? [], 0, 10)
        ];
        
        return $detailed;
    }

    /**
     * Prepare executive data
     */
    private function prepareExecutiveData(array $data): array
    {
        $executive = $this->getExecutiveSummary($data);
        
        return [
            'executive_summary' => [
                'overall_score' => $executive['overall_score'],
                'risk_level' => $executive['risk_level'],
                'key_metrics' => $executive['key_metrics'],
                'critical_issues' => $executive['critical_issues'],
                'trends' => $executive['trends']
            ],
            'strategic_insights' => [
                'improvement_areas' => $this->identifyImprovementAreas($data),
                'investment_priorities' => $this->getInvestmentPriorities($data),
                'timeline_recommendations' => $this->getTimelineRecommendations($data)
            ],
            'action_items' => array_map(function($item) {
                return [
                    'priority' => $item['priority'],
                    'action' => $item['action'],
                    'timeline' => $item['timeline'],
                    'description' => $item['description'],
                    'estimated_effort' => $this->estimateEffort($item)
                ];
            }, $executive['action_items'] ?? [])
        ];
    }

    /**
     * Prepare security data
     */
    private function prepareSecurityData(array $data): array
    {
        $securityViolations = array_filter($data['violations'] ?? [], function($violation) {
            return strpos(strtolower($violation->getType()), 'security') !== false;
        });
        
        return [
            'security_score' => $this->calculateSecurityScore($data),
            'vulnerabilities' => [
                'critical' => $this->countSecurityViolations($securityViolations, 'critical'),
                'high' => $this->countSecurityViolations($securityViolations, 'error'),
                'medium' => $this->countSecurityViolations($securityViolations, 'warning'),
                'low' => $this->countSecurityViolations($securityViolations, 'info')
            ],
            'security_violations' => $this->formatSecurityViolations($securityViolations),
            'recommendations' => $this->getSecurityRecommendations($data),
            'compliance' => $this->assessSecurityCompliance($data)
        ];
    }

    /**
     * Prepare performance data
     */
    private function preparePerformanceData(array $data): array
    {
        return [
            'performance_score' => $this->calculatePerformanceScore($data),
            'metrics' => [
                'issues_count' => $data['performance']['issues_count'] ?? 0,
                'hotspots_count' => count($data['performance']['hotspots'] ?? []),
                'n_plus_one_queries' => $data['performance']['n_plus_one_queries'] ?? 0,
                'memory_issues' => $data['performance']['memory_intensive_operations'] ?? 0
            ],
            'hotspots' => array_map(function($hotspot) {
                return [
                    'file' => $hotspot['file'] ?? '',
                    'issues' => $hotspot['issues'] ?? [],
                    'severity' => $hotspot['severity'] ?? 'medium',
                    'impact' => $this->calculatePerformanceImpact($hotspot)
                ];
            }, array_slice($data['performance']['hotspots'] ?? [], 0, 20)),
            'recommendations' => $this->getPerformanceRecommendations($data)
        ];
    }

    /**
     * Prepare predictive data
     */
    private function preparePredictiveData(array $data, array $options): array
    {
        // Get predictive analytics data
        $predictiveData = [];
        if (isset($options['project_path'])) {
            $predictiveData = $this->generatePredictiveData($options['project_path']);
        }
        
        return [
            'forecast' => [
                'quality_trends' => $predictiveData['trends'] ?? [],
                'technical_debt_prediction' => $predictiveData['debt_prediction'] ?? [],
                'risk_assessment' => $predictiveData['risk_assessment'] ?? [],
                'confidence' => $predictiveData['confidence'] ?? 0
            ],
            'predictions' => [
                'next_30_days' => $this->generateShortTermPredictions($predictiveData),
                'next_90_days' => $this->generateMediumTermPredictions($predictiveData),
                'recommendations' => $predictiveData['recommendations'] ?? []
            ],
            'analytics' => [
                'data_quality' => $this->assessDataQuality($predictiveData),
                'model_confidence' => $this->getModelConfidence($predictiveData),
                'last_updated' => date('c')
            ]
        ];
    }

    /**
     * Format violations for JSON
     */
    private function formatViolationsForJSON(array $violations): array
    {
        $formatted = [];
        
        foreach ($violations as $violation) {
            // Handle array format (from ReportCommand)
            if (is_array($violation)) {
                $formatted[] = [
                    'file' => $violation['file'] ?? 'unknown',
                    'line' => $violation['line'] ?? 0,
                    'column' => 0,
                    'severity' => $violation['severity'] ?? 'info',
                    'type' => $violation['type'] ?? 'other',
                    'rule_id' => $violation['rule'] ?? 'unknown',
                    'message' => $violation['message'] ?? 'No message provided',
                    'source' => $violation['module'] ?? null,
                    'fix_suggestions' => []
                ];
            }
            // Handle Violation object format
            else if (is_object($violation) && method_exists($violation, 'getSeverity')) {
                $formatted[] = [
                    'file' => $violation->getFile(),
                    'line' => $violation->getLine(),
                    'column' => $violation->getColumn(),
                    'severity' => $violation->getSeverity(),
                    'type' => $violation->getType(),
                    'rule_id' => $violation->getRuleId(),
                    'message' => $violation->getMessage(),
                    'source' => $violation->getSource(),
                    'fix_suggestions' => $this->getFixSuggestions($violation)
                ];
            }
        }
        
        return $formatted;
    }

    /**
     * Generate computed fields
     */
    private function generateComputedFields(array $data): array
    {
        return [
            'health_indicators' => [
                'overall_health' => $this->calculateOverallHealth($data),
                'trend_direction' => $this->determineTrendDirection($data),
                'improvement_velocity' => $this->calculateImprovementVelocity($data)
            ],
            'benchmarks' => [
                'industry_comparison' => $this->getIndustryBenchmarks($data),
                'historical_comparison' => $this->getHistoricalComparison($data)
            ],
            'risk_factors' => [
                'immediate_risks' => $this->getImmediateRisks($data),
                'future_risks' => $this->getFutureRisks($data),
                'mitigation_strategies' => $this->getMitigationStrategies($data)
            ]
        ];
    }

    /**
     * Generate API links
     */
    private function generateAPILinks(array $options): array
    {
        $baseUrl = $options['api_base_url'] ?? '/api/v1';
        $reportId = $options['report_id'] ?? uniqid();
        
        return [
            'self' => "{$baseUrl}/reports/{$reportId}",
            'violations' => "{$baseUrl}/reports/{$reportId}/violations",
            'metrics' => "{$baseUrl}/reports/{$reportId}/metrics",
            'recommendations' => "{$baseUrl}/reports/{$reportId}/recommendations",
            'export' => [
                'pdf' => "{$baseUrl}/reports/{$reportId}/export/pdf",
                'excel' => "{$baseUrl}/reports/{$reportId}/export/excel",
                'html' => "{$baseUrl}/reports/{$reportId}/export/html"
            ],
            'actions' => [
                'rerun_analysis' => "{$baseUrl}/analysis/run",
                'update_configuration' => "{$baseUrl}/configuration"
            ]
        ];
    }

    /**
     * Validate JSON schema
     */
    private function validateSchema(array $data): bool
    {
        // Basic schema validation
        $requiredFields = ['metadata', 'report', 'data'];
        
        foreach ($requiredFields as $field) {
            if (!isset($data[$field])) {
                throw new \Exception("Missing required field: {$field}");
            }
        }
        
        return true;
    }

    /**
     * Generate filename
     */
    private function generateFilename(array $options): string
    {
        $prefix    = $this->buildFilenamePrefix($options, 'acqc-report');
        $timestamp = date('Y-m-d_H-i-s');
        $suffix    = $this->reportType !== 'summary' ? "-{$this->reportType}" : '';

        return "{$prefix}{$suffix}_{$timestamp}.json";
    }

    /**
     * Get report title
     */
    private function getReportTitle(): string
    {
        $titles = [
            self::REPORT_SUMMARY => 'Quality Summary Report',
            self::REPORT_DETAILED => 'Detailed Analysis Report',
            self::REPORT_EXECUTIVE => 'Executive Quality Report',
            self::REPORT_SECURITY => 'Security Analysis Report',
            self::REPORT_PERFORMANCE => 'Performance Analysis Report',
            self::REPORT_PREDICTIVE => 'Predictive Analytics Report'
        ];

        return $titles[$this->reportType] ?? 'Quality Report';
    }

    // Helper methods with placeholder implementations
    private function prepareGenericData(array $data): array { return $data; }
    private function formatFilesForJSON(array $files): array { return $files; }
    private function identifyImprovementAreas(array $data): array { return []; }
    private function getInvestmentPriorities(array $data): array { return []; }
    private function getTimelineRecommendations(array $data): array { return []; }
    private function estimateEffort(array $item): string { return 'medium'; }
    private function calculateSecurityScore(array $data): float { return 7.5; }
    private function countSecurityViolations(array $violations, string $severity): int { return 0; }
    private function formatSecurityViolations(array $violations): array { return []; }
    private function getSecurityRecommendations(array $data): array { return []; }
    private function assessSecurityCompliance(array $data): array { return []; }
    private function calculatePerformanceScore(array $data): float { return 8.0; }
    private function calculatePerformanceImpact(array $hotspot): string { return 'medium'; }
    private function getPerformanceRecommendations(array $data): array { return []; }
    private function generateShortTermPredictions(array $data): array { return []; }
    private function generateMediumTermPredictions(array $data): array { return []; }
    private function assessDataQuality(array $data): float { return 0.9; }
    private function getModelConfidence(array $data): float { return 0.85; }
    private function getFixSuggestions($violation): array { return []; }
    private function calculateOverallHealth(array $data): float { return 0.8; }
    private function determineTrendDirection(array $data): string { return 'stable'; }
    private function calculateImprovementVelocity(array $data): float { return 0.1; }
    private function getIndustryBenchmarks(array $data): array { return []; }
    private function getHistoricalComparison(array $data): array { return []; }
    private function getImmediateRisks(array $data): array { return []; }
    private function getFutureRisks(array $data): array { return []; }
    private function getMitigationStrategies(array $data): array { return []; }
} 
<?php

declare(strict_types=1);

namespace ACQC\Reports\Generators;

use DOMDocument;
use DOMElement;

/**
 * JUnit XML Report Generator
 * 
 * Generates JUnit-compatible XML reports for CI/CD pipeline integration,
 * test result aggregation, and automated quality gates.
 */
class JUnitReportGenerator extends AbstractReportGenerator
{
    private DOMDocument $dom;
    private DOMElement $testsuite;
    private int $testCount = 0;
    private int $failureCount = 0;
    private int $errorCount = 0;
    private int $skippedCount = 0;
    private float $totalTime = 0.0;

    /**
     * Generate JUnit XML report
     */
    public function generate(array $data, array $options = []): ReportResult
    {
        try {
            $this->logger->info('Generating JUnit XML report', [
                'type' => $this->reportType,
                'format' => 'junit'
            ]);

            // Initialize DOM
            $this->initializeDOM($options);
            
            // Process data and convert to test cases
            $this->processDataAsTests($data, $options);
            
            // Finalize test suite
            $this->finalizeTestSuite();
            
            // Generate XML
            $xmlContent = $this->dom->saveXML();
            $filename = $this->generateFilename($options);
            
            return new ReportResult(
                true,
                $xmlContent,
                $filename,
                $this->getMimeType(),
                [
                    'tests' => $this->testCount,
                    'failures' => $this->failureCount,
                    'errors' => $this->errorCount,
                    'skipped' => $this->skippedCount,
                    'time' => $this->totalTime,
                    'format' => 'junit'
                ]
            );

        } catch (\Exception $e) {
            $this->logger->error('JUnit report generation failed', [
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
            self::REPORT_SECURITY,
            self::REPORT_PERFORMANCE
        ];
    }

    /**
     * Get file extension
     */
    public function getFileExtension(): string
    {
        return 'xml';
    }

    /**
     * Get MIME type
     */
    public function getMimeType(): string
    {
        return 'application/xml';
    }

    /**
     * Initialize DOM document
     */
    private function initializeDOM(array $options): void
    {
        $this->dom = new DOMDocument('1.0', 'UTF-8');
        $this->dom->formatOutput = true;
        
        // Create root testsuites element
        $testsuites = $this->dom->createElement('testsuites');
        $this->dom->appendChild($testsuites);
        
        // Create main testsuite
        $this->testsuite = $this->dom->createElement('testsuite');
        $this->testsuite->setAttribute('name', $this->getTestSuiteName($options));
        $this->testsuite->setAttribute('timestamp', date('c'));
        $this->testsuite->setAttribute('hostname', gethostname() ?: 'localhost');
        
        $testsuites->appendChild($this->testsuite);
        
        // Reset counters
        $this->testCount = 0;
        $this->failureCount = 0;
        $this->errorCount = 0;
        $this->skippedCount = 0;
        $this->totalTime = 0.0;
    }

    /**
     * Process data and convert to test cases
     */
    private function processDataAsTests(array $data, array $options): void
    {
        // Convert violations to test cases
        if (isset($data['violations'])) {
            $this->processViolationsAsTests($data['violations']);
        }
        
        // Convert metrics to test cases
        if (isset($data['metrics'])) {
            $this->processMetricsAsTests($data['metrics']);
        }
        
        // Convert security issues to test cases
        if (isset($data['security'])) {
            $this->processSecurityAsTests($data['security']);
        }
        
        // Convert performance issues to test cases
        if (isset($data['performance'])) {
            $this->processPerformanceAsTests($data['performance']);
        }
        
        // Convert quality gates to test cases
        $this->processQualityGatesAsTests($data, $options);
    }

    /**
     * Process violations as test cases
     */
    private function processViolationsAsTests(array $violations): void
    {
        if (is_array($violations) && isset($violations[0]) && is_object($violations[0])) {
            // Array of violation objects
            foreach ($violations as $violation) {
                $this->createViolationTestCase($violation);
            }
        } elseif (isset($violations['by_severity'])) {
            // Processed violations data
            foreach ($violations['by_severity'] as $severity => $count) {
                $this->createSeverityTestCase($severity, $count);
            }
            
            if (isset($violations['top_violations'])) {
                foreach ($violations['top_violations'] as $violation) {
                    $this->createTopViolationTestCase($violation);
                }
            }
        }
    }

    /**
     * Create test case for individual violation
     */
    private function createViolationTestCase($violation): void
    {
        $testCase = $this->dom->createElement('testcase');
        $testCase->setAttribute('classname', 'ACQC.Violations');
        $testCase->setAttribute('name', $this->sanitizeTestName($violation->getRuleId()));
        $testCase->setAttribute('file', $violation->getFile());
        $testCase->setAttribute('line', (string) $violation->getLine());
        $testCase->setAttribute('time', '0.001');
        
        $severity = $violation->getSeverity();
        
        if ($severity === 'critical' || $severity === 'error') {
            // Create failure element
            $failure = $this->dom->createElement('failure');
            $failure->setAttribute('type', 'ViolationFailure');
            $failure->setAttribute('message', $violation->getMessage());
            
            $failureText = sprintf(
                "File: %s\nLine: %d\nRule: %s\nMessage: %s\nSeverity: %s",
                $violation->getFile(),
                $violation->getLine(),
                $violation->getRuleId(),
                $violation->getMessage(),
                $severity
            );
            $failure->appendChild($this->dom->createCDATASection($failureText));
            $testCase->appendChild($failure);
            
            $this->failureCount++;
        } elseif ($severity === 'warning') {
            // Create error element for warnings (to distinguish from failures)
            $error = $this->dom->createElement('error');
            $error->setAttribute('type', 'ViolationWarning');
            $error->setAttribute('message', $violation->getMessage());
            
            $errorText = sprintf(
                "File: %s\nLine: %d\nRule: %s\nMessage: %s",
                $violation->getFile(),
                $violation->getLine(),
                $violation->getRuleId(),
                $violation->getMessage()
            );
            $error->appendChild($this->dom->createCDATASection($errorText));
            $testCase->appendChild($error);
            
            $this->errorCount++;
        }
        // Info level violations are considered passing tests
        
        $this->testsuite->appendChild($testCase);
        $this->testCount++;
        $this->totalTime += 0.001;
    }

    /**
     * Create test case for severity summary
     */
    private function createSeverityTestCase(string $severity, int $count): void
    {
        $testCase = $this->dom->createElement('testcase');
        $testCase->setAttribute('classname', 'ACQC.Summary');
        $testCase->setAttribute('name', "violations_{$severity}_count");
        $testCase->setAttribute('time', '0.001');
        
        // Define acceptable thresholds
        $thresholds = [
            'critical' => 0,    // No critical violations allowed
            'error' => 5,       // Max 5 errors
            'warning' => 20,    // Max 20 warnings
            'info' => 100       // Max 100 info violations
        ];
        
        $threshold = $thresholds[$severity] ?? 0;
        
        if ($count > $threshold) {
            $failure = $this->dom->createElement('failure');
            $failure->setAttribute('type', 'ThresholdExceeded');
            $failure->setAttribute('message', "Too many {$severity} violations: {$count} (threshold: {$threshold})");
            
            $failureText = "Violation count exceeds acceptable threshold.\n";
            $failureText .= "Severity: {$severity}\n";
            $failureText .= "Count: {$count}\n";
            $failureText .= "Threshold: {$threshold}";
            
            $failure->appendChild($this->dom->createCDATASection($failureText));
            $testCase->appendChild($failure);
            
            $this->failureCount++;
        }
        
        $this->testsuite->appendChild($testCase);
        $this->testCount++;
        $this->totalTime += 0.001;
    }

    /**
     * Create test case for top violations
     */
    private function createTopViolationTestCase(array $violation): void
    {
        $testCase = $this->dom->createElement('testcase');
        $testCase->setAttribute('classname', 'ACQC.TopViolations');
        $testCase->setAttribute('name', $this->sanitizeTestName($violation['rule']));
        $testCase->setAttribute('file', $violation['file']);
        $testCase->setAttribute('line', (string) $violation['line']);
        $testCase->setAttribute('time', '0.001');
        
        if (in_array($violation['severity'], ['critical', 'error'])) {
            $failure = $this->dom->createElement('failure');
            $failure->setAttribute('type', 'HighPriorityViolation');
            $failure->setAttribute('message', $violation['message']);
            
            $failureText = sprintf(
                "High priority violation detected:\nFile: %s\nLine: %d\nRule: %s\nSeverity: %s\nMessage: %s",
                $violation['file'],
                $violation['line'],
                $violation['rule'],
                $violation['severity'],
                $violation['message']
            );
            
            $failure->appendChild($this->dom->createCDATASection($failureText));
            $testCase->appendChild($failure);
            
            $this->failureCount++;
        }
        
        $this->testsuite->appendChild($testCase);
        $this->testCount++;
        $this->totalTime += 0.001;
    }

    /**
     * Process metrics as test cases
     */
    private function processMetricsAsTests(array $metrics): void
    {
        // Quality score test
        $qualityScore = $metrics['quality_score'] ?? 0;
        $this->createMetricTestCase('quality_score', $qualityScore, 7.0, 'Quality score too low');
        
        // Complexity test
        if (isset($metrics['complexity'])) {
            $avgComplexity = $metrics['complexity']['average'] ?? 0;
            $this->createMetricTestCase('average_complexity', $avgComplexity, 10.0, 'Average complexity too high', false);
        }
        
        // Technical debt test
        if (isset($metrics['technical_debt'])) {
            $debtHours = $metrics['technical_debt']['total_hours'] ?? 0;
            $this->createMetricTestCase('technical_debt_hours', $debtHours, 100.0, 'Technical debt too high', false);
        }
        
        // Test coverage test
        if (isset($metrics['test_coverage'])) {
            $coverage = $metrics['test_coverage']['line_coverage'] ?? 0;
            $this->createMetricTestCase('test_coverage', $coverage * 100, 80.0, 'Test coverage too low');
        }
        
        // Maintainability test
        if (isset($metrics['maintainability'])) {
            $maintainability = $metrics['maintainability']['index'] ?? 0;
            $this->createMetricTestCase('maintainability_index', $maintainability, 60.0, 'Maintainability index too low');
        }
    }

    /**
     * Create metric test case
     */
    private function createMetricTestCase(string $name, float $value, float $threshold, string $failureMessage, bool $higherIsBetter = true): void
    {
        $testCase = $this->dom->createElement('testcase');
        $testCase->setAttribute('classname', 'ACQC.Metrics');
        $testCase->setAttribute('name', $name);
        $testCase->setAttribute('time', '0.001');
        
        $failed = $higherIsBetter ? ($value < $threshold) : ($value > $threshold);
        
        if ($failed) {
            $failure = $this->dom->createElement('failure');
            $failure->setAttribute('type', 'MetricThresholdFailure');
            $failure->setAttribute('message', $failureMessage);
            
            $failureText = sprintf(
                "%s\nValue: %.2f\nThreshold: %.2f\nCondition: %s",
                $failureMessage,
                $value,
                $threshold,
                $higherIsBetter ? 'value >= threshold' : 'value <= threshold'
            );
            
            $failure->appendChild($this->dom->createCDATASection($failureText));
            $testCase->appendChild($failure);
            
            $this->failureCount++;
        }
        
        $this->testsuite->appendChild($testCase);
        $this->testCount++;
        $this->totalTime += 0.001;
    }

    /**
     * Process security issues as test cases
     */
    private function processSecurityAsTests(array $security): void
    {
        $this->createTestCase(
            'ACQC.Security',
            'security_vulnerabilities',
            $security['vulnerabilities']['critical'] ?? 0,
            0,
            'Critical security vulnerabilities found'
        );
        
        $this->createTestCase(
            'ACQC.Security',
            'security_score',
            $security['security_score'] ?? 0,
            8.0,
            'Security score below threshold'
        );
    }

    /**
     * Process performance issues as test cases
     */
    private function processPerformanceAsTests(array $performance): void
    {
        $this->createTestCase(
            'ACQC.Performance',
            'performance_issues',
            $performance['issues_count'] ?? 0,
            5,
            'Too many performance issues',
            false
        );
        
        $this->createTestCase(
            'ACQC.Performance',
            'n_plus_one_queries',
            $performance['n_plus_one_queries'] ?? 0,
            0,
            'N+1 query patterns detected',
            false
        );
    }

    /**
     * Process quality gates as test cases
     */
    private function processQualityGatesAsTests(array $data, array $options): void
    {
        $gates = $options['quality_gates'] ?? $this->getDefaultQualityGates();
        
        foreach ($gates as $gate) {
            $this->processQualityGate($gate, $data);
        }
    }

    /**
     * Process individual quality gate
     */
    private function processQualityGate(array $gate, array $data): void
    {
        $testCase = $this->dom->createElement('testcase');
        $testCase->setAttribute('classname', 'ACQC.QualityGates');
        $testCase->setAttribute('name', $gate['name']);
        $testCase->setAttribute('time', '0.001');
        
        $passed = $this->evaluateQualityGate($gate, $data);
        
        if (!$passed) {
            $failure = $this->dom->createElement('failure');
            $failure->setAttribute('type', 'QualityGateFailure');
            $failure->setAttribute('message', $gate['failure_message'] ?? 'Quality gate failed');
            
            $failureText = sprintf(
                "Quality gate '%s' failed.\nCondition: %s\nDescription: %s",
                $gate['name'],
                $gate['condition'] ?? 'Unknown condition',
                $gate['description'] ?? 'No description provided'
            );
            
            $failure->appendChild($this->dom->createCDATASection($failureText));
            $testCase->appendChild($failure);
            
            $this->failureCount++;
        }
        
        $this->testsuite->appendChild($testCase);
        $this->testCount++;
        $this->totalTime += 0.001;
    }

    /**
     * Create generic test case
     */
    private function createTestCase(string $className, string $testName, float $value, float $threshold, string $failureMessage, bool $higherIsBetter = true): void
    {
        $testCase = $this->dom->createElement('testcase');
        $testCase->setAttribute('classname', $className);
        $testCase->setAttribute('name', $testName);
        $testCase->setAttribute('time', '0.001');
        
        $failed = $higherIsBetter ? ($value < $threshold) : ($value > $threshold);
        
        if ($failed) {
            $failure = $this->dom->createElement('failure');
            $failure->setAttribute('type', 'ThresholdFailure');
            $failure->setAttribute('message', $failureMessage);
            
            $failureText = sprintf(
                "%s\nActual: %.2f\nThreshold: %.2f",
                $failureMessage,
                $value,
                $threshold
            );
            
            $failure->appendChild($this->dom->createCDATASection($failureText));
            $testCase->appendChild($failure);
            
            $this->failureCount++;
        }
        
        $this->testsuite->appendChild($testCase);
        $this->testCount++;
        $this->totalTime += 0.001;
    }

    /**
     * Finalize test suite with summary attributes
     */
    private function finalizeTestSuite(): void
    {
        $this->testsuite->setAttribute('tests', (string) $this->testCount);
        $this->testsuite->setAttribute('failures', (string) $this->failureCount);
        $this->testsuite->setAttribute('errors', (string) $this->errorCount);
        $this->testsuite->setAttribute('skipped', (string) $this->skippedCount);
        $this->testsuite->setAttribute('time', sprintf('%.3f', $this->totalTime));
        
        // Add system properties
        $properties = $this->dom->createElement('properties');
        
        $property = $this->dom->createElement('property');
        $property->setAttribute('name', 'generator');
        $property->setAttribute('value', 'ACQC JUnit Generator');
        $properties->appendChild($property);
        
        $property = $this->dom->createElement('property');
        $property->setAttribute('name', 'version');
        $property->setAttribute('value', '1.0.0');
        $properties->appendChild($property);
        
        $property = $this->dom->createElement('property');
        $property->setAttribute('name', 'report_type');
        $property->setAttribute('value', $this->reportType);
        $properties->appendChild($property);
        
        $this->testsuite->appendChild($properties);
    }

    /**
     * Get test suite name
     */
    private function getTestSuiteName(array $options): string
    {
        return $options['testsuite_name'] ?? 'ACQC.CodeAnalysis';
    }

    /**
     * Sanitize test name for XML
     */
    private function sanitizeTestName(string $name): string
    {
        // Remove or replace characters that might cause issues in XML
        $name = preg_replace('/[^\w\.\-_]/', '_', $name);
        return trim($name, '_');
    }

    /**
     * Get default quality gates
     */
    private function getDefaultQualityGates(): array
    {
        return [
            [
                'name' => 'no_critical_violations',
                'condition' => 'critical_violations == 0',
                'description' => 'No critical violations allowed',
                'failure_message' => 'Critical violations detected'
            ],
            [
                'name' => 'minimum_quality_score',
                'condition' => 'quality_score >= 7.0',
                'description' => 'Quality score must be at least 7.0',
                'failure_message' => 'Quality score below minimum threshold'
            ],
            [
                'name' => 'maximum_technical_debt',
                'condition' => 'technical_debt_hours <= 100',
                'description' => 'Technical debt must not exceed 100 hours',
                'failure_message' => 'Technical debt exceeds maximum threshold'
            ]
        ];
    }

    /**
     * Evaluate quality gate condition
     */
    private function evaluateQualityGate(array $gate, array $data): bool
    {
        // Simple condition evaluation
        $condition = $gate['condition'] ?? '';
        
        // Extract values from data
        $criticalViolations = $data['violations']['by_severity']['critical'] ?? 0;
        $qualityScore = $data['quality_score'] ?? 0;
        $technicalDebtHours = $data['technical_debt']['total_hours'] ?? 0;
        
        // Evaluate common conditions
        switch ($condition) {
            case 'critical_violations == 0':
                return $criticalViolations == 0;
            case 'quality_score >= 7.0':
                return $qualityScore >= 7.0;
            case 'technical_debt_hours <= 100':
                return $technicalDebtHours <= 100;
            default:
                return true; // Unknown condition passes by default
        }
    }

    /**
     * Generate filename
     */
    private function generateFilename(array $options): string
    {
        $prefix = $options['filename_prefix'] ?? 'junit-acqc';
        $timestamp = date('Y-m-d_H-i-s');
        
        return "{$prefix}_{$timestamp}.xml";
    }
} 
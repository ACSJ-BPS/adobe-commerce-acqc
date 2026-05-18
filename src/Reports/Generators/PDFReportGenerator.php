<?php

declare(strict_types=1);

namespace ACQC\Reports\Generators;

use TCPDF;

/**
 * PDF Report Generator
 * 
 * Generates professional PDF reports using TCPDF library with charts,
 * tables, and custom layouts for different user roles.
 */
class PDFReportGenerator extends AbstractReportGenerator
{
    private TCPDF $pdf;
    private array $colors;
    private int $currentY = 0;
    private int $pageBreakThreshold = 250;

    public function __construct($configManager = null, $logger = null, $predictiveAnalytics = null)
    {
        parent::__construct($configManager, $logger, $predictiveAnalytics);
        $this->initializeColors();
    }

    /**
     * Generate PDF report
     */
    public function generate(array $data, array $options = []): ReportResult
    {
        if (!class_exists('TCPDF')) {
            return new ReportResult(
                false, '', '', '',
                [],
                'PDF generation requires TCPDF. Install it with: composer require tecnickcom/tcpdf'
            );
        }

        try {
            $this->logger->info('Generating PDF report', [
                'type' => $this->reportType,
                'role' => $this->userRole
            ]);

            // Initialize PDF
            $this->initializePDF($options);
            
            // Process data
            $processedData = $this->prepareReportData($data, $options);
            
            // Generate report sections
            $this->generateReportContent($processedData, $options);
            
            // Get PDF content
            $pdfContent = $this->pdf->Output('', 'S');
            $filename = $this->generateFilename($options);
            
            return new ReportResult(
                true,
                $pdfContent,
                $filename,
                $this->getMimeType(),
                [
                    'pages' => $this->pdf->getNumPages(),
                    'size' => strlen($pdfContent),
                    'orientation' => $options['orientation'] ?? 'portrait',
                    'format' => $options['format'] ?? 'A4'
                ]
            );

        } catch (\Exception $e) {
            $this->logger->error('PDF report generation failed', [
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
            self::REPORT_PERFORMANCE
        ];
    }

    /**
     * Get file extension
     */
    public function getFileExtension(): string
    {
        return 'pdf';
    }

    /**
     * Get MIME type
     */
    public function getMimeType(): string
    {
        return 'application/pdf';
    }

    /**
     * Initialize PDF settings
     */
    private function initializePDF(array $options): void
    {
        $this->pdf = new TCPDF(
            $options['orientation'] ?? 'P',
            'mm',
            $options['format'] ?? 'A4',
            true,
            'UTF-8',
            false
        );

        // Set document information
        $this->pdf->SetCreator('ACQC');
        $this->pdf->SetAuthor('ACQC Report Generator');
        $this->pdf->SetTitle($this->getReportTitle());
        $this->pdf->SetSubject('Code Quality Analysis Report');
        $this->pdf->SetKeywords('code quality, magento, analysis, report');

        // Set margins
        $this->pdf->SetMargins(15, 27, 15);
        $this->pdf->SetHeaderMargin(5);
        $this->pdf->SetFooterMargin(10);

        // Set auto page breaks
        $this->pdf->SetAutoPageBreak(true, 25);

        // Set font
        $this->pdf->SetFont('helvetica', '', 10);

        // Add header and footer
        $this->setupHeaderFooter($options);
    }

    /**
     * Setup header and footer
     */
    private function setupHeaderFooter(array $options): void
    {
        // Custom header
        $this->pdf->setHeaderCallback(function($pdf) use ($options) {
            $pdf->SetY(15);
            $pdf->SetFont('helvetica', 'B', 16);
            $pdf->Cell(0, 15, $this->getReportTitle(), 0, false, 'C', 0, '', 0, false, 'M', 'M');
            $pdf->SetY(25);
            $pdf->SetFont('helvetica', '', 10);
            $pdf->Cell(0, 10, 'Generated: ' . date('Y-m-d H:i:s'), 0, false, 'R', 0, '', 0, false, 'M', 'M');
            $pdf->Line(15, 30, 195, 30);
        });

        // Custom footer
        $this->pdf->setFooterCallback(function($pdf) {
            $pdf->SetY(-15);
            $pdf->SetFont('helvetica', 'I', 8);
            $pdf->Cell(0, 10, 'Page ' . $pdf->getAliasNumPage() . '/' . $pdf->getAliasNbPages(), 0, false, 'C', 0, '', 0, false, 'T', 'M');
        });
    }

    /**
     * Generate report content
     */
    private function generateReportContent(array $data, array $options): void
    {
        $this->pdf->AddPage();
        $this->currentY = 40;

        // Generate sections based on report type and role
        $sections = $this->getSections();

        foreach ($sections as $section => $title) {
            if (isset($data[$section]) || $section === 'executive_summary') {
                $this->generateSection($section, $title, $data, $options);
            }
        }
    }

    /**
     * Generate individual section
     */
    private function generateSection(string $section, string $title, array $data, array $options): void
    {
        $this->checkPageBreak(40);
        
        switch ($section) {
            case 'executive_summary':
                $this->generateExecutiveSummary($data);
                break;
            case 'overview':
                $this->generateOverview($data);
                break;
            case 'violations':
                $this->generateViolationsSection($data['violations'] ?? []);
                break;
            case 'metrics':
                $this->generateMetricsSection($data['metrics'] ?? []);
                break;
            case 'technical_debt':
                $this->generateTechnicalDebtSection($data['technical_debt'] ?? []);
                break;
            case 'performance':
                $this->generatePerformanceSection($data['performance'] ?? []);
                break;
            case 'predictive':
                $this->generatePredictiveSection($data['predictive'] ?? []);
                break;
            case 'recommendations':
                $this->generateRecommendationsSection($data);
                break;
        }
    }

    /**
     * Generate executive summary
     */
    private function generateExecutiveSummary(array $data): void
    {
        $this->addSectionTitle('Executive Summary');
        
        $summary = $this->getExecutiveSummary($data);
        
        // Overall score with visual indicator
        $this->addScoreCard('Overall Quality Score', $summary['overall_score'], 10);
        
        // Risk level
        $this->addInfoBox('Risk Level', $summary['risk_level']);
        
        // Key metrics table
        $this->addKeyMetricsTable($summary['key_metrics']);
        
        // Critical issues
        if (!empty($summary['critical_issues'])) {
            $this->addCriticalIssues($summary['critical_issues']);
        }
        
        // Top recommendations
        if (!empty($summary['recommendations'])) {
            $this->addTopRecommendations($summary['recommendations']);
        }
    }

    /**
     * Generate overview section
     */
    private function generateOverview(array $data): void
    {
        $this->addSectionTitle('Project Overview');
        
        $overview = [
            'Quality Score' => number_format($data['quality_score'] ?? 0, 1),
            'Total Violations' => number_format($data['violations']['total_count'] ?? 0),
            'Technical Debt' => ($data['technical_debt']['total_hours'] ?? 0) . ' hours',
            'Files Analyzed' => number_format($data['code_size']['file_count'] ?? 0),
            'Lines of Code' => number_format($data['code_size']['lines_of_code'] ?? 0)
        ];
        
        $this->addKeyValueTable($overview);
    }

    /**
     * Generate violations section
     */
    private function generateViolationsSection(array $violations): void
    {
        $this->addSectionTitle('Code Violations Analysis');
        
        // Violations by severity chart
        if (!empty($violations['by_severity'])) {
            $this->addPieChart('Violations by Severity', $violations['by_severity']);
        }
        
        // Top violations table
        if (!empty($violations['top_violations'])) {
            $this->addViolationsTable($violations['top_violations']);
        }
        
        // Violations by category
        if (!empty($violations['by_category'])) {
            $this->addBarChart('Violations by Category', $violations['by_category']);
        }
    }

    /**
     * Generate metrics section
     */
    private function generateMetricsSection(array $metrics): void
    {
        $this->addSectionTitle('Quality Metrics');
        
        // Complexity metrics
        if (isset($metrics['complexity'])) {
            $this->addComplexityMetrics($metrics['complexity']);
        }
        
        // Test coverage
        if (isset($metrics['test_coverage'])) {
            $this->addTestCoverageMetrics($metrics['test_coverage']);
        }
        
        // Maintainability
        if (isset($metrics['maintainability'])) {
            $this->addMaintainabilityMetrics($metrics['maintainability']);
        }
    }

    /**
     * Generate technical debt section
     */
    private function generateTechnicalDebtSection(array $debt): void
    {
        $this->addSectionTitle('Technical Debt Analysis');
        
        // Debt overview
        $debtOverview = [
            'Total Debt' => ($debt['total_hours'] ?? 0) . ' hours',
            'Debt Ratio' => number_format(($debt['ratio'] ?? 0) * 100, 2) . '%',
            'Estimated Days' => number_format($debt['total_days'] ?? 0, 1) . ' days'
        ];
        
        $this->addKeyValueTable($debtOverview);
        
        // Debt by category
        if (!empty($debt['by_category'])) {
            $this->addBarChart('Technical Debt by Category', $debt['by_category']);
        }
    }

    /**
     * Generate performance section
     */
    private function generatePerformanceSection(array $performance): void
    {
        $this->addSectionTitle('Performance Analysis');
        
        $perfData = [
            'Performance Issues' => number_format($performance['issues_count'] ?? 0),
            'Hotspots Identified' => count($performance['hotspots'] ?? [])
        ];
        
        $this->addKeyValueTable($perfData);
        
        // Performance hotspots
        if (!empty($performance['hotspots'])) {
            $this->addPerformanceHotspots($performance['hotspots']);
        }
    }

    /**
     * Generate predictive section
     */
    private function generatePredictiveSection(array $predictive): void
    {
        $this->addSectionTitle('Predictive Analytics');
        
        if (isset($predictive['forecast'])) {
            $this->addPredictiveForecast($predictive['forecast']);
        }
        
        if (isset($predictive['risk_assessment'])) {
            $this->addRiskAssessment($predictive['risk_assessment']);
        }
    }

    /**
     * Generate recommendations section
     */
    private function generateRecommendationsSection(array $data): void
    {
        $this->addSectionTitle('Recommendations');
        
        $recommendations = [];
        
        // Collect recommendations from various sources
        if (isset($data['predictive']['recommendations'])) {
            foreach ($data['predictive']['recommendations'] as $category => $recs) {
                if (is_array($recs)) {
                    $recommendations = array_merge($recommendations, $recs);
                }
            }
        }
        
        if (!empty($recommendations)) {
            $this->addRecommendationsList($recommendations);
        }
    }

    /**
     * Add section title
     */
    private function addSectionTitle(string $title): void
    {
        $this->checkPageBreak(20);
        
        $this->pdf->SetY($this->currentY);
        $this->pdf->SetFont('helvetica', 'B', 14);
        $this->pdf->SetTextColor($this->colors['primary']['r'], $this->colors['primary']['g'], $this->colors['primary']['b']);
        $this->pdf->Cell(0, 10, $title, 0, 1, 'L');
        $this->pdf->Line(15, $this->currentY + 8, 195, $this->currentY + 8);
        $this->currentY += 15;
        
        $this->pdf->SetFont('helvetica', '', 10);
        $this->pdf->SetTextColor(0, 0, 0);
    }

    /**
     * Add score card with visual indicator
     */
    private function addScoreCard(string $label, float $score, float $maxScore): void
    {
        $this->checkPageBreak(30);
        
        $percentage = ($score / $maxScore) * 100;
        $color = $this->getScoreColor($percentage);
        
        $this->pdf->SetY($this->currentY);
        $this->pdf->SetFont('helvetica', 'B', 12);
        $this->pdf->Cell(60, 10, $label . ':', 0, 0, 'L');
        
        // Score value
        $this->pdf->SetTextColor($color['r'], $color['g'], $color['b']);
        $this->pdf->Cell(30, 10, number_format($score, 1) . '/' . $maxScore, 0, 0, 'L');
        
        // Progress bar
        $this->addProgressBar($percentage, 100, 15);
        
        $this->currentY += 20;
        $this->pdf->SetTextColor(0, 0, 0);
    }

    /**
     * Add key-value table
     */
    private function addKeyValueTable(array $data): void
    {
        $this->checkPageBreak(count($data) * 8 + 20);
        
        foreach ($data as $key => $value) {
            $this->pdf->SetY($this->currentY);
            $this->pdf->Cell(80, 8, $key . ':', 1, 0, 'L', false);
            $this->pdf->Cell(80, 8, $value, 1, 1, 'L', false);
            $this->currentY += 8;
        }
        
        $this->currentY += 5;
    }

    /**
     * Add simple pie chart
     */
    private function addPieChart(string $title, array $data): void
    {
        $this->checkPageBreak(60);
        
        $this->pdf->SetY($this->currentY);
        $this->pdf->SetFont('helvetica', 'B', 11);
        $this->pdf->Cell(0, 10, $title, 0, 1, 'L');
        $this->currentY += 15;
        
        // Simple text representation of pie chart data
        foreach ($data as $label => $value) {
            $this->pdf->SetY($this->currentY);
            $this->pdf->Cell(60, 6, $label . ':', 0, 0, 'L');
            $this->pdf->Cell(30, 6, number_format($value), 0, 1, 'R');
            $this->currentY += 6;
        }
        
        $this->currentY += 10;
    }

    /**
     * Add bar chart (text representation)
     */
    private function addBarChart(string $title, array $data): void
    {
        $this->checkPageBreak(count($data) * 8 + 25);
        
        $this->pdf->SetY($this->currentY);
        $this->pdf->SetFont('helvetica', 'B', 11);
        $this->pdf->Cell(0, 10, $title, 0, 1, 'L');
        $this->currentY += 15;
        
        $maxValue = max($data);
        
        foreach ($data as $label => $value) {
            $this->pdf->SetY($this->currentY);
            $this->pdf->Cell(60, 6, $label . ':', 0, 0, 'L');
            $this->pdf->Cell(20, 6, number_format($value), 0, 0, 'R');
            
            // Simple bar representation
            $barWidth = ($value / $maxValue) * 80;
            $this->pdf->SetFillColor(70, 130, 180);
            $this->pdf->Rect(85, $this->currentY + 1, $barWidth, 4, 'F');
            
            $this->currentY += 8;
        }
        
        $this->currentY += 10;
    }

    /**
     * Add progress bar
     */
    private function addProgressBar(float $percentage, float $width, float $y): void
    {
        $x = 105;
        $height = 6;
        
        // Background
        $this->pdf->SetFillColor(220, 220, 220);
        $this->pdf->Rect($x, $y, $width, $height, 'F');
        
        // Progress
        $color = $this->getScoreColor($percentage);
        $this->pdf->SetFillColor($color['r'], $color['g'], $color['b']);
        $progressWidth = ($percentage / 100) * $width;
        $this->pdf->Rect($x, $y, $progressWidth, $height, 'F');
        
        // Border
        $this->pdf->SetDrawColor(100, 100, 100);
        $this->pdf->Rect($x, $y, $width, $height, 'D');
    }

    /**
     * Check if page break is needed
     */
    private function checkPageBreak(int $requiredSpace): void
    {
        if ($this->currentY + $requiredSpace > $this->pageBreakThreshold) {
            $this->pdf->AddPage();
            $this->currentY = 40;
        }
    }

    /**
     * Initialize color palette
     */
    private function initializeColors(): void
    {
        $this->colors = [
            'primary' => ['r' => 0, 'g' => 123, 'b' => 255],
            'success' => ['r' => 40, 'g' => 167, 'b' => 69],
            'warning' => ['r' => 255, 'g' => 193, 'b' => 7],
            'danger' => ['r' => 220, 'g' => 53, 'b' => 69],
            'info' => ['r' => 23, 'g' => 162, 'b' => 184]
        ];
    }

    /**
     * Get color based on score
     */
    private function getScoreColor(float $percentage): array
    {
        if ($percentage >= 80) return $this->colors['success'];
        if ($percentage >= 60) return $this->colors['info'];
        if ($percentage >= 40) return $this->colors['warning'];
        return $this->colors['danger'];
    }

    /**
     * Generate filename
     */
    private function generateFilename(array $options): string
    {
        $prefix    = $this->buildFilenamePrefix($options, 'acqc-report');
        $timestamp = date('Y-m-d_H-i-s');
        $suffix    = $this->reportType !== 'summary' ? "-{$this->reportType}" : '';

        return "{$prefix}{$suffix}_{$timestamp}.pdf";
    }

    /**
     * Get report title
     */
    private function getReportTitle(): string
    {
        $titles = [
            self::REPORT_SUMMARY => 'ACQC Summary Report',
            self::REPORT_DETAILED => 'ACQC Detailed Analysis',
            self::REPORT_EXECUTIVE => 'ACQC Executive Report',
            self::REPORT_SECURITY => 'ACQC Security Analysis',
            self::REPORT_PERFORMANCE => 'ACQC Performance Report'
        ];

        return $titles[$this->reportType] ?? 'ACQC Analysis Report';
    }

    /**
     * Get sections for current report type
     */
    private function getSections(): array
    {
        $sections = [
            self::REPORT_SUMMARY => [
                'overview' => 'Overview',
                'violations' => 'Violations',
                'metrics' => 'Metrics'
            ],
            self::REPORT_DETAILED => [
                'overview' => 'Overview',
                'violations' => 'Violations',
                'metrics' => 'Metrics',
                'technical_debt' => 'Technical Debt',
                'performance' => 'Performance',
                'recommendations' => 'Recommendations'
            ],
            self::REPORT_EXECUTIVE => [
                'executive_summary' => 'Executive Summary',
                'overview' => 'Overview',
                'predictive' => 'Predictive Analytics'
            ]
        ];

        return $sections[$this->reportType] ?? $sections[self::REPORT_SUMMARY];
    }

    // Additional helper methods
    private function addInfoBox(string $label, string $value): void
    {
        $this->checkPageBreak(15);
        $this->pdf->SetY($this->currentY);
        $this->pdf->SetFont('helvetica', 'B', 10);
        $this->pdf->Cell(40, 8, $label . ':', 0, 0, 'L');
        $this->pdf->SetFont('helvetica', '', 10);
        $this->pdf->Cell(0, 8, $value, 0, 1, 'L');
        $this->currentY += 12;
    }

    private function addKeyMetricsTable(array $metrics): void
    {
        $this->addKeyValueTable($metrics);
    }

    private function addCriticalIssues(array $issues): void
    {
        $this->pdf->SetY($this->currentY);
        $this->pdf->SetFont('helvetica', 'B', 11);
        $this->pdf->Cell(0, 10, 'Critical Issues:', 0, 1, 'L');
        $this->currentY += 15;
        
        foreach ($issues as $issue) {
            $this->pdf->SetY($this->currentY);
            $this->pdf->Cell(0, 6, '• ' . $issue['description'], 0, 1, 'L');
            $this->currentY += 6;
        }
        $this->currentY += 5;
    }

    private function addTopRecommendations(array $recommendations): void
    {
        $this->pdf->SetY($this->currentY);
        $this->pdf->SetFont('helvetica', 'B', 11);
        $this->pdf->Cell(0, 10, 'Top Recommendations:', 0, 1, 'L');
        $this->currentY += 15;
        
        foreach (array_slice($recommendations, 0, 3) as $rec) {
            $text = is_string($rec) ? $rec : ($rec['description'] ?? 'Improvement recommended');
            $this->pdf->SetY($this->currentY);
            $this->pdf->Cell(0, 6, '• ' . $text, 0, 1, 'L');
            $this->currentY += 6;
        }
        $this->currentY += 5;
    }

    private function addViolationsTable(array $violations): void
    {
        $this->checkPageBreak(count($violations) * 8 + 20);
        
        // Headers
        $this->pdf->SetY($this->currentY);
        $this->pdf->SetFont('helvetica', 'B', 9);
        $this->pdf->Cell(30, 8, 'Severity', 1, 0, 'C');
        $this->pdf->Cell(80, 8, 'File', 1, 0, 'C');
        $this->pdf->Cell(20, 8, 'Line', 1, 0, 'C');
        $this->pdf->Cell(50, 8, 'Rule', 1, 1, 'C');
        $this->currentY += 8;
        
        // Data
        $this->pdf->SetFont('helvetica', '', 8);
        foreach (array_slice($violations, 0, 10) as $violation) {
            $this->pdf->SetY($this->currentY);
            $this->pdf->Cell(30, 6, $violation['severity'], 1, 0, 'L');
            $this->pdf->Cell(80, 6, basename($violation['file']), 1, 0, 'L');
            $this->pdf->Cell(20, 6, $violation['line'], 1, 0, 'C');
            $this->pdf->Cell(50, 6, substr($violation['rule'], 0, 20), 1, 1, 'L');
            $this->currentY += 6;
        }
        $this->currentY += 10;
    }

    private function prepareReportData(array $data, array $options): array
    {
        // Process violations
        if (isset($data['violations'])) {
            $data['violations'] = $this->processViolationsData($data['violations']);
        }

        // Process metrics
        if (isset($data['metrics'])) {
            $data['metrics'] = $this->processMetricsData($data['metrics']);
        }

        // Add predictive analytics if requested
        if (($options['include_predictive'] ?? false) && isset($options['project_path'])) {
            $data['predictive'] = $this->generatePredictiveData($options['project_path']);
        }

        return $this->filterDataByRole($data);
    }

    // Placeholder methods for additional sections
    private function addComplexityMetrics(array $complexity): void { /* Implementation */ }
    private function addTestCoverageMetrics(array $coverage): void { /* Implementation */ }
    private function addMaintainabilityMetrics(array $maintainability): void { /* Implementation */ }
    private function addPerformanceHotspots(array $hotspots): void { /* Implementation */ }
    private function addPredictiveForecast(array $forecast): void { /* Implementation */ }
    private function addRiskAssessment(array $risk): void { /* Implementation */ }
    private function addRecommendationsList(array $recommendations): void { /* Implementation */ }
} 
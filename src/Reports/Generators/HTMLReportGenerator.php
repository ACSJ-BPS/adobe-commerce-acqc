<?php

declare(strict_types=1);

namespace ACQC\Reports\Generators;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\Extension\DebugExtension;

/**
 * HTML Report Generator
 * 
 * Generates interactive HTML reports with charts, tables, and responsive design.
 * Uses Twig templating engine for flexible report customization.
 */
class HTMLReportGenerator extends AbstractReportGenerator
{
    private Environment $twig;
    private string $templatesDir;
    private array $chartLibraries = ['chart.js', 'plotly.js'];

    public function __construct($configManager = null, $logger = null, $predictiveAnalytics = null)
    {
        parent::__construct($configManager, $logger, $predictiveAnalytics);
        $relativeTemplatesDir = $this->reportConfig['formats']['html']['templates_dir'] ?? 'templates/reports/html';
        $this->templatesDir = $this->getPackageRoot() . '/' . $relativeTemplatesDir;
        $this->initializeTwig();
    }

    /**
     * Root of the ACQC package itself, where bundled templates live — as opposed to
     * getcwd(), which is the target project being analyzed and reported on.
     */
    private function getPackageRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * Generate HTML report
     */
    public function generate(array $data, array $options = []): ReportResult
    {
        try {
            $this->logger->info('Generating HTML report', [
                'type' => $this->reportType,
                'role' => $this->userRole
            ]);

            // Process and filter data
            $processedData = $this->prepareReportData($data, $options);
            
            // Generate charts
            $charts = $this->generateChartData($processedData);
            
            // Prepare template variables
            $templateVars = $this->prepareTemplateVariables($processedData, $charts, $options);
            
            // Render template
            $template = $this->getTemplate();
            $htmlContent = $this->twig->render($template, $templateVars);
            
            // Post-process HTML
            $htmlContent = $this->postProcessHTML($htmlContent, $options);
            
            $filename = $this->generateFilename($options);
            
            return new ReportResult(
                true,
                $htmlContent,
                $filename,
                $this->getMimeType(),
                [
                    'report_type' => $this->reportType,
                    'user_role' => $this->userRole,
                    'charts_count' => count($charts),
                    'generation_time' => date('Y-m-d H:i:s'),
                    'data_points' => $this->countDataPoints($processedData)
                ]
            );

        } catch (\Throwable $e) {
            $this->logger->error('HTML report generation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
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
        return 'html';
    }

    /**
     * Get MIME type
     */
    public function getMimeType(): string
    {
        return 'text/html';
    }

    /**
     * Initialize Twig templating engine
     */
    private function initializeTwig(): void
    {
        $loader = new FilesystemLoader($this->templatesDir);
        $this->twig = new Environment($loader, [
            'cache' => $this->getPackageRoot() . '/var/cache/twig',
            'debug' => true,
            'auto_reload' => true
        ]);

        $this->twig->addExtension(new DebugExtension());
        $this->addCustomTwigFilters();
        $this->addCustomTwigFunctions();
    }

    /**
     * Prepare data for reporting
     */
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

        // Filter by role
        $data = $this->filterDataByRole($data);

        // Add computed fields
        $data['computed'] = $this->computeAdditionalFields($data);

        return $data;
    }

    /**
     * Prepare template variables
     */
    private function prepareTemplateVariables(array $data, array $charts, array $options): array
    {
        $data['rules_evaluated'] = $this->countRules();

        $filesAnalyzed = max(
            (int) ($data['metrics']['files_analyzed'] ?? 0),
            (int) ($data['total_files'] ?? 0),
            (int) ($data['code_size']['file_count'] ?? 0)
        );

        // Fall back to unique files mentioned in violations when no other source is available
        if ($filesAnalyzed === 0) {
            $allViolations = $data['violations']['violations'] ?? $data['violations']['all'] ?? [];
            $uniqueFiles = [];
            foreach ($allViolations as $v) {
                $f = $v['file'] ?? $v['file_path'] ?? null;
                if ($f) {
                    $uniqueFiles[$f] = true;
                }
            }
            $filesAnalyzed = count($uniqueFiles);
        }

        $data['metrics']['files_analyzed'] = $filesAnalyzed;

        $vars = [
            'report' => [
                'title'        => $this->getReportTitle(),
                'type'         => $this->reportType,
                'user_role'    => $this->userRole,
                'generated_at' => date('Y-m-d H:i:s'),
                'project_name' => $options['project_name'] ?? 'M2 Quality Analysis',
                'version'      => $options['version'] ?? '1.0.0'
            ],
            'data' => $data,
            'charts' => $charts,
            'options' => $options,
            'theme' => $options['theme'] ?? 'default',
            'interactive' => $options['interactive'] ?? true,
            'chart_library' => $options['chart_library'] ?? 'chart.js'
        ];

        // Add role-specific sections
        $vars['sections'] = $this->getRoleSections();

        // Add executive summary for appropriate roles
        if (in_array($this->userRole, [self::ROLE_MANAGER, self::ROLE_EXECUTIVE])) {
            $vars['executive_summary'] = $this->getExecutiveSummary($data);
            // Attach APM performance targets (from docs/references/metrics.md)
            // so executive/performance templates can render RAG status indicators.
            $vars['performance_targets'] = $this->getPerformanceTargets($data['metrics'] ?? []);
        }

        return $vars;
    }

    /**
     * Get appropriate template for current report type and role
     */
    private function getTemplate(): string
    {
        $templates = [
            self::REPORT_SUMMARY => 'summary.html.twig',
            self::REPORT_DETAILED => 'detailed.html.twig',
            self::REPORT_EXECUTIVE => 'executive.html.twig',
            self::REPORT_SECURITY => 'security.html.twig',
            self::REPORT_PERFORMANCE => 'performance.html.twig',
            self::REPORT_PREDICTIVE => 'predictive.html.twig'
        ];

        $template = $templates[$this->reportType] ?? 'summary.html.twig';

        // Check for role-specific template
        $roleTemplate = str_replace('.html.twig', "_{$this->userRole}.html.twig", $template);
        if ($this->templateExists($roleTemplate)) {
            return $roleTemplate;
        }

        return $template;
    }

    /**
     * Post-process HTML content
     */
    private function postProcessHTML(string $html, array $options): string
    {
        // Inject chart libraries
        if ($options['interactive'] ?? true) {
            $html = $this->injectChartLibraries($html, $options['chart_library'] ?? 'chart.js');
        }

        // Inject custom CSS/JS
        if (isset($options['custom_css'])) {
            $html = $this->injectCustomCSS($html, $options['custom_css']);
        }

        if (isset($options['custom_js'])) {
            $html = $this->injectCustomJS($html, $options['custom_js']);
        }

        // Minify HTML if requested
        if ($options['minify'] ?? false) {
            $html = $this->minifyHTML($html);
        }

        return $html;
    }

    /**
     * Inject chart libraries
     */
    private function injectChartLibraries(string $html, string $library): string
    {
        $cdnLinks = [
            'chart.js' => '<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>',
            'plotly.js' => '<script src="https://cdn.plot.ly/plotly-latest.min.js"></script>',
            'd3.js' => '<script src="https://d3js.org/d3.v7.min.js"></script>'
        ];

        $libraryTag = $cdnLinks[$library] ?? $cdnLinks['chart.js'];
        
        // Inject before closing head tag
        $html = str_replace('</head>', $libraryTag . "\n</head>", $html);

        // Add chart initialization script
        $chartScript = $this->generateChartScript($library);
        $html = str_replace('</body>', $chartScript . "\n</body>", $html);

        return $html;
    }

    /**
     * Generate chart initialization script
     */
    private function generateChartScript(string $library): string
    {
        $script = "<script>\n";
        
        switch ($library) {
            case 'chart.js':
                $script .= $this->generateChartJSScript();
                break;
            case 'plotly.js':
                $script .= $this->generatePlotlyScript();
                break;
            case 'd3.js':
                $script .= $this->generateD3Script();
                break;
        }
        
        $script .= "\n</script>";
        return $script;
    }

    /**
     * Generate Chart.js initialization script
     */
    private function generateChartJSScript(): string
    {
        return "
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize all charts
            const chartElements = document.querySelectorAll('[data-chart]');
            chartElements.forEach(element => {
                const chartData = JSON.parse(element.getAttribute('data-chart-data'));
                const chartConfig = JSON.parse(element.getAttribute('data-chart-config') || '{}');
                
                const ctx = element.getContext('2d');
                new Chart(ctx, {
                    type: chartData.type,
                    data: chartData.data,
                    options: {
                        responsive: true,
                        plugins: {
                            title: {
                                display: true,
                                text: chartData.title
                            },
                            legend: {
                                display: chartConfig.legend !== false
                            }
                        },
                        ...chartConfig
                    }
                });
            });
            
            // Add interactivity
            addChartInteractivity();
        });
        
        function addChartInteractivity() {
            // Add click handlers for chart drill-down
            document.querySelectorAll('.chart-container').forEach(container => {
                container.addEventListener('click', function(e) {
                    if (this.dataset.drilldown) {
                        const drilldownUrl = this.dataset.drilldown;
                        if (confirm('View detailed analysis?')) {
                            window.open(drilldownUrl, '_blank');
                        }
                    }
                });
            });
        }";
    }

    /**
     * Generate Plotly.js initialization script
     */
    private function generatePlotlyScript(): string
    {
        return "
        document.addEventListener('DOMContentLoaded', function() {
            const chartElements = document.querySelectorAll('[data-chart]');
            chartElements.forEach(element => {
                const chartData = JSON.parse(element.getAttribute('data-chart-data'));
                const chartConfig = JSON.parse(element.getAttribute('data-chart-config') || '{}');
                
                const layout = {
                    title: chartData.title,
                    responsive: true,
                    ...chartConfig
                };
                
                Plotly.newPlot(element, chartData.data, layout);
            });
        });";
    }

    /**
     * Generate D3.js initialization script
     */
    private function generateD3Script(): string
    {
        return "
        document.addEventListener('DOMContentLoaded', function() {
            // D3.js chart initialization
            initializeD3Charts();
        });
        
        function initializeD3Charts() {
            // Custom D3 chart implementations
            console.log('D3 charts initialized');
        }";
    }

    /**
     * Add custom Twig filters
     */
    private function addCustomTwigFilters(): void
    {
        // Severity color filter
        $this->twig->addFilter(new \Twig\TwigFilter('severity_color', function($severity) {
            $colors = [
                'blocker'  => '#6f42c1',  // purple — release stopper
                'critical' => '#dc3545',  // red — urgent
                'error'    => '#fd7e14',  // orange — must fix
                'warning'  => '#ffc107',  // yellow — should fix
                'info'     => '#17a2b8',  // blue — informational
                'minor'    => '#6c757d',  // grey — trivial
            ];
            return $colors[strtolower((string) $severity)] ?? '#6c757d';
        }));

        // Format file size filter
        $this->twig->addFilter(new \Twig\TwigFilter('filesize', function($bytes) {
            $units = ['B', 'KB', 'MB', 'GB'];
            $bytes = max($bytes, 0);
            $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
            $pow = min($pow, count($units) - 1);
            $bytes /= (1 << (10 * $pow));
            return round($bytes, 2) . ' ' . $units[$pow];
        }));

        // Percentage filter
        $this->twig->addFilter(new \Twig\TwigFilter('percentage', function($value, $decimals = 1) {
            return number_format($value * 100, $decimals) . '%';
        }));

        // Duration filter
        $this->twig->addFilter(new \Twig\TwigFilter('duration', function($minutes) {
            if ($minutes < 60) {
                return round($minutes) . ' min';
            } else if ($minutes < 1440) {
                return round($minutes / 60, 1) . ' hours';
            } else {
                return round($minutes / 1440, 1) . ' days';
            }
        }));
    }

    /**
     * Add custom Twig functions
     */
    private function addCustomTwigFunctions(): void
    {
        // Chart function
        $this->twig->addFunction(new \Twig\TwigFunction('chart', function($chartData, $options = []) {
            $id = 'chart_' . uniqid();
            $dataAttr = htmlspecialchars(json_encode($chartData), ENT_QUOTES, 'UTF-8');
            $configAttr = htmlspecialchars(json_encode($options), ENT_QUOTES, 'UTF-8');
            
            return sprintf(
                '<canvas id="%s" data-chart="true" data-chart-data="%s" data-chart-config="%s"></canvas>',
                $id,
                $dataAttr,
                $configAttr
            );
        }, ['is_safe' => ['html']]));

        // Progress bar function
        $this->twig->addFunction(new \Twig\TwigFunction('progress_bar', function($value, $max = 100, $class = 'primary') {
            $percentage = ($value / $max) * 100;
            return sprintf(
                '<div class="progress"><div class="progress-bar bg-%s" style="width: %.1f%%">%.1f%%</div></div>',
                $class,
                $percentage,
                $percentage
            );
        }, ['is_safe' => ['html']]));

        // Badge function
        $this->twig->addFunction(new \Twig\TwigFunction('badge', function($text, $type = 'secondary') {
            return sprintf('<span class="badge bg-%s">%s</span>', $type, htmlspecialchars($text));
        }, ['is_safe' => ['html']]));
    }

    /**
     * Generate filename for the report
     */
    private function generateFilename(array $options): string
    {
        $prefix    = $this->buildFilenamePrefix($options, 'acqc-report');
        $timestamp = date('Y-m-d_H-i-s');
        $suffix    = $this->reportType !== 'summary' ? "-{$this->reportType}" : '';

        return "{$prefix}{$suffix}_{$timestamp}.html";
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

    /**
     * Get sections based on user role
     */
    private function getRoleSections(): array
    {
        $allSections = [
            'overview' => 'Project Overview',
            'violations' => 'Code Violations',
            'metrics' => 'Quality Metrics',
            'complexity' => 'Code Complexity',
            'technical_debt' => 'Technical Debt',
            'test_coverage' => 'Test Coverage',
            'performance' => 'Performance Analysis',
            'security' => 'Security Analysis',
            'predictive' => 'Predictive Analytics',
            'recommendations' => 'Recommendations',
            'files' => 'File Analysis',
            'trends' => 'Quality Trends',
            'executive_summary' => 'Executive Summary'
        ];

        $roleConfig = $this->reportConfig['roles'][$this->userRole] ?? [];
        $includeSections = $roleConfig['include'] ?? array_keys($allSections);

        $sections = [];
        foreach ($includeSections as $section) {
            if (isset($allSections[$section])) {
                $sections[$section] = $allSections[$section];
            }
        }

        return $sections;
    }

    /**
     * Compute additional fields for the report
     */
    private function computeAdditionalFields(array $data): array
    {
        return [
            'health_score' => $this->calculateHealthScore($data),
            'improvement_areas' => $this->identifyImprovementAreas($data),
            'priority_fixes' => $this->getPriorityFixes($data),
            'trends' => $this->computeTrends($data)
        ];
    }

    /**
     * Check if template exists
     */
    private function templateExists(string $template): bool
    {
        return file_exists($this->templatesDir . '/' . $template);
    }

    /**
     * Inject custom CSS
     */
    private function injectCustomCSS(string $html, string $css): string
    {
        $cssTag = "<style>\n{$css}\n</style>";
        return str_replace('</head>', $cssTag . "\n</head>", $html);
    }

    /**
     * Inject custom JavaScript
     */
    private function injectCustomJS(string $html, string $js): string
    {
        $jsTag = "<script>\n{$js}\n</script>";
        return str_replace('</body>', $jsTag . "\n</body>", $html);
    }

    /**
     * Minify HTML content
     */
    private function minifyHTML(string $html): string
    {
        // Remove comments
        $html = preg_replace('/<!--.*?-->/s', '', $html);
        
        // Remove extra whitespace
        $html = preg_replace('/\s+/', ' ', $html);
        
        // Remove whitespace around tags
        $html = preg_replace('/>\s+</', '><', $html);
        
        return trim($html);
    }

    // Helper calculation methods
    private function calculateHealthScore(array $data): float
    {
        $score = $data['quality_score'] ?? 5.0;
        return min(100, max(0, $score * 10));
    }

    private function identifyImprovementAreas(array $data): array
    {
        $areas = [];
        
        if (isset($data['violations']['by_severity']['critical']) && $data['violations']['by_severity']['critical'] > 0) {
            $areas[] = 'critical_violations';
        }
        
        if (isset($data['technical_debt']['total_hours']) && $data['technical_debt']['total_hours'] > 50) {
            $areas[] = 'technical_debt';
        }
        
        if (isset($data['test_coverage']['line_coverage']) && $data['test_coverage']['line_coverage'] < 80) {
            $areas[] = 'test_coverage';
        }
        
        return $areas;
    }

    private function getPriorityFixes(array $data): array
    {
        return $data['violations']['top_violations'] ?? [];
    }

    private function computeTrends(array $data): array
    {
        return $data['predictive']['trends'] ?? [];
    }

    /**
     * Build a performance targets comparison array for executive/performance reports.
     *
     * Targets are sourced from config/reports.yaml performance_targets section
     * (populated from docs/references/metrics.md). Each entry includes the target
     * value, measured value (if available in $metrics), and a RAG status:
     *   green  — at or better than target
     *   amber  — within 20% of target
     *   red    — worse than target by more than 20%
     */
    private function getPerformanceTargets(array $metrics): array
    {
        $configured = $this->reportConfig['performance_targets'] ?? [];

        $defaults = [
            'apdex_score'               => ['target' => 0.9,  'label' => 'Apdex Score',       'comparison' => '>=', 'unit' => ''],
            'average_response_time_ms'  => ['target' => 200,  'label' => 'Avg Response Time',  'comparison' => '<=', 'unit' => 'ms'],
            'error_rate_percent'        => ['target' => 1.0,  'label' => 'Error Rate',          'comparison' => '<=', 'unit' => '%'],
            'db_query_time_ms'          => ['target' => 50,   'label' => 'DB Query Time',       'comparison' => '<=', 'unit' => 'ms'],
        ];

        $targets = array_merge($defaults, $configured);
        $result  = [];

        foreach ($targets as $key => $config) {
            $target   = (float) ($config['target'] ?? 0);
            $measured = isset($metrics[$key]) ? (float) $metrics[$key] : null;
            $status   = 'unknown';

            if ($measured !== null) {
                $isGe = ($config['comparison'] ?? '<=') === '>=';
                if ($isGe) {
                    $status = $measured >= $target ? 'green' : ($measured >= $target * 0.8 ? 'amber' : 'red');
                } else {
                    $status = $measured <= $target ? 'green' : ($measured <= $target * 1.2 ? 'amber' : 'red');
                }
            }

            $result[$key] = [
                'label'      => $config['label'] ?? $key,
                'target'     => $target,
                'measured'   => $measured,
                'unit'       => $config['unit'] ?? '',
                'comparison' => $config['comparison'] ?? '<=',
                'status'     => $status,
            ];
        }

        return $result;
    }
} 
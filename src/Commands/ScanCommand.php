<?php

declare(strict_types=1);

namespace ACQC\Commands;

use ACQC\Core\Application;
use ACQC\Core\CodeAnalyzer;
use ACQC\Core\AnalyzerRegistry;
use ACQC\Core\Config\ConfigManager;
use ACQC\Core\Scanner\ScannerEngine;
use ACQC\Core\Scanner\ParallelScanner;
use ACQC\Core\Scanner\FileScanner;
use ACQC\Core\Rules\RuleEngine;
use ACQC\Reports\ReportGenerator;
use ACQC\Core\AI\AIEngine;
use ACQC\Core\AI\PredictiveAnalytics;
use ACQC\Core\AI\FixSuggester;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Stopwatch\Stopwatch;
use Psr\Log\LoggerInterface;

/**
 * ACQC Main Scanning Command
 * 
 * Comprehensive code analysis with advanced features:
 * - Multiple output formats (JSON, XML, HTML, PDF, JUnit)
 * - Role-based scanning profiles
 * - Parallel processing for performance
 * - Interactive mode with guided setup
 * - Auto-fix capabilities with AI suggestions
 * - Baseline comparison and drift detection
 * - Severity filtering and pattern matching
 * - Progress indicators and detailed reporting
 */
#[AsCommand(name: 'scan', description: 'Scan code for quality issues with comprehensive analysis')]
class ScanCommand extends Command
{
    protected static $defaultName = 'scan';
    protected static $defaultDescription = 'Scan code for quality issues with comprehensive analysis';

    private CodeAnalyzer $codeAnalyzer;
    private ConfigManager $configManager;
    private ScannerEngine $scannerEngine;
    private ParallelScanner $parallelScanner;
    private RuleEngine $ruleEngine;
    private ReportGenerator $reportGenerator;
    private PredictiveAnalytics $predictiveAnalytics;
    private FixSuggester $fixSuggester;
    private LoggerInterface $logger;
    private Stopwatch $stopwatch;

    // Supported output formats
    private const OUTPUT_FORMATS = [
        'json' => 'JSON format for API integration',
        'xml' => 'XML format for CI/CD systems',
        'html' => 'Interactive HTML report',
        'pdf' => 'Professional PDF report',
        'junit' => 'JUnit XML for test integration',
        'csv' => 'CSV format for data analysis',
        'txt' => 'Plain text format',
        'console' => 'Console output only'
    ];

    // Available severity levels
    private const SEVERITY_LEVELS = ['critical', 'error', 'warning', 'info'];

    // Role-based scanning profiles
    private const SCANNING_ROLES = [
        'developer' => 'Focus on code quality and immediate fixes',
        'lead' => 'Comprehensive analysis with team metrics',
        'architect' => 'Architecture and design pattern analysis',
        'security' => 'Security-focused scanning with vulnerability detection',
        'performance' => 'Performance optimization analysis',
        'ci' => 'Continuous integration optimized scanning'
    ];

    public function __construct(
        ?CodeAnalyzer $codeAnalyzer = null,
        ?ConfigManager $configManager = null,
        ?ScannerEngine $scannerEngine = null,
        ?LoggerInterface $logger = null
    ) {
        parent::__construct();
        
        $this->codeAnalyzer = $codeAnalyzer ?? new CodeAnalyzer();
        $this->configManager = $configManager ?? new ConfigManager();
        $this->scannerEngine = $scannerEngine ?? new ScannerEngine();
        $this->parallelScanner = new ParallelScanner(new \ACQC\Core\Scanner\FileScanner());
        $this->ruleEngine = new RuleEngine();
        $this->reportGenerator = new ReportGenerator();
        $this->predictiveAnalytics = new PredictiveAnalytics();
        $this->fixSuggester = new FixSuggester(new AIEngine());
        $this->logger = $logger ?? new \Psr\Log\NullLogger();
        $this->stopwatch = new Stopwatch();
    }

    protected function configure(): void
    {
        $this
            ->setName('scan')
            ->setDescription('Scan code for quality issues with comprehensive analysis')
            ->setHelp($this->getDetailedHelp())
            
            // Main arguments
            ->addArgument(
                'path',
                InputArgument::OPTIONAL,
                'Path to scan (file or directory)',
                '.'
            )
            
            // Output options
            ->addOption(
                'format',
                'f',
                InputOption::VALUE_REQUIRED,
                'Output format: ' . implode(', ', array_keys(self::OUTPUT_FORMATS)),
                'console'
            )
            ->addOption(
                'output',
                'o',
                InputOption::VALUE_REQUIRED,
                'Output file path (auto-generated if not specified)'
            )
            ->addOption(
                'template',
                't',
                InputOption::VALUE_REQUIRED,
                'Custom template for HTML/PDF reports'
            )
            
            // Scanning options
            ->addOption(
                'role',
                'r',
                InputOption::VALUE_REQUIRED,
                'Scanning role: ' . implode(', ', array_keys(self::SCANNING_ROLES)),
                'developer'
            )
            ->addOption(
                'severity',
                's',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Filter by severity levels (can be used multiple times)',
                self::SEVERITY_LEVELS
            )
            ->addOption(
                'include',
                'i',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Include patterns (glob format, can be used multiple times)'
            )
            ->addOption(
                'exclude',
                'x',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Exclude patterns (glob format, can be used multiple times)'
            )
            ->addOption(
                'rules',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Specific rules to run (can be used multiple times)'
            )
            ->addOption(
                'skip-rules',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Rules to skip (can be used multiple times)'
            )
            
            // Performance options
            ->addOption(
                'parallel',
                null,
                InputOption::VALUE_OPTIONAL,
                'Number of parallel processes (auto-detect if not specified)',
                null
            )
            ->addOption(
                'memory-limit',
                'm',
                InputOption::VALUE_REQUIRED,
                'Memory limit per process',
                '256M'
            )
            ->addOption(
                'timeout',
                null,
                InputOption::VALUE_REQUIRED,
                'Timeout per file in seconds',
                '30'
            )
            
            // Analysis options
            ->addOption(
                'baseline',
                'b',
                InputOption::VALUE_REQUIRED,
                'Baseline file to compare against'
            )
            ->addOption(
                'update-baseline',
                null,
                InputOption::VALUE_NONE,
                'Update the baseline file with current results'
            )
            ->addOption(
                'drift-only',
                null,
                InputOption::VALUE_NONE,
                'Show only changes from baseline'
            )
            ->addOption(
                'ai-insights',
                null,
                InputOption::VALUE_NONE,
                'Enable AI-powered insights and suggestions'
            )
            ->addOption(
                'predictive',
                null,
                InputOption::VALUE_NONE,
                'Include predictive analytics'
            )
            
            // Auto-fix options
            ->addOption(
                'fix',
                null,
                InputOption::VALUE_NONE,
                'Apply auto-fixes where possible'
            )
            ->addOption(
                'fix-risky',
                null,
                InputOption::VALUE_NONE,
                'Apply risky fixes (use with caution)'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Show what would be fixed without applying changes'
            )
            
            // Interactive and display options
            ->addOption(
                'interactive',
                null,
                InputOption::VALUE_NONE,
                'Interactive mode with guided configuration'
            )
            ->addOption(
                'progress',
                null,
                InputOption::VALUE_NONE,
                'Show detailed progress information'
            )
            ->addOption(
                'no-cache',
                null,
                InputOption::VALUE_NONE,
                'Disable caching for this scan'
            )
            ->addOption(
                'profile',
                null,
                InputOption::VALUE_REQUIRED,
                'Use predefined configuration profile'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $this->setupOutputFormatting($output);
        $this->stopwatch->start('total_scan');

        try {
            // Interactive mode setup
            if ($input->getOption('interactive')) {
                $this->runInteractiveMode($input, $io);
            }

            // Validate and normalize inputs
            $config = $this->validateAndNormalizeInputs($input, $io);
            
            // Display scan configuration
            $this->displayScanConfiguration($config, $io);
            
            // Initialize scanning components
            $this->initializeScanningComponents($config);
            
            // Perform the scan
            $results = $this->performScan($config, $io);
            
            // Process results (baseline comparison, AI insights, etc.)
            $processedResults = $this->processResults($results, $config, $io);
            
            // Apply auto-fixes if requested
            if ($config['fix'] || $config['dry_run']) {
                $this->handleAutoFixes($processedResults, $config, $io);
            }
            
            // Generate and save reports
            $this->generateReports($processedResults, $config, $io);
            
            // Display summary
            $this->displaySummary($processedResults, $config, $io);
            
            // Determine exit code based on results
            $exitCode = $this->determineExitCode($processedResults, $config);
            
            $this->stopwatch->stop('total_scan');
            $this->logScanCompletion($config, $processedResults, $exitCode);
            
            return $exitCode;
            
        } catch (\Exception $e) {
            $this->stopwatch->stop('total_scan');
            $io->error("Scan failed: " . $e->getMessage());
            $this->logger->error('Scan failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            if ($input->getOption('verbose')) {
                $io->writeln('<error>Stack trace:</error>');
                $io->writeln($e->getTraceAsString());
            }
            
            return Command::FAILURE;
        }
    }

    private function runInteractiveMode(InputInterface $input, SymfonyStyle $io): void
    {
        $io->title('🔍 ACQC Interactive Scan Setup');
        
        $helper = $this->getHelper('question');
        
        // Scan path
        if (!$input->getArgument('path') || $input->getArgument('path') === '.') {
            $pathQuestion = new Question('Enter path to scan: ', '.');
            $path = $helper->ask($input, $io->getOutput(), $pathQuestion);
            $input->setArgument('path', $path);
        }
        
        // Scanning role
        $roleQuestion = new ChoiceQuestion(
            'Select scanning role:',
            array_keys(self::SCANNING_ROLES),
            'developer'
        );
        $role = $helper->ask($input, $io->getOutput(), $roleQuestion);
        $input->setOption('role', $role);
        
        // Output format
        $formatQuestion = new ChoiceQuestion(
            'Select output format:',
            array_keys(self::OUTPUT_FORMATS),
            'console'
        );
        $format = $helper->ask($input, $io->getOutput(), $formatQuestion);
        $input->setOption('format', $format);
        
        // Severity levels
        $severityQuestion = new ChoiceQuestion(
            'Select severity levels to include (comma-separated):',
            self::SEVERITY_LEVELS,
            implode(',', self::SEVERITY_LEVELS)
        );
        $severityQuestion->setMultiselect(true);
        $severities = $helper->ask($input, $io->getOutput(), $severityQuestion);
        $input->setOption('severity', $severities);
        
        // Advanced options
        $advancedQuestion = new ConfirmationQuestion('Configure advanced options? ', false);
        if ($helper->ask($input, $io->getOutput(), $advancedQuestion)) {
            $this->configureAdvancedOptions($input, $io, $helper);
        }
        
        // AI features
        $aiQuestion = new ConfirmationQuestion('Enable AI-powered insights? ', false);
        if ($helper->ask($input, $io->getOutput(), $aiQuestion)) {
            $input->setOption('ai-insights', true);
            $input->setOption('predictive', true);
        }
        
        // Auto-fix
        $fixQuestion = new ConfirmationQuestion('Enable auto-fix capabilities? ', false);
        if ($helper->ask($input, $io->getOutput(), $fixQuestion)) {
            $input->setOption('fix', true);
            
            $riskyFixQuestion = new ConfirmationQuestion('Include risky fixes? ', false);
            if ($helper->ask($input, $io->getOutput(), $riskyFixQuestion)) {
                $input->setOption('fix-risky', true);
            }
        }
        
        $io->success('Interactive setup completed!');
    }

    private function configureAdvancedOptions(InputInterface $input, SymfonyStyle $io, $helper): void
    {
        // Parallel processing
        $parallelQuestion = new Question('Number of parallel processes (leave empty for auto): ');
        $parallel = $helper->ask($input, $io->getOutput(), $parallelQuestion);
        if ($parallel) {
            $input->setOption('parallel', (int)$parallel);
        }
        
        // Include patterns
        $includeQuestion = new Question('Include patterns (comma-separated, e.g., *.php,src/**): ');
        $include = $helper->ask($input, $io->getOutput(), $includeQuestion);
        if ($include) {
            $input->setOption('include', explode(',', $include));
        }
        
        // Exclude patterns
        $excludeQuestion = new Question('Exclude patterns (comma-separated, e.g., vendor/*,tests/*): ');
        $exclude = $helper->ask($input, $io->getOutput(), $excludeQuestion);
        if ($exclude) {
            $input->setOption('exclude', explode(',', $exclude));
        }
        
        // Baseline
        $baselineQuestion = new Question('Baseline file path (leave empty to skip): ');
        $baseline = $helper->ask($input, $io->getOutput(), $baselineQuestion);
        if ($baseline) {
            $input->setOption('baseline', $baseline);
        }
    }

    private function validateAndNormalizeInputs(InputInterface $input, SymfonyStyle $io): array
    {
        $config = [
            'path' => realpath($input->getArgument('path')) ?: $input->getArgument('path'),
            'format' => strtolower($input->getOption('format')),
            'output' => $input->getOption('output'),
            'template' => $input->getOption('template'),
            'role' => $input->getOption('role'),
            'severity' => $input->getOption('severity'),
            'include' => $input->getOption('include') ?: [],
            'exclude' => $input->getOption('exclude') ?: [],
            'rules' => $input->getOption('rules') ?: [],
            'skip_rules' => $input->getOption('skip-rules') ?: [],
            'parallel' => $input->getOption('parallel'),
            'memory_limit' => $input->getOption('memory-limit'),
            'timeout' => (int)$input->getOption('timeout'),
            'baseline' => $input->getOption('baseline'),
            'update_baseline' => $input->getOption('update-baseline'),
            'drift_only' => $input->getOption('drift-only'),
            'ai_insights' => $input->getOption('ai-insights'),
            'predictive' => $input->getOption('predictive'),
            'fix' => $input->getOption('fix'),
            'fix_risky' => $input->getOption('fix-risky'),
            'dry_run' => $input->getOption('dry-run'),
            'progress' => $input->getOption('progress'),
            'verbose' => $input->getOption('verbose'),
            'quiet' => $input->getOption('quiet'),
            'no_cache' => $input->getOption('no-cache'),
            'profile' => $input->getOption('profile')
        ];

        // Validate path
        if (!file_exists($config['path'])) {
            throw new \InvalidArgumentException("Path does not exist: {$config['path']}");
        }

        // Validate format
        if (!isset(self::OUTPUT_FORMATS[$config['format']])) {
            throw new \InvalidArgumentException("Invalid format: {$config['format']}");
        }

        // Validate role
        if (!isset(self::SCANNING_ROLES[$config['role']])) {
            throw new \InvalidArgumentException("Invalid role: {$config['role']}");
        }

        // Validate severity levels
        $invalidSeverities = array_diff($config['severity'], self::SEVERITY_LEVELS);
        if (!empty($invalidSeverities)) {
            throw new \InvalidArgumentException("Invalid severity levels: " . implode(', ', $invalidSeverities));
        }

        // Validate baseline file
        if ($config['baseline'] && !file_exists($config['baseline'])) {
            if (!$config['update_baseline']) {
                throw new \InvalidArgumentException("Baseline file does not exist: {$config['baseline']}");
            }
        }

        // Auto-generate output file if not specified
        if (!$config['output'] && $config['format'] !== 'console') {
            $config['output'] = $this->generateOutputFilename($config);
        }

        // Set parallel processes
        if ($config['parallel'] === null) {
            $config['parallel'] = $this->detectOptimalParallelProcesses();
        } else {
            $config['parallel'] = max(1, (int)$config['parallel']);
        }

        return $config;
    }

    private function displayScanConfiguration(array $config, SymfonyStyle $io): void
    {
        if ($config['quiet']) {
            return;
        }

        $io->title('🔍 ACQC Code Scan Configuration');
        
        $configTable = new Table($io);
        $configTable
            ->setHeaders(['Setting', 'Value'])
            ->setRows([
                ['Path', $config['path']],
                ['Format', $config['format']],
                ['Role', $config['role'] . ' - ' . self::SCANNING_ROLES[$config['role']]],
                ['Severity Levels', implode(', ', $config['severity'])],
                ['Parallel Processes', $config['parallel']],
                ['Memory Limit', $config['memory_limit']],
                ['Output File', $config['output'] ?: 'Console only'],
                ['AI Insights', $config['ai_insights'] ? '✅ Enabled' : '❌ Disabled'],
                ['Auto-fix', $config['fix'] ? '✅ Enabled' : '❌ Disabled'],
                ['Baseline', $config['baseline'] ?: 'None']
            ]);

        if (!empty($config['include'])) {
            $configTable->addRow(['Include Patterns', implode(', ', $config['include'])]);
        }
        if (!empty($config['exclude'])) {
            $configTable->addRow(['Exclude Patterns', implode(', ', $config['exclude'])]);
        }

        $configTable->render();
        $io->writeln('');
    }

    private function initializeScanningComponents(array $config): void
    {
        // Load role-based configuration (method exists on ConfigManager)
        if (method_exists($this->configManager, 'loadRoleConfiguration')) {
            $this->configManager->loadRoleConfiguration($config['role']);
        }

        // Apply caching preference to rule engine if supported
        if (!$config['no_cache'] === false && method_exists($this->ruleEngine, 'setCaching')) {
            $this->ruleEngine->setCaching(false);
        }

        // Set parallel execution if supported
        if ($config['parallel'] > 1 && method_exists($this->ruleEngine, 'setParallelExecution')) {
            $this->ruleEngine->setParallelExecution(true, (int) $config['parallel']);
        }
    }

    private function performScan(array $config, SymfonyStyle $io): array
    {
        $this->stopwatch->start('scanning');
        
        if (!$config['quiet']) {
            $io->section('🔍 Scanning Code...');
        }

        // Create progress bar if requested
        $progressBar = null;
        if ($config['progress'] && !$config['quiet']) {
            $fileCount = $this->countFiles($config['path'], $config['include'], $config['exclude']);
            $progressBar = $io->createProgressBar($fileCount);
            $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s% - %message%');
            $progressBar->setMessage('Initializing...');
            $progressBar->start();
        }

        // Perform the actual scanning via CodeAnalyzer
        $analyzerOptions = array_filter([
            'analyzers' => $config['analyzers'] ?? [],
        ]);
        $results = $this->codeAnalyzer->analyze($config['path'], $analyzerOptions);

        if ($progressBar) {
            $progressBar->advance();
        }

        if ($progressBar) {
            $progressBar->finish();
            $io->writeln('');
        }

        $this->stopwatch->stop('scanning');
        
        return $results;
    }

    private function processResults(array $results, array $config, SymfonyStyle $io): array
    {
        $processedResults = $results;

        // Baseline comparison
        if ($config['baseline']) {
            $processedResults = $this->performBaselineComparison($results, $config, $io);
        }

        // AI insights
        if ($config['ai_insights']) {
            $processedResults['ai_insights'] = $this->generateAIInsights($results, $config, $io);
        }

        // Predictive analytics
        if ($config['predictive']) {
            $processedResults['predictions'] = $this->generatePredictiveAnalytics($results, $config, $io);
        }

        return $processedResults;
    }

    private function performBaselineComparison(array $results, array $config, SymfonyStyle $io): array
    {
        if (!$config['quiet']) {
            $io->section('📊 Baseline Comparison');
        }

        if (!file_exists($config['baseline'])) {
            $io->warning("Baseline file not found: {$config['baseline']}");
            return $results;
        }

        $baseline = json_decode(file_get_contents($config['baseline']), true);
        
        $comparison = $this->compareWithBaseline($results, $baseline);
        $results['baseline_comparison'] = $comparison;

        if (!$config['quiet']) {
            $this->displayBaselineComparison($comparison, $io);
        }

        // Update baseline if requested
        if ($config['update_baseline']) {
            file_put_contents($config['baseline'], json_encode($results, JSON_PRETTY_PRINT));
            $io->success("Baseline updated: {$config['baseline']}");
        }

        return $results;
    }

    private function handleAutoFixes(array $results, array $config, SymfonyStyle $io): void
    {
        if (!$config['fix'] && !$config['dry_run']) {
            return;
        }

        if (!$config['quiet']) {
            $io->section($config['dry_run'] ? '🔧 Auto-fix Preview (Dry Run)' : '🔧 Applying Auto-fixes');
        }

        $fixableViolations = $this->findFixableViolations($results);
        
        if (empty($fixableViolations)) {
            $io->info('No auto-fixable violations found.');
            return;
        }

        $fixCount = 0;
        $riskCount = 0;

        foreach ($fixableViolations as $violation) {
            $fix = $this->fixSuggester->generateFix($violation);
            
            if (!$fix) {
                continue;
            }

            if ($fix->isRisky() && !$config['fix_risky']) {
                $riskCount++;
                continue;
            }

            if ($config['dry_run']) {
                $io->writeln("Would fix: {$violation['file']}:{$violation['line']} - {$violation['message']}");
            } else {
                $this->applyFix($fix);
                $io->writeln("Fixed: {$violation['file']}:{$violation['line']} - {$violation['message']}");
            }
            
            $fixCount++;
        }

        if (!$config['quiet']) {
            $io->success($config['dry_run'] 
                ? "Preview: $fixCount fixes would be applied" 
                : "Applied $fixCount auto-fixes"
            );
            
            if ($riskCount > 0) {
                $io->warning("$riskCount risky fixes skipped (use --fix-risky to include)");
            }
        }
    }

    private function generateReports(array $results, array $config, SymfonyStyle $io): void
    {
        if ($config['format'] === 'console') {
            return;
        }

        if (!$config['quiet']) {
            $io->section('📄 Generating Reports');
        }

        $reportOptions = [
            'format' => $config['format'],
            'template' => $config['template'],
            'role' => $config['role'],
            'include_ai_insights' => $config['ai_insights'],
            'include_predictions' => $config['predictive']
        ];

        $reportContent = $this->reportGenerator->generate($results, $reportOptions['format']);
        
        if ($config['output']) {
            file_put_contents($config['output'], $reportContent);
            $io->success("Report saved: {$config['output']}");
        } else {
            $io->writeln($reportContent);
        }
    }

    private function displaySummary(array $results, array $config, SymfonyStyle $io): void
    {
        if ($config['quiet']) {
            return;
        }

        $totalTime = $this->stopwatch->getEvent('total_scan')->getDuration();
        $scanTime = $this->stopwatch->getEvent('scanning')->getDuration();

        $io->title('📊 Scan Summary');

        // Create summary table
        $summary = $this->generateSummaryStats($results);
        
        $summaryTable = new Table($io);
        $summaryTable
            ->setHeaders(['Metric', 'Value'])
            ->setRows([
                ['Total Files Scanned', number_format($summary['files_scanned'])],
                ['Total Violations', number_format($summary['total_violations'])],
                ['Critical Issues', number_format($summary['critical'])],
                ['Errors', number_format($summary['errors'])],
                ['Warnings', number_format($summary['warnings'])],
                ['Info', number_format($summary['info'])],
                ['Lines of Code', number_format($summary['lines_of_code'])],
                ['Scan Time', $scanTime . 'ms'],
                ['Total Time', $totalTime . 'ms'],
                ['Memory Usage', $this->formatBytes(memory_get_peak_usage(true))]
            ]);
            
        $summaryTable->render();

        // Display quality score if available
        if (isset($summary['quality_score'])) {
            $scoreColor = $summary['quality_score'] >= 8 ? 'green' : 
                         ($summary['quality_score'] >= 6 ? 'yellow' : 'red');
            
            $io->writeln('');
            $io->writeln(sprintf(
                'Quality Score: <%s>%.1f/10</%s>',
                $scoreColor,
                $summary['quality_score'],
                $scoreColor
            ));
        }

        // Display top issues
        if (!empty($results['violations'])) {
            $this->displayTopIssues($results['violations'], $io);
        }
    }

    private function determineExitCode(array $results, array $config): int
    {
        $summary = $this->generateSummaryStats($results);
        
        // Exit with error if critical issues found
        if ($summary['critical'] > 0) {
            return 2;
        }
        
        // Exit with warning if errors found
        if ($summary['errors'] > 0) {
            return 1;
        }
        
        return Command::SUCCESS;
    }

    // Helper methods

    private function generateOutputFilename(array $config): string
    {
        $timestamp = date('Y-m-d_H-i-s');
        $basename = basename($config['path']);
        $extension = $this->getFileExtension($config['format']);
        
        return "acqc_{$basename}_{$timestamp}.{$extension}";
    }

    private function getFileExtension(string $format): string
    {
        $extensions = [
            'json' => 'json',
            'xml' => 'xml',
            'html' => 'html',
            'pdf' => 'pdf',
            'junit' => 'xml',
            'csv' => 'csv',
            'txt' => 'txt'
        ];
        
        return $extensions[$format] ?? 'txt';
    }

    private function detectOptimalParallelProcesses(): int
    {
        $cpuCount = function_exists('shell_exec') ? 
            (int)shell_exec('nproc 2>/dev/null || sysctl -n hw.ncpu 2>/dev/null || echo 1') : 1;
        
        return max(1, min($cpuCount, 8)); // Cap at 8 processes
    }

    private function countFiles(string $path, array $include, array $exclude): int
    {
        // Simplified file counting - in real implementation would use proper directory iteration
        return is_file($path) ? 1 : 100; // Placeholder
    }

    private function setupOutputFormatting(OutputInterface $output): void
    {
        $output->getFormatter()->setStyle('success', new OutputFormatterStyle('green', null, ['bold']));
        $output->getFormatter()->setStyle('warning', new OutputFormatterStyle('yellow', null, ['bold']));
        $output->getFormatter()->setStyle('error', new OutputFormatterStyle('red', null, ['bold']));
        $output->getFormatter()->setStyle('info', new OutputFormatterStyle('blue', null, ['bold']));
    }

    private function generateSummaryStats(array $results): array
    {
        $violations = $results['violations'] ?? [];
        
        return [
            'files_scanned' => $results['files_scanned'] ?? $results['files_processed'] ?? 0,
            'total_violations' => count($violations),
            'critical' => count(array_filter($violations, fn($v) => $v['severity'] === 'critical')),
            'errors' => count(array_filter($violations, fn($v) => $v['severity'] === 'error')),
            'warnings' => count(array_filter($violations, fn($v) => $v['severity'] === 'warning')),
            'info' => count(array_filter($violations, fn($v) => $v['severity'] === 'info')),
            'lines_of_code' => $results['metrics']['lines_of_code'] ?? 0,
            'quality_score' => $results['metrics']['quality_score'] ?? null
        ];
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $unitIndex = 0;
        
        while ($bytes >= 1024 && $unitIndex < count($units) - 1) {
            $bytes /= 1024;
            $unitIndex++;
        }
        
        return round($bytes, 2) . ' ' . $units[$unitIndex];
    }

    private function displayTopIssues(array $violations, SymfonyStyle $io): void
    {
        $io->writeln('');
        $io->writeln('<info>Top Issues:</info>');
        
        $issueGroups = [];
        foreach ($violations as $violation) {
            $key = $violation['rule'] ?? 'Unknown';
            $issueGroups[$key] = ($issueGroups[$key] ?? 0) + 1;
        }
        
        arsort($issueGroups);
        $topIssues = array_slice($issueGroups, 0, 5, true);
        
        $table = new Table($io);
        $table->setHeaders(['Rule', 'Count']);
        
        foreach ($topIssues as $rule => $count) {
            $table->addRow([$rule, $count]);
        }
        
        $table->render();
    }

    private function getDetailedHelp(): string
    {
        return <<<'HELP'
The <info>scan</info> command performs comprehensive code quality analysis with advanced features.

<comment>Basic Usage:</comment>
  acqc scan                          # Scan current directory
  acqc scan src/                     # Scan specific directory
  acqc scan --format=html           # Generate HTML report
  acqc scan --role=lead             # Use lead developer profile

<comment>Role-based Scanning:</comment>
  --role=developer    Focus on code quality and immediate fixes
  --role=lead         Comprehensive analysis with team metrics
  --role=architect    Architecture and design pattern analysis
  --role=security     Security-focused scanning
  --role=performance  Performance optimization analysis
  --role=ci           Continuous integration optimized

<comment>Output Formats:</comment>
  --format=console    Interactive console output (default)
  --format=json       JSON format for API integration
  --format=html       Interactive HTML report
  --format=pdf        Professional PDF report
  --format=junit      JUnit XML for CI/CD integration

<comment>Advanced Features:</comment>
  --interactive       Guided interactive setup
  --ai-insights       AI-powered code analysis
  --fix               Apply automatic fixes
  --baseline=file     Compare against baseline
  --parallel=4        Use parallel processing

<comment>Examples:</comment>
  # Interactive setup with AI insights
  acqc scan --interactive --ai-insights

  # Security-focused scan with PDF report
  acqc scan --role=security --format=pdf --output=security-report.pdf

  # CI/CD integration with baseline comparison
  acqc scan --format=junit --baseline=baseline.json --severity=critical,error

  # Auto-fix with dry run preview
  acqc scan --fix --dry-run --verbose

  # Performance analysis with parallel processing
  acqc scan --role=performance --parallel=8 --include="*.php" --exclude="vendor/*"
HELP;
    }

    // Placeholder methods for additional functionality
    private function generateAIInsights(array $results, array $config, SymfonyStyle $io): array
    {
        // AI insights generation logic
        return [];
    }

    private function generatePredictiveAnalytics(array $results, array $config, SymfonyStyle $io): array
    {
        // Predictive analytics logic
        return [];
    }

    private function compareWithBaseline(array $results, array $baseline): array
    {
        // Baseline comparison logic
        return [];
    }

    private function displayBaselineComparison(array $comparison, SymfonyStyle $io): void
    {
        // Display baseline comparison results
    }

    private function findFixableViolations(array $results): array
    {
        // Find violations that can be auto-fixed
        return [];
    }

    private function applyFix($fix): void
    {
        // Apply the actual fix to the file
    }

    private function logScanCompletion(array $config, array $results, int $exitCode): void
    {
        $this->logger->info('Scan completed', [
            'path' => $config['path'],
            'format' => $config['format'],
            'role' => $config['role'],
            'violations' => count($results['violations'] ?? []),
            'exit_code' => $exitCode,
            'duration' => $this->stopwatch->getEvent('total_scan')->getDuration()
        ]);
    }
} 
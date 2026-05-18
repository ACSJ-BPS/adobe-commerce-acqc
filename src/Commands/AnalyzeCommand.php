<?php

declare(strict_types=1);

namespace ACQC\Commands;

use ACQC\Core\AnalyzerRegistry;
use ACQC\Core\Config\ConfigManager;
use ACQC\Core\Scanner\ScannerEngine;
use ACQC\Core\Scanner\ScanContext;
use ACQC\Core\AutoFix\FixScriptGenerator;
use ACQC\Core\AutoFix\Generators\MagentoConfigFixGenerator;
use ACQC\Core\AutoFix\Generators\DatabaseFixGenerator;
use ACQC\Core\AutoFix\Generators\CacheFixGenerator;
use ACQC\Core\AutoFix\Generators\IndexerFixGenerator;
use ACQC\Core\AutoFix\Generators\HttpProtocolFixGenerator;
use ACQC\Utils\SeverityMapper;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Psr\Log\NullLogger;

#[AsCommand(
    name: 'analyze',
    description: 'Analyze Magento code using comprehensive quality analyzers'
)]
class AnalyzeCommand extends Command
{
    private AnalyzerRegistry $analyzerRegistry;
    private ConfigManager $configManager;
    private ScannerEngine $scanner;

    public function __construct()
    {
        parent::__construct();
        $this->configManager = new ConfigManager();
        $this->analyzerRegistry = new AnalyzerRegistry($this->configManager, new NullLogger());
        $this->scanner = new ScannerEngine($this->configManager, new NullLogger());
    }

    protected function configure(): void
    {
        $this
            ->addArgument('target', InputArgument::OPTIONAL, 'File path, pattern, or module namespace to analyze', '.')
            ->addOption('analyzers', 'a', InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Specific analyzers to run (comma-separated)')
            ->addOption('use-case', 'u', InputOption::VALUE_OPTIONAL, 'Pre-defined use case for analysis')
            ->addOption('category', 'c', InputOption::VALUE_OPTIONAL, 'Analyzer category to run')
            ->addOption('profile', 'p', InputOption::VALUE_OPTIONAL, 'Configuration profile to use')
            ->addOption('format', 'f', InputOption::VALUE_OPTIONAL, 'Output format (table, json, xml, html)', 'table')
            ->addOption('fix', null, InputOption::VALUE_NONE, 'Attempt to automatically fix issues')
            ->addOption('generate-fix', null, InputOption::VALUE_NONE, 'Generate executable fix script for detected issues')
            ->addOption('module', 'm', InputOption::VALUE_NONE, 'Treat target as Magento module namespace (e.g., Vendor/Module)')
            ->addOption('all-modules', null, InputOption::VALUE_NONE, 'Analyze all modules in app/code')
            ->addOption('module-path', null, InputOption::VALUE_OPTIONAL, 'Base path for modules', 'app/code')
            ->addOption('severity', 's', InputOption::VALUE_OPTIONAL, 'Minimum severity level (1-6 or minor/info/warning/error/critical/blocker)')
            ->addOption('baseline', 'b', InputOption::VALUE_OPTIONAL, 'Baseline file to compare against')
            ->addOption('output', 'o', InputOption::VALUE_OPTIONAL, 'Output results to file')
            ->addOption('parallel', null, InputOption::VALUE_NONE, 'Enable parallel processing')
            ->addOption('exclude', 'e', InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Patterns to exclude')
            ->addOption('include', 'i', InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Patterns to include')
            ->addOption('diff', null, InputOption::VALUE_OPTIONAL, 'Only analyze files changed in git (vs main/HEAD/branch)', false)
            ->addOption('diff-base', null, InputOption::VALUE_OPTIONAL, 'Base branch/commit for --diff comparison', 'main')
            ->addOption('fix-preview', null, InputOption::VALUE_NONE, 'Preview what auto-fix would change without applying (shows colored diff)')
            ->addOption('list-analyzers', null, InputOption::VALUE_NONE, 'List available analyzers')
            ->addOption('list-use-cases', null, InputOption::VALUE_NONE, 'List available use cases')
            ->addOption('list-profiles', null, InputOption::VALUE_NONE, 'List available configuration profiles')
            ->setHelp('This command analyzes Magento code using comprehensive quality analyzers.

<comment>Basic Usage:</comment>
  <info>acqc analyze</info>                           # Analyze current directory
  <info>acqc analyze app/code/Vendor/Module</info>    # Analyze specific module
  <info>acqc analyze Vendor/Module --module</info>    # Analyze by namespace
  <info>acqc analyze --all-modules</info>             # Analyze all modules

<comment>Use Cases (Predefined Analyzer Sets):</comment>
  <info>acqc analyze --use-case magento-full</info>         # Complete Magento analysis
  <info>acqc analyze --use-case pwa-full</info>             # Complete PWA analysis
  <info>acqc analyze --use-case security</info>             # Security-focused analysis
  <info>acqc analyze --use-case pre-deployment</info>       # Pre-deployment checks
  <info>acqc analyze --use-case quick-scan</info>           # Fast CI/CD checks

<comment>Configuration Profiles:</comment>
  <info>acqc analyze --profile magento-developer</info>     # Developer-friendly settings
  <info>acqc analyze --profile magento-lead</info>          # Tech lead comprehensive analysis
  <info>acqc analyze --profile ci-pipeline</info>           # CI/CD optimized settings

<comment>Custom Analyzer Selection:</comment>
  <info>acqc analyze --analyzers magento-di,magento-security</info>
  <info>acqc analyze --category magento-backend</info>
  <info>acqc analyze --category frontend</info>

<comment>Advanced Options:</comment>
  <info>acqc analyze --format json --output report.json</info>
  <info>acqc analyze --severity error --baseline baseline.json</info>
  <info>acqc analyze --severity 4 --baseline baseline.json</info>         # Same as --severity error
  <info>acqc analyze --parallel --exclude "*/Test/*"</info>

<comment>Severity Levels:</comment>
  You can specify severity using either numbers (1-6) or names:
  6 = blocker   | 5 = critical | 4 = error
  3 = warning   | 2 = info     | 1 = minor

  Examples:
  <info>acqc analyze --severity 6</info>        # Show only blocker issues
  <info>acqc analyze --severity blocker</info>  # Same as above
  <info>acqc analyze --severity 4</info>        # Show error and above
  <info>acqc analyze --severity error</info>    # Same as above');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Handle list options
        if ($input->getOption('list-analyzers')) {
            return $this->listAnalyzers($io);
        }

        if ($input->getOption('list-use-cases')) {
            return $this->listUseCases($io);
        }

        if ($input->getOption('list-profiles')) {
            return $this->listProfiles($io);
        }

        // Load configuration profile if specified
        if ($profile = $input->getOption('profile')) {
            $this->loadConfigurationProfile($profile);
        }

        $format = $input->getOption('format');
        $outputFile = $input->getOption('output');
        $quiet = ($format !== 'table') && !$outputFile;

        if (!$quiet) {
            $io->title('ACQC Code Analysis');
        }

        try {
            // Determine what to analyze
            $target = $input->getArgument('target');
            $analysisOptions = $this->buildAnalysisOptions($input);

            // Get files to analyze
            $files = $this->getFilesToAnalyze($target, $input, $io, $quiet);

            if (empty($files)) {
                if (!$quiet) $io->warning('No files found to analyze');
                return Command::SUCCESS;
            }

            // Get analyzers to run
            $analyzers = $this->getAnalyzersToRun($input, $io);

            if (empty($analyzers)) {
                $io->error('No analyzers selected. Use --list-analyzers to see available options.');
                return Command::FAILURE;
            }

            if (!$quiet) {
                $io->info(sprintf('Analyzing %d files with %d analyzers', count($files), count($analyzers)));
            }
            
            // Display selected analyzers
            if ($io->isVerbose()) {
                $analyzerNames = array_keys($analyzers);
                $io->listing($analyzerNames);
            }

            // Run analysis
            $results = $this->analyzerRegistry->runAnalyzers(array_keys($analyzers), $files, $analysisOptions);

            // Generate fix script if requested
            if ($input->getOption('generate-fix') && !empty($results['violations'])) {
                $this->generateFixScript($results['violations'], $input, $io);
            }

            // Process and display results
            return $this->displayResults($results, $input, $io, $output);

        } catch (\Exception $e) {
            $io->error('Analysis failed: ' . $e->getMessage());
            if ($io->isVerbose()) {
                $io->text('<comment>Stack trace:</comment>');
                $io->text($e->getTraceAsString());
            }
            return Command::FAILURE;
        }
    }

    private function listAnalyzers(SymfonyStyle $io): int
    {
        $io->title('Available Analyzers');

        $analyzerInfo = $this->analyzerRegistry->getAnalyzerInfo();
        $categories = $this->analyzerRegistry->getCategories();

        foreach ($categories as $category) {
            $io->section(ucwords(str_replace('-', ' ', $category)));
            
            $categoryAnalyzers = $this->analyzerRegistry->getAnalyzersByCategory($category);
            $rows = [];
            
            foreach ($categoryAnalyzers as $id => $analyzer) {
                $info = $analyzerInfo[$id];
                $rows[] = [
                    $id,
                    $info['name'],
                    $info['version'],
                    $info['available'] ? '✓' : '✗',
                    $info['description']
                ];
            }
            
            $io->table(['ID', 'Name', 'Version', 'Available', 'Description'], $rows);
        }

        return Command::SUCCESS;
    }

    private function listUseCases(SymfonyStyle $io): int
    {
        $io->title('Available Use Cases');
        
        $useCases = $this->analyzerRegistry->getUseCases();
        $rows = [];
        
        foreach ($useCases as $useCase => $description) {
            $analyzers = $this->analyzerRegistry->getAnalyzersByUseCase($useCase);
            $rows[] = [
                $useCase,
                $description,
                count($analyzers),
                implode(', ', array_slice(array_keys($analyzers), 0, 3)) . (count($analyzers) > 3 ? '...' : '')
            ];
        }
        
        $io->table(['Use Case', 'Description', 'Analyzers', 'Sample Analyzers'], $rows);
        
        $io->note('Use: acqc analyze --use-case <use-case-name>');
        
        return Command::SUCCESS;
    }

    private function listProfiles(SymfonyStyle $io): int
    {
        $io->title('Available Configuration Profiles');
        
        $profiles = [
            'magento-developer' => 'Standard configuration for Magento developers',
            'magento-lead' => 'Comprehensive analysis for technical leads',
            'ci-pipeline' => 'Fast, focused analysis for CI/CD pipelines'
        ];
        
        $rows = [];
        foreach ($profiles as $profile => $description) {
            $rows[] = [$profile, $description];
        }
        
        $io->table(['Profile', 'Description'], $rows);
        $io->note('Use: acqc analyze --profile <profile-name>');
        
        return Command::SUCCESS;
    }

    private function loadConfigurationProfile(string $profile): void
    {
        // Profile loading functionality
        // Note: Profiles are pre-configured in the system
        // Available profiles: magento-developer, magento-lead, ci-pipeline
        
        // For now, profiles are informational - the actual configuration
        // is managed through the ConfigManager's built-in profile support
        
        // Future enhancement: Add specific profile loading logic here
        // if needed for advanced customization
    }

    private function getFilesToAnalyze(string $target, InputInterface $input, SymfonyStyle $io, bool $quiet = false): array
    {
        $context = new ScanContext();

        if ($include = $input->getOption('include')) {
            $context->setIncludePatterns($include);
        }

        if ($exclude = $input->getOption('exclude')) {
            $context->setExcludePatterns($exclude);
        }

        // Handle different analysis modes
        if ($input->getOption('all-modules')) {
            $modulePath = $input->getOption('module-path');
            if (!$quiet) $io->info(sprintf('Scanning all modules in: %s', $modulePath));
            return [];

        } elseif ($input->getOption('module')) {
            $modulePath = $input->getOption('module-path');
            $fullPath = $modulePath . '/' . str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $target);
            if (!$quiet) $io->info(sprintf('Scanning module: %s', $fullPath));
            return $this->scanner->discoverDirectory($fullPath, $context);

        } else {
            if (!$quiet) $io->info(sprintf('Scanning: %s', $target));
            
            if (is_file($target)) {
                return [$target];
            } elseif (is_dir($target)) {
                return $this->scanner->discoverDirectory($target, $context);
            } else {
                // Try glob pattern
                $files = glob($target);
                return $files ?: [];
            }
        }
    }

    private function getAnalyzersToRun(InputInterface $input, SymfonyStyle $io): array
    {
        // Priority order: specific analyzers -> use case -> category -> all
        
        if ($analyzers = $input->getOption('analyzers')) {
            $analyzerIds = [];
            foreach ($analyzers as $analyzerList) {
                $analyzerIds = array_merge($analyzerIds, explode(',', $analyzerList));
            }
            return $this->analyzerRegistry->getAnalyzersById(array_map('trim', $analyzerIds));
        }

        if ($useCase = $input->getOption('use-case')) {
            return $this->analyzerRegistry->getAnalyzersByUseCase($useCase);
        }

        if ($category = $input->getOption('category')) {
            return $this->analyzerRegistry->getAnalyzersByCategory($category);
        }

        // Default: use magento-basic for module analysis, all for general analysis
        if ($input->getOption('module') || $input->getOption('all-modules')) {
            return $this->analyzerRegistry->getAnalyzersByUseCase('magento-basic');
        }

        return $this->analyzerRegistry->getAllAnalyzers();
    }

    private function buildAnalysisOptions(InputInterface $input): array
    {
        $options = [];

        if ($input->getOption('fix')) {
            $options['auto_fix'] = true;
        }

        if ($input->getOption('parallel')) {
            $options['parallel'] = true;
        }

        if ($baseline = $input->getOption('baseline')) {
            $options['baseline'] = $baseline;
        }

        if ($severity = $input->getOption('severity')) {
            // Support both numeric (1-6) and text-based severity
            $normalizedSeverity = SeverityMapper::normalize($severity);
            
            if ($normalizedSeverity === null) {
                throw new \InvalidArgumentException(
                    "Invalid severity level: {$severity}. " . 
                    "Use 1-6 (numeric) or minor/info/warning/error/critical/blocker (text)"
                );
            }
            
            $options['min_severity'] = $normalizedSeverity;
        }

        return $options;
    }

    private function displayResults(array $results, InputInterface $input, SymfonyStyle $io, OutputInterface $output): int
    {
        $format = $input->getOption('format');
        $outputFile = $input->getOption('output');
        
        // Calculate totals
        $totalViolations = 0;
        $severityBreakdown = ['blocker' => 0, 'critical' => 0, 'error' => 0, 'warning' => 0, 'info' => 0, 'minor' => 0];
        
        foreach ($results['violations'] as $analyzerViolations) {
            $totalViolations += count($analyzerViolations);
            
            foreach ($analyzerViolations as $violation) {
                // Skip non-Violation objects (bug in some analyzers)
                if (!is_object($violation) || !method_exists($violation, 'getSeverity')) {
                    continue;
                }
                
                $severity = $violation->getSeverity();
                if (isset($severityBreakdown[$severity])) {
                    $severityBreakdown[$severity]++;
                }
            }
        }

        $machineReadable = ($format !== 'table') && !$outputFile;

        // Display summary (suppress when piping machine-readable output)
        if (!$machineReadable) {
            if ($totalViolations === 0) {
                $io->success('🎉 No issues found! Your code quality looks great.');
                return Command::SUCCESS;
            }

            $io->warning(sprintf('Found %d total violations', $totalViolations));

            $io->definitionList(
                ['Blocker' => $severityBreakdown['blocker']],
                ['Critical' => $severityBreakdown['critical']],
                ['Errors' => $severityBreakdown['error']],
                ['Warnings' => $severityBreakdown['warning']],
                ['Info' => $severityBreakdown['info']],
                ['Minor' => $severityBreakdown['minor']]
            );

            if ($io->isVerbose()) {
                $io->section('Analyzer Summary');
                $summaryRows = [];
                foreach ($results['summary'] as $summary) {
                    $executionTime = number_format($summary['execution_time'], 3);
                    $summaryRows[] = [
                        $summary['analyzer'],
                        $summary['violations'],
                        $executionTime . 's',
                        isset($summary['error']) ? '❌ ' . $summary['error'] : '✅'
                    ];
                }
                $io->table(['Analyzer', 'Violations', 'Time', 'Status'], $summaryRows);
            }
        } elseif ($totalViolations === 0 && !$outputFile) {
            // Machine-readable empty result
        }

        // Output in specified format
        $outputData = $this->formatOutput($results, $format);

        if ($outputFile) {
            file_put_contents($outputFile, $outputData);
            $io->success("Results written to: {$outputFile}");
        } elseif ($format === 'table') {
            $this->displayViolationsTable($io, $results['violations']);
        } else {
            $output->write($outputData);
        }

        // Return exit code based on severity
        if ($severityBreakdown['critical'] > 0) {
            return 2;
        } elseif ($severityBreakdown['error'] > 0) {
            return 1;
        }

        return Command::SUCCESS;
    }

    private function displayViolationsTable(SymfonyStyle $io, array $allViolations): void
    {
        $rows = [];
        $maxRows = 50; // Limit display for readability
        $currentRows = 0;

        foreach ($allViolations as $analyzerName => $violations) {
            if ($currentRows >= $maxRows) break;
            
            foreach ($violations as $violation) {
                if ($currentRows >= $maxRows) break;
                
                // Skip non-Violation objects (bug in some analyzers)
                if (!is_object($violation) || !method_exists($violation, 'getFilePath')) {
                    continue;
                }
                
                $filePath = $violation->getFilePath();
                $moduleInfo = $this->extractModuleInfo($filePath);
                
                $rows[] = [
                    $analyzerName,
                    $moduleInfo['vendor'],
                    $moduleInfo['module'],
                    basename($filePath),
                    $violation->getLine() ?: 'N/A',
                    $violation->getSeverity(),
                    $violation->getRuleId(),
                    $this->truncateMessage($violation->getMessage(), 80)
                ];
                $currentRows++;
            }
        }

        $io->table(['Analyzer', 'Vendor', 'Module', 'File', 'Line', 'Severity', 'Rule', 'Message'], $rows);
        
        if ($currentRows >= $maxRows) {
            $io->note("Showing first {$maxRows} violations. Use --format json for complete results.");
        }
    }

    /**
     * Extract vendor and module information from file path
     */
    private function extractModuleInfo(string $filePath): array
    {
        // Default values
        $vendor = 'N/A';
        $module = 'N/A';
        
        // Try to extract from path: app/code/Vendor/Module/...
        if (preg_match('#/app/code/([^/]+)/([^/]+)/#', $filePath, $matches)) {
            $vendor = $matches[1];
            $module = $matches[2];
        }
        // Try to extract from path: code/Vendor/Module/...
        elseif (preg_match('#/code/([^/]+)/([^/]+)/#', $filePath, $matches)) {
            $vendor = $matches[1];
            $module = $matches[2];
        }
        // Try to extract from path: Vendor/Module/...
        elseif (preg_match('#([^/]+)/([^/]+)/(?:Model|Block|Controller|Helper|Setup|etc|view)/#', $filePath, $matches)) {
            $vendor = $matches[1];
            $module = $matches[2];
        }
        
        return [
            'vendor' => $vendor,
            'module' => $module
        ];
    }

    private function formatOutput(array $results, string $format): string
    {
        switch ($format) {
            case 'json':
                return json_encode($results, JSON_PRETTY_PRINT);
                
            case 'xml':
                return $this->convertToXml($results);
                
            case 'html':
                return $this->convertToHtml($results);
                
            default:
                return json_encode($results, JSON_PRETTY_PRINT);
        }
    }

    private function convertToXml(array $results): string
    {
        $xml = new \SimpleXMLElement('<analysis/>');
        $xml->addChild('summary');
        $xml->summary->addChild('total_violations', (string)array_sum(array_map('count', $results['violations'])));
        $xml->summary->addChild('analyzers_run', (string)count($results['analyzers_run']));
        
        $violationsNode = $xml->addChild('violations');
        
        foreach ($results['violations'] as $analyzerName => $violations) {
            $analyzerNode = $violationsNode->addChild('analyzer');
            $analyzerNode->addAttribute('name', $analyzerName);
            
            foreach ($violations as $violation) {
                // Skip non-Violation objects (bug in some analyzers)
                if (!is_object($violation) || !method_exists($violation, 'getFilePath')) {
                    continue;
                }
                
                $violationNode = $analyzerNode->addChild('violation');
                $violationNode->addChild('file', htmlspecialchars($violation->getFilePath()));
                $violationNode->addChild('line', (string)$violation->getLine());
                $violationNode->addChild('severity', $violation->getSeverity());
                $violationNode->addChild('rule', $violation->getRuleId());
                $violationNode->addChild('message', htmlspecialchars($violation->getMessage()));
            }
        }
        
        return $xml->asXML();
    }

    private function convertToHtml(array $results): string
    {
        $html = '<!DOCTYPE html><html><head><title>ACQC Analysis Report</title>';
        $html .= '<style>body{font-family:Arial,sans-serif;margin:20px;}table{border-collapse:collapse;width:100%;}th,td{border:1px solid #ddd;padding:8px;text-align:left;}th{background-color:#f2f2f2;}.critical{color:#d32f2f;}.error{color:#f57c00;}.warning{color:#ffa000;}.info{color:#1976d2;}</style>';
        $html .= '</head><body><h1>ACQC Analysis Report</h1>';
        
        $totalViolations = array_sum(array_map('count', $results['violations']));
        $html .= "<h2>Summary</h2><p>Total violations: {$totalViolations}</p>";
        
        $html .= '<h2>Violations</h2><table><tr><th>Analyzer</th><th>File</th><th>Line</th><th>Severity</th><th>Rule</th><th>Message</th></tr>';
        
        foreach ($results['violations'] as $analyzerName => $violations) {
            foreach ($violations as $violation) {
                // Skip non-Violation objects (bug in some analyzers)
                if (!is_object($violation) || !method_exists($violation, 'getFilePath')) {
                    continue;
                }
                
                $severity = $violation->getSeverity();
                $html .= "<tr><td>{$analyzerName}</td>";
                $html .= "<td>" . htmlspecialchars(basename($violation->getFilePath())) . "</td>";
                $html .= "<td>{$violation->getLine()}</td>";
                $html .= "<td class=\"{$severity}\">{$severity}</td>";
                $html .= "<td>{$violation->getRuleId()}</td>";
                $html .= "<td>" . htmlspecialchars($violation->getMessage()) . "</td></tr>";
            }
        }
        
        $html .= '</table></body></html>';
        return $html;
    }

    private function truncateMessage(string $message, int $length): string
    {
        return strlen($message) > $length ? substr($message, 0, $length) . '...' : $message;
    }

    /**
     * Generate executable fix script for violations
     *
     * @param array<string, array> $violationsByAnalyzer Violations grouped by analyzer
     * @param InputInterface $input Command input
     * @param SymfonyStyle $io Console I/O
     * @return void
     */
    private function generateFixScript(array $violationsByAnalyzer, InputInterface $input, SymfonyStyle $io): void
    {
        $io->section('🔧 Automated Fix Generation');
        
        // Flatten violations array
        $allViolations = [];
        foreach ($violationsByAnalyzer as $violations) {
            $allViolations = array_merge($allViolations, $violations);
        }
        
        // Initialize fix generator
        $fixGenerator = new FixScriptGenerator(new NullLogger());
        
        // Register available fix generators
        $fixGenerator->registerGenerator('magento_config', new MagentoConfigFixGenerator());
        $fixGenerator->registerGenerator('database', new DatabaseFixGenerator());
        $fixGenerator->registerGenerator('cache', new CacheFixGenerator());
        $fixGenerator->registerGenerator('indexer', new IndexerFixGenerator());
        $fixGenerator->registerGenerator('http_protocol', new HttpProtocolFixGenerator());
        
        // Get statistics about fixable violations
        $stats = $fixGenerator->getFixableStatistics($allViolations);
        
        $io->info(sprintf(
            'Found %d total violations: %d fixable, %d unfixable',
            $stats['total'],
            $stats['fixable'],
            $stats['unfixable']
        ));
        
        if ($stats['fixable'] === 0) {
            $io->warning('No fixable violations found. Fix generation skipped.');
            return;
        }
        
        // Display fixable violations by generator
        if (!empty($stats['by_generator'])) {
            $io->text('<comment>Fixable violations by type:</comment>');
            foreach ($stats['by_generator'] as $generator => $count) {
                $io->text(sprintf('  • %s: %d fixes', $generator, $count));
            }
            $io->newLine();
        }
        
        // Generate script filename
        $target = $input->getArgument('target');
        $timestamp = date('Ymd_His');
        $scriptName = sprintf('acqc_fixes_%s.sh', $timestamp);
        $scriptPath = getcwd() . '/' . $scriptName;
        
        try {
            // Generate the fix script
            $result = $fixGenerator->generateFixScript(
                $allViolations,
                $scriptPath,
                [
                    'dry_run' => false,
                    'require_confirmation' => false,  // Individual fixes can still require confirmation
                    'include_rollback' => true,
                    'magento_root' => is_dir($target) ? $target : dirname($target),
                    'script_path' => $scriptPath
                ]
            );
            
            $io->success('Fix script generated successfully!');
            $io->newLine();
            
            // Display information about the generated script
            $io->definitionList(
                ['Script path' => $scriptPath],
                ['Fixable violations' => (string)$result['fixable_count']],
                ['Unfixable violations' => (string)$result['unfixable_count']],
                ['File size' => $this->formatBytes(filesize($scriptPath))]
            );
            
            $io->newLine();
            $io->block([
                'To apply fixes:',
                '',
                "  bash {$scriptName}",
                '',
                '⚠️  WARNING:',
                '  • Review the script before executing',
                '  • The script includes safety checks and backups',
                '  • Some fixes may require user confirmation',
                '  • Test on a development environment first'
            ], null, 'fg=yellow', ' ', true);
            
            // Show a preview of the fixes
            if ($io->isVerbose() && !empty($result['commands'])) {
                $io->section('Fix Preview (first 5)');
                $previewCount = min(5, count($result['commands']));
                for ($i = 0; $i < $previewCount; $i++) {
                    $command = $result['commands'][$i];
                    $io->text(sprintf(
                        '<comment>[%d]</comment> %s',
                        $i + 1,
                        $command->getSummary()
                    ));
                }
                if (count($result['commands']) > 5) {
                    $io->text(sprintf('<comment>... and %d more fixes</comment>', count($result['commands']) - 5));
                }
            }
            
        } catch (\Exception $e) {
            $io->error('Failed to generate fix script: ' . $e->getMessage());
            if ($io->isVerbose()) {
                $io->text('<comment>Stack trace:</comment>');
                $io->text($e->getTraceAsString());
            }
        }
    }

    /**
     * Format bytes to human-readable format
     *
     * @param int $bytes Number of bytes
     * @return string Formatted string
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $factor = floor((strlen((string)$bytes) - 1) / 3);
        return sprintf("%.2f %s", $bytes / pow(1024, $factor), $units[$factor]);
    }
} 
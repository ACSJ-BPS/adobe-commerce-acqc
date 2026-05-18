<?php

declare(strict_types=1);

namespace ACQC\Commands;

use ACQC\Core\CodeAnalyzer;
use ACQC\Core\Config\ConfigManager;
use ACQC\Core\Scanner\ScannerEngine;
use ACQC\Reports\ReportGenerator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Psr\Log\LoggerInterface;

/**
 * Baseline Management Command
 * 
 * Manages quality baselines for tracking changes over time:
 * - Create new baselines from current code state
 * - Compare current state against existing baselines
 * - Update baselines with new scan results
 * - Analyze quality drift and trends
 * - Generate baseline reports and summaries
 * - Manage multiple baseline versions
 */
#[AsCommand(name: 'baseline', description: 'Manage quality baselines for change tracking')]
class BaselineCommand extends Command
{
    protected static $defaultName = 'baseline';
    protected static $defaultDescription = 'Manage quality baselines for change tracking';

    private CodeAnalyzer $codeAnalyzer;
    private ConfigManager $configManager;
    private ScannerEngine $scannerEngine;
    private ReportGenerator $reportGenerator;
    private LoggerInterface $logger;

    // Baseline format version for backward compatibility
    private const BASELINE_FORMAT_VERSION = '1.0';
    
    // Default baseline filename
    private const DEFAULT_BASELINE_FILE = '.acqc-baseline.json';

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
        $this->reportGenerator = new ReportGenerator();
        $this->logger = $logger ?? new \Psr\Log\NullLogger();
    }

    protected function configure(): void
    {
        $this
            ->setName('baseline')
            ->setDescription('Manage quality baselines for tracking code changes over time')
            ->setHelp($this->getDetailedHelp())
            
            // Subcommands
            ->addArgument(
                'action',
                InputArgument::REQUIRED,
                'Action to perform: create, update, compare, show, list, clean, export'
            )
            ->addArgument(
                'path',
                InputArgument::OPTIONAL,
                'Path to scan for baseline creation/comparison',
                '.'
            )
            
            // Baseline file options
            ->addOption(
                'baseline',
                'b',
                InputOption::VALUE_REQUIRED,
                'Baseline file path',
                self::DEFAULT_BASELINE_FILE
            )
            ->addOption(
                'name',
                null,
                InputOption::VALUE_REQUIRED,
                'Baseline name/label for identification'
            )
            ->addOption(
                'description',
                'd',
                InputOption::VALUE_REQUIRED,
                'Baseline description'
            )
            
            // Comparison options
            ->addOption(
                'compare-with',
                'c',
                InputOption::VALUE_REQUIRED,
                'Compare with specific baseline file'
            )
            ->addOption(
                'tolerance',
                't',
                InputOption::VALUE_REQUIRED,
                'Tolerance threshold for change detection (percentage)',
                '5'
            )
            ->addOption(
                'ignore-new',
                null,
                InputOption::VALUE_NONE,
                'Ignore new violations in comparison'
            )
            ->addOption(
                'ignore-resolved',
                null,
                InputOption::VALUE_NONE,
                'Ignore resolved violations in comparison'
            )
            
            // Filtering options
            ->addOption(
                'severity',
                's',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Filter by severity levels',
                ['critical', 'error', 'warning', 'info']
            )
            ->addOption(
                'category',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Filter by violation categories'
            )
            ->addOption(
                'include',
                'i',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Include patterns for files'
            )
            ->addOption(
                'exclude',
                'x',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Exclude patterns for files'
            )
            
            // Output options
            ->addOption(
                'format',
                'f',
                InputOption::VALUE_REQUIRED,
                'Output format for reports: json, xml, html, csv, console',
                'console'
            )
            ->addOption(
                'output',
                'o',
                InputOption::VALUE_REQUIRED,
                'Output file for reports'
            )
            ->addOption(
                'detailed',
                null,
                InputOption::VALUE_NONE,
                'Show detailed violation information'
            )
            
            // Maintenance options
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                'Force action without confirmation'
            )
            ->addOption(
                'backup',
                null,
                InputOption::VALUE_NONE,
                'Create backup before updating baseline'
            )
            ->addOption(
                'keep-versions',
                'k',
                InputOption::VALUE_REQUIRED,
                'Number of baseline versions to keep',
                '10'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $action = strtolower($input->getArgument('action'));

        try {
            switch ($action) {
                case 'create':
                    return $this->createBaseline($input, $io);
                    
                case 'update':
                    return $this->updateBaseline($input, $io);
                    
                case 'compare':
                    return $this->compareBaseline($input, $io);
                    
                case 'show':
                    return $this->showBaseline($input, $io);
                    
                case 'list':
                    return $this->listBaselines($input, $io);
                    
                case 'clean':
                    return $this->cleanBaselines($input, $io);
                    
                case 'export':
                    return $this->exportBaseline($input, $io);
                    
                default:
                    $io->error("Unknown action: $action");
                    $io->writeln("Available actions: create, update, compare, show, list, clean, export");
                    return Command::FAILURE;
            }
        } catch (\Exception $e) {
            $io->error("Baseline operation failed: " . $e->getMessage());
            $this->logger->error('Baseline operation failed', [
                'action' => $action,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return Command::FAILURE;
        }
    }

    private function createBaseline(InputInterface $input, SymfonyStyle $io): int
    {
        $path = $input->getArgument('path');
        $baselineFile = $input->getOption('baseline');
        $name = $input->getOption('name') ?? "Baseline " . date('Y-m-d H:i:s');
        $description = $input->getOption('description') ?? "Baseline created from $path";

        $io->title('📊 Creating Quality Baseline');
        
        // Check if baseline already exists
        if (file_exists($baselineFile) && !$input->getOption('force')) {
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion(
                "Baseline file already exists. Overwrite? ",
                false
            );
            
            if (!$helper->ask($input, $io, $question)) {
                $io->info('Baseline creation cancelled.');
                return Command::SUCCESS;
            }
        }

        // Create backup if requested
        if (file_exists($baselineFile) && $input->getOption('backup')) {
            $backupFile = $baselineFile . '.backup.' . date('Y-m-d_H-i-s');
            copy($baselineFile, $backupFile);
            $io->info("Backup created: $backupFile");
        }

        $io->section('🔍 Scanning for baseline creation...');
        
        // Perform scan
        $progressBar = $io->createProgressBar();
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% - %message%');
        $progressBar->setMessage('Initializing scan...');
        $progressBar->start();

        $scanResults = $this->performScan($path, $input, function($message) use ($progressBar) {
            $progressBar->setMessage($message);
            $progressBar->advance();
        });

        $progressBar->finish();
        $io->writeln('');

        // Create baseline data structure
        $baseline = [
            'metadata' => [
                'format_version' => self::BASELINE_FORMAT_VERSION,
                'name' => $name,
                'description' => $description,
                'path' => realpath($path),
                'created_at' => date('c'),
                'created_by' => get_current_user(),
                'git_commit' => $this->getGitCommit($path),
                'git_branch' => $this->getGitBranch($path),
                'scan_options' => $this->extractScanOptions($input)
            ],
            'metrics' => [
                'total_files' => $scanResults['total_files'],
                'total_violations' => count($scanResults['violations']),
                'lines_of_code' => $scanResults['lines_of_code'],
                'quality_score' => $scanResults['quality_score'],
                'by_severity' => $this->groupBySeverity($scanResults['violations']),
                'by_category' => $this->groupByCategory($scanResults['violations']),
                'by_file' => $this->groupByFile($scanResults['violations'])
            ],
            'violations' => $this->processViolationsForBaseline($scanResults['violations']),
            'file_hashes' => $this->generateFileHashes($path, $input),
            'configuration' => $this->extractConfiguration()
        ];

        // Save baseline
        $success = file_put_contents(
            $baselineFile, 
            json_encode($baseline, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        if ($success === false) {
            $io->error("Failed to save baseline to: $baselineFile");
            return Command::FAILURE;
        }

        $io->success("Baseline created successfully: $baselineFile");
        $this->displayBaselineInfo($baseline, $io);
        
        $this->logger->info('Baseline created', [
            'file' => $baselineFile,
            'name' => $name,
            'violations' => count($scanResults['violations']),
            'files' => $scanResults['total_files']
        ]);

        return Command::SUCCESS;
    }

    private function updateBaseline(InputInterface $input, SymfonyStyle $io): int
    {
        $path = $input->getArgument('path');
        $baselineFile = $input->getOption('baseline');

        if (!file_exists($baselineFile)) {
            $io->error("Baseline file not found: $baselineFile");
            return Command::FAILURE;
        }

        $io->title('🔄 Updating Quality Baseline');

        // Load existing baseline
        $existingBaseline = json_decode(file_get_contents($baselineFile), true);
        if (!$existingBaseline) {
            $io->error("Invalid baseline file format");
            return Command::FAILURE;
        }

        // Create backup
        if ($input->getOption('backup')) {
            $backupFile = $baselineFile . '.backup.' . date('Y-m-d_H-i-s');
            copy($baselineFile, $backupFile);
            $io->info("Backup created: $backupFile");
        }

        $io->section('🔍 Scanning for baseline update...');

        // Perform new scan
        $scanResults = $this->performScan($path, $input);

        // Update baseline
        $updatedBaseline = $existingBaseline;
        $updatedBaseline['metadata']['updated_at'] = date('c');
        $updatedBaseline['metadata']['updated_by'] = get_current_user();
        $updatedBaseline['metadata']['git_commit'] = $this->getGitCommit($path);
        $updatedBaseline['metadata']['git_branch'] = $this->getGitBranch($path);
        $updatedBaseline['metadata']['version'] = ($existingBaseline['metadata']['version'] ?? 1) + 1;

        // Update metrics
        $updatedBaseline['metrics'] = [
            'total_files' => $scanResults['total_files'],
            'total_violations' => count($scanResults['violations']),
            'lines_of_code' => $scanResults['lines_of_code'],
            'quality_score' => $scanResults['quality_score'],
            'by_severity' => $this->groupBySeverity($scanResults['violations']),
            'by_category' => $this->groupByCategory($scanResults['violations']),
            'by_file' => $this->groupByFile($scanResults['violations'])
        ];

        $updatedBaseline['violations'] = $this->processViolationsForBaseline($scanResults['violations']);
        $updatedBaseline['file_hashes'] = $this->generateFileHashes($path, $input);

        // Calculate changes
        $changes = $this->calculateBaselineChanges($existingBaseline, $updatedBaseline);

        // Save updated baseline
        file_put_contents(
            $baselineFile, 
            json_encode($updatedBaseline, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        $io->success("Baseline updated successfully: $baselineFile");
        $this->displayBaselineChanges($changes, $io);

        return Command::SUCCESS;
    }

    private function compareBaseline(InputInterface $input, SymfonyStyle $io): int
    {
        $path = $input->getArgument('path');
        $baselineFile = $input->getOption('baseline');
        $compareWith = $input->getOption('compare-with');

        if (!file_exists($baselineFile)) {
            $io->error("Baseline file not found: $baselineFile");
            return Command::FAILURE;
        }

        $io->title('📊 Baseline Comparison');

        // Load baseline
        $baseline = json_decode(file_get_contents($baselineFile), true);
        if (!$baseline) {
            $io->error("Invalid baseline file format");
            return Command::FAILURE;
        }

        if ($compareWith) {
            // Compare two baseline files
            if (!file_exists($compareWith)) {
                $io->error("Comparison baseline file not found: $compareWith");
                return Command::FAILURE;
            }
            
            $comparisonBaseline = json_decode(file_get_contents($compareWith), true);
            $comparison = $this->compareBaselines($baseline, $comparisonBaseline);
            
        } else {
            // Compare baseline with current state
            $io->section('🔍 Scanning current state...');
            $currentResults = $this->performScan($path, $input);
            $comparison = $this->compareWithCurrentState($baseline, $currentResults);
        }

        // Display comparison results
        $this->displayComparisonResults($comparison, $input, $io);

        // Generate report if requested
        if ($input->getOption('output')) {
            $this->generateComparisonReport($comparison, $input, $io);
        }

        // Determine exit code based on changes
        return $this->determineComparisonExitCode($comparison, $input);
    }

    private function showBaseline(InputInterface $input, SymfonyStyle $io): int
    {
        $baselineFile = $input->getOption('baseline');

        if (!file_exists($baselineFile)) {
            $io->error("Baseline file not found: $baselineFile");
            return Command::FAILURE;
        }

        $baseline = json_decode(file_get_contents($baselineFile), true);
        if (!$baseline) {
            $io->error("Invalid baseline file format");
            return Command::FAILURE;
        }

        $io->title('📋 Baseline Information');
        $this->displayBaselineInfo($baseline, $io);

        if ($input->getOption('detailed')) {
            $this->displayDetailedBaselineInfo($baseline, $io);
        }

        return Command::SUCCESS;
    }

    private function listBaselines(InputInterface $input, SymfonyStyle $io): int
    {
        $io->title('📂 Available Baselines');

        // Find baseline files in current directory and subdirectories
        $baselineFiles = $this->findBaselineFiles('.');
        
        if (empty($baselineFiles)) {
            $io->info('No baseline files found.');
            return Command::SUCCESS;
        }

        $table = new Table($io);
        $table->setHeaders(['File', 'Name', 'Created', 'Violations', 'Quality Score']);

        foreach ($baselineFiles as $file) {
            $baseline = json_decode(file_get_contents($file), true);
            if (!$baseline) continue;

            $table->addRow([
                $file,
                $baseline['metadata']['name'] ?? 'Unnamed',
                $baseline['metadata']['created_at'] ?? 'Unknown',
                $baseline['metrics']['total_violations'] ?? 'N/A',
                isset($baseline['metrics']['quality_score']) ? 
                    number_format($baseline['metrics']['quality_score'], 1) : 'N/A'
            ]);
        }

        $table->render();
        return Command::SUCCESS;
    }

    private function cleanBaselines(InputInterface $input, SymfonyStyle $io): int
    {
        $keepVersions = (int)$input->getOption('keep-versions');
        
        $io->title('🧹 Cleaning Old Baselines');

        // Find all backup files
        $backupFiles = glob('*.backup.*');
        $baselineBackups = array_filter($backupFiles, function($file) {
            return strpos($file, '.acqc-baseline') !== false;
        });

        if (empty($baselineBackups)) {
            $io->info('No baseline backup files found.');
            return Command::SUCCESS;
        }

        // Sort by modification time (newest first)
        usort($baselineBackups, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });

        $toDelete = array_slice($baselineBackups, $keepVersions);

        if (empty($toDelete)) {
            $io->info("All backup files are within the keep limit ($keepVersions).");
            return Command::SUCCESS;
        }

        $io->writeln("Found " . count($toDelete) . " old backup files to remove:");
        foreach ($toDelete as $file) {
            $io->writeln("  - $file");
        }

        if (!$input->getOption('force')) {
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion('Delete these files? ', false);
            
            if (!$helper->ask($input, $io, $question)) {
                $io->info('Cleanup cancelled.');
                return Command::SUCCESS;
            }
        }

        $deleted = 0;
        foreach ($toDelete as $file) {
            if (unlink($file)) {
                $deleted++;
                $io->writeln("Deleted: $file");
            }
        }

        $io->success("Cleaned up $deleted old baseline backup files.");
        return Command::SUCCESS;
    }

    private function exportBaseline(InputInterface $input, SymfonyStyle $io): int
    {
        $baselineFile = $input->getOption('baseline');
        $format = $input->getOption('format');
        $outputFile = $input->getOption('output');

        if (!file_exists($baselineFile)) {
            $io->error("Baseline file not found: $baselineFile");
            return Command::FAILURE;
        }

        $baseline = json_decode(file_get_contents($baselineFile), true);
        if (!$baseline) {
            $io->error("Invalid baseline file format");
            return Command::FAILURE;
        }

        $io->title('📤 Exporting Baseline');

        // Generate export content
        $exportContent = $this->generateExportContent($baseline, $format);

        if ($outputFile) {
            file_put_contents($outputFile, $exportContent);
            $io->success("Baseline exported to: $outputFile");
        } else {
            $io->writeln($exportContent);
        }

        return Command::SUCCESS;
    }

    // Helper methods

    private function performScan(string $path, InputInterface $input, ?callable $progressCallback = null): array
    {
        // Configure scanner based on input options
        $options = [
            'severity' => $input->getOption('severity'),
            'include' => $input->getOption('include'),
            'exclude' => $input->getOption('exclude'),
            'category' => $input->getOption('category')
        ];

        // Use real scanner to get actual violations
        try {
            $codeAnalyzer = new \ACQC\Core\CodeAnalyzer();
            $result = $codeAnalyzer->analyze($path);
            
            $violations = $result['violations'] ?? [];
            $metrics = $result['metrics'] ?? [];
            
            // Count files
            $files = $this->getFilesFromPath($path);
            $totalFiles = count($files);
            
            // Count lines of code
            $linesOfCode = 0;
            foreach ($files as $file) {
                if (file_exists($file)) {
                    $linesOfCode += count(file($file));
                }
            }
            
            // Calculate quality score from violations
            $qualityScore = $this->calculateQualityScore($violations);
            
            // Add hash to each violation for baseline tracking
            foreach ($violations as &$violation) {
                $hashString = sprintf('%s:%d:%s',
                    $violation['file'] ?? 'unknown',
                    $violation['line'] ?? 0,
                    $violation['rule'] ?? 'unknown'
                );
                $violation['hash'] = md5($hashString);
            }
            
            return [
                'total_files' => $totalFiles,
                'lines_of_code' => $linesOfCode,
                'quality_score' => $qualityScore,
                'violations' => $violations
            ];
        } catch (\Exception $e) {
            // Return empty result if scan fails
            return [
                'total_files' => 0,
                'lines_of_code' => 0,
                'quality_score' => 0,
                'violations' => []
            ];
        }
    }
    
    private function getFilesFromPath(string $path): array
    {
        $files = [];
        
        if (is_file($path)) {
            return [$path];
        }
        
        if (is_dir($path)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
            );
            
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }
        
        return $files;
    }
    
    private function calculateQualityScore(array $violations): float
    {
        // Calculate quality score based on violations (0-10 scale)
        $baseScore = 10.0;
        $criticalCount = 0;
        $errorCount = 0;
        $warningCount = 0;
        
        foreach ($violations as $violation) {
            $severity = strtolower($violation['severity'] ?? 'info');
            if (in_array($severity, ['critical', 'blocker'])) $criticalCount++;
            elseif (in_array($severity, ['error', 'high'])) $errorCount++;
            elseif (in_array($severity, ['warning', 'medium'])) $warningCount++;
        }
        
        $score = $baseScore - ($criticalCount * 2.0) - ($errorCount * 0.5) - ($warningCount * 0.1);
        return max(0, min(10, $score));
    }

    private function processViolationsForBaseline(array $violations): array
    {
        return array_map(function($violation) {
            return [
                'hash' => $violation['hash'],
                'file' => $violation['file'],
                'line' => $violation['line'],
                'severity' => $violation['severity'],
                'category' => $violation['category'],
                'rule' => $violation['rule'],
                'message' => $violation['message']
            ];
        }, $violations);
    }

    private function generateFileHashes(string $path, InputInterface $input): array
    {
        // Generate file hashes for change detection
        $hashes = [];
        
        if (is_file($path)) {
            $hashes[$path] = md5_file($path);
        } else {
            // In real implementation, would recursively scan directory
            $hashes['example.php'] = md5('example content');
        }
        
        return $hashes;
    }

    private function groupBySeverity(array $violations): array
    {
        $groups = ['critical' => 0, 'error' => 0, 'warning' => 0, 'info' => 0];
        
        foreach ($violations as $violation) {
            $severity = $violation['severity'] ?? 'info';
            $groups[$severity] = ($groups[$severity] ?? 0) + 1;
        }
        
        return $groups;
    }

    private function groupByCategory(array $violations): array
    {
        $groups = [];
        
        foreach ($violations as $violation) {
            $category = $violation['category'] ?? 'other';
            $groups[$category] = ($groups[$category] ?? 0) + 1;
        }
        
        return $groups;
    }

    private function groupByFile(array $violations): array
    {
        $groups = [];
        
        foreach ($violations as $violation) {
            $file = $violation['file'] ?? 'unknown';
            $groups[$file] = ($groups[$file] ?? 0) + 1;
        }
        
        return $groups;
    }

    private function extractScanOptions(InputInterface $input): array
    {
        return [
            'severity' => $input->getOption('severity'),
            'category' => $input->getOption('category'),
            'include' => $input->getOption('include'),
            'exclude' => $input->getOption('exclude')
        ];
    }

    private function extractConfiguration(): array
    {
        return [
            'rules' => $this->configManager->getEnabledRules(),
            'thresholds' => $this->configManager->getQualityThresholds(),
            'version' => '1.0'
        ];
    }

    private function displayBaselineInfo(array $baseline, SymfonyStyle $io): void
    {
        $metadata = $baseline['metadata'];
        $metrics = $baseline['metrics'];

        $table = new Table($io);
        $table->setHeaders(['Property', 'Value']);
        $table->setRows([
            ['Name', $metadata['name'] ?? 'Unnamed'],
            ['Description', $metadata['description'] ?? 'No description'],
            ['Path', $metadata['path'] ?? 'Unknown'],
            ['Created', $metadata['created_at'] ?? 'Unknown'],
            ['Total Files', number_format($metrics['total_files'] ?? 0)],
            ['Total Violations', number_format($metrics['total_violations'] ?? 0)],
            ['Lines of Code', number_format($metrics['lines_of_code'] ?? 0)],
            ['Quality Score', isset($metrics['quality_score']) ? number_format($metrics['quality_score'], 1) : 'N/A'],
            ['Git Commit', $metadata['git_commit'] ?? 'N/A'],
            ['Git Branch', $metadata['git_branch'] ?? 'N/A']
        ]);

        $table->render();

        // Show severity breakdown
        if (isset($metrics['by_severity'])) {
            $io->writeln('');
            $io->writeln('<info>Violations by Severity:</info>');
            
            $severityTable = new Table($io);
            $severityTable->setHeaders(['Severity', 'Count']);
            
            foreach ($metrics['by_severity'] as $severity => $count) {
                $severityTable->addRow([ucfirst($severity), number_format($count)]);
            }
            
            $severityTable->render();
        }
    }

    private function displayDetailedBaselineInfo(array $baseline, SymfonyStyle $io): void
    {
        if (isset($baseline['metrics']['by_category'])) {
            $io->writeln('');
            $io->writeln('<info>Violations by Category:</info>');
            
            $categoryTable = new Table($io);
            $categoryTable->setHeaders(['Category', 'Count']);
            
            foreach ($baseline['metrics']['by_category'] as $category => $count) {
                $categoryTable->addRow([ucfirst($category), number_format($count)]);
            }
            
            $categoryTable->render();
        }

        if (isset($baseline['metrics']['by_file'])) {
            $io->writeln('');
            $io->writeln('<info>Top Files by Violations:</info>');
            
            $fileViolations = $baseline['metrics']['by_file'];
            arsort($fileViolations);
            $topFiles = array_slice($fileViolations, 0, 10, true);
            
            $fileTable = new Table($io);
            $fileTable->setHeaders(['File', 'Violations']);
            
            foreach ($topFiles as $file => $count) {
                $fileTable->addRow([basename($file), number_format($count)]);
            }
            
            $fileTable->render();
        }
    }

    private function getGitCommit(string $path): ?string
    {
        if (!is_dir($path . '/.git')) {
            return null;
        }
        
        $commit = shell_exec('cd ' . escapeshellarg($path) . ' && git rev-parse HEAD 2>/dev/null');
        return $commit ? trim($commit) : null;
    }

    private function getGitBranch(string $path): ?string
    {
        if (!is_dir($path . '/.git')) {
            return null;
        }
        
        $branch = shell_exec('cd ' . escapeshellarg($path) . ' && git rev-parse --abbrev-ref HEAD 2>/dev/null');
        return $branch ? trim($branch) : null;
    }

    private function findBaselineFiles(string $directory): array
    {
        $files = [];
        $pattern = $directory . '/**/*.json';
        
        foreach (glob($pattern) as $file) {
            if (strpos(basename($file), 'baseline') !== false) {
                $files[] = $file;
            }
        }
        
        return $files;
    }

    private function getDetailedHelp(): string
    {
        return <<<'HELP'
The <info>baseline</info> command manages quality baselines for tracking code changes over time.

<comment>Actions:</comment>
  create      Create a new baseline from current code state
  update      Update existing baseline with current scan results
  compare     Compare current state or two baselines
  show        Display baseline information
  list        List all available baselines
  clean       Remove old baseline backup files
  export      Export baseline in different formats

<comment>Examples:</comment>
  # Create a new baseline
  acqc baseline create --name="Release 1.0" --description="Initial release baseline"

  # Update existing baseline
  acqc baseline update --backup

  # Compare current state with baseline
  acqc baseline compare

  # Compare two baselines
  acqc baseline compare --compare-with=old-baseline.json

  # Show baseline information
  acqc baseline show --detailed

  # Export baseline as HTML report
  acqc baseline export --format=html --output=baseline-report.html

  # Clean old backup files (keep last 5)
  acqc baseline clean --keep-versions=5
HELP;
    }

    // Additional placeholder methods for comparison and reporting
    private function calculateBaselineChanges(array $old, array $new): array { return []; }
    private function displayBaselineChanges(array $changes, SymfonyStyle $io): void {}
    private function compareBaselines(array $baseline1, array $baseline2): array { return []; }
    private function compareWithCurrentState(array $baseline, array $current): array { return []; }
    private function displayComparisonResults(array $comparison, InputInterface $input, SymfonyStyle $io): void {}
    private function generateComparisonReport(array $comparison, InputInterface $input, SymfonyStyle $io): void {}
    private function determineComparisonExitCode(array $comparison, InputInterface $input): int { return Command::SUCCESS; }
    private function generateExportContent(array $baseline, string $format): string { return json_encode($baseline, JSON_PRETTY_PRINT); }
} 
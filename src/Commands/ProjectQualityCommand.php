<?php

declare(strict_types=1);

namespace ACQC\Commands;

use ACQC\Core\ProjectQuality\ProjectQualityAnalyzer;
use ACQC\Reports\Generators\ProjectQualityReportGenerator;
use ACQC\Core\Config\ConfigManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Project Quality Command
 * 
 * Generates comprehensive project quality reports for leadership
 * in Excel format matching the Commerce_project_updates.xlsx template.
 */
#[AsCommand(name: 'project-quality', description: 'Generate comprehensive project quality report for leadership')]
class ProjectQualityCommand extends Command
{
    protected static $defaultName = 'project-quality';
    protected static $defaultDescription = 'Generate comprehensive project quality report for leadership';

    private ConfigManager $configManager;
    private LoggerInterface $logger;
    private ProjectQualityAnalyzer $analyzer;
    private array $config;

    public function __construct(?ConfigManager $configManager = null, ?LoggerInterface $logger = null)
    {
        parent::__construct();
        $this->configManager = $configManager ?? new ConfigManager();
        $this->logger = $logger ?? new NullLogger();
        $this->analyzer = new ProjectQualityAnalyzer($this->configManager, $this->logger);
        $this->config = $this->configManager->get('project_quality') ?? [];
    }

    protected function configure(): void
    {
        $this
            ->setName('project-quality')
            ->setDescription('Generate comprehensive project quality report for leadership')
            ->setHidden(true)
            ->setHelp(<<<'HELP'
The <info>project-quality</info> command generates Excel reports matching the
Commerce_project_updates.xlsx template for leadership reporting.

<comment>Basic Usage:</comment>
  <info>php bin/acqc project-quality /path/to/project</info>

<comment>With Project Details:</comment>
  <info>php bin/acqc project-quality /path/to/project \
    --project-name "Commerce Platform" \
    --region "EMEA" \
    --kind "Implementation" \
    --sprint 3 \
    --resources 5 \
    --requirements 120 \
    --test-cases 450 \
    --output reports/quality-report.xlsx</info>

<comment>Output Formats:</comment>
  - Excel (.xlsx) - Default, matches leadership template
  - JSON (.json) - For programmatic processing

<comment>Examples:</comment>

  # Generate report for current sprint
  <info>php bin/acqc project-quality . --sprint 3</info>

  # Generate report with custom output path
  <info>php bin/acqc project-quality . --output /tmp/report.xlsx</info>

  # Generate JSON report for API integration
  <info>php bin/acqc project-quality . --format json</info>

  # Full example with all parameters
  <info>php bin/acqc project-quality /Users/dev/Shell_SA \
    --project-name "Shell SA Commerce" \
    --region "EMEA" \
    --kind "Implementation" \
    --sprint 3 \
    --resources 5 \
    --resource-names "John,Jane,Bob" \
    --hours 160 \
    --qa-percentage 20 \
    --requirements 120 \
    --test-cases 450 \
    --output reports/shell-sa-sprint3.xlsx</info>

HELP)
            ->addArgument(
                'project-path',
                InputArgument::REQUIRED,
                'Path to the project directory to analyze'
            )
            ->addOption(
                'project-name',
                null,
                InputOption::VALUE_REQUIRED,
                'Project name (defaults to directory name)'
            )
            ->addOption(
                'region',
                null,
                InputOption::VALUE_REQUIRED,
                'Project region (EMEA, APAC, NAM)',
                $this->config['defaults']['region'] ?? 'EMEA'
            )
            ->addOption(
                'kind',
                null,
                InputOption::VALUE_REQUIRED,
                'Kind of project (Implementation, R&O)',
                $this->config['defaults']['kind'] ?? 'Implementation'
            )
            ->addOption(
                'solutions',
                null,
                InputOption::VALUE_REQUIRED,
                'Solutions used',
                'Adobe Commerce'
            )
            ->addOption(
                'description',
                null,
                InputOption::VALUE_REQUIRED,
                'Brief project description'
            )
            ->addOption(
                'sprint',
                's',
                InputOption::VALUE_REQUIRED,
                'Current sprint number',
                '1'
            )
            ->addOption(
                'resources',
                'r',
                InputOption::VALUE_REQUIRED,
                'Number of resources',
                '5'
            )
            ->addOption(
                'resource-names',
                null,
                InputOption::VALUE_REQUIRED,
                'Comma-separated list of resource names'
            )
            ->addOption(
                'hours',
                null,
                InputOption::VALUE_REQUIRED,
                'Total project hours',
                '160'
            )
            ->addOption(
                'qa-percentage',
                null,
                InputOption::VALUE_REQUIRED,
                'QA percentage',
                (string)($this->config['defaults']['qa_percentage'] ?? '20')
            )
            ->addOption(
                'requirements',
                null,
                InputOption::VALUE_REQUIRED,
                'Number of requirements',
                '0'
            )
            ->addOption(
                'test-cases',
                null,
                InputOption::VALUE_REQUIRED,
                'Number of test cases',
                '0'
            )
            ->addOption(
                'actual-hours',
                null,
                InputOption::VALUE_REQUIRED,
                'Actual hours spent (for effort deviation calculation)'
            )
            ->addOption(
                'estimated-hours',
                null,
                InputOption::VALUE_REQUIRED,
                'Estimated hours (for effort deviation calculation)'
            )
            ->addOption(
                'velocity',
                null,
                InputOption::VALUE_REQUIRED,
                'Sprint velocity percentage'
            )
            ->addOption(
                'output',
                'o',
                InputOption::VALUE_REQUIRED,
                'Output file path',
                'reports/project-quality-report.xlsx'
            )
            ->addOption(
                'format',
                'f',
                InputOption::VALUE_REQUIRED,
                'Output format (excel, json)',
                'excel'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $io->title('Project Quality Report Generator');
        
        // Get and validate project path
        $projectPath = $input->getArgument('project-path');
        $projectPath = $this->resolvePath($projectPath);
        
        if (!is_dir($projectPath)) {
            $io->error("Project path does not exist: {$projectPath}");
            return Command::FAILURE;
        }
        
        $io->section('Configuration');
        $io->text([
            "Project Path: <info>{$projectPath}</info>",
            "Sprint: <info>{$input->getOption('sprint')}</info>",
            "Region: <info>{$input->getOption('region')}</info>",
            "Format: <info>{$input->getOption('format')}</info>"
        ]);
        
        // Collect options
        $options = $this->collectOptions($input, $projectPath);
        
        try {
            // Run analysis
            $io->section('Analysis');
            $io->text('Collecting project metrics...');
            
            $progressBar = $io->createProgressBar(5);
            $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %message%');
            
            $progressBar->setMessage('Scanning project structure...');
            $progressBar->advance();
            
            $progressBar->setMessage('Running code quality audit...');
            $progressBar->advance();
            
            $progressBar->setMessage('Calculating test coverage...');
            $progressBar->advance();
            
            $progressBar->setMessage('Analyzing sprint metrics...');
            $data = $this->analyzer->analyzeProject($projectPath, $options);
            $progressBar->advance();
            
            $progressBar->setMessage('Generating report...');
            $success = $this->generateReport($data, $options);
            $progressBar->advance();
            
            $progressBar->finish();
            $io->newLine(2);
            
            if ($success) {
                $this->displaySummary($io, $data, $options);
                return Command::SUCCESS;
            } else {
                $io->error('Failed to generate report');
                return Command::FAILURE;
            }
            
        } catch (\Exception $e) {
            $io->error('Error during analysis: ' . $e->getMessage());
            $this->logger->error('Project quality analysis failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return Command::FAILURE;
        }
    }

    /**
     * Collect options from input
     */
    private function collectOptions(InputInterface $input, string $projectPath): array
    {
        $resourceNames = $input->getOption('resource-names');
        $resourceNamesArray = $resourceNames ? array_map('trim', explode(',', $resourceNames)) : [];
        
        return [
            'project_name' => $input->getOption('project-name') ?? basename($projectPath),
            'region' => $input->getOption('region'),
            'kind' => $input->getOption('kind'),
            'solutions' => $input->getOption('solutions'),
            'description' => $input->getOption('description'),
            'sprint' => (int) $input->getOption('sprint'),
            'resources' => (int) $input->getOption('resources'),
            'resource_names' => $resourceNamesArray,
            'hours' => (int) $input->getOption('hours'),
            'qa_percentage' => (int) $input->getOption('qa-percentage'),
            'requirements' => (int) $input->getOption('requirements'),
            'test_cases' => (int) $input->getOption('test-cases'),
            'actual_hours' => $input->getOption('actual-hours') ? (int) $input->getOption('actual-hours') : null,
            'estimated_hours' => $input->getOption('estimated-hours') ? (int) $input->getOption('estimated-hours') : null,
            'velocity' => $input->getOption('velocity') ? (float) $input->getOption('velocity') : null,
            'output' => $input->getOption('output'),
            'format' => $input->getOption('format'),
        ];
    }

    /**
     * Generate report in requested format
     */
    private function generateReport(array $data, array $options): bool
    {
        $outputPath = $options['output'];
        $format = $options['format'];
        
        // Ensure output directory exists
        $outputDir = dirname($outputPath);
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }
        
        if ($format === 'json') {
            return $this->generateJsonReport($outputPath, $data);
        } else {
            return $this->generateExcelReport($outputPath, $data);
        }
    }

    /**
     * Generate Excel report
     */
    private function generateExcelReport(string $outputPath, array $data): bool
    {
        // Ensure .xlsx extension
        if (!str_ends_with($outputPath, '.xlsx')) {
            $outputPath .= '.xlsx';
        }
        
        $generator = new ProjectQualityReportGenerator($this->config, $this->logger);
        return $generator->generate($outputPath, $data);
    }

    /**
     * Generate JSON report
     */
    private function generateJsonReport(string $outputPath, array $data): bool
    {
        // Ensure .json extension
        if (!str_ends_with($outputPath, '.json')) {
            $outputPath .= '.json';
        }
        
        // Convert SprintMetrics objects to arrays
        if (isset($data['sprints'])) {
            $data['sprints'] = array_map(function($sprint) {
                return is_object($sprint) && method_exists($sprint, 'toArray') 
                    ? $sprint->toArray() 
                    : $sprint;
            }, $data['sprints']);
        }
        
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return file_put_contents($outputPath, $json) !== false;
    }

    /**
     * Display summary of results
     */
    private function displaySummary(SymfonyStyle $io, array $data, array $options): void
    {
        $io->section('Report Generated Successfully');
        
        $projectInfo = $data['project_info'] ?? [];
        $summary = $data['summary'] ?? [];
        $sprints = $data['sprints'] ?? [];
        
        // Project Details
        $io->definitionList(
            ['Project Name' => $projectInfo['name'] ?? 'Unknown'],
            ['Region' => $projectInfo['region'] ?? 'N/A'],
            ['Kind' => $projectInfo['kind'] ?? 'N/A'],
            ['Current Sprint' => $summary['current_sprint'] ?? 'N/A'],
            ['Total Sprints' => $summary['total_sprints'] ?? 'N/A']
        );
        
        // Quality Metrics
        $io->section('Quality Metrics');
        
        $testCoverage = $summary['test_coverage'] ?? 0;
        $codeQuality = $summary['code_quality'] ?? 0;
        $defectDensity = $summary['defect_density'] ?? 0;
        $overallHealth = $summary['overall_health'] ?? 'unknown';
        
        $io->table(
            ['Metric', 'Value', 'Status'],
            [
                ['Test Coverage', sprintf('%.1f%%', $testCoverage), $this->getStatusEmoji($testCoverage, 80)],
                ['Code Quality', sprintf('%.1f/100', $codeQuality), $this->getStatusEmoji($codeQuality, 75)],
                ['Defect Density', sprintf('%.2f per 1K LOC', $defectDensity), $this->getDefectStatusEmoji($defectDensity)],
                ['Overall Health', strtoupper($overallHealth), $this->getHealthEmoji($overallHealth)]
            ]
        );
        
        // Recommendations
        if (!empty($summary['recommendations'])) {
            $io->section('Recommendations');
            foreach ($summary['recommendations'] as $rec) {
                $priority = $rec['priority'] ?? 'medium';
                $icon = $priority === 'high' ? '🔴' : ($priority === 'medium' ? '🟡' : '🟢');
                $io->text("{$icon} <comment>[{$rec['category']}]</comment> {$rec['message']}");
            }
        }
        
        // Output Location
        $io->section('Output');
        $outputPath = $options['output'];
        $fileSize = file_exists($outputPath) ? $this->formatBytes(filesize($outputPath)) : 'N/A';
        
        $io->definitionList(
            ['File' => "<info>{$outputPath}</info>"],
            ['Format' => $options['format']],
            ['Size' => $fileSize],
            ['Sheets' => $options['format'] === 'excel' ? '2 (Summary, Project_template)' : 'N/A']
        );
        
        $io->success([
            'Project quality report generated successfully!',
            sprintf('Analysis completed in %.2fs', $data['metadata']['analysis_time'] ?? 0)
        ]);
    }

    /**
     * Get status emoji based on value and threshold
     */
    private function getStatusEmoji(float $value, float $threshold): string
    {
        if ($value >= $threshold + 10) return '✅ Excellent';
        if ($value >= $threshold) return '✓ Good';
        if ($value >= $threshold - 20) return '⚠ Warning';
        return '❌ Critical';
    }

    /**
     * Get defect status emoji
     */
    private function getDefectStatusEmoji(float $density): string
    {
        if ($density <= 1.0) return '✅ Low';
        if ($density <= 2.0) return '⚠ Medium';
        return '❌ High';
    }

    /**
     * Get health emoji
     */
    private function getHealthEmoji(string $health): string
    {
        return match($health) {
            'excellent' => '🌟 Excellent',
            'good' => '✓ Good',
            'warning' => '⚠ Warning',
            'critical' => '❌ Critical',
            default => '❓ Unknown'
        };
    }

    /**
     * Format bytes to human readable
     */
    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) return "{$bytes} B";
        if ($bytes < 1048576) return round($bytes / 1024, 2) . " KB";
        return round($bytes / 1048576, 2) . " MB";
    }

    /**
     * Resolve relative paths
     */
    private function resolvePath(string $path): string
    {
        if ($path === '.' || $path === './') {
            return getcwd();
        }
        
        if (!str_starts_with($path, '/')) {
            return getcwd() . '/' . $path;
        }
        
        return $path;
    }
}

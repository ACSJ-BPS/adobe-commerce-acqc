<?php

declare(strict_types=1);

namespace ACQC\Commands;

use ACQC\Core\Config\RoleConfig;
use ACQC\Core\AnalyzerRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Role-Based Quality Checklist Command
 * 
 * Runs quality checklists based on developer roles:
 * - junior-developer
 * - senior-developer
 * - architect
 */
#[AsCommand(name: 'checklist', description: 'Run role-based quality checklists')]
class ChecklistCommand extends Command
{
    protected static $defaultName = 'checklist';
    protected static $defaultDescription = 'Run role-based quality checklists';

    private RoleConfig $roleConfig;
    private AnalyzerRegistry $analyzerRegistry;

    public function __construct(?RoleConfig $roleConfig = null, ?AnalyzerRegistry $analyzerRegistry = null)
    {
        parent::__construct();
        $this->roleConfig = $roleConfig ?? new RoleConfig();
        $this->analyzerRegistry = $analyzerRegistry ?? new AnalyzerRegistry();
    }

    protected function configure(): void
    {
        $this
            ->setName('checklist')
            ->setDescription('Run role-based quality checklists')
            ->addArgument(
                'role',
                InputArgument::REQUIRED,
                'Developer role (junior-developer, senior-developer, architect)'
            )
            ->addArgument(
                'path',
                InputArgument::OPTIONAL,
                'Path to analyze (default: current directory)',
                '.'
            )
            ->addOption(
                'format',
                'f',
                InputOption::VALUE_OPTIONAL,
                'Output format (table, json, xml, html)',
                'table'
            )
            ->addOption(
                'category',
                'c',
                InputOption::VALUE_OPTIONAL,
                'Specific category to check'
            )
            ->addOption(
                'severity',
                's',
                InputOption::VALUE_OPTIONAL,
                'Minimum severity level (info, warning, error, critical)',
                'info'
            )
            ->addOption(
                'output',
                'o',
                InputOption::VALUE_OPTIONAL,
                'Output file path'
            )
            ->addOption(
                'detailed',
                'd',
                InputOption::VALUE_NONE,
                'Show detailed checklist items'
            )
            ->addOption(
                'tools-only',
                't',
                InputOption::VALUE_NONE,
                'Show only tool recommendations'
            )
            ->addOption(
                'summary',
                null,
                InputOption::VALUE_NONE,
                'Show summary only'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $role = $input->getArgument('role');
        $path = $input->getArgument('path');
        $format = $input->getOption('format');
        $category = $input->getOption('category');
        $severity = $input->getOption('severity');
        $outputFile = $input->getOption('output');
        $detailed = $input->getOption('detailed');
        $toolsOnly = $input->getOption('tools-only');
        $summary = $input->getOption('summary');

        // Validate role
        if (!$this->roleConfig->isValidRole($role)) {
            $io->error("Invalid role: {$role}");
            $io->note("Available roles: " . implode(', ', array_keys($this->roleConfig->getRoles())));
            return Command::FAILURE;
        }

        $roleInfo = $this->roleConfig->getRole($role);
        $checklist = $this->roleConfig->getQualityChecklist($role);
        
        // Handle missing checklist
        if ($checklist === null) {
            $io->warning("Quality checklist not configured for role: {$role}");
            $io->note("The role configuration exists but does not include a quality_checklist section.");
            $io->note("Showing role information only:");
            $io->definitionList(
                ['Role' => $roleInfo['name'] ?? $role],
                ['Level' => $roleInfo['level'] ?? 'N/A'],
                ['Description' => $roleInfo['description'] ?? 'No description available']
            );
            return Command::SUCCESS;
        }

        if ($summary) {
            return $this->showSummary($io, $role, $roleInfo, $checklist);
        }

        if ($toolsOnly) {
            return $this->showTools($io, $role, $checklist);
        }

        if ($detailed) {
            return $this->showDetailedChecklist($io, $role, $checklist, $category, $severity);
        }

        // Run the checklist analysis
        return $this->runChecklistAnalysis($io, $role, $path, $checklist, $format, $category, $severity, $outputFile);
    }

    private function showSummary(SymfonyStyle $io, string $role, array $roleInfo, array $checklist): int
    {
        $io->title("Role-Based Quality Checklist Summary: {$roleInfo['name']}");

        $io->section("Role Information");
        $io->definitionList(
            ['Role' => $roleInfo['name']],
            ['Level' => $roleInfo['level']],
            ['Description' => $roleInfo['description']]
        );

        $io->section("Checklist Overview");
        $categories = [];
        $totalChecks = 0;

        foreach ($checklist as $categoryKey => $category) {
            if ($categoryKey === 'tools') {
                continue;
            }

            $checkCount = 0;
            if (isset($category['items']) && is_array($category['items'])) {
                foreach ($category['items'] as $item) {
                    if (isset($item['checks']) && is_array($item['checks'])) {
                        $checkCount += count($item['checks']);
                    }
                }
            }

            $categories[] = [$category['name'], $checkCount];
            $totalChecks += $checkCount;
        }

        $table = new Table($io);
        $table->setHeaders(['Category', 'Checks']);
        $table->setRows($categories);
        $table->render();

        $io->note("Total checks: {$totalChecks}");

        return Command::SUCCESS;
    }

    private function showTools(SymfonyStyle $io, string $role, array $checklist): int
    {
        $io->title("Recommended Tools for {$role}");

        $tools = $checklist['tools'] ?? [];
        
        if (empty($tools)) {
            $io->warning("No tools specified for this role");
            return Command::SUCCESS;
        }

        $table = new Table($io);
        $table->setHeaders(['Tool', 'Description']);
        
        $rows = [];
        foreach ($tools as $tool => $description) {
            $rows[] = [$tool, $description];
        }
        
        $table->setRows($rows);
        $table->render();

        return Command::SUCCESS;
    }

    private function showDetailedChecklist(SymfonyStyle $io, string $role, array $checklist, ?string $category, string $severity): int
    {
        $io->title("Detailed Quality Checklist: {$role}");

        $severityLevels = ['info' => 1, 'warning' => 2, 'error' => 3, 'critical' => 4];
        $minSeverityLevel = $severityLevels[$severity] ?? 1;

        foreach ($checklist as $categoryKey => $categoryData) {
            if ($categoryKey === 'tools') {
                continue;
            }

            if ($category && $categoryKey !== $category) {
                continue;
            }

            $io->section($categoryData['name']);

            foreach ($categoryData['items'] as $itemKey => $item) {
                $io->subsection($item['name']);

                $table = new Table($io);
                $table->setHeaders(['Check', 'Severity', 'Tools', 'Description']);

                $rows = [];
                foreach ($item['checks'] as $checkKey => $check) {
                    $checkSeverityLevel = $severityLevels[$check['severity']] ?? 1;
                    
                    if ($checkSeverityLevel >= $minSeverityLevel) {
                        $rows[] = [
                            $check['name'],
                            $this->formatSeverity($check['severity']),
                            implode(', ', $check['tools']),
                            $check['description']
                        ];
                    }
                }

                $table->setRows($rows);
                $table->render();
            }
        }

        return Command::SUCCESS;
    }

    private function runChecklistAnalysis(SymfonyStyle $io, string $role, string $path, array $checklist, string $format, ?string $category, string $severity, ?string $outputFile): int
    {
        $io->title("Running Quality Checklist: {$role}");

        // Get all checks for the role
        $allChecks = $this->roleConfig->getAllChecks($role);
        
        // Filter by category if specified
        if ($category) {
            $allChecks = array_filter($allChecks, function($check) use ($category) {
                return $check['category'] === $category;
            });
        }

        // Filter by severity
        $severityLevels = ['minor' => 1, 'info' => 2, 'warning' => 3, 'error' => 4, 'critical' => 5, 'blocker' => 6];
        $minSeverityLevel = $severityLevels[$severity] ?? 1;
        
        $allChecks = array_filter($allChecks, function($check) use ($severityLevels, $minSeverityLevel) {
            $checkSeverityLevel = $severityLevels[$check['severity'] ?? 'info'] ?? 1;
            return $checkSeverityLevel >= $minSeverityLevel;
        });

        if (empty($allChecks)) {
            $io->warning("No checks found for the specified criteria");
            return Command::SUCCESS;
        }

        // Get required analyzers
        $requiredAnalyzers = [];
        foreach ($allChecks as $check) {
            if (isset($check['tools']) && is_array($check['tools'])) {
                foreach ($check['tools'] as $tool) {
                    $requiredAnalyzers[$tool] = true;
                }
            }
        }

        $analyzerIds = array_keys($requiredAnalyzers);
        $availableAnalyzers = $this->analyzerRegistry->getAllAnalyzers();
        
        // Filter available analyzers
        $availableAnalyzerIds = array_keys($availableAnalyzers);
        $analyzersToRun = array_intersect($analyzerIds, $availableAnalyzerIds);

        if (empty($analyzersToRun)) {
            $io->warning("No analyzers available for the required tools");
            $io->note("Required: " . implode(', ', $analyzerIds));
            $io->note("Available: " . implode(', ', $availableAnalyzerIds));
            return Command::SUCCESS;
        }

        // Run analysis
        $io->section("Running Analysis");
        $progress = new ProgressBar($io, count($analyzersToRun));
        $progress->start();

        $results = [];
        foreach ($analyzersToRun as $analyzerId) {
            try {
                $analyzer = $this->analyzerRegistry->getAnalyzer($analyzerId);
                if ($analyzer) {
                    // Get files to analyze
                    $files = $this->getFilesToAnalyze($path);
                    $analyzerResults = $this->analyzerRegistry->runAnalyzers([$analyzerId], $files);
                    $results[$analyzerId] = $analyzerResults;
                }
            } catch (\Exception $e) {
                $io->error("Error running analyzer {$analyzerId}: " . $e->getMessage());
            }
            $progress->advance();
        }
        $progress->finish();
        $io->newLine();

        // Process results
        $checklistResults = $this->processChecklistResults($allChecks, $results);

        // Display results
        $this->displayResults($io, $checklistResults, $format, $outputFile);

        return Command::SUCCESS;
    }

    private function getFilesToAnalyze(string $path): array
    {
        $files = [];
        
        if (is_file($path)) {
            $files[] = $path;
        } elseif (is_dir($path)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
            );
            
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $extension = $file->getExtension();
                    if (in_array($extension, ['php', 'js', 'jsx', 'ts', 'tsx', 'xml', 'html', 'css'])) {
                        $files[] = $file->getPathname();
                    }
                }
            }
        }

        return $files;
    }

    private function processChecklistResults(array $checks, array $analysisResults): array
    {
        $results = [];

        foreach ($checks as $checkId => $check) {
            $status = 'pending';
            $violations = [];
            $score = 0;

            // Check if any violations match this check
            foreach ($analysisResults as $analyzerId => $analyzerResults) {
                if (in_array($analyzerId, $check['tools'])) {
                    foreach ($analyzerResults as $violation) {
                        if ($this->violationMatchesCheck($violation, $check)) {
                            $violations[] = $violation;
                            $status = 'failed';
                        }
                    }
                }
            }

            // Calculate score based on violations
            if (empty($violations)) {
                $status = 'passed';
                $score = 100;
            } else {
                $score = max(0, 100 - (count($violations) * 10));
            }

            $results[$checkId] = [
                'check' => $check,
                'status' => $status,
                'score' => $score,
                'violations' => $violations,
                'category' => $check['category'],
                'item' => $check['item']
            ];
        }

        return $results;
    }

    private function violationMatchesCheck($violation, array $check): bool
    {
        // Simple matching logic - can be enhanced
        $violationMessage = strtolower($violation->getMessage());
        $checkName = strtolower($check['name']);
        
        // Check if violation message contains check keywords
        $keywords = explode(' ', $checkName);
        foreach ($keywords as $keyword) {
            if (strlen($keyword) > 3 && strpos($violationMessage, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }

    private function displayResults(SymfonyStyle $io, array $results, string $format, ?string $outputFile): void
    {
        $io->section("Checklist Results");

        // Calculate summary
        $total = count($results);
        $passed = count(array_filter($results, fn($r) => $r['status'] === 'passed'));
        $failed = count(array_filter($results, fn($r) => $r['status'] === 'failed'));
        $pending = count(array_filter($results, fn($r) => $r['status'] === 'pending'));

        $overallScore = $total > 0 ? round(array_sum(array_column($results, 'score')) / $total, 1) : 0;

        $io->definitionList(
            ['Total Checks' => $total],
            ['Passed' => $passed],
            ['Failed' => $failed],
            ['Pending' => $pending],
            ['Overall Score' => $overallScore . '%']
        );

        // Display detailed results
        $table = new Table($io);
        $table->setHeaders(['Check', 'Category', 'Status', 'Score', 'Violations']);

        $rows = [];
        foreach ($results as $checkId => $result) {
            $rows[] = [
                $result['check']['name'],
                $result['check']['category'],
                $this->formatStatus($result['status']),
                $result['score'] . '%',
                count($result['violations'])
            ];
        }

        $table->setRows($rows);
        $table->render();

        // Show failed checks details
        $failedResults = array_filter($results, fn($r) => $r['status'] === 'failed');
        if (!empty($failedResults)) {
            $io->section("Failed Checks Details");
            
            foreach ($failedResults as $checkId => $result) {
                $io->text("<fg=red>● {$result['check']['name']}</>");
                $io->text("  Category: {$result['check']['category']}");
                $io->text("  Description: {$result['check']['description']}");
                
                if (!empty($result['violations'])) {
                    $io->text("  Violations:");
                    foreach ($result['violations'] as $violation) {
                        $io->text("    - {$violation->getMessage()}");
                    }
                }
                $io->newLine();
            }
        }

        // Export if requested
        if ($outputFile) {
            $this->exportResults($results, $format, $outputFile);
            $io->success("Results exported to: {$outputFile}");
        }
    }

    private function formatStatus(string $status): string
    {
        return match($status) {
            'passed' => '<fg=green>✓ Passed</>',
            'failed' => '<fg=red>✗ Failed</>',
            'pending' => '<fg=yellow>○ Pending</>',
            default => $status
        };
    }

    private function formatSeverity(string $severity): string
    {
        return match($severity) {
            'critical' => '<fg=red>Critical</>',
            'error' => '<fg=red>Error</>',
            'warning' => '<fg=yellow>Warning</>',
            'info' => '<fg=blue>Info</>',
            default => $severity
        };
    }

    private function exportResults(array $results, string $format, string $outputFile): void
    {
        $data = [
            'summary' => [
                'total' => count($results),
                'passed' => count(array_filter($results, fn($r) => $r['status'] === 'passed')),
                'failed' => count(array_filter($results, fn($r) => $r['status'] === 'failed')),
                'overall_score' => count($results) > 0 ? round(array_sum(array_column($results, 'score')) / count($results), 1) : 0
            ],
            'results' => $results
        ];

        switch ($format) {
            case 'json':
                file_put_contents($outputFile, json_encode($data, JSON_PRETTY_PRINT));
                break;
            case 'xml':
                $xml = new \SimpleXMLElement('<?xml version="1.0"?><checklist></checklist>');
                $this->arrayToXml($data, $xml);
                file_put_contents($outputFile, $xml->asXML());
                break;
            case 'html':
                $html = $this->generateHtmlReport($data);
                file_put_contents($outputFile, $html);
                break;
            default:
                file_put_contents($outputFile, json_encode($data, JSON_PRETTY_PRINT));
        }
    }

    private function arrayToXml(array $data, \SimpleXMLElement $xml): void
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $subnode = $xml->addChild($key);
                $this->arrayToXml($value, $subnode);
            } else {
                $xml->addChild($key, (string) $value);
            }
        }
    }

    private function generateHtmlReport(array $data): string
    {
        $html = '<!DOCTYPE html>
<html>
<head>
    <title>Quality Checklist Report</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .summary { background: #f5f5f5; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
        .passed { color: green; }
        .failed { color: red; }
        .pending { color: orange; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
    </style>
</head>
<body>
    <h1>Quality Checklist Report</h1>
    
    <div class="summary">
        <h2>Summary</h2>
        <p>Total Checks: ' . $data['summary']['total'] . '</p>
        <p class="passed">Passed: ' . $data['summary']['passed'] . '</p>
        <p class="failed">Failed: ' . $data['summary']['failed'] . '</p>
        <p>Overall Score: ' . $data['summary']['overall_score'] . '%</p>
    </div>
    
    <h2>Detailed Results</h2>
    <table>
        <tr>
            <th>Check</th>
            <th>Category</th>
            <th>Status</th>
            <th>Score</th>
            <th>Violations</th>
        </tr>';

        foreach ($data['results'] as $result) {
            $statusClass = $result['status'];
            $html .= '
        <tr>
            <td>' . htmlspecialchars($result['check']['name']) . '</td>
            <td>' . htmlspecialchars($result['check']['category']) . '</td>
            <td class="' . $statusClass . '">' . ucfirst($result['status']) . '</td>
            <td>' . $result['score'] . '%</td>
            <td>' . count($result['violations']) . '</td>
        </tr>';
        }

        $html .= '
    </table>
</body>
</html>';

        return $html;
    }
} 
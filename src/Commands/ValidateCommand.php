<?php

declare(strict_types=1);

namespace ACQC\Commands;

use ACQC\Core\Validation\SystemValidator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Logger\ConsoleLogger;

#[AsCommand(
    name: 'validate',
    description: 'Validate all code analyzers and audit rules are working properly'
)]
class ValidateCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('export', 'e', InputOption::VALUE_OPTIONAL, 'Export results to file')
            ->addOption('format', 'f', InputOption::VALUE_OPTIONAL, 'Export format (json, yaml, md)', 'json')
            ->addOption('detailed', 'd', InputOption::VALUE_NONE, 'Show detailed results')
            ->setHelp('
Comprehensive validation of all ACQC components including:
- Code analyzers
- Audit rules
- System dependencies
- Component integration
- Performance metrics

<comment>Usage:</comment>
  <info>acqc validate</info>                      # Basic validation
  <info>acqc validate --detailed</info>           # Detailed results
  <info>acqc validate --export report.json</info> # Export to file
  <info>acqc validate --export report.md --format md</info> # Export as Markdown
');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $logger = new ConsoleLogger($output);

        $io->title('ACQC System Validation');
        $io->text('Validating all components, analyzers, and rules...');
        $io->newLine();

        $validator = new SystemValidator($logger);
        
        $progressBar = $io->createProgressBar(6);
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %message%');
        $progressBar->setMessage('Initializing...');
        $progressBar->start();

        $progressBar->setMessage('Validating analyzers...');
        $progressBar->advance();

        $progressBar->setMessage('Validating rules...');
        $progressBar->advance();

        $progressBar->setMessage('Validating orchestrator...');
        $progressBar->advance();

        $progressBar->setMessage('Validating integration...');
        $progressBar->advance();

        $progressBar->setMessage('Validating dependencies...');
        $progressBar->advance();

        $progressBar->setMessage('Measuring performance...');
        $progressBar->advance();

        try {
            $results = $validator->validate();
        } catch (\Throwable $e) {
            $progressBar->finish();
            $io->newLine(2);
            $io->error('Validation failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $progressBar->finish();
        $io->newLine(2);

        // Display overall status
        $this->displayOverallStatus($io, $results);

        // Display summary
        $this->displaySummary($io, $results);

        // Display recommendations
        if (!empty($results['recommendations'])) {
            $this->displayRecommendations($io, $results['recommendations']);
        }

        // Display detailed results if requested
        if ($input->getOption('detailed')) {
            $this->displayDetailedResults($io, $results);
        }

        // Export results if requested
        if ($exportFile = $input->getOption('export')) {
            $format = $input->getOption('format');
            if ($validator->exportToFile($exportFile, $format)) {
                $io->success("Validation results exported to: {$exportFile}");
            } else {
                $io->error("Failed to export results to: {$exportFile}");
            }
        }

        // Return exit code based on status
        return match ($results['overall_status']) {
            'excellent', 'good' => Command::SUCCESS,
            'warning' => 1,
            'critical' => 2,
            default => Command::FAILURE
        };
    }

    private function displayOverallStatus(SymfonyStyle $io, array $results): void
    {
        $status = $results['overall_status'];
        
        $statusMessages = [
            'excellent' => '✅ EXCELLENT - All systems operational',
            'good' => '✓ GOOD - Minor issues detected',
            'warning' => '⚠ WARNING - Attention required',
            'critical' => '❌ CRITICAL - Immediate action required'
        ];

        $statusColors = [
            'excellent' => 'info',
            'good' => 'info',
            'warning' => 'comment',
            'critical' => 'error'
        ];

        $message = $statusMessages[$status] ?? 'UNKNOWN STATUS';
        $color = $statusColors[$status] ?? 'error';

        $io->writeln("<{$color}>" . str_repeat('=', 70) . "</{$color}>");
        $io->writeln("<{$color}>" . str_pad($message, 70, ' ', STR_PAD_BOTH) . "</{$color}>");
        $io->writeln("<{$color}>" . str_repeat('=', 70) . "</{$color}>");
        $io->newLine();
    }

    private function displaySummary(SymfonyStyle $io, array $results): void
    {
        $io->section('Summary');

        $summaryData = [
            ['Component', 'Status', 'Details'],
            new \Symfony\Component\Console\Helper\TableSeparator(),
            [
                'Analyzers',
                $this->formatStatus($results['analyzers']['status']),
                "{$results['analyzers']['available_count']}/{$results['analyzers']['total_count']} available"
            ],
            [
                'Rules',
                $this->formatStatus($results['rules']['status']),
                "{$results['rules']['rules_loaded']} rules loaded"
            ],
            [
                'Orchestrator',
                $this->formatStatus($results['orchestrator']['status']),
                "{$results['orchestrator']['enabled_analyzers']} analyzers enabled"
            ],
            [
                'Integration',
                $this->formatStatus($results['integration']['status']),
                "Components connected"
            ],
            [
                'Dependencies',
                $this->formatStatus($results['dependencies']['status']),
                "PHP {$results['dependencies']['php_version']}"
            ],
            [
                'Performance',
                $this->formatStatus($results['performance']['status']),
                "{$results['performance']['memory_usage_formatted']} memory"
            ],
        ];

        $io->table($summaryData[0], array_slice($summaryData, 1));
    }

    private function displayRecommendations(SymfonyStyle $io, array $recommendations): void
    {
        $io->section('Recommendations');

        $criticalCount = count(array_filter($recommendations, fn($r) => $r['severity'] === 'critical'));
        $warningCount = count(array_filter($recommendations, fn($r) => $r['severity'] === 'warning'));
        $infoCount = count(array_filter($recommendations, fn($r) => $r['severity'] === 'info'));

        $io->writeln("Found: <error>{$criticalCount} critical</error>, <comment>{$warningCount} warnings</comment>, <info>{$infoCount} info</info>");
        $io->newLine();

        foreach ($recommendations as $index => $rec) {
            $icon = match ($rec['severity']) {
                'critical' => '❌',
                'warning' => '⚠️',
                'info' => 'ℹ️',
                default => '•'
            };

            $color = match ($rec['severity']) {
                'critical' => 'error',
                'warning' => 'comment',
                'info' => 'info',
                default => ''
            };

            $io->writeln("<{$color}>{$icon} [{$rec['category']}] {$rec['message']}</{$color}>");
            $io->writeln("   → Action: {$rec['action']}");
            $io->newLine();
        }
    }

    private function displayDetailedResults(SymfonyStyle $io, array $results): void
    {
        $io->section('Detailed Results');

        // Analyzer details
        if (!empty($results['analyzers']['analyzers'])) {
            $io->writeln('<info>Analyzer Status:</info>');
            $analyzerRows = [];
            
            foreach ($results['analyzers']['analyzers'] as $id => $analyzer) {
                $status = $analyzer['available'] ? '✓' : '✗';
                $deps = $analyzer['dependencies_met'] ? '✓' : '✗';
                $test = $analyzer['test_result']['can_run'] ? '✓' : '✗';
                
                $analyzerRows[] = [
                    $id,
                    $status,
                    $deps,
                    $test,
                    $analyzer['category'],
                    $analyzer['version']
                ];
            }
            
            $io->table(
                ['ID', 'Available', 'Dependencies', 'Test', 'Category', 'Version'],
                $analyzerRows
            );
            $io->newLine();
        }

        // Dependency details
        if (!empty($results['dependencies'])) {
            $io->writeln('<info>Dependencies:</info>');
            
            // PHP Extensions
            $io->writeln('  Required Extensions:');
            foreach ($results['dependencies']['required_extensions'] as $ext => $loaded) {
                $status = $loaded ? '✓' : '✗';
                $io->writeln("    {$status} {$ext}");
            }
            
            $io->newLine();
            
            // External Tools
            $io->writeln('  External Tools:');
            foreach ($results['dependencies']['external_tools'] as $tool => $status) {
                $icon = $status === 'available' ? '✓' : '✗';
                $io->writeln("    {$icon} {$tool}: {$status}");
            }
            
            $io->newLine();
        }

        // Performance metrics
        if (!empty($results['performance'])) {
            $io->writeln('<info>Performance Metrics:</info>');
            $io->definitionList(
                ['Startup Time' => round($results['performance']['startup_time'], 3) . 's'],
                ['Analyzer Init' => round($results['performance']['analyzer_initialization_time'], 3) . 's'],
                ['Rule Loading' => round($results['performance']['rule_loading_time'], 3) . 's'],
                ['Memory Usage' => $results['performance']['memory_usage_formatted']],
                ['Memory Peak' => $results['performance']['memory_peak_formatted']]
            );
            $io->newLine();
        }
    }

    private function formatStatus(string $status): string
    {
        return match ($status) {
            'excellent' => '<info>✅ Excellent</info>',
            'good' => '<info>✓ Good</info>',
            'warning' => '<comment>⚠ Warning</comment>',
            'critical' => '<error>❌ Critical</error>',
            default => "<comment>{$status}</comment>"
        };
    }
}

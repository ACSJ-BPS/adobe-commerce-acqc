<?php
declare(strict_types=1);
namespace ACQC\Commands;

use ACQC\Core\History\SqliteHistoryStorage;
use ACQC\Core\History\HistoryEntry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Helper\ProgressBar;

#[AsCommand(
    name: 'aggregate',
    description: 'Run quality analysis across multiple Magento projects and produce a unified report'
)]
class AggregateCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('projects', InputArgument::IS_ARRAY | InputArgument::REQUIRED,
                'Space-separated list of project paths to analyze')
            ->addOption('names', null, InputOption::VALUE_OPTIONAL,
                'Comma-separated project names matching the order of paths')
            ->addOption('format', 'f', InputOption::VALUE_OPTIONAL,
                'Output format: table, json, csv', 'table')
            ->addOption('output', 'o', InputOption::VALUE_OPTIONAL,
                'Save output to a file')
            ->addOption('type', null, InputOption::VALUE_OPTIONAL,
                'Analysis type passed to each project (audit|analyze)', 'audit')
            ->addOption('severity', 's', InputOption::VALUE_OPTIONAL,
                'Minimum severity to include in violation counts', 'warning')
            ->addOption('exclude-paths', null, InputOption::VALUE_OPTIONAL,
                'Path patterns to exclude in every project (comma-separated)',
                '*/Test/*,*/Tests/*,*/vendor/*')
            ->addOption('save-history', null, InputOption::VALUE_NONE,
                'Save each project result to history database')
            ->addOption('parallel', null, InputOption::VALUE_NONE,
                'Run projects in parallel (uses proc_open)')
            ->setHelp(<<<'HELP'
Run quality analysis across multiple Magento projects and produce a unified comparison report.

<comment>Basic usage:</comment>
  <info>acqc aggregate /path/to/project1 /path/to/project2</info>
  <info>acqc aggregate /projects/store-a /projects/store-b --names="Store A,Store B"</info>

<comment>JSON output for dashboards/CI:</comment>
  <info>acqc aggregate /p1 /p2 /p3 --format=json --output=aggregate.json</info>

<comment>Save to history for trend tracking:</comment>
  <info>acqc aggregate /p1 /p2 --save-history</info>

<comment>CSV for spreadsheets:</comment>
  <info>acqc aggregate /p1 /p2 --format=csv --output=report.csv</info>
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $paths = $input->getArgument('projects');
        $names = array_filter(array_map('trim', explode(',', $input->getOption('names') ?? '')));
        $format = $input->getOption('format') ?? 'table';
        $outputFile = $input->getOption('output');
        $analysisType = $input->getOption('type') ?? 'audit';
        $severityFilter = $input->getOption('severity') ?? 'warning';
        $excludePaths = $input->getOption('exclude-paths') ?? '*/Test/*,*/Tests/*,*/vendor/*';
        $saveHistory = (bool)$input->getOption('save-history');

        // Validate paths
        $validPaths = [];
        foreach ($paths as $i => $path) {
            $real = realpath($path);
            if (!$real || !is_dir($real)) {
                $io->warning("Path does not exist, skipping: {$path}");
                continue;
            }
            $validPaths[] = ['path' => $real, 'name' => $names[$i] ?? basename($real)];
        }

        if (empty($validPaths)) {
            $io->error('No valid project paths provided.');
            return Command::FAILURE;
        }

        $io->title('ACQC Multi-Project Aggregation');
        $io->writeln(sprintf('<info>Projects to analyze:</info> %d', count($validPaths)));
        $io->writeln(sprintf('<info>Analysis type:</info> %s', $analysisType));
        $io->newLine();

        $progress = new ProgressBar($output, count($validPaths));
        $progress->setFormat(' %current%/%max% [%bar%] %percent:3s%% %message%');
        $progress->start();

        $results = [];
        foreach ($validPaths as $project) {
            $progress->setMessage('Analyzing ' . $project['name'] . '...');
            $progress->advance();

            $result = $this->analyzeProject($project['path'], $project['name'], $analysisType, $excludePaths, $severityFilter);
            $results[] = $result;

            if ($saveHistory && isset($result['score'])) {
                $this->saveToHistory($result);
            }
        }

        $progress->finish();
        $io->newLine(2);

        // Sort by score descending
        usort($results, fn($a, $b) => $b['score'] <=> $a['score']);

        $output_content = match($format) {
            'json' => $this->formatJson($results),
            'csv'  => $this->formatCsv($results),
            default => null,
        };

        if ($format !== 'table') {
            if ($outputFile) {
                file_put_contents($outputFile, $output_content);
                $io->success("Aggregate report saved to: {$outputFile}");
            } else {
                $io->writeln($output_content);
            }
        } else {
            $this->renderTable($io, $results);
            if ($outputFile) {
                file_put_contents($outputFile, $this->formatJson($results));
                $io->success("JSON report also saved to: {$outputFile}");
            }
        }

        $this->renderSummary($io, $results);
        return Command::SUCCESS;
    }

    private function analyzeProject(string $path, string $name, string $type, string $excludePaths, string $severity): array
    {
        $acqcBin = realpath(__DIR__ . '/../../bin/acqc') ?: 'php bin/acqc';
        $phpBin  = PHP_BINARY;

        $cmd = sprintf(
            '%s %s %s %s --format=json --exclude-paths=%s --severity=%s 2>/dev/null',
            escapeshellarg($phpBin),
            escapeshellarg($acqcBin),
            $type,
            escapeshellarg($path),
            escapeshellarg($excludePaths),
            escapeshellarg($severity)
        );

        $descriptorSpec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($cmd, $descriptorSpec, $pipes, $path);

        if (!is_resource($process)) {
            return $this->buildEmptyResult($path, $name, 'Failed to start process');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $data = @json_decode($stdout, true);

        if (!is_array($data)) {
            // Try to extract basic metrics from text output
            return $this->buildEmptyResult($path, $name, $exitCode !== 0 ? 'Analysis failed (exit ' . $exitCode . ')' : 'No parseable output');
        }

        $violations = $data['violations'] ?? $data['issues'] ?? [];
        $critical = array_filter($violations, function ($v) {
            $sev = strtolower(is_array($v) ? ($v['severity'] ?? '') : '');
            return in_array($sev, ['critical', 'blocker', 'error']);
        });

        return [
            'path'               => $path,
            'name'               => $name,
            'score'              => round((float)($data['score'] ?? 0), 1),
            'grade'              => $data['grade'] ?? $this->scoreToGrade((float)($data['score'] ?? 0)),
            'total_violations'   => count($violations),
            'critical_violations'=> count($critical),
            'files_analyzed'     => (int)($data['files_analyzed'] ?? 0),
            'line_coverage'      => (float)($data['coverage']['line_coverage'] ?? 0),
            'modules'            => count($data['modules'] ?? []),
            'analyzed_at'        => date('Y-m-d H:i:s'),
            'error'              => null,
            'raw'                => $data,
        ];
    }

    private function buildEmptyResult(string $path, string $name, string $error): array
    {
        return [
            'path' => $path, 'name' => $name,
            'score' => 0.0, 'grade' => 'N/A',
            'total_violations' => 0, 'critical_violations' => 0,
            'files_analyzed' => 0, 'line_coverage' => 0.0,
            'modules' => 0, 'analyzed_at' => date('Y-m-d H:i:s'),
            'error' => $error, 'raw' => [],
        ];
    }

    private function renderTable(SymfonyStyle $io, array $results): void
    {
        $io->section('Project Quality Comparison');

        $rows = [];
        foreach ($results as $r) {
            $scoreColor = $r['score'] >= 80 ? 'green' : ($r['score'] >= 60 ? 'yellow' : 'red');
            $rows[] = [
                $r['name'],
                sprintf('<fg=%s>%s (%.1f)</>',  $scoreColor, $r['grade'], $r['score']),
                $r['total_violations'] . ($r['critical_violations'] > 0
                    ? " (<fg=red>{$r['critical_violations']} crit</>)" : ''),
                $r['files_analyzed'],
                $r['line_coverage'] > 0 ? round($r['line_coverage'] * 100, 1) . '%' : 'N/A',
                $r['modules'] > 0 ? $r['modules'] : 'N/A',
                $r['error'] ? "<fg=red>{$r['error']}</>" : '<fg=green>OK</>',
            ];
        }

        $io->table(
            ['Project', 'Grade (Score)', 'Violations', 'Files', 'Coverage', 'Modules', 'Status'],
            $rows
        );
    }

    private function renderSummary(SymfonyStyle $io, array $results): void
    {
        $successful = array_filter($results, fn($r) => $r['error'] === null);
        if (empty($successful)) return;

        $avgScore = round(array_sum(array_column($successful, 'score')) / count($successful), 1);
        $totalViolations = array_sum(array_column($successful, 'total_violations'));
        $totalCritical = array_sum(array_column($successful, 'critical_violations'));
        $best = $results[0];
        $worst = end($results);

        $io->section('Portfolio Summary');
        $io->definitionList(
            ['Projects analyzed' => count($successful) . ' / ' . count($results)],
            ['Portfolio avg score' => $avgScore . '/100'],
            ['Total violations'  => $totalViolations . " ({$totalCritical} critical)"],
            ['Best project'      => "{$best['name']} ({$best['score']})"],
            ['Needs most work'   => "{$worst['name']} ({$worst['score']})"],
        );
    }

    private function formatJson(array $results): string
    {
        $summary = [
            'generated_at'       => date('c'),
            'projects_count'     => count($results),
            'avg_score'          => count($results) > 0 ? round(array_sum(array_column($results, 'score')) / count($results), 2) : 0,
            'total_violations'   => array_sum(array_column($results, 'total_violations')),
            'critical_violations'=> array_sum(array_column($results, 'critical_violations')),
        ];
        $output = array_map(function ($r) { unset($r['raw']); return $r; }, $results);
        return json_encode(['summary' => $summary, 'projects' => $output], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private function formatCsv(array $results): string
    {
        $lines = ["Project,Path,Score,Grade,Violations,Critical,Files,Coverage,Modules,Status,Analyzed At"];
        foreach ($results as $r) {
            $lines[] = implode(',', [
                '"' . str_replace('"', '""', $r['name']) . '"',
                '"' . str_replace('"', '""', $r['path']) . '"',
                $r['score'], $r['grade'],
                $r['total_violations'], $r['critical_violations'],
                $r['files_analyzed'],
                $r['line_coverage'] > 0 ? round($r['line_coverage'] * 100, 1) . '%' : 'N/A',
                $r['modules'], $r['error'] ? 'ERROR' : 'OK',
                $r['analyzed_at'],
            ]);
        }
        return implode("\n", $lines);
    }

    private function scoreToGrade(float $score): string
    {
        return match(true) {
            $score >= 90 => 'A+', $score >= 80 => 'A',
            $score >= 70 => 'B', $score >= 60 => 'C',
            $score >= 50 => 'D', default => 'F',
        };
    }

    private function saveToHistory(array $result): void
    {
        try {
            $storage = new SqliteHistoryStorage();
            $entry = new HistoryEntry(
                id: null,
                projectPath: $result['path'],
                projectName: $result['name'],
                command: 'aggregate',
                score: $result['score'],
                totalViolations: $result['total_violations'],
                criticalViolations: $result['critical_violations'],
                filesAnalyzed: $result['files_analyzed'],
                lineCoverage: $result['line_coverage'],
                metadata: ['grade' => $result['grade'], 'modules' => $result['modules']],
                createdAt: $result['analyzed_at'],
            );
            $storage->save($entry);
        } catch (\Exception $e) {
            // Non-fatal
        }
    }
}

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

#[AsCommand(
    name: 'history',
    description: 'View and compare historical scan results stored in SQLite'
)]
class HistoryCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::OPTIONAL,
                'Action: list, show, compare, clear, export', 'list')
            ->addArgument('args', InputArgument::IS_ARRAY,
                'Arguments for the action (IDs, project path, etc.)')
            ->addOption('project', 'p', InputOption::VALUE_OPTIONAL,
                'Filter by project path')
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL,
                'Number of entries to show', '20')
            ->addOption('format', 'f', InputOption::VALUE_OPTIONAL,
                'Output format (table/json)', 'table')
            ->addOption('db', null, InputOption::VALUE_OPTIONAL,
                'Path to SQLite database file')
            ->setHelp(<<<'HELP'
View and compare historical ACQC scan results.

<comment>List recent scans:</comment>
  <info>acqc history</info>
  <info>acqc history list --project /path/to/project --limit=10</info>

<comment>Show a specific entry:</comment>
  <info>acqc history show 42</info>

<comment>Compare two entries:</comment>
  <info>acqc history compare 10 20</info>

<comment>Export history as JSON:</comment>
  <info>acqc history export --format=json > history.json</info>

<comment>Clear history for a project:</comment>
  <info>acqc history clear --project /path/to/project</info>

History is stored at: ~/.acqc/history.sqlite
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $action = $input->getArgument('action');
        $args   = $input->getArgument('args');
        $dbPath = $input->getOption('db') ?? '';

        try {
            $storage = new SqliteHistoryStorage($dbPath);
        } catch (\Exception $e) {
            $io->error('Failed to open history database: ' . $e->getMessage());
            return Command::FAILURE;
        }

        return match($action) {
            'list'    => $this->actionList($io, $input, $storage),
            'show'    => $this->actionShow($io, $args, $storage),
            'compare' => $this->actionCompare($io, $args, $storage),
            'clear'   => $this->actionClear($io, $input, $storage),
            'export'  => $this->actionExport($io, $input, $storage),
            default   => $this->actionList($io, $input, $storage),
        };
    }

    private function actionList(SymfonyStyle $io, InputInterface $input, SqliteHistoryStorage $storage): int
    {
        $project = $input->getOption('project') ?? '';
        $limit   = (int)($input->getOption('limit') ?? 20);
        $format  = $input->getOption('format') ?? 'table';

        $entries = $storage->findAll($project, $limit);

        if (empty($entries)) {
            $io->info('No history entries found. Run a report or analyze command to start tracking.');
            return Command::SUCCESS;
        }

        if ($format === 'json') {
            $io->writeln(json_encode(array_map(fn($e) => $e->toArray(), $entries), JSON_PRETTY_PRINT));
            return Command::SUCCESS;
        }

        $io->title('ACQC Scan History');
        if ($project) {
            $io->writeln("<info>Project filter:</info> {$project}");
        }

        $rows = [];
        foreach ($entries as $entry) {
            $scoreColor = $entry->score >= 80 ? 'green' : ($entry->score >= 60 ? 'yellow' : 'red');
            $rows[] = [
                $entry->id,
                $entry->projectName ?: basename($entry->projectPath),
                sprintf('<fg=%s>%.1f</>', $scoreColor, $entry->score),
                $entry->totalViolations . ($entry->criticalViolations > 0 ? " (<fg=red>{$entry->criticalViolations} crit</>)" : ''),
                $entry->filesAnalyzed,
                $entry->lineCoverage > 0 ? round($entry->lineCoverage, 1) . '%' : 'N/A',
                $entry->command,
                $entry->createdAt,
            ];
        }

        $io->table(
            ['ID', 'Project', 'Score', 'Violations', 'Files', 'Coverage', 'Command', 'Date'],
            $rows
        );

        $io->writeln(sprintf('<fg=gray>Database: %s</>', $storage->getDbPath()));
        return Command::SUCCESS;
    }

    private function actionShow(SymfonyStyle $io, array $args, SqliteHistoryStorage $storage): int
    {
        $id = (int)($args[0] ?? 0);
        if (!$id) {
            $io->error('Usage: acqc history show <id>');
            return Command::FAILURE;
        }

        $entry = $storage->findById($id);
        if (!$entry) {
            $io->error("History entry #{$id} not found.");
            return Command::FAILURE;
        }

        $io->title("History Entry #{$entry->id}");
        $io->definitionList(
            ['Project'      => $entry->projectName ?: $entry->projectPath],
            ['Path'         => $entry->projectPath],
            ['Command'      => $entry->command],
            ['Score'        => $entry->score . '/100'],
            ['Violations'   => $entry->totalViolations . " ({$entry->criticalViolations} critical)"],
            ['Files'        => $entry->filesAnalyzed],
            ['Coverage'     => $entry->lineCoverage > 0 ? round($entry->lineCoverage, 2) . '%' : 'N/A'],
            ['Recorded at'  => $entry->createdAt],
        );

        if (!empty($entry->metadata)) {
            $io->section('Metadata');
            $io->writeln(json_encode($entry->metadata, JSON_PRETTY_PRINT));
        }

        return Command::SUCCESS;
    }

    private function actionCompare(SymfonyStyle $io, array $args, SqliteHistoryStorage $storage): int
    {
        if (count($args) < 2) {
            $io->error('Usage: acqc history compare <id-a> <id-b>');
            return Command::FAILURE;
        }

        try {
            $diff = $storage->compare((int)$args[0], (int)$args[1]);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $a = $diff['entry_a'];
        $b = $diff['entry_b'];
        $delta = $diff['delta'];
        $trend = $diff['trend'];

        $io->title(sprintf('Comparing #%d → #%d', $a['id'], $b['id']));

        $trendIcon = fn(string $t, bool $invertColors = false): string => match($t) {
            'improved' => $invertColors ? '<fg=red>↑</>' : '<fg=green>↑</>',
            'degraded' => $invertColors ? '<fg=green>↓</>' : '<fg=red>↓</>',
            default    => '<fg=gray>→</>',
        };

        $io->table(
            ['Metric', "#{$a['id']} ({$a['created_at']})", "#{$b['id']} ({$b['created_at']})", 'Delta', 'Trend'],
            [
                ['Score',      $a['score'], $b['score'],
                    ($delta['score'] >= 0 ? '+' : '') . $delta['score'],
                    $trendIcon($trend['score'])],
                ['Violations', $a['total_violations'], $b['total_violations'],
                    ($delta['total_violations'] >= 0 ? '+' : '') . $delta['total_violations'],
                    $trendIcon($trend['violations'], true)],
                ['Critical',   $a['critical_violations'], $b['critical_violations'],
                    ($delta['critical_violations'] >= 0 ? '+' : '') . $delta['critical_violations'],
                    $trendIcon($trend['violations'], true)],
                ['Coverage',   $a['line_coverage'] . '%', $b['line_coverage'] . '%',
                    ($delta['line_coverage'] >= 0 ? '+' : '') . $delta['line_coverage'] . '%',
                    $trendIcon($trend['coverage'])],
                ['Files',      $a['files_analyzed'], $b['files_analyzed'],
                    ($delta['files_analyzed'] >= 0 ? '+' : '') . $delta['files_analyzed'],
                    ''],
            ]
        );

        return Command::SUCCESS;
    }

    private function actionClear(SymfonyStyle $io, InputInterface $input, SqliteHistoryStorage $storage): int
    {
        $project = $input->getOption('project') ?? '';
        $target  = $project ? "project: {$project}" : 'all projects';

        if (!$io->confirm("Clear history for {$target}?", false)) {
            $io->writeln('Aborted.');
            return Command::SUCCESS;
        }

        $storage->clear($project);
        $io->success("History cleared for {$target}.");
        return Command::SUCCESS;
    }

    private function actionExport(SymfonyStyle $io, InputInterface $input, SqliteHistoryStorage $storage): int
    {
        $project = $input->getOption('project') ?? '';
        $entries = $storage->findAll($project, 10000);
        $data = array_map(fn($e) => $e->toArray(), $entries);
        $io->writeln(json_encode(['history' => $data, 'exported_at' => date('c')], JSON_PRETTY_PRINT));
        return Command::SUCCESS;
    }
}

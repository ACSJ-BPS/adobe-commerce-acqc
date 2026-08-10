<?php

declare(strict_types=1);

namespace ACQC\Commands\AppBuilder;

use ACQC\Analyzers\AppBuilder\AbqcAnalyzer;
use ACQC\Core\Platform\PlatformDetector;
use ACQC\Core\Platform\PlatformRegistry;
use ACQC\Core\Reporting\ResultSchema;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'appbuilder:lint',
    description: 'ESLint-backed App Builder / Node.js checks via the vendored abqc engine (mechanical + security rules)'
)]
class LintCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('path', InputArgument::OPTIONAL, 'Path to the App Builder / Node.js project', getcwd())
            ->addOption('format', 'f', InputOption::VALUE_OPTIONAL, 'Output format (console, json)', 'console')
            ->addOption('min-confidence', null, InputOption::VALUE_OPTIONAL, 'Drop findings below this confidence (high, medium, low)', null)
            ->setHelp(<<<'HELP'
Runs the vendored `abqc` Node/TypeScript engine (abqc/packages/cli) against an
App Builder / Node.js project and reports its findings as ACQC issues.

abqc findings carry a `confidence` field: rules with no taint tracking (e.g.
object-injection, non-literal RegExp/require/fs) are marked "low" since a
pattern match there still needs a human to confirm real risk. Use
--min-confidence=high to see only the mechanically reliable rules (complexity,
unused vars, node-prefix, process.exit misuse, etc).

Requires `npm install` to have been run at the ACQC repo root (builds abqc via
its postinstall hook). Skips gracefully — zero issues, exit 0 — if abqc hasn't
been built or Node.js isn't available.
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path    = (string) $input->getArgument('path');
        $machine = $input->getOption('format') === 'json';
        $errOut  = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $io      = new SymfonyStyle($input, $machine ? $errOut : $output);

        $acqcRoot  = \ACQC\Core\AcqcPaths::resourceRoot();
        $detector  = new PlatformDetector(new PlatformRegistry($acqcRoot));
        $platforms = array_map(static fn ($p) => $p->id, $detector->detect($path));
        if (array_intersect(['app-builder', 'nodejs'], $platforms) === []) {
            $msg = 'Not an App Builder / Node.js project (no package.json / aio markers).';
            $machine ? $output->writeln(json_encode(['error' => $msg])) : $io->error($msg);
            return Command::FAILURE;
        }

        $analyzer = new AbqcAnalyzer();
        $result   = $analyzer->analyze([], ['project_path' => $path]);
        $issues   = $result['issues'];

        $minConfidence = $input->getOption('min-confidence');
        if (is_string($minConfidence) && $minConfidence !== '') {
            $rank = ['low' => 0, 'medium' => 1, 'high' => 2];
            $floor = $rank[$minConfidence] ?? 0;
            $issues = array_values(array_filter(
                $issues,
                static fn ($issue) => ($rank[(string) ($issue['confidence'] ?? 'high')] ?? 2) >= $floor
            ));
        }

        $bySeverity = ResultSchema::countBy($issues, 'severity');
        $errorCount = $bySeverity['error'] ?? 0;

        if ($machine) {
            $output->writeln(json_encode([
                'schema_version' => ResultSchema::VERSION,
                'tool'           => 'acqc',
                'analyzer'       => 'appbuilder-abqc',
                'platforms'      => $platforms,
                'issues_found'   => count($issues),
                'by_severity'    => $bySeverity,
                'by_category'    => ResultSchema::countBy($issues, 'category'),
                'issues'         => $issues,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return $errorCount > 0 ? Command::FAILURE : Command::SUCCESS;
        }

        $io->title('App Builder / Node.js Lint (abqc)');
        $io->writeln('Project: ' . $path . '  (' . implode(', ', $platforms) . ')');

        if ($issues === []) {
            $io->success('No abqc findings (or abqc is not built — run `npm install` at the ACQC root).');
            return Command::SUCCESS;
        }

        $io->section('Findings by severity');
        foreach ($bySeverity as $sev => $n) {
            $io->writeln(sprintf('  %-9s %d', $sev, $n));
        }

        $io->section('Findings by category');
        foreach (ResultSchema::countBy($issues, 'category') as $cat => $n) {
            $io->writeln(sprintf('  %-15s %d', $cat, $n));
        }

        return $errorCount > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}

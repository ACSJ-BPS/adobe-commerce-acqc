<?php

declare(strict_types=1);

namespace ACQC\Commands\Version;

use ACQC\Core\Version\BreakingChange;
use ACQC\Core\Version\VersionRegistry;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'version:diff', description: 'Show all breaking changes between two Adobe Commerce versions')]
class DiffCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('from', InputArgument::REQUIRED, 'Source version (e.g. 2.4.5)')
            ->addArgument('to', InputArgument::REQUIRED, 'Target version (e.g. 2.4.8)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $registry = $this->buildRegistry();
        $io = new SymfonyStyle($input, $output);
        $from = $input->getArgument('from');
        $to = $input->getArgument('to');

        $io->title("Breaking Changes: {$from} → {$to}");

        try {
            $changes = $registry->getBreakingChanges($from, $to);
        } catch (\Throwable $e) {
            $io->error('Could not retrieve breaking changes: ' . $e->getMessage());
            return Command::FAILURE;
        }

        if (empty($changes)) {
            $io->success('No breaking changes found in this range.');
            return Command::SUCCESS;
        }

        $grouped = [];
        foreach ($changes as $bc) {
            $grouped[$bc->type][] = $bc;
        }

        foreach ($grouped as $type => $items) {
            $io->section(ucwords(str_replace('_', ' ', $type)) . ' (' . count($items) . ')');
            $table = new Table($output);
            $table->setHeaders(['ID', 'Symbol / Class', 'Severity', 'Remediation Summary']);
            foreach ($items as $bc) {
                $symbol = $bc->symbol ?? ($bc->class ? "{$bc->class}::{$bc->method}" : 'N/A');
                $table->addRow([$bc->id, $symbol, $bc->severity, wordwrap($bc->remediationSummary, 50)]);
            }
            $table->render();
        }

        $countsByType = array_map('count', $grouped);
        arsort($countsByType);
        $io->newLine();
        $io->section('Summary by Type');
        foreach ($countsByType as $type => $count) {
            $output->writeln(sprintf('  %-35s %d', $type, $count));
        }
        $output->writeln(sprintf('  %-35s %d', 'TOTAL', count($changes)));

        return Command::SUCCESS;
    }

    private function buildRegistry(): VersionRegistry
    {
        $basePath = dirname(dirname(dirname(__DIR__)));
        $registryPath = $basePath . '/config/versions/adobe-commerce';
        return new VersionRegistry($registryPath, new ArrayAdapter());
    }
}

<?php

declare(strict_types=1);

namespace ACQC\Commands\Version;

use ACQC\Core\Version\UpgradePathPlanner;
use ACQC\Core\Version\VersionRegistry;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'version:path', description: 'Show recommended upgrade path with per-hop risk')]
class PathCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('from', InputArgument::REQUIRED, 'Source version (e.g. 2.4.3)')
            ->addArgument('to', InputArgument::REQUIRED, 'Target version (e.g. 2.4.8)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $registry = $this->buildRegistry();
        $io = new SymfonyStyle($input, $output);
        $from = $input->getArgument('from');
        $to = $input->getArgument('to');

        $io->title("Upgrade Path: {$from} → {$to}");

        $planner = new UpgradePathPlanner($registry);

        try {
            $path = $planner->plan($from, $to);
        } catch (\Throwable $e) {
            $io->error('Could not plan upgrade path: ' . $e->getMessage());
            return Command::FAILURE;
        }

        // Build ASCII path diagram
        $segments = [];
        $prev = $from;
        foreach ($path->hops as $hop) {
            $hopChanges = count($registry->getBreakingChanges($prev, $hop));
            $segments[] = sprintf('──(%d changes)──▶ %s', $hopChanges, $hop);
            $prev = $hop;
        }

        $output->writeln('');
        $output->writeln('  ' . $from . ' ' . implode(' ', $segments));
        $output->writeln('');

        $io->section('Rationale');
        $io->text(explode("\n", $path->rationale));

        $io->section('Summary');
        $io->definitionList(
            ['Direct Path' => $path->isDirect ? 'Yes' : 'No'],
            ['Total Breaking Changes' => $path->totalBreakingChanges],
            ['Estimated Duration' => $path->estimatedDuration],
            ['Intermediate Stops' => implode(' → ', array_slice($path->hops, 0, -1)) ?: 'None'],
        );

        return Command::SUCCESS;
    }

    private function buildRegistry(): VersionRegistry
    {
        $basePath = dirname(dirname(dirname(__DIR__)));
        $registryPath = $basePath . '/config/versions/adobe-commerce';
        return new VersionRegistry($registryPath, new ArrayAdapter());
    }
}

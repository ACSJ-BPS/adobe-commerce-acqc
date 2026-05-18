<?php

declare(strict_types=1);

namespace ACQC\Commands\Rules;

use ACQC\Core\Rules\SuppressionStore;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class UnsuppressCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('rules:unsuppress')
            ->setDescription('Remove a suppression for a rule')
            ->addArgument('rule-id', InputArgument::REQUIRED, 'Rule ID to un-suppress (e.g. AC_DI_001)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ruleId     = (string) $input->getArgument('rule-id');
        $configPath = getcwd() . '/.acqc/project.yaml';

        if (!file_exists($configPath)) {
            $output->writeln('<error>.acqc/project.yaml not found. Run: php bin/acqc init</error>');
            return Command::FAILURE;
        }

        try {
            $store   = new SuppressionStore($configPath);
            $removed = $store->removeSuppression($ruleId);
        } catch (\Throwable $e) {
            $output->writeln("<error>Failed to update config: {$e->getMessage()}</error>");
            return Command::FAILURE;
        }

        if (!$removed) {
            $output->writeln("<comment>No suppression found for rule {$ruleId}.</comment>");
            return Command::SUCCESS;
        }

        $output->writeln("<info>✓ Suppression removed for rule {$ruleId}</info>");
        return Command::SUCCESS;
    }
}

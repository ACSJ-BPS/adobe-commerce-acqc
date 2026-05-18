<?php

declare(strict_types=1);

namespace ACQC\Commands\Rules;

use ACQC\Core\Rules\SuppressionStore;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SuppressCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('rules:suppress')
            ->setDescription('Suppress a rule for this project')
            ->addArgument('rule-id',    InputArgument::REQUIRED, 'Rule ID to suppress (e.g. AC_DI_001)')
            ->addOption('reason',       null, InputOption::VALUE_REQUIRED, 'Human-readable reason (REQUIRED)')
            ->addOption('review-by',    null, InputOption::VALUE_OPTIONAL, 'Date to review suppression (YYYY-MM-DD)')
            ->addOption('global',       null, InputOption::VALUE_NONE,     'Suppress globally (not yet supported — reserved)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ruleId   = (string) $input->getArgument('rule-id');
        $reason   = $input->getOption('reason');
        $reviewBy = $input->getOption('review-by');

        if (empty($reason)) {
            $output->writeln('<error>The --reason option is required. Describe why this rule is being suppressed.</error>');
            $output->writeln('Example: --reason="Legacy code, scheduled for removal in Q3 2025"');
            return Command::FAILURE;
        }

        if ($reviewBy !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $reviewBy)) {
            $output->writeln("<error>--review-by must be in YYYY-MM-DD format, got: {$reviewBy}</error>");
            return Command::FAILURE;
        }

        $configPath = getcwd() . '/.acqc/project.yaml';

        if (!file_exists($configPath)) {
            $output->writeln('<error>.acqc/project.yaml not found. Run: php bin/acqc init</error>');
            return Command::FAILURE;
        }

        try {
            $store = new SuppressionStore($configPath);
            $store->addSuppression($ruleId, (string) $reason, $reviewBy ? (string) $reviewBy : null);
        } catch (\Throwable $e) {
            $output->writeln("<error>Failed to save suppression: {$e->getMessage()}</error>");
            return Command::FAILURE;
        }

        $output->writeln("<info>✓ Suppressed rule {$ruleId}</info>");
        $output->writeln("  Reason    : {$reason}");
        if ($reviewBy !== null) {
            $output->writeln("  Review by : {$reviewBy}");
        }
        $output->writeln('');
        $output->writeln("To un-suppress: php bin/acqc rules:unsuppress {$ruleId}");

        return Command::SUCCESS;
    }
}

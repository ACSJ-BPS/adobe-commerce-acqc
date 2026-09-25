<?php

declare(strict_types=1);

namespace ACQC\Commands\Hooks;

use ACQC\Core\Git\HookInstaller;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'hooks:install',
    description: 'Install the ACQC git pre-commit hook (blocks commits with critical/error issues in staged files)'
)]
class InstallCommand extends Command
{
    public function __construct(private readonly HookInstaller $installer = new HookInstaller())
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('path', InputArgument::OPTIONAL, 'Path inside the target git repository', getcwd())
            ->addOption('profile', 'p', InputOption::VALUE_REQUIRED, 'Profile the hook runs with', 'pre-commit')
            ->addOption('acqc-bin', null, InputOption::VALUE_REQUIRED, 'Absolute path to bin/acqc recorded in the hook (default: this ACQC install)')
            ->addOption('php', null, InputOption::VALUE_REQUIRED, 'PHP binary recorded in the hook (default: the PHP running this command)')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite an existing non-ACQC hook when pre-commit.local is already taken')
            ->addOption('strict', null, InputOption::VALUE_NONE, 'Fail closed: block the commit when ACQC itself cannot run (missing binary, node, tool error). Default: warn and allow.')
            ->setHelp(<<<'HELP'
Installs a git <info>pre-commit</info> hook in the target repository. On every
<info>git commit</info> the hook runs:

  acqc audit <repo> --staged --profile=pre-commit --format=compact

and blocks the commit when the staged content has blocker/critical/error issues.

An existing pre-commit hook is preserved as <info>pre-commit.local</info> and still runs first.
Re-running install updates the hook in place.

Per-commit overrides: <info>ACQC_SKIP=1 git commit ...</info>, <info>ACQC_FAIL_ON=critical git commit ...</info>
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $acqcBin = (string) ($input->getOption('acqc-bin') ?? (realpath(HookInstaller::defaultAcqcBin()) ?: HookInstaller::defaultAcqcBin()));
        $php     = (string) ($input->getOption('php') ?? PHP_BINARY);

        try {
            $result = $this->installer->install(
                (string) $input->getArgument('path'),
                $acqcBin,
                $php,
                (string) $input->getOption('profile'),
                (bool) $input->getOption('force'),
                (bool) $input->getOption('strict')
            );
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $io->success(($result['updated'] ? 'Updated' : 'Installed') . ' ACQC pre-commit hook: ' . $result['hook']);
        $io->listing(array_filter([
            'ACQC binary: ' . $acqcBin,
            'PHP binary:  ' . $php,
            'Profile:     ' . $input->getOption('profile'),
            'Mode:        ' . ($input->getOption('strict') ? 'strict (blocks if ACQC cannot run)' : 'fail-open (warns if ACQC cannot run; use --strict to block)'),
            $result['chained'] ? 'Existing hook preserved and chained: ' . $result['chained'] : null,
        ]));
        $io->writeln('Skip once: <info>ACQC_SKIP=1 git commit ...</info>   Remove: <info>acqc hooks:uninstall</info>');

        return Command::SUCCESS;
    }
}

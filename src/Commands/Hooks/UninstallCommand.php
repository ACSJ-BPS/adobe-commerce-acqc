<?php

declare(strict_types=1);

namespace ACQC\Commands\Hooks;

use ACQC\Core\Git\HookInstaller;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'hooks:uninstall',
    description: 'Remove the ACQC git pre-commit hook (restores a preserved pre-commit.local hook)'
)]
class UninstallCommand extends Command
{
    public function __construct(private readonly HookInstaller $installer = new HookInstaller())
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('path', InputArgument::OPTIONAL, 'Path inside the target git repository', getcwd());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $result = $this->installer->uninstall((string) $input->getArgument('path'));
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        if (!$result['removed']) {
            $io->note('No ACQC pre-commit hook installed at ' . $result['hook'] . ' — nothing to do.');
            return Command::SUCCESS;
        }

        $io->success('Removed ACQC pre-commit hook.' . ($result['restored'] ? ' Restored the previous hook.' : ''));
        return Command::SUCCESS;
    }
}

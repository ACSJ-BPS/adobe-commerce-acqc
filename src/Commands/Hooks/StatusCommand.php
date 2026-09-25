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
    name: 'hooks:status',
    description: 'Show whether the ACQC git pre-commit hook is installed and healthy'
)]
class StatusCommand extends Command
{
    public function __construct(private readonly HookInstaller $installer = new HookInstaller())
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('path', InputArgument::OPTIONAL, 'Path inside the target git repository', getcwd());
    }

    /**
     * Exit 0 = installed and healthy; 1 = not installed or unhealthy.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $s = $this->installer->status((string) $input->getArgument('path'));
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        if (!$s['installed']) {
            $io->warning($s['foreign_hook']
                ? 'A pre-commit hook exists but it is not the ACQC hook: ' . $s['hook'] . ' (run acqc hooks:install to chain it).'
                : 'ACQC pre-commit hook is not installed (run acqc hooks:install).');
            return Command::FAILURE;
        }

        $problems = [];
        if (!$s['executable']) {
            $problems[] = 'hook file is not executable';
        }
        if (!$s['acqc_bin_exists']) {
            $problems[] = 'recorded acqc binary not found: ' . $s['acqc_bin'];
        }
        if ($s['version'] !== HookInstaller::HOOK_VERSION) {
            $problems[] = sprintf('hook version %s differs from current %d (re-run hooks:install)', $s['version'] ?? '?', HookInstaller::HOOK_VERSION);
        }

        $io->table(['Property', 'Value'], [
            ['Hook',        $s['hook']],
            ['Version',     (string) ($s['version'] ?? '?')],
            ['Profile',     (string) ($s['profile'] ?? '?')],
            ['ACQC binary', (string) $s['acqc_bin'] . ($s['acqc_bin_exists'] ? '' : '  (MISSING)')],
            ['PHP',         (string) $s['php']],
            ['Chained hook', $s['chained'] ?? '-'],
        ]);

        if ($problems !== []) {
            $io->error("ACQC hook installed but unhealthy:\n - " . implode("\n - ", $problems));
            return Command::FAILURE;
        }

        $io->success('ACQC pre-commit hook is installed and healthy.');
        return Command::SUCCESS;
    }
}

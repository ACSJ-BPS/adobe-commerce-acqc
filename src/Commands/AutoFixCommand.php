<?php

declare(strict_types=1);

namespace ACQC\Commands;

use ACQC\Core\AutoFix\AutoFixEngine;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Helper\Table;

/**
 * Auto-Fix Command
 * 
 * Automatically fixes common upgrade issues
 */
#[AsCommand(name: 'auto-fix')]
class AutoFixCommand extends Command
{
    protected static $defaultName = 'auto-fix';

    protected function configure(): void
    {
        $this
            ->setName('auto-fix')
            ->setDescription('Automatically fix common upgrade issues')
            ->addArgument(
                'path',
                InputArgument::OPTIONAL,
                'Path to file or directory to fix (required unless --list-fixers)'
            )
            ->addOption(
                'fixer',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Specific fixer(s) to apply (default: all)',
                []
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Preview changes without applying them'
            )
            ->addOption(
                'no-backup',
                null,
                InputOption::VALUE_NONE,
                'Do not create backup before applying fixes'
            )
            ->addOption(
                'list-fixers',
                'l',
                InputOption::VALUE_NONE,
                'List all available fixers'
            )
            ->setHelp(<<<'HELP'
The <info>auto-fix</info> command automatically fixes common upgrade issues:

<comment>List Available Fixers:</comment>
  <info>php bin/acqc auto-fix --list-fixers</info>

<comment>Preview Fixes (Dry Run):</comment>
  <info>php bin/acqc auto-fix /path/to/module --dry-run</info>

<comment>Apply All Fixes:</comment>
  <info>php bin/acqc auto-fix /path/to/module</info>

<comment>Apply Specific Fixer:</comment>
  <info>php bin/acqc auto-fix /path/to/module --fixer zend-to-laminas</info>

<comment>Apply Without Backup:</comment>
  <info>php bin/acqc auto-fix /path/to/module --no-backup</info>

Available fixers:
  - zend-to-laminas: Converts Zend Framework to Laminas
  (More fixers coming soon...)

HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $engine = new AutoFixEngine();

        // List fixers and exit
        if ($input->getOption('list-fixers')) {
            return $this->listFixers($io, $engine);
        }

        $path = $input->getArgument('path');
        $dryRun = $input->getOption('dry-run');
        $noBackup = $input->getOption('no-backup');
        $selectedFixers = $input->getOption('fixer');

        // Validate path
        if (!$path) {
            $io->error('Path argument is required');
            return Command::FAILURE;
        }
        
        if (!file_exists($path)) {
            $io->error(sprintf('Path does not exist: %s', $path));
            return Command::FAILURE;
        }

        // Show configuration
        $io->title('ACQC Auto-Fix');
        $io->definitionList(
            ['Path' => realpath($path)],
            ['Mode' => $dryRun ? 'Dry Run (Preview Only)' : 'Apply Fixes'],
            ['Backup' => $noBackup ? 'Disabled' : 'Enabled'],
            ['Fixers' => empty($selectedFixers) ? 'All' : implode(', ', $selectedFixers)]
        );

        // Confirm if not dry-run
        if (!$dryRun) {
            $io->warning('This will modify your code!');
            if (!$io->confirm('Do you want to continue?', false)) {
                $io->text('Operation cancelled');
                return Command::SUCCESS;
            }
        }

        // Apply fixes
        $io->newLine();
        $io->text('Analyzing and fixing code...');

        $results = $engine->applyFixes($path, [
            'dry-run' => $dryRun,
            'backup' => !$noBackup,
            'fixers' => empty($selectedFixers) ? array_keys($engine->getFixers()) : $selectedFixers
        ]);

        // Display results
        $this->displayResults($io, $results, $dryRun);

        return Command::SUCCESS;
    }

    /**
     * List all available fixers
     */
    private function listFixers(SymfonyStyle $io, AutoFixEngine $engine): int
    {
        $io->title('Available Fixers');

        $table = new Table($io);
        $table->setHeaders(['Name', 'Description']);

        foreach ($engine->getFixers() as $fixer) {
            $table->addRow([
                $fixer->getName(),
                $fixer->getDescription()
            ]);
        }

        $table->render();

        return Command::SUCCESS;
    }

    /**
     * Display fix results
     */
    private function displayResults(SymfonyStyle $io, array $results, bool $dryRun): void
    {
        $io->newLine();
        $io->section('Results');

        // Summary
        $io->definitionList(
            ['Files Scanned' => count($results['fixes_applied'])],
            ['Files Modified' => $results['files_modified']],
            ['Total Changes' => $results['total_changes']]
        );

        // Backup info
        if ($results['backup_created']) {
            $io->success('Backup created: ' . $results['backup_file']);
        }

        // Errors
        if (!empty($results['errors'])) {
            $io->error('Errors occurred:');
            $io->listing($results['errors']);
        }

        // Detailed changes
        if (!empty($results['fixes_applied'])) {
            $io->newLine();
            $io->writeln('<comment>Detailed Changes:</comment>');

            foreach ($results['fixes_applied'] as $fileResult) {
                if ($fileResult['changes_count'] === 0) {
                    continue;
                }

                $io->newLine();
                $io->writeln(sprintf('<info>%s</info> (%d change(s))', 
                    $fileResult['file'], 
                    $fileResult['changes_count']
                ));

                foreach ($fileResult['changes'] as $change) {
                    $io->writeln(sprintf('  • %s', $change['description']));
                }
            }
        } else {
            $io->info('No fixable issues found');
        }

        // Summary message
        $io->newLine();
        if ($dryRun) {
            $io->note('This was a dry run. No changes were applied. Run without --dry-run to apply these fixes.');
        } else {
            if ($results['total_changes'] > 0) {
                $io->success(sprintf('Successfully applied %d fix(es) to %d file(s)', 
                    $results['total_changes'], 
                    $results['files_modified']
                ));
            } else {
                $io->success('No fixes needed - code is up to date!');
            }
        }
    }
}


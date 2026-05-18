<?php

declare(strict_types=1);

namespace ACQC\Commands;

use ACQC\Utils\BackupManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Helper\Table;

/**
 * Backup Management Command
 * 
 * Create, restore, list, and manage code backups
 */
#[AsCommand(name: 'backup')]
class BackupCommand extends Command
{
    protected static $defaultName = 'backup';

    protected function configure(): void
    {
        $this
            ->setName('backup')
            ->setDescription('Manage code backups (create, restore, list, delete)')
            ->addArgument(
                'action',
                InputArgument::REQUIRED,
                'Action to perform (create, restore, list, delete, cleanup)'
            )
            ->addArgument(
                'path',
                InputArgument::OPTIONAL,
                'Path to backup/restore (required for create/restore actions)'
            )
            ->addOption(
                'backup-dir',
                'b',
                InputOption::VALUE_REQUIRED,
                'Directory to store backups',
                './backups'
            )
            ->addOption(
                'name',
                null,
                InputOption::VALUE_REQUIRED,
                'Custom backup name',
                null
            )
            ->addOption(
                'older-than',
                null,
                InputOption::VALUE_REQUIRED,
                'For cleanup: delete backups older than N days',
                '30'
            )
            ->setHelp(<<<'HELP'
The <info>backup</info> command manages code backups:

<comment>Create Backup:</comment>
  <info>php bin/acqc backup create /path/to/module</info>
  <info>php bin/acqc backup create /path/to/module --name my-backup</info>

<comment>List Backups:</comment>
  <info>php bin/acqc backup list</info>

<comment>Restore Backup:</comment>
  <info>php bin/acqc backup restore backup-name.zip</info>

<comment>Delete Backup:</comment>
  <info>php bin/acqc backup delete backup-name.zip</info>

<comment>Cleanup Old Backups:</comment>
  <info>php bin/acqc backup cleanup</info>
  <info>php bin/acqc backup cleanup --older-than 60</info>

HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $action = $input->getArgument('action');
        $backupManager = new BackupManager($input->getOption('backup-dir'));

        switch ($action) {
            case 'create':
                return $this->createBackup($io, $input, $backupManager);
            
            case 'list':
                return $this->listBackups($io, $backupManager);
            
            case 'restore':
                return $this->restoreBackup($io, $input, $backupManager);
            
            case 'delete':
                return $this->deleteBackup($io, $input, $backupManager);
            
            case 'cleanup':
                return $this->cleanupBackups($io, $input, $backupManager);
            
            default:
                $io->error(sprintf('Unknown action: %s', $action));
                $io->writeln('Valid actions: create, list, restore, delete, cleanup');
                return Command::FAILURE;
        }
    }

    /**
     * Create a new backup
     */
    private function createBackup(SymfonyStyle $io, InputInterface $input, BackupManager $backupManager): int
    {
        $path = $input->getArgument('path');
        
        if (!$path) {
            $io->error('Path argument is required for create action');
            return Command::FAILURE;
        }
        
        $io->title('Creating Backup');
        $io->text(sprintf('Source: %s', realpath($path) ?: $path));
        $io->text(sprintf('Backup Dir: %s', $backupManager->getBackupDir()));
        
        $io->newLine();
        $io->text('Creating backup...');
        
        $result = $backupManager->createBackup($path, $input->getOption('name'));
        
        if ($result['success']) {
            $io->success($result['message']);
            $io->definitionList(
                ['Backup File' => $result['backup_file']]
            );
            return Command::SUCCESS;
        } else {
            $io->error($result['error']);
            return Command::FAILURE;
        }
    }

    /**
     * List all backups
     */
    private function listBackups(SymfonyStyle $io, BackupManager $backupManager): int
    {
        $io->title('Available Backups');
        $io->text(sprintf('Backup Directory: %s', $backupManager->getBackupDir()));
        
        $backups = $backupManager->listBackups();
        
        if (empty($backups)) {
            $io->info('No backups found');
            return Command::SUCCESS;
        }
        
        $table = new Table($output = $io);
        $table->setHeaders(['Name', 'Size', 'Created', 'Age']);
        
        foreach ($backups as $backup) {
            $table->addRow([
                $backup['name'],
                $backup['size_formatted'],
                $backup['created_at'],
                $backup['age']
            ]);
        }
        
        $table->render();
        
        $io->newLine();
        $io->text(sprintf('Total backups: %d', count($backups)));
        
        return Command::SUCCESS;
    }

    /**
     * Restore a backup
     */
    private function restoreBackup(SymfonyStyle $io, InputInterface $input, BackupManager $backupManager): int
    {
        $backupFile = $input->getArgument('path');
        
        if (!$backupFile) {
            $io->error('Backup file argument is required for restore action');
            return Command::FAILURE;
        }
        
        $io->title('Restoring Backup');
        $io->text(sprintf('Backup: %s', $backupFile));
        
        // Confirm before restoring
        if (!$io->confirm('This will overwrite existing files. Continue?', false)) {
            $io->warning('Restore cancelled');
            return Command::SUCCESS;
        }
        
        $io->text('Restoring backup...');
        
        $result = $backupManager->restoreBackup($backupFile);
        
        if ($result['success']) {
            $io->success($result['message']);
            return Command::SUCCESS;
        } else {
            $io->error($result['error']);
            return Command::FAILURE;
        }
    }

    /**
     * Delete a backup
     */
    private function deleteBackup(SymfonyStyle $io, InputInterface $input, BackupManager $backupManager): int
    {
        $backupFile = $input->getArgument('path');
        
        if (!$backupFile) {
            $io->error('Backup file argument is required for delete action');
            return Command::FAILURE;
        }
        
        $io->title('Deleting Backup');
        $io->text(sprintf('Backup: %s', $backupFile));
        
        // Confirm before deleting
        if (!$io->confirm('Are you sure you want to delete this backup?', false)) {
            $io->warning('Delete cancelled');
            return Command::SUCCESS;
        }
        
        $result = $backupManager->deleteBackup($backupFile);
        
        if ($result['success']) {
            $io->success($result['message']);
            return Command::SUCCESS;
        } else {
            $io->error($result['error']);
            return Command::FAILURE;
        }
    }

    /**
     * Cleanup old backups
     */
    private function cleanupBackups(SymfonyStyle $io, InputInterface $input, BackupManager $backupManager): int
    {
        $olderThan = (int) $input->getOption('older-than');
        
        $io->title('Cleanup Old Backups');
        $io->text(sprintf('Deleting backups older than %d days...', $olderThan));
        
        $result = $backupManager->cleanupOldBackups($olderThan);
        
        if ($result['deleted_count'] > 0) {
            $io->success($result['message']);
        } else {
            $io->info('No old backups to delete');
        }
        
        return Command::SUCCESS;
    }
}


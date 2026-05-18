<?php

declare(strict_types=1);

namespace ACQC\Commands\Version;

use ACQC\Core\Version\VersionFeedSyncer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Downloads a clean vanilla Adobe Commerce instance for deep file-level analysis.
 *
 * Mirrors the pattern used by UCT's core:code:changes command:
 *   composer create-project --repository-url=https://repo.magento.com
 *     magento/project-community-edition=<version> <vanillaDir>
 *
 * Cached in var/vanilla/<version>/ — re-use is automatic on subsequent calls.
 */
#[AsCommand(name: 'version:download', description: 'Download a vanilla Adobe Commerce instance for analysis')]
class DownloadCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('version', InputArgument::REQUIRED, 'Adobe Commerce version to download (e.g. 2.4.8)')
            ->addOption(
                'edition',
                null,
                InputOption::VALUE_REQUIRED,
                'Commerce edition: ce (Community) or ee (Enterprise)',
                'ce'
            )
            ->addOption(
                'repository-url',
                null,
                InputOption::VALUE_REQUIRED,
                'Composer repository URL',
                VersionFeedSyncer::REPO_MAGENTO_BASE
            )
            ->addOption('force', null, InputOption::VALUE_NONE, 'Re-download even if already cached')
            ->setHelp(<<<'HELP'
The <info>version:download</info> command downloads a clean ("vanilla") Adobe Commerce installation
into <comment>var/vanilla/&lt;version&gt;/</comment> for use by deep analysis commands.

This mirrors how UCT downloads a reference instance for <info>core:code:changes</info>.

<comment>Download Community Edition:</comment>
  <info>vendor/bin/acqc version:download 2.4.8</info>
  <info>vendor/bin/acqc version:download 2.4.8 --edition=ce</info>

<comment>Download Enterprise Edition (requires EE auth):</comment>
  <info>vendor/bin/acqc version:download 2.4.8 --edition=ee</info>

<comment>Re-download if already cached:</comment>
  <info>vendor/bin/acqc version:download 2.4.8 --force</info>

<comment>Custom repository (mirrors UCT --repository-url):</comment>
  <info>vendor/bin/acqc version:download 2.4.8 --repository-url=https://mirror.example.com</info>

Auth keys are read from ACQC_MAGENTO_PUBLIC_KEY / ACQC_MAGENTO_PRIVATE_KEY env vars
or ~/.composer/auth.json (http-basic for the repository host).
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io            = new SymfonyStyle($input, $output);
        $version       = (string) $input->getArgument('version');
        $edition       = strtolower((string) $input->getOption('edition'));
        $repositoryUrl = rtrim((string) $input->getOption('repository-url'), '/');
        $force         = (bool) $input->getOption('force');

        $basePath   = dirname(dirname(dirname(__DIR__)));
        $vanillaDir = $basePath . '/var/vanilla/' . $version;

        $io->title(sprintf('ACQC Version Download — Adobe Commerce %s', $version));

        // Map edition shorthand to Composer package name (same mapping as UCT)
        $composerPackage = match ($edition) {
            'ee'    => 'magento/project-enterprise-edition',
            default => 'magento/project-community-edition',
        };

        // Check cache
        if (is_dir($vanillaDir) && !$force) {
            $io->success(sprintf(
                'Using previously downloaded Adobe Commerce %s from %s',
                $version,
                $vanillaDir
            ));
            $io->comment(sprintf('Re-run with --force to discard the cache and download again.'));
            return Command::SUCCESS;
        }

        $downloadSizeMb = $edition === 'ee' ? '~900 MB' : '~650 MB';
        $io->note(sprintf(
            "About to download %s=%s from %s (%s).\nDestination: %s",
            $composerPackage,
            $version,
            $repositoryUrl,
            $downloadSizeMb,
            $vanillaDir
        ));

        // Confirmation prompt (matches UCT behaviour); skipped when -n / --no-interaction is passed
        if ($input->isInteractive()) {
            /** @var QuestionHelper $helper */
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion(
                sprintf('<question>Download Adobe Commerce %s now? [y/N]</question> ', $version),
                false
            );
            if (!$helper->ask($input, $output, $question)) {
                $io->comment('Download cancelled.');
                return Command::SUCCESS;
            }
        }

        // Remove stale cached copy if --force
        if (is_dir($vanillaDir) && $force) {
            $io->comment('Removing cached copy…');
            $this->removeDirectory($vanillaDir);
        }

        if (!is_dir(dirname($vanillaDir))) {
            mkdir(dirname($vanillaDir), 0755, true);
        }

        $composerBin = $this->resolveComposerBin();
        $cmd = sprintf(
            '%s create-project --repository-url=%s %s=%s %s --no-interaction --ignore-platform-reqs --no-scripts 2>&1',
            escapeshellcmd($composerBin),
            escapeshellarg($repositoryUrl),
            escapeshellarg($composerPackage),
            escapeshellarg($version),
            escapeshellarg($vanillaDir)
        );

        $io->comment(sprintf('Running: composer create-project --repository-url=%s %s=%s …', $repositoryUrl, $composerPackage, $version));

        passthru($cmd, $exitCode);

        if ($exitCode !== 0 || !is_dir($vanillaDir)) {
            $io->error(sprintf(
                'Download failed (exit code %d). Check auth keys and version availability.',
                $exitCode
            ));
            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Downloaded Adobe Commerce %s to %s',
            $version,
            $vanillaDir
        ));
        $io->comment('Use upgrade:analyze --deep or version:diff with this path for file-level analysis.');

        return Command::SUCCESS;
    }

    private function resolveComposerBin(): string
    {
        foreach (['composer', 'composer.phar', '/usr/local/bin/composer', '/usr/bin/composer'] as $c) {
            if (shell_exec('which ' . escapeshellarg($c) . ' 2>/dev/null')) {
                return $c;
            }
        }
        throw new \RuntimeException('composer binary not found. Install Composer: https://getcomposer.org');
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }
        rmdir($dir);
    }
}

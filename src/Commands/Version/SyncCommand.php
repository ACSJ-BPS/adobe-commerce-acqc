<?php

declare(strict_types=1);

namespace ACQC\Commands\Version;

use ACQC\Core\Version\VersionFeedSyncer;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'version:sync', description: 'Sync version registry from remote sources')]
class SyncCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be synced without writing')
            ->addOption(
                'source',
                null,
                InputOption::VALUE_REQUIRED,
                'Sync source: repo.magento.com (default, requires auth), packagist (public, no auth), all',
                'repo.magento.com'
            )
            ->addOption(
                'repository-url',
                null,
                InputOption::VALUE_REQUIRED,
                'Composer repository URL (overrides the default repo.magento.com endpoint)',
                VersionFeedSyncer::REPO_MAGENTO_BASE
            )
            ->setHelp(<<<'HELP'
The <info>version:sync</info> command fetches new Adobe Commerce version metadata and adds stub YAML
files to config/versions/adobe-commerce/ for any versions not already present.

On every run the bundled <comment>offline seed</comment> (155 known versions) is applied first, so
the registry is always populated even when the network is unavailable.

<comment>Primary source — repo.magento.com (requires Magento Marketplace auth keys):</comment>
  <info>vendor/bin/acqc version:sync</info>
  <info>vendor/bin/acqc version:sync --source=repo.magento.com</info>

  Auth keys are read from (in priority order):
    1. Environment variables:  ACQC_MAGENTO_PUBLIC_KEY  /  ACQC_MAGENTO_PRIVATE_KEY
    2. ~/.composer/auth.json   http-basic.repo.magento.com

  Generate keys at: https://commercemarketplace.adobe.com/customer/accessKeys/

<comment>Custom repository URL (mirrors UCT --repository-url pattern):</comment>
  <info>vendor/bin/acqc version:sync --repository-url=https://mirror.example.com</info>

  Credentials are looked up in ~/.composer/auth.json for the given host.

<comment>Public fallback — packagist.org (no auth required):</comment>
  <info>vendor/bin/acqc version:sync --source=packagist</info>

  Uses magento/community-edition on Packagist. Provides release dates but may
  lag behind Commerce patch releases.

<comment>Both sources:</comment>
  <info>vendor/bin/acqc version:sync --source=all</info>
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io            = new SymfonyStyle($input, $output);
        $isDryRun      = (bool) $input->getOption('dry-run');
        $source        = $input->getOption('source');
        $repositoryUrl = rtrim((string)$input->getOption('repository-url'), '/');

        $basePath     = dirname(dirname(dirname(__DIR__)));
        $registryPath = $basePath . '/config/versions/adobe-commerce';

        $io->title('ACQC Version Registry Sync');

        if ($isDryRun) {
            $io->warning('Dry-run mode — no files will be written.');
        }

        $syncer = new VersionFeedSyncer($registryPath, new NullLogger());

        if ($syncer->isRegistryStale()) {
            $io->comment('Registry is stale — running sync.');
        } else {
            $io->comment('Registry is fresh, but running sync anyway.');
        }

        // Show seed info in dry-run
        if ($isDryRun) {
            $seedFile = $basePath . '/config/versions/seed.php';
            if (file_exists($seedFile)) {
                $seed = include $seedFile;
                $io->comment(sprintf(
                    'Bundled seed contains %d known versions (applied automatically on sync).',
                    count($seed)
                ));
            }
        }

        $sources = match ($source) {
            'all'   => ['repo.magento.com', 'packagist'],
            default => [$source],
        };

        // Show auth status when using a Composer repository (not packagist shorthand)
        $repoHost = (string)parse_url($repositoryUrl, PHP_URL_HOST);
        if (in_array('repo.magento.com', $sources, true)) {
            $hasEnvKeys  = getenv('ACQC_MAGENTO_PUBLIC_KEY') && getenv('ACQC_MAGENTO_PRIVATE_KEY');
            $authFile    = (getenv('HOME') ?: '') . '/.composer/auth.json';
            $hasAuthJson = false;
            if (file_exists($authFile)) {
                $auth        = json_decode(file_get_contents($authFile), true) ?? [];
                $hasAuthJson = !empty($auth['http-basic'][$repoHost]['username']);
            }

            if ($hasEnvKeys) {
                $io->comment('Auth: using ACQC_MAGENTO_PUBLIC_KEY / ACQC_MAGENTO_PRIVATE_KEY env vars.');
            } elseif ($hasAuthJson) {
                $io->comment(sprintf('Auth: using credentials from ~/.composer/auth.json (%s).', $repoHost));
            } else {
                $io->warning(
                    "No auth keys found for {$repoHost}. Falling back to --source=packagist." . PHP_EOL .
                    'To use repo.magento.com, set ACQC_MAGENTO_PUBLIC_KEY and ACQC_MAGENTO_PRIVATE_KEY,' . PHP_EOL .
                    'or run: composer config http-basic.repo.magento.com <public_key> <private_key>'
                );
                $sources = ['packagist'];
            }
        }

        if ($isDryRun) {
            $io->note(sprintf(
                'Would sync from: %s (repository: %s)',
                implode(', ', $sources),
                $repositoryUrl
            ));
            return Command::SUCCESS;
        }

        $result = $syncer->sync($sources, $repositoryUrl);

        if ($result->isOffline) {
            $io->warning('Network unavailable — registry not updated. Offline seed was applied.');
            return Command::SUCCESS;
        }

        if (empty($result->newVersionsFound)) {
            $io->success('Registry is up to date — no new versions found.');
        } else {
            $io->success(sprintf(
                'Sync complete. %d new version(s) found: %s',
                count($result->newVersionsFound),
                implode(', ', $result->newVersionsFound)
            ));
        }

        return Command::SUCCESS;
    }
}

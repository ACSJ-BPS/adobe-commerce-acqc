<?php

declare(strict_types=1);

namespace ACQC\Commands\Version;

use ACQC\Core\Version\VersionRegistry;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'version:advisories', description: 'Show security advisories affecting an Adobe Commerce version')]
class AdvisoriesCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('version', InputArgument::REQUIRED, 'Adobe Commerce version (e.g. 2.4.7)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $registry = $this->buildRegistry();
        $io = new SymfonyStyle($input, $output);
        $version = $input->getArgument('version');

        $io->title("Security Advisories — Adobe Commerce {$version}");

        // Collect advisories from all known manifests that list this version as affected
        $allAdvisories = [];
        foreach ($registry->getKnownVersions() as $knownVersion) {
            try {
                $manifest = $registry->getManifest($knownVersion);
            } catch (\Throwable) {
                continue;
            }

            foreach ($manifest->securityAdvisories as $advisory) {
                if (in_array($version, $advisory->affectedVersions, true)) {
                    $allAdvisories[$advisory->id] = $advisory;
                }
            }
        }

        if (empty($allAdvisories)) {
            $io->success("No security advisories found affecting version {$version}.");
            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['Advisory ID', 'CVE', 'Severity', 'Description', 'Fixed In']);

        foreach ($allAdvisories as $advisory) {
            $severityTag = match ($advisory->severity) {
                'critical' => '<error>' . strtoupper($advisory->severity) . '</error>',
                'high' => '<comment>HIGH</comment>',
                default => $advisory->severity,
            };

            $table->addRow([
                $advisory->id,
                $advisory->cve ?? 'N/A',
                $severityTag,
                wordwrap($advisory->description, 45),
                $advisory->fixedIn ?? 'N/A',
            ]);
        }

        $table->render();
        $io->newLine();
        $io->text(sprintf('Total advisories affecting %s: %d', $version, count($allAdvisories)));

        return Command::SUCCESS;
    }

    private function buildRegistry(): VersionRegistry
    {
        $basePath = dirname(dirname(dirname(__DIR__)));
        $registryPath = $basePath . '/config/versions/adobe-commerce';
        return new VersionRegistry($registryPath, new ArrayAdapter());
    }
}

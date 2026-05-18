<?php

declare(strict_types=1);

namespace ACQC\Commands\Version;

use ACQC\Core\Version\VersionRegistry;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'version:list', description: 'List all known Adobe Commerce versions with EOL status')]
class ListCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('format', null, InputOption::VALUE_OPTIONAL, 'Output format (table|json)', 'table')
            ->addOption('include-eol', null, InputOption::VALUE_NONE, 'Include EOL versions in output');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $registry = $this->buildRegistry();
        $io = new SymfonyStyle($input, $output);
        $includeEol = (bool)$input->getOption('include-eol');
        $format = $input->getOption('format');

        $now = new \DateTimeImmutable();
        $sixMonths = new \DateTimeImmutable('+6 months');

        $rows = [];
        foreach ($registry->getKnownVersions() as $version) {
            try {
                $manifest = $registry->getManifest($version);
            } catch (\Throwable) {
                continue;
            }

            $isEol = $manifest->isEndOfLife($now);

            if ($isEol && !$includeEol) {
                continue;
            }

            $eolDateStr = $manifest->eolDate?->format('Y-m-d') ?? 'N/A';
            $releaseDateStr = $manifest->releaseDate?->format('Y-m-d') ?? 'N/A';
            $phpMin = $manifest->phpSupport['minimum'] ?? 'N/A';
            $phpMax = $manifest->phpSupport['maximum'] ?? 'N/A';

            $status = $this->computeStatus($manifest, $now, $sixMonths);

            if ($format === 'json') {
                $rows[] = [
                    'version' => $version,
                    'release_date' => $releaseDateStr,
                    'eol_date' => $eolDateStr,
                    'php_min' => $phpMin,
                    'php_max' => $phpMax,
                    'status' => strip_tags($status),
                ];
            } else {
                $rows[] = [$version, $releaseDateStr, $eolDateStr, $phpMin, $phpMax, $status];
            }
        }

        if ($format === 'json') {
            $output->writeln(json_encode($rows, JSON_PRETTY_PRINT));
            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['Version', 'Release Date', 'EOL Date', 'PHP Min', 'PHP Max', 'Status']);
        $table->addRows($rows);
        $table->render();

        return Command::SUCCESS;
    }

    private function computeStatus(
        \ACQC\Core\Version\VersionManifest $manifest,
        \DateTimeImmutable $now,
        \DateTimeImmutable $sixMonths
    ): string {
        if ($manifest->isEndOfLife($now)) {
            return '<error>EOL</error>';
        }

        if ($manifest->eolDate !== null && $manifest->eolDate <= $sixMonths) {
            return '<comment>EOL Soon</comment>';
        }

        if ($manifest->releaseDate !== null && $manifest->releaseDate > $now) {
            return '<info>Upcoming</info>';
        }

        return '<info>Active</info>';
    }

    private function buildRegistry(): VersionRegistry
    {
        $basePath = dirname(dirname(dirname(__DIR__)));
        $registryPath = $basePath . '/config/versions/adobe-commerce';
        $cache = new ArrayAdapter();

        return new VersionRegistry($registryPath, $cache);
    }
}

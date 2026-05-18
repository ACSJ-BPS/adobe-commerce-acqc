<?php

declare(strict_types=1);

namespace ACQC\Commands\Version;

use ACQC\Core\Version\VersionRegistry;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'version:eol-report', description: 'Show EOL timeline for all Adobe Commerce versions')]
class EolReportCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $registry = $this->buildRegistry();
        $io = new SymfonyStyle($input, $output);
        $now = new \DateTimeImmutable();
        $sixMonths = new \DateTimeImmutable('+6 months');

        $io->title('Adobe Commerce — EOL Report');

        $table = new Table($output);
        $table->setHeaders(['Version', 'Release Date', 'EOL Date', 'Days Until EOL', 'Status']);

        $rows = [];
        foreach ($registry->getKnownVersions() as $version) {
            try {
                $manifest = $registry->getManifest($version);
            } catch (\Throwable) {
                continue;
            }

            if ($manifest->eolDate === null) {
                continue;
            }

            $daysUntil = (int)$now->diff($manifest->eolDate)->days;
            if ($manifest->isEndOfLife($now)) {
                $daysUntil = -$daysUntil;
            }

            $rows[] = [
                'version' => $version,
                'release' => $manifest->releaseDate?->format('Y-m-d') ?? 'N/A',
                'eol' => $manifest->eolDate->format('Y-m-d'),
                'days' => $daysUntil,
                'status' => $manifest->isEndOfLife($now) ? 'EOL' : ($manifest->eolDate <= $sixMonths ? 'EOL Soon' : 'Active'),
            ];
        }

        // Sort by EOL date ascending
        usort($rows, static fn(array $a, array $b) => strcmp($a['eol'], $b['eol']));

        foreach ($rows as $row) {
            $statusTag = match ($row['status']) {
                'EOL' => '<error>' . $row['status'] . '</error>',
                'EOL Soon' => '<comment>' . $row['status'] . '</comment>',
                default => '<info>' . $row['status'] . '</info>',
            };

            $daysLabel = $row['days'] < 0
                ? abs($row['days']) . ' days ago'
                : $row['days'] . ' days';

            $table->addRow([$row['version'], $row['release'], $row['eol'], $daysLabel, $statusTag]);
        }

        $table->render();

        return Command::SUCCESS;
    }

    private function buildRegistry(): VersionRegistry
    {
        $basePath = dirname(dirname(dirname(__DIR__)));
        $registryPath = $basePath . '/config/versions/adobe-commerce';
        return new VersionRegistry($registryPath, new ArrayAdapter());
    }
}

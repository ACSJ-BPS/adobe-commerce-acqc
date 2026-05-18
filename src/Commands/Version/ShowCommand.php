<?php

declare(strict_types=1);

namespace ACQC\Commands\Version;

use ACQC\Core\Version\VersionRegistry;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'version:show', description: 'Show full manifest for a specific Adobe Commerce version')]
class ShowCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('version', InputArgument::REQUIRED, 'Adobe Commerce version (e.g. 2.4.8)')
            ->addOption('detail', null, InputOption::VALUE_NONE, 'Show all breaking changes and deprecated symbols in detail');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $registry = $this->buildRegistry();
        $io = new SymfonyStyle($input, $output);
        $version = $input->getArgument('version');

        try {
            $manifest = $registry->getManifest($version);
        } catch (\Throwable $e) {
            $io->error("Version '{$version}' not found: " . $e->getMessage());
            return Command::FAILURE;
        }

        $io->title("Adobe Commerce {$version} — Manifest");

        $io->section('Release Info');
        $io->definitionList(
            ['Version' => $manifest->version],
            ['Type' => $manifest->releaseType],
            ['Release Date' => $manifest->releaseDate?->format('Y-m-d') ?? 'Unknown'],
            ['EOL Date' => $manifest->eolDate?->format('Y-m-d') ?? 'Unknown'],
            ['EOL Status' => $manifest->isEndOfLife() ? '<error>EOL</error>' : '<info>Active</info>'],
        );

        $io->section('PHP Support');
        $php = $manifest->phpSupport;
        $io->definitionList(
            ['Minimum' => $php['minimum'] ?? 'N/A'],
            ['Maximum' => $php['maximum'] ?? 'N/A'],
            ['Recommended' => $php['recommended'] ?? 'N/A'],
            ['Dropped' => implode(', ', $php['dropped'] ?? []) ?: 'None'],
        );

        $io->section('Search Engine Support');
        $se = $manifest->searchEngineSupport;
        $io->definitionList(
            ['OpenSearch' => implode(', ', $se['opensearch'] ?? []) ?: 'Not supported'],
            ['Elasticsearch' => implode(', ', $se['elasticsearch'] ?? []) ?: 'Dropped / Not supported'],
        );

        $io->section('Summary Counts');
        $io->definitionList(
            ['Breaking Changes' => count($manifest->breakingChanges)],
            ['Deprecated Symbols' => array_sum(array_map(
                'count',
                array_intersect_key($manifest->deprecatedSymbols, array_flip(['classes', 'methods', 'events']))
            ))],
            ['DB Schema Changes' => count($manifest->dbSchemaChanges)],
            ['Security Advisories' => count($manifest->securityAdvisories)],
        );

        if ($input->getOption('detail')) {
            if (!empty($manifest->breakingChanges)) {
                $io->section('Breaking Changes');
                foreach ($manifest->breakingChanges as $bc) {
                    $io->text(sprintf(
                        '<comment>[%s]</comment> <error>%s</error> — %s',
                        $bc->severity,
                        $bc->id,
                        $bc->remediationSummary
                    ));
                }
            }

            $deprecated = $manifest->deprecatedSymbols;
            if (!empty($deprecated)) {
                $io->section('Deprecated Symbols');
                foreach (['classes', 'methods', 'events'] as $kind) {
                    foreach ($deprecated[$kind] ?? [] as $sym) {
                        $label = $sym['symbol'] ?? ($sym['event'] ?? (($sym['class'] ?? '') . '::' . ($sym['method'] ?? '')));
                        $io->text(sprintf('<comment>[%s]</comment> %s → %s', $kind, $label, $sym['replacement'] ?? 'N/A'));
                    }
                }
            }
        }

        return Command::SUCCESS;
    }

    private function buildRegistry(): VersionRegistry
    {
        $basePath = dirname(dirname(dirname(__DIR__)));
        $registryPath = $basePath . '/config/versions/adobe-commerce';
        return new VersionRegistry($registryPath, new ArrayAdapter());
    }
}

<?php

declare(strict_types=1);

namespace ACQC\Commands\Rules;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;

class SyncCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('rules:sync')
            ->setDescription('Compare project rule files against the bundled ACQC rule set')
            ->addOption('dry-run', null, InputOption::VALUE_NONE,     'Show changes but do not apply')
            ->addOption('bundle',  null, InputOption::VALUE_OPTIONAL, 'Specific bundle to sync (e.g. adobe-commerce/latest)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun  = (bool) $input->getOption('dry-run');
        $bundle  = $input->getOption('bundle');

        $acqcRoot    = dirname(__DIR__, 3);
        $projectRoot = getcwd();
        $configPath  = $projectRoot . '/.acqc/project.yaml';

        // Determine which bundles to check
        $bundles = [];
        if ($bundle !== null) {
            $bundles = [$bundle];
        } elseif (file_exists($configPath)) {
            $config  = Yaml::parseFile($configPath);
            $bundles = $config['rule_bundles'] ?? [];
        }

        if (empty($bundles)) {
            $output->writeln('<comment>No rule bundles configured. Run: php bin/acqc init</comment>');
            return Command::SUCCESS;
        }

        $added      = [];
        $missing    = [];
        $totalRules = 0;

        foreach ($bundles as $bName) {
            $bundleDir = $acqcRoot . '/config/rules/' . $bName;

            if (!is_dir($bundleDir)) {
                $missing[] = $bName;
                continue;
            }

            $finder = new Finder();
            $finder->files()->name(['*.yaml', '*.yml'])->in($bundleDir);

            foreach ($finder as $file) {
                $data = Yaml::parseFile($file->getRealPath());
                if (!is_array($data) || !isset($data['id'])) {
                    continue;
                }
                $totalRules++;
                $added[] = [
                    'id'       => $data['id'],
                    'bundle'   => $bName,
                    'severity' => $data['severity'] ?? 'unknown',
                    'tier'     => $data['tier']     ?? '?',
                ];
            }
        }

        if (!empty($missing)) {
            $output->writeln('<comment>Bundles not found (not yet downloaded):</comment>');
            foreach ($missing as $m) {
                $output->writeln("  - {$m}");
            }
            $output->writeln('');
        }

        if (empty($added)) {
            $output->writeln('<info>No rules found in configured bundles.</info>');
            return Command::SUCCESS;
        }

        $verb = $dryRun ? '[dry-run] Would load' : 'Loaded';
        $output->writeln("<info>{$verb} {$totalRules} rule(s) from " . count($bundles) . " bundle(s):</info>");
        $output->writeln('');

        $table = new Table($output);
        $table->setHeaders(['ID', 'Bundle', 'Severity', 'Tier']);
        foreach ($added as $row) {
            $table->addRow([$row['id'], $row['bundle'], $row['severity'], $row['tier']]);
        }
        $table->render();

        return Command::SUCCESS;
    }
}

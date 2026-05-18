<?php

declare(strict_types=1);

namespace ACQC\Commands;

use ACQC\Core\Export\ClaudeExporter;
use ACQC\Core\Export\CursorExporter;
use ACQC\Core\Export\ExporterInterface;
use ACQC\Core\Rules\RuleRegistry;
use ACQC\Core\Rules\RuleValidator;
use ACQC\Core\Rules\YamlRuleLoader;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ExportCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('export')
            ->setDescription('Export ACQC rules to editor and AI-tool formats')
            ->addArgument('target', InputArgument::REQUIRED, 'cursor|claude|sarif|junit|pdf')
            ->addOption('profile',  null, InputOption::VALUE_OPTIONAL, 'Role profile',              'developer')
            ->addOption('check',    null, InputOption::VALUE_NONE,     'Exit non-zero if output is stale')
            ->addOption('output',   null, InputOption::VALUE_OPTIONAL, 'Output directory (default: cwd)')
            ->addOption('category', null, InputOption::VALUE_OPTIONAL, 'Restrict export to one category');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $target   = (string) $input->getArgument('target');
        $profile  = (string) ($input->getOption('profile') ?? 'developer');
        $doCheck  = (bool)   $input->getOption('check');
        $outPath  = (string) ($input->getOption('output') ?? getcwd());
        $category = $input->getOption('category');

        $allowed = ['cursor', 'claude', 'sarif', 'junit', 'pdf'];
        if (!in_array($target, $allowed, true)) {
            $output->writeln("<error>Invalid target '{$target}'. Use: " . implode('|', $allowed) . '</error>');
            return Command::FAILURE;
        }

        $exporter = $this->resolveExporter($target);
        if ($exporter === null) {
            $output->writeln("<comment>Target '{$target}' is scan-based. Use 'php bin/acqc report' instead.</comment>");
            return Command::FAILURE;
        }

        $rules = $this->loadRules($profile, $outPath);

        if ($category !== null) {
            $rules = array_values(array_filter($rules, fn($r) => $r->category === $category));
        } else {
            $rules = array_values($rules);
        }

        if ($doCheck) {
            $upToDate = $exporter->check($rules, $outPath, $profile);
            if ($upToDate) {
                $output->writeln("<info>✓ '{$target}' export is up to date.</info>");
                return Command::SUCCESS;
            }
            $output->writeln("<fg=yellow>⚠ '{$target}' export is stale — run: php bin/acqc export {$target}</>");
            return Command::FAILURE;
        }

        $result = $exporter->export($rules, $outPath, $profile);

        foreach ($result->writtenFiles as $file) {
            $rel = ltrim(str_replace(rtrim($outPath, '/'), '', $file), '/');
            $output->writeln("<info>✓</info>  {$rel}");
        }

        $output->writeln('');
        $output->writeln(sprintf(
            '<info>Exported %d file(s) for target: %s</info>',
            count($result->writtenFiles),
            $target,
        ));

        return Command::SUCCESS;
    }

    private function resolveExporter(string $target): ?ExporterInterface
    {
        foreach ([new CursorExporter(), new ClaudeExporter()] as $exporter) {
            if ($exporter->supports($target)) {
                return $exporter;
            }
        }
        return null;
    }

    private function loadRules(string $profile, string $projectRoot): array
    {
        $acqcRoot  = dirname(__DIR__, 2);
        $validator = new RuleValidator();
        $cache     = new FilesystemAdapter('acqc_rules', 3600, sys_get_temp_dir() . '/acqc_cache');
        $loader    = new YamlRuleLoader($validator, $projectRoot, $acqcRoot, $cache);

        return (new RuleRegistry($loader))->forProfile($profile);
    }
}

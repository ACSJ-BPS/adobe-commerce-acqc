<?php

declare(strict_types=1);

namespace ACQC\Commands\Rules;

use ACQC\Core\Rules\RuleDefinition;
use ACQC\Core\Rules\RuleRegistry;
use ACQC\Core\Rules\RuleValidator;
use ACQC\Core\Rules\YamlRuleLoader;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ListCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('rules:list')
            ->setDescription('List all rules for the current project and profile')
            ->addOption('profile',     null, InputOption::VALUE_OPTIONAL, 'Role profile',              'developer')
            ->addOption('category',    null, InputOption::VALUE_OPTIONAL, 'Filter by category')
            ->addOption('tier',        null, InputOption::VALUE_OPTIONAL, 'Filter by tier (1, 2, or 3)')
            ->addOption('severity',    null, InputOption::VALUE_OPTIONAL, 'Filter by severity')
            ->addOption('active-only', null, InputOption::VALUE_NONE,     'Show only active (non-suppressed) rules')
            ->addOption('format',      null, InputOption::VALUE_OPTIONAL, 'Output format: table|json|csv', 'table');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $profile    = $input->getOption('profile')  ?? 'developer';
        $category   = $input->getOption('category');
        $tier       = $input->getOption('tier') !== null ? (int) $input->getOption('tier') : null;
        $severity   = $input->getOption('severity');
        $activeOnly = (bool) $input->getOption('active-only');
        $format     = $input->getOption('format') ?? 'table';

        if (!in_array($format, ['table', 'json', 'csv'], true)) {
            $output->writeln("<error>Invalid format '{$format}'. Use: table, json, csv</error>");
            return Command::FAILURE;
        }

        $rules  = $this->buildRegistry($profile)->forProfile($profile);
        $rules  = $this->applyFilters($rules, $category, $tier, $severity, $activeOnly);

        $active     = array_filter($rules, fn($r) => $r->isActive());
        $suppressed = array_filter($rules, fn($r) => $r->suppression !== null);
        $deprecated = array_filter($rules, fn($r) => $r->isDeprecated() && $r->suppression === null);

        switch ($format) {
            case 'json':
                $output->writeln(json_encode(array_values(array_map(
                    fn(RuleDefinition $r) => [
                        'id'       => $r->id,
                        'title'    => $r->title,
                        'severity' => $r->severity,
                        'tier'     => $r->tier,
                        'category' => $r->category,
                        'status'   => $this->ruleStatus($r),
                    ],
                    $rules
                )), JSON_PRETTY_PRINT));
                break;

            case 'csv':
                $output->writeln('ID,Title,Severity,Tier,Category,Status');
                foreach ($rules as $rule) {
                    $output->writeln(implode(',', [
                        $rule->id,
                        '"' . str_replace('"', '""', $rule->title) . '"',
                        $rule->severity,
                        $rule->tier,
                        $rule->category,
                        $this->ruleStatus($rule),
                    ]));
                }
                break;

            default:
                $table = new Table($output);
                $table->setHeaders(['ID', 'Title', 'Severity', 'Tier', 'Category', 'Status']);
                foreach ($rules as $rule) {
                    $status = $this->ruleStatus($rule);
                    $table->addRow([
                        $rule->id,
                        mb_strimwidth($rule->title, 0, 55, '…'),
                        $rule->severity,
                        $rule->tier,
                        $rule->category,
                        $status,
                    ]);
                }
                $table->render();
                break;
        }

        $total = count($rules);
        if ($format !== 'json') {
            $output->writeln('');
            $output->writeln(sprintf(
                '<info>%d rule(s) total  (%d active, %d suppressed, %d deprecated)</info>',
                $total,
                count($active),
                count($suppressed),
                count($deprecated)
            ));
        }

        return Command::SUCCESS;
    }

    // ── private helpers ────────────────────────────────────────────────────────

    /** @return RuleDefinition[] */
    private function applyFilters(
        array   $rules,
        ?string $category,
        ?int    $tier,
        ?string $severity,
        bool    $activeOnly,
    ): array {
        return array_filter($rules, function (RuleDefinition $r) use ($category, $tier, $severity, $activeOnly): bool {
            if ($category !== null && $r->category !== $category) {
                return false;
            }
            if ($tier !== null && $r->tier !== $tier) {
                return false;
            }
            if ($severity !== null && $r->severity !== $severity) {
                return false;
            }
            if ($activeOnly && !$r->isActive()) {
                return false;
            }
            return true;
        });
    }

    private function ruleStatus(RuleDefinition $rule): string
    {
        if ($rule->suppression !== null) {
            return 'suppressed';
        }
        if ($rule->isDeprecated()) {
            return 'deprecated';
        }
        return 'active';
    }

    private function buildRegistry(string $profile): RuleRegistry
    {
        $acqcRoot    = dirname(__DIR__, 3);
        $projectRoot = getcwd();
        $validator   = new RuleValidator();
        $cache       = new FilesystemAdapter('acqc_rules', 3600, sys_get_temp_dir() . '/acqc_cache');
        $loader      = new YamlRuleLoader($validator, $projectRoot, $acqcRoot, $cache);
        return new RuleRegistry($loader);
    }
}

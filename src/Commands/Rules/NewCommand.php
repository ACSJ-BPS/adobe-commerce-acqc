<?php

declare(strict_types=1);

namespace ACQC\Commands\Rules;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Yaml\Yaml;

class NewCommand extends Command
{
    private const PREFIX_MAP = [
        'GL' => 'global',
        'AC' => 'adobe-commerce',
        'FE' => 'global/frontend',
    ];

    protected function configure(): void
    {
        $this
            ->setName('rules:new')
            ->setDescription('Scaffold a new rule YAML file interactively')
            ->addOption('id',         null, InputOption::VALUE_OPTIONAL, 'Rule ID (e.g. AC_DI_002)')
            ->addOption('category',   null, InputOption::VALUE_OPTIONAL, 'Rule category (e.g. dependency_injection)')
            ->addOption('tier',       null, InputOption::VALUE_OPTIONAL, 'Rule tier (1, 2, or 3)')
            ->addOption('check-type', null, InputOption::VALUE_OPTIONAL, 'Check type (regex, xml_xpath, php_ast, guidance, …)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $helper = $this->getHelper('question');

        // ── Collect rule ID ───────────────────────────────────────────────────
        $id = $input->getOption('id');
        if (!$id) {
            $q  = new Question('<question>Rule ID (e.g. AC_DI_002): </question>');
            $id = $helper->ask($input, $output, $q);
        }

        if (!preg_match('/^[A-Z]{2,5}_[A-Z]{2,8}_\d{3}$/', (string) $id)) {
            $output->writeln("<error>Invalid ID format '{$id}'. Use PREFIX_CATEGORY_NNN (e.g. AC_DI_002).</error>");
            return Command::FAILURE;
        }

        // ── Collect category ──────────────────────────────────────────────────
        $category = $input->getOption('category');
        if (!$category) {
            $q        = new Question('<question>Category (e.g. dependency_injection): </question>');
            $category = $helper->ask($input, $output, $q);
        }

        // ── Collect tier ──────────────────────────────────────────────────────
        $tier = $input->getOption('tier');
        if (!$tier) {
            $q    = new ChoiceQuestion('<question>Tier: </question>', ['1', '2', '3'], '1');
            $tier = (int) $helper->ask($input, $output, $q);
        } else {
            $tier = (int) $tier;
        }

        // ── Collect check type ────────────────────────────────────────────────
        $checkType = $input->getOption('check-type');
        if (!$checkType) {
            $q         = new ChoiceQuestion(
                '<question>Check type: </question>',
                ['regex', 'xml_xpath', 'php_ast', 'json_path', 'structural', 'composite', 'guidance'],
                'regex'
            );
            $checkType = $helper->ask($input, $output, $q);
        }

        // ── Determine output path ─────────────────────────────────────────────
        $prefix     = explode('_', (string) $id)[0] ?? 'GL';
        $baseDir    = self::PREFIX_MAP[$prefix] ?? 'global';
        $categorySlug = str_replace('_', '-', strtolower((string) $category));
        $idSlug     = strtolower(str_replace('_', '-', (string) $id));

        $acqcRoot = dirname(__DIR__, 3);
        $dir      = "{$acqcRoot}/config/rules/{$baseDir}/{$categorySlug}";
        $filePath = "{$dir}/{$idSlug}.yaml";

        if (file_exists($filePath)) {
            $output->writeln("<error>File already exists: {$filePath}</error>");
            return Command::FAILURE;
        }

        // ── Generate scaffold ─────────────────────────────────────────────────
        $scaffold = [
            'schema_version' => '1.0',
            'id'             => $id,
            'name'           => $idSlug,
            'title'          => 'TODO: Add rule title',
            'description'    => 'TODO: Add rule description',
            'category'       => $category,
            'severity'       => 'warning',
            'tier'           => $tier,
            'roles'          => ['developer', 'ci'],
            'platforms'      => ['cli', 'ci', 'sarif'],
            'introduced_in'  => '3.0.0',
            'tags'           => [],
            'globs'          => $tier <= 2 ? ['**/*.php'] : null,
            'check'          => $tier <= 2 ? ['type' => $checkType, 'pattern' => 'TODO'] : null,
            'recommendation' => $tier <= 2 ? 'TODO: Add recommendation' : null,
            'examples'       => $tier <= 2 ? ['bad' => 'TODO', 'good' => 'TODO'] : null,
            'references'     => [],
        ];

        // Remove null-valued keys for tier 3
        $scaffold = array_filter($scaffold, fn($v) => $v !== null);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($filePath, Yaml::dump($scaffold, 4, 2));

        $output->writeln('');
        $output->writeln("<info>✓ Created rule scaffold:</info>");
        $output->writeln("  {$filePath}");
        $output->writeln('');
        $output->writeln('<comment>Next steps:</comment>');
        $output->writeln("  1. Edit {$filePath}");
        $output->writeln('  2. Replace all TODO placeholders');
        $output->writeln("  3. Validate: php bin/acqc rules:validate {$dir}");

        return Command::SUCCESS;
    }
}

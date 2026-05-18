<?php
declare(strict_types=1);
namespace ACQC\Commands;

use ACQC\Core\Policy\PolicyLoader;
use ACQC\Core\Policy\PolicyEvaluator;
use ACQC\Core\Policy\PolicyRule;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'policy:check',
    description: 'Evaluate policy-as-code rules (YAML) against a project'
)]
class PolicyCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('path', InputArgument::OPTIONAL, 'Project path to check', '.')
            ->addOption('policy', 'p', InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
                'Policy YAML file(s) to load (can specify multiple times)')
            ->addOption('policy-dir', null, InputOption::VALUE_OPTIONAL,
                'Directory containing policy YAML files')
            ->addOption('format', 'f', InputOption::VALUE_OPTIONAL,
                'Output format: table, json, sarif', 'table')
            ->addOption('severity', 's', InputOption::VALUE_OPTIONAL,
                'Minimum severity to report (minor/info/warning/error/critical/blocker)', 'warning')
            ->addOption('exclude', 'e', InputOption::VALUE_OPTIONAL,
                'Path patterns to exclude (comma-separated)', '*/vendor/*,*/Test/*')
            ->addOption('fail-on', null, InputOption::VALUE_OPTIONAL,
                'Exit non-zero when violations of this severity or above are found', 'error')
            ->addOption('list', null, InputOption::VALUE_NONE,
                'List all loaded policies without running them')
            ->setHelp(<<<'HELP'
Evaluates custom policy rules defined in YAML files against your project.

Policy files are auto-loaded from:
  - .acqc/policies/*.yaml  (project-level, committed to repo)
  - ~/.acqc/policies/*.yaml (user-level, personal overrides)
  - Explicit --policy flags

<comment>Examples:</comment>
  <info>acqc policy:check app/code</info>
  <info>acqc policy:check app/code --policy=.acqc/policies/team-rules.yaml</info>
  <info>acqc policy:check app/code --policy-dir=./policies --format=json</info>
  <info>acqc policy:check app/code --severity=error --fail-on=error</info>
  <info>acqc policy:check --list</info>

<comment>Policy file format (.acqc/policies/my-rules.yaml):</comment>
  rules:
    no-object-manager:
      name: No direct ObjectManager usage
      type: pattern
      severity: error
      category: magento
      conditions:
        pattern: 'ObjectManager::getInstance\(\)'
        files: ['*.php']
      message: "Direct ObjectManager usage at line {line}"
      fix: "Use constructor injection instead"
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $projectPath = realpath($input->getArgument('path')) ?: $input->getArgument('path');
        $format      = $input->getOption('format') ?? 'table';
        $failOn      = $input->getOption('fail-on') ?? 'error';

        // Load policies
        $loader = new PolicyLoader();
        $rules  = [];

        // Explicit policy files
        foreach ($input->getOption('policy') ?? [] as $policyFile) {
            try {
                $rules = array_merge($rules, $loader->loadFromFile($policyFile));
            } catch (\Exception $e) {
                $io->warning("Could not load policy file: {$policyFile} — " . $e->getMessage());
            }
        }

        // Explicit policy directory
        if ($policyDir = $input->getOption('policy-dir')) {
            $rules = array_merge($rules, $loader->loadFromDirectory($policyDir));
        }

        // Auto-load from project
        $autoRules = $loader->loadForProject($projectPath);
        $rules = array_merge($rules, $autoRules);

        if (empty($rules)) {
            $io->info('No policy rules loaded. Create .acqc/policies/rules.yaml in your project.');
            $io->writeln('See: <href=https://github.com/adobe-commerce-quality/acqc/wiki/Policy-as-Code>Policy-as-Code documentation</>');
            return Command::SUCCESS;
        }

        // List mode
        if ($input->getOption('list')) {
            return $this->listPolicies($io, $rules, $projectPath);
        }

        // Collect PHP files
        $excludePatterns = array_filter(array_map('trim',
            explode(',', $input->getOption('exclude') ?? '*/vendor/*,*/Test/*')
        ));
        $phpFiles = $this->collectFiles($projectPath, $excludePatterns);

        $io->writeln(sprintf(
            '<info>Checking %d policies against %d files in</info> %s',
            count($rules), count($phpFiles), $projectPath
        ));

        // Evaluate
        $evaluator   = new PolicyEvaluator();
        $allViolations = [];

        // File-independent rules (file_exists, file_missing) run once
        $fileIndepRules = array_filter($rules, fn($r) => in_array($r->type, ['file_exists', 'file_missing']));
        foreach ($fileIndepRules as $rule) {
            $vs = $evaluator->evaluate($rule, '', $projectPath);
            $allViolations = array_merge($allViolations, $vs);
        }

        // File-dependent rules
        $fileRules = array_filter($rules, fn($r) => !in_array($r->type, ['file_exists', 'file_missing']));
        foreach ($phpFiles as $file) {
            foreach ($fileRules as $rule) {
                $vs = $evaluator->evaluate($rule, $file, $projectPath);
                $allViolations = array_merge($allViolations, $vs);
            }
        }

        // Filter by severity
        $severityOrder = ['minor' => 1, 'info' => 2, 'warning' => 3, 'error' => 4, 'critical' => 5, 'blocker' => 6];
        $minSev = $input->getOption('severity') ?? 'warning';
        $minSevInt = $severityOrder[$minSev] ?? 3;
        $filtered = array_filter($allViolations, fn($v) => ($severityOrder[$v['severity']] ?? 3) >= $minSevInt);
        $filtered = array_values($filtered);

        // Output
        if ($format === 'json') {
            $io->writeln(json_encode([
                'project' => $projectPath,
                'policies_loaded' => count($rules),
                'files_checked' => count($phpFiles),
                'violations' => $filtered,
                'total' => count($filtered),
            ], JSON_PRETTY_PRINT));
        } else {
            $this->renderTable($io, $filtered, count($phpFiles), count($rules));
        }

        // Determine exit code
        $failOnInt = $severityOrder[$failOn] ?? 4;
        foreach ($filtered as $v) {
            if (($severityOrder[$v['severity']] ?? 3) >= $failOnInt) {
                return Command::FAILURE;
            }
        }

        return Command::SUCCESS;
    }

    private function listPolicies(SymfonyStyle $io, array $rules, string $projectPath): int
    {
        $io->title('Loaded Policies for: ' . $projectPath);
        $rows = [];
        foreach ($rules as $rule) {
            $rows[] = [
                $rule->id,
                $rule->name,
                $rule->type,
                $rule->severity,
                $rule->category,
                $rule->enabled ? '<fg=green>yes</>' : '<fg=red>no</>',
            ];
        }
        $io->table(['ID', 'Name', 'Type', 'Severity', 'Category', 'Enabled'], $rows);
        return Command::SUCCESS;
    }

    private function renderTable(SymfonyStyle $io, array $violations, int $fileCount, int $policyCount): void
    {
        if (empty($violations)) {
            $io->success(sprintf('All policies passed! (%d files, %d rules)', $fileCount, $policyCount));
            return;
        }

        $io->writeln(sprintf('<fg=red>%d policy violation(s) found</>', count($violations)));
        $rows = [];
        foreach (array_slice($violations, 0, 100) as $v) {
            $color = match($v['severity']) {
                'blocker','critical' => 'red', 'error' => 'red', 'warning' => 'yellow', default => 'cyan'
            };
            $rows[] = [
                sprintf('<fg=%s>%s</>', $color, strtoupper($v['severity'])),
                $v['rule_id'],
                basename($v['file']),
                $v['line'] ?: 'N/A',
                substr($v['message'], 0, 70),
            ];
        }
        if (count($violations) > 100) {
            $io->writeln(sprintf('<comment>Showing first 100 of %d violations</comment>', count($violations)));
        }
        $io->table(['Severity', 'Rule', 'File', 'Line', 'Message'], $rows);

        // Per-rule summary
        $byRule = [];
        foreach ($violations as $v) {
            $byRule[$v['rule_id']] = ($byRule[$v['rule_id']] ?? 0) + 1;
        }
        arsort($byRule);
        $io->section('Violations by Rule');
        foreach (array_slice($byRule, 0, 10, true) as $ruleId => $count) {
            $io->writeln(sprintf('  %-40s %d', $ruleId, $count));
        }
    }

    private function collectFiles(string $basePath, array $excludePatterns): array
    {
        if (!is_dir($basePath)) {
            return is_file($basePath) && str_ends_with($basePath, '.php') ? [$basePath] : [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($basePath, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') continue;
            $path = str_replace('\\', '/', $file->getPathname());
            $skip = false;
            foreach ($excludePatterns as $pattern) {
                if (fnmatch($pattern, $path) || str_contains($path, '/' . trim($pattern, '*/') . '/')) {
                    $skip = true;
                    break;
                }
            }
            if (!$skip) $files[] = $path;
        }

        return $files;
    }
}

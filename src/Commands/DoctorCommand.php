<?php

declare(strict_types=1);

namespace ACQC\Commands;

use ACQC\Core\History\SqliteHistoryStorage;
use ACQC\Core\Rules\SuppressionStore;
use ACQC\Core\Validation\AnalyzerAutoloadValidator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;

class DoctorCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('doctor')
            ->setDescription('Run health checks on the current ACQC project setup')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output results as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $projectRoot = getcwd();
        $asJson      = (bool) $input->getOption('json');

        $acqcRoot = dirname(__DIR__, 2);

        $results = [
            $this->checkAnalyzerAutoload($acqcRoot),
            $this->checkConfigFile($projectRoot),
            $this->checkRuleBundle($projectRoot),
            $this->checkLastScan($projectRoot),
            $this->checkTeamRules($projectRoot),
            $this->checkCiIntegration($projectRoot),
            $this->checkConfigCommitted($projectRoot),
            $this->checkSuppressionDebt($projectRoot),
        ];

        if ($asJson) {
            $output->writeln(json_encode($results, JSON_PRETTY_PRINT));
            $fail = array_filter($results, fn($r) => $r['status'] === 'fail');
            return count($fail) > 0 ? Command::FAILURE : Command::SUCCESS;
        }

        $pass   = 0;
        $issues = [];

        foreach ($results as $result) {
            $icon = match ($result['status']) {
                'pass' => '<fg=green>✓</>',
                'warn' => '<fg=yellow>⚠</>',
                default => '<fg=red>✗</>',
            };
            $output->writeln(sprintf('  %s  %s', $icon, $result['label']));
            if (isset($result['detail']) && $result['detail'] !== '') {
                $output->writeln("       {$result['detail']}");
            }
            if ($result['status'] === 'pass') {
                $pass++;
            } elseif (!empty($result['action'])) {
                $issues[] = $result;
            }
        }

        $total = count($results);
        $output->writeln('');
        $output->writeln("Score: {$pass}/{$total} criteria met.");

        if (!empty($issues)) {
            $output->writeln('');
            $output->writeln('<comment>Priority actions:</comment>');
            foreach ($issues as $issue) {
                $prefix = $issue['status'] === 'fail' ? '<fg=red>✗</>' : '<fg=yellow>⚠</>';
                $output->writeln("  {$prefix}  {$issue['action']}");
            }
        }

        $failed = array_filter($results, fn($r) => $r['status'] === 'fail');
        return count($failed) > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    // ── checks ─────────────────────────────────────────────────────────────────

    private function checkAnalyzerAutoload(string $acqcRoot): array
    {
        $validator = new AnalyzerAutoloadValidator();
        $issues = $validator->validateAcqcPackage($acqcRoot);

        if ($issues === []) {
            return $this->pass('Analyzer autoload', 'All orchestrator analyzer classes match PSR-4 paths');
        }

        $preview = implode('; ', array_slice($issues, 0, 3));
        if (count($issues) > 3) {
            $preview .= sprintf(' (+%d more)', count($issues) - 3);
        }

        return $this->fail(
            'Analyzer autoload',
            $preview,
            'Fix class names to match filenames (case-sensitive autoload) in AnalyzerOrchestrator.php'
        );
    }

    private function checkConfigFile(string $projectRoot): array
    {
        $path = $projectRoot . '/.acqc/project.yaml';
        if (file_exists($path)) {
            return $this->pass('Config file', '.acqc/project.yaml found');
        }
        return $this->warn('Config file', '.acqc/project.yaml not found — project not yet initialised', 'Run: php bin/acqc init');
    }

    private function checkRuleBundle(string $projectRoot): array
    {
        $configPath = $projectRoot . '/.acqc/project.yaml';
        if (!file_exists($configPath)) {
            return $this->warn('Rule bundle', 'No project.yaml to read bundles from — project not yet initialised', 'Run: php bin/acqc init');
        }

        $config  = Yaml::parseFile($configPath);
        $bundles = $config['rule_bundles'] ?? [];

        if (empty($bundles)) {
            return $this->fail('Rule bundle', 'No rule_bundles defined in project.yaml', 'Add a rule_bundles entry to .acqc/project.yaml');
        }

        $acqcRoot = dirname(__DIR__, 2);
        $count    = 0;
        $found    = [];

        foreach ($bundles as $bundle) {
            $bundleDir = $acqcRoot . '/config/rules/' . $bundle;
            if (is_dir($bundleDir)) {
                $finder = new Finder();
                $n      = $finder->files()->name(['*.yaml', '*.yml'])->in($bundleDir)->count();
                $count += $n;
                $found[] = $bundle;
            }
        }

        if (empty($found)) {
            return $this->warn(
                'Rule bundle',
                'Configured bundle directories not found (rules not yet synced)',
                'Run: php bin/acqc rules:sync'
            );
        }

        return $this->pass('Rule bundle', "{$count} rule(s) loaded from: " . implode(', ', $found));
    }

    private function checkLastScan(string $projectRoot): array
    {
        try {
            $storage = new SqliteHistoryStorage();
            $entries = $storage->findAll($projectRoot, 1);
        } catch (\Throwable) {
            return $this->warn('Last scan', 'Could not read scan history', 'Run a scan: php bin/acqc scan .');
        }

        if (empty($entries)) {
            return $this->warn('Last scan', 'No scan history found for this project', 'Run: php bin/acqc scan .');
        }

        $last    = $entries[0];
        $sevenDaysAgo = date('Y-m-d H:i:s', strtotime('-7 days'));

        if ($last->createdAt < $sevenDaysAgo) {
            return $this->warn(
                'Last scan',
                "Last scan was {$last->createdAt} (more than 7 days ago)",
                'Schedule regular scans or set up CI integration'
            );
        }

        return $this->pass('Last scan', "Last scan: {$last->createdAt} (score: {$last->score})");
    }

    private function checkTeamRules(string $projectRoot): array
    {
        $teamDir = $projectRoot . '/.acqc/rules/team';
        if (!is_dir($teamDir)) {
            return $this->warn(
                'Team rules',
                'No .acqc/rules/team/ directory found',
                'Create team-specific rules in .acqc/rules/team/ (optional)'
            );
        }

        $finder = new Finder();
        $count  = $finder->files()->name(['*.yaml', '*.yml'])->in($teamDir)->count();

        if ($count === 0) {
            return $this->warn(
                'Team rules',
                'Team rules directory exists but contains no YAML files',
                'Add rule YAML files to .acqc/rules/team/'
            );
        }

        return $this->pass('Team rules', "{$count} team rule(s) found in .acqc/rules/team/");
    }

    private function checkCiIntegration(string $projectRoot): array
    {
        $ciLocations = [
            'GitHub Actions' => $projectRoot . '/.github/workflows',
            'GitLab CI'      => $projectRoot . '/.gitlab-ci.yml',
            'Bitbucket'      => $projectRoot . '/bitbucket-pipelines.yml',
        ];

        foreach ($ciLocations as $platform => $location) {
            if (is_dir($location)) {
                $finder = new Finder();
                foreach ($finder->files()->in($location) as $file) {
                    if (str_contains((string) file_get_contents($file->getRealPath()), 'acqc')) {
                        return $this->pass('CI integration', "ACQC found in {$platform}: {$file->getRelativePathname()}");
                    }
                }
            } elseif (is_file($location) && str_contains((string) file_get_contents($location), 'acqc')) {
                return $this->pass('CI integration', "ACQC found in {$platform}");
            }
        }

        return $this->fail(
            'CI integration',
            'No CI file references acqc',
            'Run: php bin/acqc init --ci=github|gitlab|bitbucket'
        );
    }

    private function checkConfigCommitted(string $projectRoot): array
    {
        $cwd = getcwd();
        chdir($projectRoot);
        $out = trim((string) shell_exec('git ls-files .acqc/project.yaml 2>/dev/null'));
        chdir($cwd);

        if ($out !== '') {
            return $this->pass('Config committed', '.acqc/project.yaml is tracked by git');
        }

        // File doesn't exist yet — not an error, just needs init first
        if (!file_exists($projectRoot . '/.acqc/project.yaml')) {
            return $this->warn('Config committed', '.acqc/project.yaml does not exist yet', 'Run: php bin/acqc init');
        }

        return $this->fail(
            'Config committed',
            '.acqc/project.yaml is not committed to git',
            'Run: git add .acqc/project.yaml && git commit -m "chore: add ACQC config"'
        );
    }

    private function checkSuppressionDebt(string $projectRoot): array
    {
        $configPath = $projectRoot . '/.acqc/project.yaml';
        try {
            $store   = new SuppressionStore($configPath);
            $expired = $store->getExpiredSuppressions();
        } catch (\Throwable) {
            return $this->pass('Suppression debt', 'No suppressions to review');
        }

        $count = count($expired);
        if ($count === 0) {
            return $this->pass('Suppression debt', 'No expired suppressions');
        }

        $ids = implode(', ', array_column($expired, 'rule_id'));
        return $this->warn(
            'Suppression debt',
            "{$count} expired suppression(s): {$ids}",
            "Review and update suppression review dates or remove them"
        );
    }

    // ── result builders ────────────────────────────────────────────────────────

    private function pass(string $label, string $detail): array
    {
        return ['status' => 'pass', 'label' => $label, 'detail' => $detail, 'action' => ''];
    }

    private function warn(string $label, string $detail, string $action): array
    {
        return ['status' => 'warn', 'label' => $label, 'detail' => $detail, 'action' => $action];
    }

    private function fail(string $label, string $detail, string $action): array
    {
        return ['status' => 'fail', 'label' => $label, 'detail' => $detail, 'action' => $action];
    }
}

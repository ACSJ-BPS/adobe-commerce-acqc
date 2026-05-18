<?php

declare(strict_types=1);

namespace ACQC\Commands;

use ACQC\Core\AuditEngine;
use ACQC\Core\Profiles\ProfileConfig;
use ACQC\Core\Profiles\ProfileResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'audit',
    description: 'Run a comprehensive code quality audit on a Magento 2 project'
)]
class AuditCommand extends Command
{
    public function __construct(
        private readonly AuditEngine $auditEngine = new AuditEngine()
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('path', InputArgument::OPTIONAL, 'Path to the Magento 2 project', getcwd())
            ->addOption('profile',   'p', InputOption::VALUE_OPTIONAL, 'Quality profile (developer|tech-lead|architect|executive|ci)', 'developer')
            ->addOption('config',    'c', InputOption::VALUE_OPTIONAL, 'Path to configuration file')
            ->addOption('format',    'f', InputOption::VALUE_OPTIONAL, 'Output format (json, html, console)')
            ->addOption('severity',  's', InputOption::VALUE_OPTIONAL, 'Minimum severity override (info, warning, error)')
            ->addOption('exclude',   null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Paths to exclude from analysis')
            ->addOption('diff-from', null, InputOption::VALUE_OPTIONAL, 'Compare against branch/commit (git diff mode)')
            ->setHelp('This command analyzes a Magento 2 project for code quality issues.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path        = $input->getArgument('path');
        $profileName = (string) ($input->getOption('profile') ?? 'developer');

        try {
            $profile = $this->resolveProfile($profileName);
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        // CLI option wins over profile default; null option → fall back to profile
        $outputFmt = $input->getOption('format') ?? $profile->format;

        $validFormats = ['json', 'html', 'console'];
        if (!in_array($outputFmt, $validFormats, true)) {
            $output->writeln(sprintf(
                '<error>Invalid output format "%s". Valid formats: %s</error>',
                $outputFmt,
                implode(', ', $validFormats)
            ));
            return Command::FAILURE;
        }

        $severity  = $input->getOption('severity') ?? $profile->minSeverity;
        $jsonMode  = $outputFmt === 'json';
        $io        = new SymfonyStyle($input, $output);
        $pathInfo  = $this->extractPathInfo((string) $path);

        if (!$jsonMode) {
            $io->title('Adobe Commerce Quality Control Tool');
            $io->writeln('');
            $io->info(sprintf('Analyzing project at: %s', $path));

            if ($pathInfo['type'] !== 'unknown') {
                $io->writeln(sprintf('<fg=cyan>Scope: %s</>', ucfirst($pathInfo['type'])));
                if ($pathInfo['vendor']) {
                    $io->writeln(sprintf('<fg=cyan>Vendor: %s</>', $pathInfo['vendor']));
                }
                if ($pathInfo['module']) {
                    $io->writeln(sprintf('<fg=cyan>Module: %s</>', $pathInfo['module']));
                }
            }
            $io->writeln('');
        }

        try {
            $result = $this->auditEngine->runAudit(
                (string) $path,
                [
                    'config'    => $input->getOption('config'),
                    'output'    => $outputFmt,
                    'severity'  => $severity,
                    'exclude'   => $input->getOption('exclude'),
                    'path_info' => $pathInfo,
                    'profile'   => $profileName,
                ]
            );

            // Apply profile transformations to issues
            $result['issues'] = $this->applyProfileToIssues($result['issues'] ?? [], $profile);

            if ($jsonMode) {
                $grade = match(true) {
                    $result['score'] >= 95 => 'A+',
                    $result['score'] >= 85 => 'A',
                    $result['score'] >= 75 => 'B',
                    $result['score'] >= 65 => 'C',
                    $result['score'] >= 50 => 'D',
                    default                => 'F',
                };

                $output->writeln(json_encode([
                    'score'          => $result['score'],
                    'grade'          => $grade,
                    'profile'        => $profileName,
                    'files_analyzed' => $result['files_analyzed'],
                    'total_lines'    => $result['total_lines'] ?? 0,
                    'issues_found'   => $result['issues_found'],
                    'errors'         => $result['errors'],
                    'warnings'       => $result['warnings'],
                    'issues'         => $result['issues'],
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                return $this->exitCode($result, $profile);
            }

            $io->success('Audit completed successfully!');

            $errorsPerFile   = $result['files_analyzed'] > 0 ?
                number_format($result['errors'] / $result['files_analyzed'], 2) : '0';
            $warningsPerFile = $result['files_analyzed'] > 0 ?
                number_format($result['warnings'] / $result['files_analyzed'], 2) : '0';

            $io->table(
                ['Metric', 'Value', 'Per File'],
                [
                    ['Files Analyzed', $result['files_analyzed'], '-'],
                    ['Issues Found', $result['issues_found'], number_format($result['issues_found'] / max(1, $result['files_analyzed']), 2)],
                    ['Errors',   sprintf('<fg=red>%d</>',    $result['errors']),   sprintf('<fg=red>%s</>',    $errorsPerFile)],
                    ['Warnings', sprintf('<fg=yellow>%d</>', $result['warnings']), sprintf('<fg=yellow>%s</>', $warningsPerFile)],
                    ['Score', $this->formatScore($result['score']), '-'],
                ]
            );

            $this->displayScoreInterpretation($io, $result['score'], $result);

            if ($pathInfo['type'] !== 'unknown') {
                $io->writeln('');
                $io->section('Audit Scope Summary');
                $this->displayScopeSummary($io, $pathInfo, $result);
            }

            return $this->exitCode($result, $profile);
        } catch (\Exception $e) {
            if ($jsonMode) {
                $output->writeln(json_encode(['error' => $e->getMessage()]));
            } else {
                $io->error('Audit failed: ' . $e->getMessage());
            }
            return Command::FAILURE;
        }
    }

    // ── profile helpers ────────────────────────────────────────────────────────

    private function resolveProfile(string $name): ProfileConfig
    {
        $acqcRoot = dirname(__DIR__, 2);
        return (new ProfileResolver($acqcRoot, (string) getcwd()))->resolve($name);
    }

    private function applyProfileToIssues(array $issues, ProfileConfig $profile): array
    {
        return array_map(function (array $issue) use ($profile): array {
            if ($profile->hideFilePaths) {
                $issue['file']      = '** (hidden)';
                $issue['file_path'] = '** (hidden)';
            }
            if ($profile->hideLineNumbers) {
                $issue['line']   = null;
                $issue['column'] = null;
            }
            if ($profile->hideRuleIds) {
                $issue['rule_id'] = null;
                $issue['rule']    = null;
            }
            if (!empty($profile->severityLabels) && isset($issue['severity'])) {
                $issue['severity_label'] = $profile->severityLabels[$issue['severity']] ?? $issue['severity'];
            }
            return $issue;
        }, $issues);
    }

    private function exitCode(array $result, ProfileConfig $profile): int
    {
        if (empty($profile->failOn)) {
            return Command::SUCCESS;
        }
        foreach ($result['issues'] ?? [] as $issue) {
            if (in_array($issue['severity'] ?? '', $profile->failOn, true)) {
                return Command::FAILURE;
            }
        }
        return Command::SUCCESS;
    }

    // ── path helpers ───────────────────────────────────────────────────────────

    private function extractPathInfo(string $path): array
    {
        $info = [
            'type' => 'unknown',
            'vendor' => null,
            'module' => null,
            'full_path' => $path,
        ];

        if (preg_match('#/([^/]+)/([^/]+)/?$#', $path, $matches)) {
            $vendor = $matches[1];
            $module = $matches[2];

            if (file_exists($path . '/registration.php') ||
                file_exists($path . '/etc/module.xml') ||
                is_dir($path . '/Model') ||
                is_dir($path . '/Controller')) {
                $info['type']   = 'module';
                $info['vendor'] = $vendor;
                $info['module'] = $module;
            } elseif (is_dir($path . '/' . $module)) {
                $info['type']   = 'vendor';
                $info['vendor'] = $vendor;
            }
        } elseif (preg_match('#/app/code/?$#', $path) || preg_match('#/code/?$#', $path)) {
            $info['type'] = 'all_vendors';
        }

        return $info;
    }

    private function formatScore(int $score): string
    {
        if ($score >= 80) {
            return sprintf('<fg=green;options=bold>%d/100 (Excellent)</>', $score);
        } elseif ($score >= 60) {
            return sprintf('<fg=yellow;options=bold>%d/100 (Good)</>', $score);
        } elseif ($score >= 40) {
            return sprintf('<fg=yellow>%d/100 (Fair)</>', $score);
        } else {
            return sprintf('<fg=red;options=bold>%d/100 (Needs Improvement)</>', $score);
        }
    }

    private function displayScoreInterpretation(SymfonyStyle $io, int $score, array $result): void
    {
        $io->writeln('');
        $io->section('Score Interpretation');

        if ($score >= 80) {
            $io->writeln('<fg=green>✓ Excellent quality! Code meets high standards.</>');
        } elseif ($score >= 60) {
            $io->writeln('<fg=yellow>→ Good quality with room for improvement.</>');
            $io->note('Focus on resolving errors and reducing warnings.');
        } elseif ($score >= 40) {
            $io->writeln('<fg=yellow>⚠ Fair quality. Several issues need attention.</>');
            $io->warning('Prioritize fixing errors to improve code quality.');
        } else {
            $io->writeln('<fg=red>✗ Needs significant improvement.</>');
            $io->error('Multiple critical issues detected. Immediate action recommended.');
        }

        $io->writeln('');
        $io->writeln('<comment>Score Calculation Formula:</comment>');
        $io->writeln('  • Base Score: 100 points');
        $io->writeln('  • Error Impact: Based on errors per file ratio (max -50 pts)');
        $io->writeln('  • Warning Impact: Based on warnings per file ratio (max -30 pts)');
        $io->writeln('  • Final Score: Scales proportionally with issue density');
    }

    private function displayScopeSummary(SymfonyStyle $io, array $pathInfo, array $result): void
    {
        $rows = [
            ['Audit Type', ucfirst($pathInfo['type'])],
        ];

        if ($pathInfo['vendor']) {
            $rows[] = ['Vendor', sprintf('<fg=cyan;options=bold>%s</>', $pathInfo['vendor'])];
        }
        if ($pathInfo['module']) {
            $rows[] = ['Module', sprintf('<fg=cyan;options=bold>%s</>', $pathInfo['module'])];
        }

        $rows[] = ['Path', $pathInfo['full_path']];
        $rows[] = ['Files Scanned', $result['files_analyzed']];
        $rows[] = ['Total Issues', $result['issues_found']];

        $io->table(['Property', 'Value'], $rows);

        if ($pathInfo['type'] === 'module') {
            $io->note(sprintf(
                'This audit focused on the %s/%s module. Run audit on the full vendor or app/code to see broader metrics.',
                $pathInfo['vendor'],
                $pathInfo['module']
            ));
        } elseif ($pathInfo['type'] === 'vendor') {
            $io->note(sprintf(
                'This audit covered all modules under vendor %s. Individual module audits can provide more focused insights.',
                $pathInfo['vendor']
            ));
        }
    }
}

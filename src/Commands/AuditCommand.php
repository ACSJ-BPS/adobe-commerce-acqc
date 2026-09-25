<?php

declare(strict_types=1);

namespace ACQC\Commands;

use ACQC\Core\AuditEngine;
use ACQC\Core\Git\GitDiffResolver;
use ACQC\Core\Git\ScopePatterns;
use ACQC\Core\Git\StagedSnapshot;
use ACQC\Core\Profiles\ProfileConfig;
use ACQC\Core\Profiles\ProfileResolver;
use ACQC\Reports\Exporters\CompactTextExporter;
use ACQC\Reports\Generators\SARIFReportGenerator;
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
    /** Exit-code contract (stable; relied on by git hooks and CI). */
    public const EXIT_PASS         = 0; // gate passed
    public const EXIT_GATE_FAILED  = 1; // quality gate failed, or invalid usage/config
    public const EXIT_TOOL_ERROR   = 2; // ACQC itself failed at runtime (git error, crash, ...)

    private const SEVERITIES = ['info', 'minor', 'warning', 'error', 'critical', 'blocker'];

    public function __construct(
        private readonly AuditEngine $auditEngine = new AuditEngine()
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('path', InputArgument::OPTIONAL, 'Path to the Magento 2 project', getcwd())
            ->addOption('profile',   'p', InputOption::VALUE_OPTIONAL, 'Quality profile (developer|tech-lead|architect|executive|ci|pre-commit)', 'developer')
            ->addOption('config',    'c', InputOption::VALUE_OPTIONAL, 'Path to configuration file')
            ->addOption('format',    'f', InputOption::VALUE_OPTIONAL, 'Output format (json, html, console, compact, sarif)')
            ->addOption('output',    null, InputOption::VALUE_OPTIONAL, 'Deprecated alias of --format')
            ->addOption('sarif-file', null, InputOption::VALUE_REQUIRED, 'Also write SARIF 2.1.0 to this file (any --format)')
            ->addOption('severity',  's', InputOption::VALUE_OPTIONAL, 'Minimum severity override (info, warning, error)')
            ->addOption('exclude',   null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Paths to exclude from analysis')
            ->addOption('diff-from', null, InputOption::VALUE_OPTIONAL, 'Audit only files changed vs a branch/commit/range (e.g. main, origin/main..HEAD)')
            ->addOption('staged',    null, InputOption::VALUE_NONE, 'Audit only the staged (index) content of changed files — for pre-commit hooks')
            ->addOption('files',     null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Audit only these files (repeatable or comma-separated, relative to <path>)')
            ->addOption('fail-on',   null, InputOption::VALUE_REQUIRED, 'Comma-separated list of severities that fail the gate (exact match, not a threshold; overrides profile fail_on). Values: info, minor, warning, error, critical, blocker. e.g. critical,error')
            ->setHelp(<<<'HELP'
This command analyzes a Magento 2 project for code quality issues.

<comment>Scope (mutually exclusive):</comment>
  (default)          whole project
  --staged           staged content only (what will be committed)
  --diff-from=main   files changed vs a ref or range
  --files=a.php,b.xml  explicit files

<comment>Exit codes:</comment>
  0  gate passed (also when there is nothing to check)
  1  gate failed (an issue with a --fail-on / profile fail_on severity) or invalid usage
  2  ACQC runtime error (e.g. git failure) — hooks may choose to allow the commit

<comment>Examples:</comment>
  <info>acqc audit --staged --profile=pre-commit</info>   (compact output, for git hooks)
  <info>acqc audit --staged --profile=ci</info>
  <info>acqc audit . --diff-from=origin/main --fail-on=critical,error</info>
  <info>acqc audit . --diff-from=origin/main --format=sarif > acqc.sarif</info>   (GitHub code scanning)
  <info>acqc audit --staged --profile=pre-commit --sarif-file=acqc.sarif</info>
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path        = $input->getArgument('path');
        $profileName = (string) ($input->getOption('profile') ?? 'developer');

        try {
            $profile = $this->resolveProfile($profileName);
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return self::EXIT_GATE_FAILED;
        }

        // CLI option wins over profile default; null option → fall back to profile
        $outputFmt = $input->getOption('format') ?? $input->getOption('output') ?? $profile->format;

        $validFormats = ['json', 'html', 'console', 'compact', 'sarif'];
        if (!in_array($outputFmt, $validFormats, true)) {
            $output->writeln(sprintf(
                '<error>Invalid output format "%s". Valid formats: %s</error>',
                $outputFmt,
                implode(', ', $validFormats)
            ));
            return self::EXIT_GATE_FAILED;
        }

        try {
            $failOn = $this->resolveFailOn($input->getOption('fail-on'), $profile);
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return self::EXIT_GATE_FAILED;
        }

        $scopeFlags = array_filter([
            'staged'    => (bool) $input->getOption('staged'),
            'diff-from' => $input->getOption('diff-from') !== null,
            'files'     => $input->getOption('files') !== [],
        ]);
        if (count($scopeFlags) > 1) {
            $output->writeln('<error>Options --' . implode(', --', array_keys($scopeFlags)) . ' cannot be combined.</error>');
            return self::EXIT_GATE_FAILED;
        }

        $severity  = $input->getOption('severity') ?? $profile->minSeverity;
        $jsonMode  = $outputFmt === 'json';
        $io        = new SymfonyStyle($input, $output);
        $pathInfo  = $this->extractPathInfo((string) $path);
        $snapshot  = null;

        try {
            $scope = $this->resolveScope($input, (string) $path, $snapshot);
        } catch (\Throwable $e) {
            $this->reportError($e, $outputFmt, $output, $io);
            return self::EXIT_TOOL_ERROR;
        }

        try {
            if ($scope !== null && $scope['files'] === []) {
                $empty = $this->emptyResult($profileName, $scope['mode']) + ['fail_on' => $failOn];
                if (!$this->emitSarif($input, $output, $outputFmt, $empty, $scope['project_root'], null)) {
                    return self::EXIT_TOOL_ERROR;
                }
                if ($jsonMode) {
                    $output->writeln(json_encode($this->emptyResult($profileName, $scope['mode']), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                } elseif ($outputFmt === 'compact') {
                    $output->writeln((new CompactTextExporter())->renderNothingToCheck($scope['mode']), OutputInterface::OUTPUT_RAW);
                } elseif ($outputFmt !== 'sarif') {
                    $io->success(sprintf('ACQC: no auditable files in scope (%s) — nothing to check.', $scope['mode']));
                }
                return self::EXIT_PASS;
            }

            return $this->runAndReport(
                $input, $output, $io, $profile, $profileName, $failOn,
                (string) $path, $scope, $snapshot, $outputFmt, $severity, $pathInfo, $jsonMode
            );
        } finally {
            $snapshot?->cleanup();
        }
    }

    /**
     * @param array{mode:string,files:string[],base_dir:string,project_root:string,relative:string[]}|null $scope
     */
    private function runAndReport(
        InputInterface $input,
        OutputInterface $output,
        SymfonyStyle $io,
        ProfileConfig $profile,
        string $profileName,
        array $failOn,
        string $path,
        ?array $scope,
        ?StagedSnapshot $snapshot,
        string $outputFmt,
        string $severity,
        array $pathInfo,
        bool $jsonMode
    ): int {
        if (!$jsonMode && !in_array($outputFmt, ['compact', 'sarif'], true)) {
            $io->title('Adobe Commerce Quality Control Tool');
            $io->writeln('');
            $io->info(sprintf('Analyzing project at: %s', $scope['project_root'] ?? $path));
            if ($scope !== null) {
                $io->writeln(sprintf('<fg=cyan>Scope: %s (%d file(s))</>', $scope['mode'], count($scope['files'])));
            }

            if ($scope === null && $pathInfo['type'] !== 'unknown') {
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
            $engineOptions = [
                'config'    => $input->getOption('config'),
                'output'    => $outputFmt,
                'severity'  => $severity,
                'exclude'   => $input->getOption('exclude'),
                'path_info' => $pathInfo,
                'profile'   => $profileName,
            ];
            if ($scope !== null) {
                $engineOptions['files']                  = $scope['files'];
                $engineOptions['base_dir']               = $scope['base_dir'];
                $engineOptions['changed_relative_paths'] = $scope['relative'];
                if (isset($scope['abqc_path'])) {
                    $engineOptions['abqc_path'] = $scope['abqc_path'];
                }
            }

            $result = $this->auditEngine->runAudit($scope['project_root'] ?? $path, $engineOptions);

            if ($snapshot !== null) {
                $result['issues'] = $this->remapSnapshotPaths($result['issues'] ?? [], $snapshot);
            } elseif ($scope !== null) {
                // --diff-from / --files: report repo-relative paths, same as --staged.
                $result['issues'] = $this->relativizePaths($result['issues'] ?? [], (string) $scope['project_root']);
            }

            // Apply profile transformations to issues
            $result['issues'] = $this->applyProfileToIssues($result['issues'] ?? [], $profile);

            $sarifData = $result + [
                'profile' => $profileName,
                'scope'   => $scope['mode'] ?? 'project',
                'fail_on' => $failOn,
                'gate'    => $this->failingIssues($result, $failOn) === [] ? 'passed' : 'failed',
            ];
            if (!$this->emitSarif($input, $output, $outputFmt, $sarifData, $scope['project_root'] ?? $path, $snapshot?->getDirectory())) {
                return self::EXIT_TOOL_ERROR;
            }
            if ($outputFmt === 'sarif') {
                return $this->exitCode($result, $failOn);
            }

            if ($outputFmt === 'compact') {
                $output->writeln(
                    (new CompactTextExporter())->render(
                        $result['issues'],
                        $failOn,
                        $scope['mode'] ?? 'project',
                        (int) ($result['files_analyzed'] ?? 0)
                    ),
                    OutputInterface::OUTPUT_RAW
                );
                return $this->exitCode($result, $failOn);
            }

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
                    'scope'          => $scope['mode'] ?? 'project',
                    'fail_on'        => $failOn,
                    'gate'           => $this->failingIssues($result, $failOn) === [] ? 'passed' : 'failed',
                    'files_analyzed' => $result['files_analyzed'],
                    'total_lines'    => $result['total_lines'] ?? 0,
                    'issues_found'   => $result['issues_found'],
                    'critical'       => $result['critical'] ?? $this->countSeverity($result, 'critical'),
                    'errors'         => $result['errors'],
                    'warnings'       => $result['warnings'],
                    'issues'         => $result['issues'],
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                return $this->exitCode($result, $failOn);
            }

            $io->success('Audit completed.');

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

            if ($scope === null && $pathInfo['type'] !== 'unknown') {
                $io->writeln('');
                $io->section('Audit Scope Summary');
                $this->displayScopeSummary($io, $pathInfo, $result);
            }

            $this->displayGateVerdict($io, $result, $failOn);

            return $this->exitCode($result, $failOn);
        } catch (\Throwable $e) {
            $this->reportError($e, $outputFmt, $output, $io);
            return self::EXIT_TOOL_ERROR;
        }
    }

    // ── scope / gate helpers ───────────────────────────────────────────────────

    /**
     * Resolve the file scope. Returns null for a whole-project audit.
     *
     * @return array{mode:string,files:string[],base_dir:string,project_root:string,relative:string[]}|null
     */
    private function resolveScope(InputInterface $input, string $path, ?StagedSnapshot &$snapshot): ?array
    {
        $resolver = new GitDiffResolver();

        if ($input->getOption('staged')) {
            $root     = $resolver->getRepositoryRoot($path);
            $snapshot = StagedSnapshot::create($root, ScopePatterns::forProject($root));
            $relative = $snapshot->getRelativePaths();
            // abqc needs project context (package.json, app.config.yaml, ...) to lint the
            // snapshot; copy the staged version of those files when App Builder code changed.
            foreach ($relative as $rel) {
                if (ScopePatterns::isAppBuilderRelevant($rel)) {
                    $snapshot->addContextFiles(ScopePatterns::APP_BUILDER_CONTEXT_FILES);
                    break;
                }
            }
            return [
                'mode'         => 'staged',
                'files'        => $snapshot->getFiles(),
                'base_dir'     => $snapshot->getDirectory(),
                'project_root' => $snapshot->getRepoRoot(),
                'relative'     => $relative,
                'abqc_path'    => $snapshot->getDirectory(),
            ];
        }

        $diffFrom = $input->getOption('diff-from');
        if ($diffFrom !== null) {
            $root  = $resolver->getRepositoryRoot($path);
            $files = $resolver->getChangedFiles($root, (string) $diffFrom, ScopePatterns::forProject($root));
            return [
                'mode'         => 'diff:' . $diffFrom,
                'files'        => $files,
                'base_dir'     => $root,
                'project_root' => $root,
                'relative'     => array_map(static fn (string $f): string => substr($f, strlen($root) + 1), $files),
            ];
        }

        $fileArgs = $input->getOption('files');
        if ($fileArgs !== []) {
            $root = rtrim(realpath($path) ?: $path, '/');
            if (!is_dir($root)) {
                throw new \InvalidArgumentException("Project path does not exist: {$path}");
            }
            $list = [];
            foreach ($fileArgs as $arg) {
                foreach (explode(',', (string) $arg) as $f) {
                    $f = trim($f);
                    if ($f !== '') {
                        $list[] = str_starts_with($f, '/') ? $f : $root . '/' . $f;
                    }
                }
            }
            return [
                'mode'         => 'files',
                'files'        => $list,
                'base_dir'     => $root,
                'project_root' => $root,
                'relative'     => array_map(
                    static fn (string $f): string => str_starts_with($f, $root . '/') ? substr($f, strlen($root) + 1) : $f,
                    $list
                ),
            ];
        }

        return null;
    }

    /** @return string[] */
    private function resolveFailOn(?string $option, ProfileConfig $profile): array
    {
        if ($option === null) {
            return $profile->failOn;
        }
        $list = array_values(array_filter(array_map(
            static fn (string $s): string => strtolower(trim($s)),
            explode(',', $option)
        ), static fn (string $s): bool => $s !== ''));

        $invalid = array_diff($list, self::SEVERITIES);
        if ($invalid !== []) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid --fail-on severity: %s. Valid: %s',
                implode(', ', $invalid),
                implode(', ', self::SEVERITIES)
            ));
        }
        return $list;
    }

    /** @param string[] $failOn */
    private function failingIssues(array $result, array $failOn): array
    {
        if ($failOn === []) {
            return [];
        }
        return array_values(array_filter(
            $result['issues'] ?? [],
            static fn (array $i): bool => in_array($i['severity'] ?? '', $failOn, true)
        ));
    }

    private function countSeverity(array $result, string $severity): int
    {
        return count(array_filter($result['issues'] ?? [], static fn (array $i): bool => ($i['severity'] ?? '') === $severity));
    }

    /** Strip the project root (raw or realpath form) from issue file paths. */
    private function relativizePaths(array $issues, string $root): array
    {
        $prefixes = array_values(array_unique(array_filter([
            rtrim($root, '/'),
            rtrim((string) realpath($root), '/'),
        ])));
        return array_map(static function (array $issue) use ($prefixes): array {
            foreach (['file', 'file_path'] as $key) {
                if (!isset($issue[$key]) || !is_string($issue[$key])) {
                    continue;
                }
                $f = str_replace('\\', '/', $issue[$key]);
                foreach ($prefixes as $p) {
                    if ($p !== '' && str_starts_with($f, $p . '/')) {
                        $issue[$key] = substr($f, strlen($p) + 1);
                        break;
                    }
                }
            }
            return $issue;
        }, $issues);
    }

    private function remapSnapshotPaths(array $issues, StagedSnapshot $snapshot): array
    {
        return array_map(static function (array $issue) use ($snapshot): array {
            foreach (['file', 'file_path'] as $key) {
                if (isset($issue[$key]) && is_string($issue[$key])) {
                    $issue[$key] = $snapshot->toRelative($issue[$key]);
                }
            }
            return $issue;
        }, $issues);
    }

    /**
     * Write SARIF to stdout (--format=sarif) and/or to --sarif-file.
     * Returns false (after reporting) if SARIF could not be generated or written.
     */
    private function emitSarif(
        InputInterface $input,
        OutputInterface $output,
        string $format,
        array $data,
        string $projectRoot,
        ?string $sourceRoot
    ): bool {
        $file = $input->getOption('sarif-file');
        if ($format !== 'sarif' && ($file === null || $file === '')) {
            return true;
        }

        $report = (new SARIFReportGenerator())->generate($data, array_filter([
            'project_path' => $projectRoot,
            'source_root'  => $sourceRoot,
            'command_line' => 'acqc audit',
        ]));
        $io = new SymfonyStyle($input, $output);
        if (!$report->isSuccess()) {
            $this->reportError(new \RuntimeException('SARIF generation failed: ' . $report->getError()), $format, $output, $io);
            return false;
        }

        if ($file !== null && $file !== '') {
            $dir = dirname((string) $file);
            if ((!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir))
                || @file_put_contents((string) $file, $report->getContent() . "\n") === false) {
                $this->reportError(new \RuntimeException("Cannot write SARIF file: {$file}"), $format, $output, $io);
                return false;
            }
        }
        if ($format === 'sarif') {
            $output->writeln($report->getContent(), OutputInterface::OUTPUT_RAW);
        }
        return true;
    }

    private function emptyResult(string $profileName, string $mode): array
    {
        return [
            'score' => 100, 'grade' => 'A+', 'profile' => $profileName, 'scope' => $mode,
            'gate' => 'passed', 'files_analyzed' => 0, 'total_lines' => 0, 'issues_found' => 0,
            'critical' => 0, 'errors' => 0, 'warnings' => 0, 'issues' => [],
        ];
    }

    /** @param string[] $failOn */
    private function displayGateVerdict(SymfonyStyle $io, array $result, array $failOn): void
    {
        $failing = $this->failingIssues($result, $failOn);
        if ($failing === []) {
            $io->success(sprintf('Quality gate PASSED (fail on: %s)', $failOn === [] ? 'none' : implode(', ', $failOn)));
            return;
        }

        $io->error(sprintf(
            'Quality gate FAILED: %d issue(s) with severity %s',
            count($failing),
            implode('/', $failOn)
        ));
        foreach (array_slice($failing, 0, 50) as $issue) {
            $io->writeln(sprintf(
                '  <fg=red>%s</> %s:%s  %s%s',
                strtoupper((string) ($issue['severity'] ?? '')),
                $issue['file'] ?? '?',
                $issue['line'] ?? '?',
                ($issue['rule_id'] ?? $issue['rule'] ?? null) ? '[' . ($issue['rule_id'] ?? $issue['rule']) . '] ' : '',
                $issue['message'] ?? ''
            ));
        }
        if (count($failing) > 50) {
            $io->writeln(sprintf('  … and %d more', count($failing) - 50));
        }
    }

    private function reportError(\Throwable $e, string $format, OutputInterface $output, SymfonyStyle $io): void
    {
        if ($format === 'sarif') {
            // Keep stdout empty so a redirected .sarif file is never half-valid.
            $err = $output instanceof \Symfony\Component\Console\Output\ConsoleOutputInterface ? $output->getErrorOutput() : $output;
            $err->writeln('ACQC ERROR: ' . $e->getMessage() . ' (exit ' . self::EXIT_TOOL_ERROR . ')', OutputInterface::OUTPUT_RAW);
        } elseif ($format === 'json') {
            $output->writeln(json_encode(['error' => $e->getMessage(), 'exit_code' => self::EXIT_TOOL_ERROR]));
        } elseif ($format === 'compact') {
            $output->writeln((new CompactTextExporter())->renderToolError($e->getMessage(), self::EXIT_TOOL_ERROR), OutputInterface::OUTPUT_RAW);
        } else {
            $io->error('Audit failed: ' . $e->getMessage());
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

    /** @param string[] $failOn */
    private function exitCode(array $result, array $failOn): int
    {
        return $this->failingIssues($result, $failOn) === [] ? self::EXIT_PASS : self::EXIT_GATE_FAILED;
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

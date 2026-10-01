<?php

declare(strict_types=1);

namespace ACQC\Commands;

use ACQC\Analyzers\PHP\PHPCSAnalyzer;
use ACQC\Core\Config\ConfigManager;
use ACQC\Reports\Generators\HTMLReportGenerator;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Finder\Finder;

#[AsCommand(
    name: 'phpcs:report',
    description: 'Run PHP CodeSniffer only and render the result as an ACQC rich HTML report'
)]
class PhpcsReportCommand extends Command
{
    /** Penalty-per-1000-violations weights — mirrors ReportCommand's quality score. */
    private const SCORE_WEIGHTS = [
        'blocker' => 15.0,
        'critical' => 6.0,
        'error' => 2.5,
        'warning' => 0.6,
        'info' => 0.1,
        'minor' => 0.03,
    ];

    protected function configure(): void
    {
        $this
            ->addArgument(
                'targets',
                InputArgument::IS_ARRAY | InputArgument::REQUIRED,
                'One or more directories (or files) to scan — e.g. vendor/casio vendor/casio-uk'
            )
            ->addOption('severity', 's', InputOption::VALUE_REQUIRED, 'PHPCS minimum severity (0-8)', '5')
            ->addOption('standard', null, InputOption::VALUE_REQUIRED, 'PHPCS coding standard', 'Magento2')
            ->addOption('extensions', null, InputOption::VALUE_REQUIRED, 'Comma-separated file extensions', 'php,phtml')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output HTML file path (default: reports/<name>-phpcs-report.html)')
            ->addOption('project-name', null, InputOption::VALUE_REQUIRED, 'Project name shown in the report')
            ->addOption('role', 'r', InputOption::VALUE_REQUIRED, 'Report role (developer, lead, architect, manager, executive)', 'developer')
            ->setHelp(<<<'HELP'
Runs <info>only</info> PHP CodeSniffer (no other analyzers) and renders the findings using
ACQC's rich HTML summary report — the same layout as <info>acqc report</info>.

<comment>Examples:</comment>
  <info>acqc phpcs:report vendor/casio-us</info>
  <info>acqc phpcs:report vendor/casio vendor/casio-common vendor/casio-uk vendor/casio-us --severity=8</info>
  <info>acqc phpcs:report app/code --severity=5 --output=reports/mycode.html --project-name="My Project"</info>

Requires the chosen standard to be installed in PHPCS. The bundled Magento2 standard is
auto-registered when ACQC is installed with Composer (allow-plugins is enabled).
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $targets    = $input->getArgument('targets');
        $severity   = (int) $input->getOption('severity');
        $standard   = $input->getOption('standard');
        $extensions = array_values(array_filter(array_map('trim', explode(',', $input->getOption('extensions')))));
        $role       = $input->getOption('role');

        if ($severity < 0 || $severity > 8) {
            $io->error('Severity must be between 0 and 8.');
            return Command::INVALID;
        }

        $io->title('ACQC PHPCS Report');

        // 1. Collect files across every requested target.
        $namePatterns = array_map(static fn ($ext) => '*.' . ltrim($ext, '.'), $extensions);
        $files = [];
        foreach ($targets as $target) {
            if (is_file($target)) {
                $files[] = realpath($target) ?: $target;
                continue;
            }
            if (!is_dir($target)) {
                $io->warning("Skipping missing target: {$target}");
                continue;
            }
            foreach (Finder::create()->files()->name($namePatterns)->ignoreVCS(true)->in($target) as $file) {
                $files[] = $file->getRealPath();
            }
        }
        $files = array_values(array_unique($files));

        if ($files === []) {
            $io->error('No matching files found to scan.');
            return Command::FAILURE;
        }

        $io->writeln(sprintf('Scanning <info>%d</info> files with PHPCS (%s, severity %d)...', count($files), $standard, $severity));

        // 2. Run ONLY the PHPCS analyzer.
        $phpcs = new PHPCSAnalyzer([
            'enabled'          => true,
            'standard'         => $standard,
            'extensions'       => $extensions,
            'warning_severity' => $severity,
            'error_severity'   => $severity,
            'ignore_patterns'  => [],
            'report_format'    => 'json',
        ], new NullLogger());

        // Point PHPCS at ACQC's bundled binary so the command works regardless of
        // whether `phpcs` is on the caller's PATH (it usually is not).
        $phpcsBinary = $this->resolvePhpcsBinary();
        if ($phpcsBinary !== null) {
            $phpcs->setCommand($phpcsBinary);
        }

        if (!$phpcs->isAvailable()) {
            $io->error('PHPCS is not available. Run "composer install" in the ACQC project first.');
            return Command::FAILURE;
        }

        $violations = $phpcs->analyze($files);

        // 3. Shape the data the way HTMLReportGenerator expects.
        $data = $this->buildReportData($violations, $files, $input, $targets);

        // 4. Render through ACQC's real HTML summary report generator.
        $generator = new HTMLReportGenerator(new ConfigManager(getcwd(), false), new NullLogger());
        $generator->setReportType(HTMLReportGenerator::REPORT_SUMMARY);
        $generator->setUserRole($role);

        $result = $generator->generate($data, [
            'project_path'   => implode(',', $targets),
            'project_name'   => $data['project_name'],
            'theme'          => 'default',
            'interactive'    => true,
            'max_violations' => 500,
        ]);

        if (!$result->isSuccess()) {
            $io->error('Report generation failed: ' . $result->getError());
            return Command::FAILURE;
        }

        // 5. Save.
        $outputPath = $this->resolveOutputPath($input, $data['project_name']);
        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($outputPath, $result->getContent());

        $bySeverity = $data['violations']['by_severity'];
        $io->success(sprintf('Report generated: %s', $outputPath));
        $io->definitionList(
            ['Files analyzed' => count($files)],
            ['Total findings' => $data['violations']['total_count']],
            ['Critical' => $bySeverity['critical']],
            ['Errors' => $bySeverity['error']],
            ['Warnings' => $bySeverity['warning']],
            ['Quality score' => $data['score']]
        );

        return Command::SUCCESS;
    }

    /**
     * @param array<int, \ACQC\Core\Rules\Violation> $violations
     * @param array<int, string> $files
     * @param array<int, string> $targets
     * @return array<string, mixed>
     */
    private function buildReportData(array $violations, array $files, InputInterface $input, array $targets): array
    {
        $severityBuckets = ['blocker' => 0, 'critical' => 0, 'error' => 0, 'warning' => 0, 'info' => 0, 'minor' => 0];
        $categoryBuckets = [];
        $rows = [];

        foreach ($violations as $v) {
            $sev = $v->getSeverity();
            $severityBuckets[$sev] = ($severityBuckets[$sev] ?? 0) + 1;
            // Coding-standard findings map to the "maintainability" dimension.
            $category = 'maintainability';
            $categoryBuckets[$category] = ($categoryBuckets[$category] ?? 0) + 1;

            $path = $v->getFilePath();
            $rows[] = [
                'rule'     => $v->getRuleId(),
                'message'  => $v->getMessage(),
                'file'     => $path,
                'line'     => $v->getLine(),
                'column'   => $v->getColumn(),
                'severity' => $sev,
                'type'     => $category,
                'category' => $category,
                'module'   => $this->deriveModule($path),
                'fixable'  => $v->isFixable(),
            ];
        }

        $score = $this->calculateScore($severityBuckets, $rows === []);

        $projectName = $input->getOption('project-name') ?: $this->detectProjectName($targets);

        return [
            'project_name'       => $projectName,
            'is_magento_project' => true,
            'total_files'        => count($files),
            'score'              => $score,
            'violations'         => [
                'total_count'    => count($rows),
                'by_severity'    => $severityBuckets,
                'by_category'    => $categoryBuckets,
                'top_violations' => array_slice($rows, 0, 20),
                'violations'     => $rows,
            ],
            'metrics'            => [
                'quality_score'  => $score,
                'files_analyzed' => count($files),
            ],
        ];
    }

    /** @param array<string, int> $severityBuckets */
    private function calculateScore(array $severityBuckets, bool $empty): float
    {
        if ($empty) {
            return 100.0;
        }
        $penalty = 0.0;
        foreach (self::SCORE_WEIGHTS as $sev => $weight) {
            $penalty += (($severityBuckets[$sev] ?? 0) / 1000) * $weight;
        }
        return max(0.0, min(100.0, round(100.0 - $penalty, 1)));
    }

    /** Derive a readable module label (e.g. vendor package) from a file path. */
    private function deriveModule(string $path): string
    {
        if (preg_match('#/vendor/([^/]+/[^/]+)#', $path, $m)) {
            return $m[1];
        }
        if (preg_match('#/app/code/([^/]+/[^/]+)#', $path, $m)) {
            return $m[1];
        }
        return basename(dirname($path));
    }

    /** @param array<int, string> $targets */
    private function detectProjectName(array $targets): string
    {
        $first = $targets[0] ?? '.';
        $real = realpath($first) ?: $first;
        return basename(rtrim($real, '/')) ?: 'PHPCS Analysis';
    }

    private function resolveOutputPath(InputInterface $input, string $projectName): string
    {
        $output = $input->getOption('output');
        if ($output) {
            return $output;
        }
        $slug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($projectName)) ?: 'project';
        return 'reports/' . trim($slug, '-') . '-phpcs-report.html';
    }

    /**
     * Locate the PHPCS executable bundled with ACQC so the command does not depend on
     * `phpcs` being on the caller's PATH. Covers both a dev checkout and ACQC installed
     * as a Composer dependency. Returns null to fall back to the PATH lookup.
     */
    private function resolvePhpcsBinary(): ?string
    {
        $packageRoot = dirname(__DIR__, 2);
        $candidates = [
            $packageRoot . '/vendor/bin/phpcs',                       // dev checkout
            dirname($packageRoot, 2) . '/bin/phpcs',                  // installed as vendor/adobe/acqc
            $packageRoot . '/vendor/squizlabs/php_codesniffer/bin/phpcs',
        ];
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }
        return null;
    }
}

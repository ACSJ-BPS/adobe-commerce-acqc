<?php
declare(strict_types=1);
namespace ACQC\Commands;

use ACQC\Core\Config\ConfigManager;
use ACQC\Core\Scanner\ScannerEngine;
use ACQC\Core\AnalyzerRegistry;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'watch',
    description: 'Watch files for changes and run analysis automatically (live feedback loop)'
)]
class WatchCommand extends Command
{
    private ConfigManager $configManager;
    private AnalyzerRegistry $analyzerRegistry;
    private ScannerEngine $scanner;

    public function __construct()
    {
        parent::__construct();
        $this->configManager = new ConfigManager();
        $this->analyzerRegistry = new AnalyzerRegistry($this->configManager, new NullLogger());
        $this->scanner = new ScannerEngine($this->configManager, new NullLogger());
    }

    protected function configure(): void
    {
        $this
            ->addArgument('path', InputArgument::OPTIONAL, 'Path to watch', '.')
            ->addOption('interval', null, InputOption::VALUE_OPTIONAL, 'Polling interval in seconds', '2')
            ->addOption('severity', 's', InputOption::VALUE_OPTIONAL, 'Minimum severity to report (warning/error/critical)', 'warning')
            ->addOption('analyzers', 'a', InputOption::VALUE_OPTIONAL, 'Comma-separated analyzers to run')
            ->addOption('exclude', 'e', InputOption::VALUE_OPTIONAL, 'Path patterns to exclude (comma-separated)', '*/Test/*,*/vendor/*')
            ->setHelp('Watches PHP files for changes and runs quality analysis automatically.

<info>acqc watch app/code/Vendor/Module</info>
<info>acqc watch app/code --interval=3 --severity=error</info>
<info>acqc watch . --analyzers=magento-di,magento-security</info>

Press Ctrl+C to stop watching.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $watchPath = realpath($input->getArgument('path')) ?: $input->getArgument('path');
        $interval = max(1, (int)$input->getOption('interval'));
        $excludePatterns = array_filter(array_map('trim', explode(',', $input->getOption('exclude') ?? '')));
        $severity = $input->getOption('severity');

        $io->title('ACQC Watch Mode');
        $io->writeln(sprintf('<info>Watching:</info> %s', $watchPath));
        $io->writeln(sprintf('<info>Interval:</info> %ds | <info>Severity:</info> %s', $interval, $severity));
        $io->writeln('<comment>Press Ctrl+C to stop</comment>');
        $io->newLine();

        $lastMtimes = $this->scanMtimes($watchPath, $excludePatterns);
        $runCount = 0;

        // Register signal handler for graceful exit
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, function () use ($io) {
                $io->newLine();
                $io->success('Watch mode stopped.');
                exit(0);
            });
        }

        while (true) {
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            sleep($interval);

            $currentMtimes = $this->scanMtimes($watchPath, $excludePatterns);
            $changedFiles = $this->detectChanges($lastMtimes, $currentMtimes);

            if (!empty($changedFiles)) {
                $runCount++;
                $io->writeln(sprintf(
                    '<fg=cyan>[%s] #%d — %d file(s) changed:</>',
                    date('H:i:s'), $runCount, count($changedFiles)
                ));
                foreach (array_slice($changedFiles, 0, 5) as $f) {
                    $io->writeln('  <comment>~</comment> ' . $f);
                }
                if (count($changedFiles) > 5) {
                    $io->writeln(sprintf('  ... and %d more', count($changedFiles) - 5));
                }

                $this->runAnalysis($io, $changedFiles, $input);
                $lastMtimes = $currentMtimes;
            }
        }

        return Command::SUCCESS;
    }

    private function scanMtimes(string $basePath, array $excludePatterns): array
    {
        $mtimes = [];
        if (!is_dir($basePath)) return $mtimes;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($basePath, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') continue;
            $path = str_replace('\\', '/', $file->getPathname());
            foreach ($excludePatterns as $pattern) {
                if (fnmatch($pattern, $path)) continue 2;
            }
            $mtimes[$path] = $file->getMTime();
        }
        return $mtimes;
    }

    private function detectChanges(array $old, array $new): array
    {
        $changed = [];
        foreach ($new as $path => $mtime) {
            if (!isset($old[$path]) || $old[$path] !== $mtime) {
                $changed[] = $path;
            }
        }
        return $changed;
    }

    private function runAnalysis(SymfonyStyle $io, array $files, InputInterface $input): void
    {
        $start = microtime(true);
        $severityOption = $input->getOption('severity');
        $severityMap = ['minor' => 1, 'info' => 2, 'warning' => 3, 'error' => 4, 'critical' => 5, 'blocker' => 6];
        $minSeverity = $severityMap[$severityOption] ?? 3;

        $allViolations = [];
        $analyzers = $this->analyzerRegistry->getAll();

        foreach ($files as $filePath) {
            if (!is_file($filePath)) continue;
            foreach ($analyzers as $analyzer) {
                try {
                    $result = $analyzer->analyze($filePath, []);
                    if (is_array($result)) {
                        $allViolations = array_merge($allViolations, $result);
                    }
                } catch (\Throwable $e) {
                    // Skip analyzer errors in watch mode
                }
            }
        }

        // Filter by severity
        $filtered = array_filter($allViolations, function ($v) use ($minSeverity) {
            $sev = is_array($v) ? ($v['severity'] ?? 'warning') : (method_exists($v, 'getSeverity') ? $v->getSeverity() : 'warning');
            $sevMap = ['minor' => 1, 'info' => 2, 'warning' => 3, 'error' => 4, 'critical' => 5, 'blocker' => 6];
            return ($sevMap[strtolower((string)$sev)] ?? 3) >= $minSeverity;
        });

        $elapsed = round(microtime(true) - $start, 2);

        if (empty($filtered)) {
            $io->writeln(sprintf('  <fg=green>✓ No issues found</> <fg=gray>(%ss)</>', $elapsed));
        } else {
            $io->writeln(sprintf('  <fg=red>✗ %d issue(s) found</> <fg=gray>(%ss)</>', count($filtered), $elapsed));
            foreach (array_slice(array_values($filtered), 0, 10) as $v) {
                $file = is_array($v) ? basename($v['file'] ?? '') : '';
                $line = is_array($v) ? ($v['line'] ?? '') : '';
                $sev  = is_array($v) ? strtoupper($v['severity'] ?? '') : '';
                $msg  = is_array($v) ? ($v['message'] ?? '') : '';
                $color = match(strtolower($sev)) { 'error', 'critical', 'blocker' => 'red', 'warning' => 'yellow', default => 'cyan' };
                $io->writeln(sprintf('    [<fg=%s>%s</>] %s:%s — %s', $color, $sev, $file, $line, substr($msg, 0, 80)));
            }
            if (count($filtered) > 10) {
                $io->writeln(sprintf('    ... and %d more', count($filtered) - 10));
            }
        }
        $io->newLine();
    }
}

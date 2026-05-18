<?php

declare(strict_types=1);

namespace ACQC\Commands;

use ACQC\Core\Version\VersionDetector;
use ACQC\Core\Version\MagentoVersion;
use ACQC\Core\Version\PhpVersion;
use ACQC\Core\Version\VersionRegistry;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use ACQC\Analyzers\Version\UpgradeAnalyzer;
use ACQC\Analyzers\Version\DatabaseCompatibilityAnalyzer;
use ACQC\Analyzers\Version\DBSchemaUpgradeAnalyzer;
use ACQC\Analyzers\Version\DB\DataPatchOrderAnalyzer;
use ACQC\Analyzers\Version\DB\DeclarativeSchemaParser;
use ACQC\Analyzers\Version\DB\EavAttributeChangeDetector;
use ACQC\Analyzers\Version\DB\ForeignKeyConflictDetector;
use ACQC\Analyzers\Version\DB\LegacyScriptDetector;
use ACQC\Analyzers\Version\DB\TableSizeRiskScorer;
use ACQC\Analyzers\Version\DB\WhitelistValidator;
use ACQC\Analyzers\Version\FrontendUpgradeAnalyzer;
use ACQC\Analyzers\Version\Frontend\TemplateOverrideDetector;
use ACQC\Analyzers\Version\Frontend\RequireJSMapAnalyzer;
use ACQC\Analyzers\Version\Frontend\LESSVariableAnalyzer;
use ACQC\Reports\Exporters\ExcelExporter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use ACQC\Analyzers\Magento\CoreFileIntegrityAnalyzer;
use ACQC\Analyzers\Version\B2BCompatibilityAnalyzer;
use ACQC\Analyzers\Version\CloudCompatibilityAnalyzer;
use ACQC\Core\Upgrade\FluffyJawsClient;
use ACQC\Core\Upgrade\NullRecommendationClient;
use ACQC\Core\Upgrade\UpgradeRecommendationEngine;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Comprehensive Upgrade Readiness Command
 * 
 * Analyzes code for upgrade compatibility, provides effort estimation,
 * and generates detailed upgrade roadmap.
 */
#[AsCommand(name: 'upgrade:analyze', aliases: ['upgrade-readiness'])]
class UpgradeReadinessCommand extends Command
{
    protected static $defaultName = 'upgrade:analyze';
    private LoggerInterface $logger;
    private ?UpgradeRecommendationEngine $recommendationEngine = null;

    public function __construct(?LoggerInterface $logger = null)
    {
        parent::__construct();
        $this->logger = $logger ?? new NullLogger();
    }

    protected function configure(): void
    {
        $this
            ->setName('upgrade:analyze')
            ->setAliases(['upgrade-readiness'])
            ->setDescription('Comprehensive upgrade compatibility analysis with effort estimation')
            ->addArgument(
                'path',
                InputArgument::OPTIONAL,
                'Path to Magento project or module (optional in --simulate mode)'
            )
            ->addOption(
                'current-version',
                null,
                InputOption::VALUE_REQUIRED,
                'Current Magento version (or "auto" to detect)'
            )
            ->addOption(
                'target-version',
                null,
                InputOption::VALUE_REQUIRED,
                'Target Magento version (e.g., 2.4.8-p2)',
                '2.4.8'
            )
            ->addOption(
                'target',
                null,
                InputOption::VALUE_REQUIRED,
                'Target Magento version — short alias for --target-version'
            )
            ->addOption(
                'current-php',
                null,
                InputOption::VALUE_REQUIRED,
                'Current PHP version (or "auto" to detect)'
            )
            ->addOption(
                'target-php',
                null,
                InputOption::VALUE_REQUIRED,
                'Target PHP version (Commerce 2.4.7+ supports 8.2/8.3/8.4)',
                '8.4'
            )
            ->addOption(
                'include-database',
                'd',
                InputOption::VALUE_NONE,
                'Include database compatibility analysis'
            )
            ->addOption(
                'no-db-scan',
                null,
                InputOption::VALUE_NONE,
                'Skip the full DB schema upgrade analysis (LegacyScriptDetector, WhitelistValidator, etc.)'
            )
            ->addOption(
                'db-scan',
                null,
                InputOption::VALUE_NONE,
                'Explicitly enable full DB schema upgrade analysis (on by default; negated by --no-db-scan)'
            )
            ->addOption(
                'frontend-scan',
                null,
                InputOption::VALUE_NONE,
                'Include frontend analysis (template overrides, RequireJS maps, LESS variables)'
            )
            ->addOption(
                'composer-deep',
                null,
                InputOption::VALUE_NONE,
                'Run deep Composer analysis: transitive deps, abandoned packages, and security advisories (slower — makes network calls)'
            )
            ->addOption(
                'compare-versions',
                null,
                InputOption::VALUE_REQUIRED,
                'Compare URI scores across multiple target versions (comma-separated, e.g. "2.4.6,2.4.7,2.4.8")'
            )
            ->addOption(
                'format',
                'f',
                InputOption::VALUE_REQUIRED,
                'Output format (console, json, html, excel)',
                'console'
            )
            ->addOption(
                'export',
                null,
                InputOption::VALUE_REQUIRED,
                'Export to one or more formats (comma-separated: html,excel)'
            )
            ->addOption(
                'output',
                'o',
                InputOption::VALUE_REQUIRED,
                'Output file path'
            )
            ->addOption(
                'generate-report',
                'r',
                InputOption::VALUE_NONE,
                'Generate detailed report'
            )
            ->addOption(
                'priority',
                'p',
                InputOption::VALUE_REQUIRED,
                'Filter issues by priority (critical, high, medium, low) - comma-separated',
                null
            )
            ->addOption(
                'min-priority',
                null,
                InputOption::VALUE_REQUIRED,
                'Show only issues of this priority and above (critical > high > medium > low)',
                null
            )
            ->addOption(
                'issue-type',
                't',
                InputOption::VALUE_REQUIRED,
                'Filter by issue type (deprecated, removed, incompatible, breaking-change) - comma-separated',
                null
            )
            ->addOption(
                'upgrade-recommendations',
                null,
                InputOption::VALUE_NONE,
                'Fetch per-finding upgrade recommendations (uses FluffyJaws if ACQC_IMS_TOKEN is set, otherwise static templates)'
            )
            ->addOption(
                'simulate',
                null,
                InputOption::VALUE_NONE,
                'Simulation mode — run registry-based checks only; no project files required or modified'
            )
            ->addOption(
                'b2b',
                null,
                InputOption::VALUE_NONE,
                'Force B2B compatibility analysis (default: auto-detect from composer.json)'
            )
            ->addOption(
                'cloud',
                null,
                InputOption::VALUE_NONE,
                'Force Cloud/ECE-Tools compatibility analysis (default: auto-detect from .magento.app.yaml)'
            )
            ->addOption(
                'save',
                null,
                InputOption::VALUE_NONE,
                'Persist scan results to the ACQC dashboard via the API (requires --project-id)'
            )
            ->addOption(
                'project-id',
                null,
                InputOption::VALUE_REQUIRED,
                'ACQC project ID to associate results with (required when --save is used)'
            )
            ->setHelp(<<<'HELP'
The <info>upgrade-readiness</info> command provides comprehensive upgrade compatibility analysis:

<comment>Basic Usage:</comment>
  <info>php bin/acqc upgrade-readiness /path/to/magento --target-version 2.4.8-p2</info>

<comment>Auto-detect current version:</comment>
  <info>php bin/acqc upgrade-readiness /path/to/magento \
    --current-version auto \
    --target-version 2.4.8</info>

<comment>Include database analysis:</comment>
  <info>php bin/acqc upgrade-readiness /path/to/magento \
    --target-version 2.4.8 \
    --include-database</info>

<comment>Generate detailed HTML report:</comment>
  <info>php bin/acqc upgrade-readiness /path/to/magento \
    --target-version 2.4.8 \
    --generate-report \
    --format html \
    --output reports/upgrade-readiness.html</info>

The command provides:
  • Code compatibility analysis (60+ deprecation patterns)
  • PHP compatibility checking
  • Database migration analysis (optional)
  • Priority-based issue categorization (Critical/High/Medium/Low)
  • Granular 10-category effort estimation with realistic ranges
  • Enterprise HTML report with drilldowns and risk heatmap
  • Priority action plan and upgrade roadmap
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = $input->getArgument('path');
        $format = $input->getOption('format');
        $simulate = (bool) $input->getOption('simulate');

        if ($simulate && $path === null) {
            $path = getcwd();
        }

        // --target is a short alias for --target-version
        if ($input->getOption('target') !== null && $input->getOption('target-version') === '2.4.8') {
            $input->setOption('target-version', $input->getOption('target'));
        }

        $io->title($simulate ? '[SIMULATION] ACQC Upgrade Readiness Analysis' : 'ACQC Upgrade Readiness Analysis');

        if ($simulate) {
            $io->writeln('<fg=cyan;options=bold>=== SIMULATION MODE — No project files will be modified ===</>');
            $io->writeln('');

            $acqcRootSim = dirname(dirname(__DIR__));
            $syncer = new \ACQC\Core\Version\VersionFeedSyncer(
                $acqcRootSim . '/config/versions/adobe-commerce',
                $this->logger,
            );
            try {
                $syncResult = $syncer->sync(['packagist']);
                if ($syncResult->isOffline) {
                    $io->warning('Network unavailable — continuing with cached version registry.');
                }
            } catch (\Throwable $e) {
                $io->warning('Version feed sync failed: ' . $e->getMessage() . ' — continuing with cached registry.');
            }
        }

        // Detect or parse versions
        $detector = new VersionDetector();
        
        $io->section('Detecting Versions...');
        
        // Current versions
        $currentVersion = $this->parseCurrentVersion(
            $input->getOption('current-version'),
            $path,
            $detector
        );
        
        $currentPhp = $this->parseCurrentPhp(
            $input->getOption('current-php'),
            $detector
        );

        // Target versions
        $targetVersion = $this->parseTargetVersion($input->getOption('target-version'));
        $targetPhp = $this->parseTargetPhp($input->getOption('target-php'));

        if (!$currentVersion) {
            $io->error('Could not detect current Magento version. Please specify with --current-version');
            return Command::FAILURE;
        }

        // Display version information
        $this->displayVersionInfo($io, $currentVersion, $targetVersion, $currentPhp, $targetPhp);

        // EOL warning: emit before analysis if current version is past its EOL date
        $this->emitEolWarningIfNeeded($io, $currentVersion->toString());

        // Guard: same version — code health scan only, no upgrade roadmap needed
        if ($currentVersion->isEqual($targetVersion)) {
            $io->warning([
                'Current and target versions are identical — no upgrade required.',
                'Running code health scan to identify technical debt and improvement opportunities.',
            ]);
        }

        // Perform upgrade analysis
        $io->section('Analyzing Upgrade Compatibility...');
        
        $analyzer = new UpgradeAnalyzer();
        $result = $analyzer->analyzeUpgrade(
            $path,
            $currentVersion,
            $targetVersion,
            $currentPhp,
            $targetPhp
        );

        // Include database analysis if requested
        if ($input->getOption('include-database')) {
            $io->text('Including database compatibility analysis...');
            $dbAnalyzer = new DatabaseCompatibilityAnalyzer();
            $dbIssues = $dbAnalyzer->analyzeDatabaseCompatibility(
                $path,
                $currentVersion,
                $targetVersion
            );
            $result->setDatabaseIssues($dbIssues);
            $result->setDatabaseMigrations($dbIssues['effort_estimation'] ?? []);
        }

        // Full DB schema upgrade analysis (legacy scripts, whitelist, large-table risk, declarative schema)
        if ($simulate) {
            $io->writeln('  <fg=yellow>⚠ DBSchemaUpgradeAnalyzer skipped in simulation mode (requires local codebase)</>');
        } elseif (!$input->getOption('no-db-scan')) {
            $io->text('Running DB schema upgrade analysis...');
            $acqcRoot = dirname(dirname(__DIR__));
            $registryPath = $acqcRoot . '/config/versions/adobe-commerce';
            $registry = new VersionRegistry($registryPath, new ArrayAdapter());
            // Pass vanilla path so DeclarativeSchemaParser can also detect undocumented schema collisions
            $vanillaPathForDb = $acqcRoot . '/var/vanilla/' . $targetVersion->toString();
            $vanillaPathForDb = is_dir($vanillaPathForDb) ? $vanillaPathForDb : null;
            $dbSchemaAnalyzer = new DBSchemaUpgradeAnalyzer(
                new LegacyScriptDetector($path),
                new WhitelistValidator($path),
                new TableSizeRiskScorer($registry, $acqcRoot . '/config/upgrade/large-tables.yaml'),
                new DeclarativeSchemaParser($path, $registry, $vanillaPathForDb),
                new DataPatchOrderAnalyzer($path),
                new ForeignKeyConflictDetector($path, $registry),
                new EavAttributeChangeDetector($path),
            );
            $dbSchemaResult = $dbSchemaAnalyzer->analyze($path, $currentVersion->toString(), $targetVersion->toString());

            if ($dbSchemaResult->totalFindings() > 0) {
                $io->section('DATABASE SCHEMA ANALYSIS');
                $blockerCount = 0;
                $warnCount    = 0;
                foreach ($dbSchemaResult->findings as $finding) {
                    $severity = $finding['severity'] ?? 'info';
                    $msg      = $finding['message'] ?? '';
                    $file     = isset($finding['file']) ? (' [' . $this->truncatePath($finding['file'], 80) . ']') : '';
                    if ($severity === 'error') {
                        $blockerCount++;
                        $io->writeln("  <fg=red>[ERROR]</> {$msg}{$file}");
                    } else {
                        $warnCount++;
                        $io->writeln("  <fg=yellow>[WARN]</>  {$msg}{$file}");
                    }
                    if (!empty($finding['remediation'])) {
                        $io->writeln("         <fg=cyan>Fix:</> {$finding['remediation']}");
                    }
                }
                $io->writeln('');
                $io->writeln(sprintf(
                    '  DB Schema: <fg=red>%d error(s)</>, <fg=yellow>%d warning(s)</> | Est. effort: %d–%d h',
                    $blockerCount,
                    $warnCount,
                    $dbSchemaResult->estimatedHoursMin,
                    $dbSchemaResult->estimatedHoursMax,
                ));
            } else {
                $io->writeln('  <fg=green>✓</> No DB schema upgrade issues found.');
            }
        }

        // Frontend analysis (opt-in due to performance — scans app/design/)
        if ($simulate) {
            $io->writeln('  <fg=yellow>⚠ FrontendUpgradeAnalyzer skipped in simulation mode (requires local codebase)</>');
        } elseif ($input->getOption('frontend-scan')) {
            $io->text('Running frontend upgrade analysis (template overrides, RequireJS, LESS)...');
            $acqcRootFe = dirname(dirname(__DIR__));
            $frontendAnalyzer = new FrontendUpgradeAnalyzer(
                new TemplateOverrideDetector($path, $acqcRootFe . '/config/upgrade/template-changes.yaml'),
                new RequireJSMapAnalyzer($path, $acqcRootFe . '/config/upgrade/js-library-changes.yaml'),
                new LESSVariableAnalyzer($path, $acqcRootFe . '/config/upgrade/less-variable-changes.yaml'),
            );
            $frontendResult = $frontendAnalyzer->analyze($path, $targetVersion->toString());

            if ($frontendResult->totalFindings() > 0) {
                $io->section('FRONTEND ANALYSIS');
                $io->writeln(sprintf(
                    '  Template overrides: <fg=cyan>%d</>, RequireJS configs: <fg=cyan>%d</>, LESS variables: <fg=cyan>%d</>',
                    $frontendResult->templateOverrideCount,
                    $frontendResult->requireJSMapCount,
                    $frontendResult->lessVariableCount,
                ));
                foreach ($frontendResult->findings as $finding) {
                    $severity = $finding['severity'] ?? 'info';
                    $msg      = $finding['message'] ?? '';
                    $prefix   = match ($severity) {
                        'warning' => '<fg=yellow>[WARN]</>',
                        'error'   => '<fg=red>[ERROR]</>',
                        default   => '<fg=cyan>[INFO]</>',
                    };
                    $io->writeln("  {$prefix} {$msg}");
                    if (!empty($finding['remediation'])) {
                        $io->writeln("         <fg=cyan>Fix:</> {$finding['remediation']}");
                    }
                }
                if ($frontendResult->estimatedHoursMax > 0) {
                    $io->writeln('');
                    $io->writeln(sprintf(
                        '  Frontend est. effort: %d–%d h',
                        $frontendResult->estimatedHoursMin,
                        $frontendResult->estimatedHoursMax,
                    ));
                }
            } else {
                $io->writeln('  <fg=green>✓</> No frontend customizations detected.');
            }
        } else {
            $io->writeln('  <fg=gray>Tip: Rerun with --frontend-scan to include template and JS analysis.</>');
        }

        // Composer deep analysis (opt-in due to network calls and transitive resolution cost)
        if ($simulate) {
            $io->writeln('  <fg=yellow>⚠ ComposerDeepAnalyzer skipped in simulation mode (requires local codebase)</>');
        } elseif ($input->getOption('composer-deep')) {
            $io->text('Running Composer deep analysis (transitive deps, abandoned packages, security advisories)...');
            $acqcRootCmp = dirname(dirname(__DIR__));
            $composerDeepAnalyzer = new \ACQC\Analyzers\Version\ComposerDeepAnalyzer(
                new \ACQC\Analyzers\Version\Composer\TransitiveDependencyResolver($path),
                new \ACQC\Analyzers\Version\Composer\AbandonedPackageDetector(
                    $acqcRootCmp . '/var/cache/composer-abandoned/'
                ),
                new \ACQC\Analyzers\Version\Composer\SecurityAdvisoryChecker(
                    $acqcRootCmp . '/config/upgrade/security-advisories/'
                ),
                new \ACQC\Analyzers\Version\Composer\PlatformConstraintValidator(
                    new VersionRegistry(
                        $acqcRootCmp . '/config/versions/adobe-commerce',
                        new ArrayAdapter()
                    )
                ),
            );
            $composerDeepResult = $composerDeepAnalyzer->analyze($path, $targetVersion->toString());

            $io->section('COMPOSER DEEP ANALYSIS');
            $io->writeln(sprintf(
                '  Packages: <fg=cyan>%d</> direct, <fg=cyan>%d</> transitive | '
                    . 'Abandoned: <fg=yellow>%d</> | Security advisories: <fg=red>%d</>',
                $composerDeepResult->packageCount,
                $composerDeepResult->transitivePackageCount,
                $composerDeepResult->abandonedCount,
                $composerDeepResult->securityAdvisoryCount,
            ));

            if ($composerDeepResult->totalFindings() > 0) {
                foreach ($composerDeepResult->findings as $finding) {
                    $sev    = $finding['severity'] ?? 'info';
                    $msg    = $finding['message'] ?? '';
                    $prefix = match ($sev) {
                        'error'   => '<fg=red>[ERROR]</>',
                        'warning' => '<fg=yellow>[WARN]</>',
                        default   => '<fg=cyan>[INFO]</>',
                    };
                    $io->writeln("  {$prefix} {$msg}");
                }
                $io->writeln('');
                $io->writeln(sprintf(
                    '  Composer deep est. effort: %d–%d h',
                    $composerDeepResult->estimatedHoursMin,
                    $composerDeepResult->estimatedHoursMax,
                ));
            } else {
                $io->writeln('  <fg=green>✓</> No Composer deep issues found.');
            }
        } else {
            $io->writeln(
                '  <fg=gray>Tip: Rerun with --composer-deep for transitive dependency and security advisory analysis.</>'
            );
        }

        // Core file integrity analysis — MD5 comparison against vanilla (requires version:download)
        $coreIntegrityViolations = [];
        if (!$simulate) {
            $acqcRootCfi    = dirname(dirname(__DIR__));
            $vanillaPathCfi = $acqcRootCfi . '/var/vanilla/' . $targetVersion->toString();
            if (is_dir($vanillaPathCfi)) {
                $io->text('Running core file integrity check (comparing against vanilla ' . $targetVersion->toString() . ')...');
                $coreIntegrityAnalyzer  = new CoreFileIntegrityAnalyzer([
                    'target_magento_version' => $targetVersion->toString(),
                    'vanilla_base_path'      => $vanillaPathCfi,
                    'vendor_path'            => $path . '/vendor',
                ]);
                $coreIntegrityViolations = $coreIntegrityAnalyzer->analyze([]);

                if (!empty($coreIntegrityViolations)) {
                    $io->section('CORE FILE INTEGRITY');
                    $modifiedCount = 0;
                    $addedCount    = 0;
                    foreach ($coreIntegrityViolations as $v) {
                        if ($v->getRuleId() === 'magento-core-file-modified') {
                            $modifiedCount++;
                            $io->writeln('  <fg=red>[CRITICAL]</> ' . $v->getMessage());
                        } else {
                            $addedCount++;
                            $io->writeln('  <fg=yellow>[ERROR]</> ' . $v->getMessage());
                        }
                    }
                    $io->writeln(sprintf(
                        '  Core file integrity: <fg=red>%d modified</>, <fg=yellow>%d added</> in core packages',
                        $modifiedCount, $addedCount
                    ));
                } else {
                    $io->writeln('  <fg=green>✓</> Core files unmodified (verified against vanilla ' . $targetVersion->toString() . ').');
                }
            } else {
                $io->writeln(
                    '  <fg=gray>Tip: Run `acqc version:download ' . $targetVersion->toString()
                    . '` to enable core file integrity checking (detects modified core vendor files).</>'
                );
            }
        }

        // B2B compatibility analysis
        $acqcRoot = dirname(dirname(__DIR__));
        $runB2b   = $input->getOption('b2b') || B2BCompatibilityAnalyzer::isInstalled($path);
        if ($runB2b) {
            if ($simulate) {
                $io->writeln('  <fg=yellow>⚠ B2BCompatibilityAnalyzer skipped in simulation mode (requires local codebase)</>');
            } else {
                $b2bAnalyzer = new B2BCompatibilityAnalyzer($acqcRoot);
                $b2bResult   = $b2bAnalyzer->analyze($path, $targetVersion->toString());

                if ($b2bResult->installed) {
                    $io->section('B2B COMPATIBILITY');
                    $io->writeln(sprintf('  Installed B2B version: <fg=cyan>%s</>', $b2bResult->installedVersion));
                    if ($b2bResult->totalFindings() > 0) {
                        foreach ($b2bResult->findings as $finding) {
                            $severity = $finding['severity'] ?? 'info';
                            $msg      = $finding['message'] ?? '';
                            $prefix   = match ($severity) {
                                'error'   => '<fg=red>[ERROR]</>',
                                'warning' => '<fg=yellow>[WARN]</>',
                                default   => '<fg=cyan>[INFO]</>',
                            };
                            $io->writeln("  {$prefix} {$msg}");
                            if (!empty($finding['remediation'])) {
                                $io->writeln("         <fg=cyan>Fix:</> {$finding['remediation']}");
                            }
                        }
                    } else {
                        $io->writeln('  <fg=green>✓</> B2B is compatible with target AC version.');
                    }
                }
            }
        }

        // Cloud / ECE-Tools compatibility analysis
        $runCloud = $input->getOption('cloud') || CloudCompatibilityAnalyzer::isCloudProject($path);
        if ($runCloud) {
            if ($simulate) {
                $io->writeln('  <fg=yellow>⚠ CloudCompatibilityAnalyzer skipped in simulation mode (requires local codebase)</>');
            } else {
                $cloudAnalyzer = new CloudCompatibilityAnalyzer($acqcRoot);
                $cloudResult   = $cloudAnalyzer->analyze($path, $targetVersion->toString());

                if ($cloudResult->isCloud) {
                    $io->section('CLOUD ENVIRONMENT');
                    $io->writeln(sprintf(
                        '  PHP (app.yaml): <fg=cyan>%s</>  ECE-Tools: <fg=cyan>%s</>  Search: <fg=cyan>%s</>',
                        $cloudResult->detectedPhpVersion ?? 'n/a',
                        $cloudResult->eceToolsVersion    ?? 'n/a',
                        $cloudResult->configuredSearchEngine ?? 'n/a',
                    ));
                    if ($cloudResult->totalFindings() > 0) {
                        foreach ($cloudResult->findings as $finding) {
                            $severity = $finding['severity'] ?? 'info';
                            $msg      = $finding['message'] ?? '';
                            $prefix   = match ($severity) {
                                'error'   => '<fg=red>[ERROR]</>',
                                'warning' => '<fg=yellow>[WARN]</>',
                                default   => '<fg=cyan>[INFO]</>',
                            };
                            $io->writeln("  {$prefix} {$msg}");
                            if (!empty($finding['remediation'])) {
                                $io->writeln("         <fg=cyan>Fix:</> {$finding['remediation']}");
                            }
                        }
                    } else {
                        $io->writeln('  <fg=green>✓</> Cloud environment configuration is compatible.');
                    }
                }
            }
        }

        // Collect all findings from every analyzer for URI calculation
        $allFindings = [];
        foreach ($result->getPrioritizedIssues() as $issues) {
            foreach ($issues as $issue) {
                $allFindings[] = $issue;
            }
        }
        if (isset($dbSchemaResult)) {
            $allFindings = array_merge($allFindings, $dbSchemaResult->findings);
        }
        if (isset($frontendResult)) {
            $allFindings = array_merge($allFindings, $frontendResult->findings);
        }
        if (isset($composerDeepResult)) {
            $allFindings = array_merge($allFindings, $composerDeepResult->findings);
        }
        if (isset($b2bResult)) {
            $allFindings = array_merge($allFindings, $b2bResult->findings);
        }
        if (isset($cloudResult)) {
            $allFindings = array_merge($allFindings, $cloudResult->findings);
        }
        // CoreFileIntegrityAnalyzer violations are Violation objects; wrap them as finding arrays for URI
        foreach ($coreIntegrityViolations as $v) {
            $allFindings[] = [
                'severity' => $v->getSeverity() === 'critical' ? 'error' : $v->getSeverity(),
                'message'  => $v->getMessage(),
                'type'     => $v->getRuleId(),
                'file'     => $v->getFile(),
            ];
        }

        // Initialise upgrade recommendation engine (always; falls back to static templates)
        if ($input->getOption('upgrade-recommendations')) {
            $recClient = FluffyJawsClient::fromEnv($this->logger);
            if (!$recClient->isConfigured()) {
                $recClient = new NullRecommendationClient();
                $output->writeln('<comment>FluffyJaws not configured (ACQC_IMS_TOKEN not set). Using static upgrade recommendations.</comment>');
            }
            $this->recommendationEngine = new UpgradeRecommendationEngine($recClient, new ArrayAdapter());
        } else {
            $io->writeln('  <fg=gray>Tip: Run with --upgrade-recommendations to get per-finding upgrade guidance.</>');
        }

        // Estimate LOC from custom code directory
        $customLoc = $this->estimateCustomLoc($path);
        $uri = (new \ACQC\Core\Upgrade\UpgradeRiskIndex())->calculate($allFindings, 0, $customLoc);

        // Display URI prominently in console
        $io->section('UPGRADE RISK INDEX');
        $gradeColor = match ($uri->grade) {
            'A' => 'green', 'B' => 'blue', 'C' => 'yellow', 'D' => 'red', default => 'magenta',
        };
        $io->writeln(sprintf(
            '  <fg=white;options=bold>URI Score: <fg=%s;options=bold>%s / 100</>  Grade: <fg=%s;options=bold>%s</>  (%s)</>',
            $gradeColor, $uri->score, $gradeColor, $uri->grade, $uri->gradeDescription
        ));
        $io->writeln(sprintf(
            '  Engineering: <fg=cyan>%dh</>  QA: <fg=cyan>%dh</>  Infra: <fg=cyan>%dh</>  Total w/ contingency: <fg=yellow>%dh</>',
            $uri->engineeringHours, $uri->qaHours, $uri->infraHours, $uri->totalHoursWithContingency
        ));
        $io->writeln(sprintf(
            '  Recommended timeline: <fg=cyan>%s</>  |  Earliest go-live: <fg=green>%s</>',
            $uri->recommendedTimeline, $uri->goLiveRecommendation
        ));

        if (!empty($uri->sprintBreakdown)) {
            $io->writeln('');
            $io->writeln('  <options=bold>SPRINT PLAN:</>');
            foreach ($uri->sprintBreakdown as $sprint) {
                $findingCount = count($sprint['findings'] ?? []);
                $io->writeln(sprintf(
                    '    Sprint %d — %s: %s findings, %dh',
                    $sprint['sprint'],
                    $sprint['name'],
                    $findingCount > 0 ? $findingCount : 'no',
                    $sprint['total_hours'] ?? 0,
                ));
            }
        }
        $io->writeln('');

        // Cache full-scan scores before any filtering changes the issue set
        $fullScanScore     = $result->getUpgradeReadinessScore();
        $fullScanRisk      = $result->getUpgradeRiskLevel();
        $fullTotalIssues   = $result->getTotalIssueCount();
        $fullCriticalCount = $result->getIssueCountByPriority('critical');
        $fullHighCount     = $result->getIssueCountByPriority('high');
        $fullMediumCount   = $result->getIssueCountByPriority('medium');
        $fullLowCount      = $result->getIssueCountByPriority('low');

        // Apply filters if specified
        $this->applyFilters($result, $input);

        // Store full-scan stats on the result for display (filters must not alter the headline score)
        $result->setFullScanStats([
            'score'    => $fullScanScore,
            'risk'     => $fullScanRisk,
            'total'    => $fullTotalIssues,
            'critical' => $fullCriticalCount,
            'high'     => $fullHighCount,
            'medium'   => $fullMediumCount,
            'low'      => $fullLowCount,
        ]);

        // Display results based on format
        if ($format === 'json') {
            $this->outputJson($output, $result, $input->getOption('output'));
        } elseif ($format === 'excel') {
            $excelRecs = null;
            if ($this->recommendationEngine !== null) {
                $excelRecs = $this->recommendationEngine->batchGetRecommendations($allFindings);
            }
            $this->outputExcel(
                $io, $result, $input->getOption('output'),
                $uri,
                $dbSchemaResult ?? null,
                $composerDeepResult ?? null,
                $excelRecs,
            );
        } elseif ($format === 'html' && $input->getOption('generate-report')) {
            $this->generateHtmlReport(
                $result,
                $input->getOption('output') ?? 'upgrade-readiness.html',
                $uri,
                $dbSchemaResult ?? null,
                $composerDeepResult ?? null,
            );
            $io->success('HTML report generated: ' . ($input->getOption('output') ?? 'upgrade-readiness.html'));
        } else {
            $this->displayConsoleOutput($io, $result, $input);
        }

        // --export: generate one or more file formats (html,excel)
        $exportFormats = array_filter(array_map('trim', explode(',', $input->getOption('export') ?? '')));
        foreach ($exportFormats as $exportFormat) {
            if ($exportFormat === 'excel') {
                $excelRecs = $this->recommendationEngine?->batchGetRecommendations($allFindings);
                $this->outputExcel($io, $result, null, $uri, $dbSchemaResult ?? null, $composerDeepResult ?? null, $excelRecs);
            } elseif ($exportFormat === 'html') {
                $htmlFile = 'upgrade-report.html';
                $this->generateHtmlReport($result, $htmlFile, $uri, $dbSchemaResult ?? null, $composerDeepResult ?? null);
                $io->success("HTML report generated: {$htmlFile}");
            }
        }

        // --compare-versions: show comparison table if requested
        if ($input->getOption('compare-versions')) {
            $this->runVersionComparison($io, $input, $path, $currentVersion, $currentPhp, $targetPhp);
        }

        // --save: persist scan results to ACQC dashboard
        if ($input->getOption('save')) {
            $projectId = $input->getOption('project-id');
            if (!$projectId) {
                $io->warning('--save requires --project-id. Results not persisted.');
            } else {
                $this->saveScanToDashboard($io, $uri, $allFindings, $projectId);
            }
        }

        // Simulation mode footer
        if ($simulate) {
            $io->writeln('');
            $io->writeln(
                '<fg=cyan>This is a simulation based on version manifests. '
                . 'Run without --simulate on actual codebase for file-level findings.</>'
            );
        }

        return Command::SUCCESS;
    }

    private function saveScanToDashboard(
        SymfonyStyle $io,
        \ACQC\Core\Upgrade\URIScore $uri,
        array $allFindings,
        string $projectId,
    ): void {
        $apiUrl = rtrim((string) getenv('ACQC_API_URL'), '/');
        if ($apiUrl === '') {
            $io->warning('Could not save results to dashboard: ACQC_API_URL environment variable is not set.');
            return;
        }

        $endpoint = "{$apiUrl}/api/v1/projects/{$projectId}/upgrade-scans";

        $severityCounts = array_count_values(array_column($allFindings, 'severity'));
        $payload = json_encode([
            'uri_score'          => $uri->score,
            'uri_grade'          => $uri->grade,
            'effort_hours_min'   => $uri->engineeringHours,
            'effort_hours_max'   => $uri->totalHoursWithContingency,
            'finding_count'      => count($allFindings),
            'error_count'        => $severityCounts['error'] ?? 0,
            'warning_count'      => $severityCounts['warning'] ?? 0,
            'notice_count'       => $severityCounts['notice'] ?? 0,
            'findings'           => $allFindings,
        ], JSON_THROW_ON_ERROR);

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => 15,
        ]);

        $responseBody = curl_exec($ch);
        $httpCode     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError    = curl_error($ch);
        curl_close($ch);

        if ($curlError !== '') {
            $io->warning("Could not save results to dashboard: {$curlError}");
            return;
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $io->warning("Could not save results to dashboard: HTTP {$httpCode} — {$responseBody}");
            return;
        }

        $response = json_decode((string) $responseBody, true);
        $scanId   = $response['data']['id'] ?? $response['id'] ?? 'unknown';

        $io->writeln("<fg=green>Results saved to ACQC dashboard (Scan ID: {$scanId})</>");
        $io->writeln("<fg=cyan>View at: {$apiUrl}/upgrade-planning?project={$projectId}</>");
    }

    private function emitEolWarningIfNeeded(SymfonyStyle $io, string $currentVersion): void
    {
        try {
            $basePath = dirname(dirname(__DIR__));
            $registryPath = $basePath . '/config/versions/adobe-commerce';
            $registry = new VersionRegistry($registryPath, new ArrayAdapter());

            if ($registry->isEndOfLife($currentVersion)) {
                $eolDate = $registry->getEolDate($currentVersion);
                $io->warning([
                    "WARNING: Adobe Commerce {$currentVersion} is past its End-of-Life date "
                        . ($eolDate?->format('Y-m-d') ?? '(unknown)') . '.',
                    'This version no longer receives security patches from Adobe.',
                    'Upgrading is strongly recommended before proceeding with further development.',
                ]);
            }
        } catch (\Throwable) {
            // Registry may not have manifest for this version; silently skip EOL check
        }
    }

    /**
     * Display version information
     */
    private function displayVersionInfo(
        SymfonyStyle $io,
        MagentoVersion $current,
        MagentoVersion $target,
        PhpVersion $currentPhp,
        PhpVersion $targetPhp
    ): void {
        $table = new Table($io);
        $table->setHeaders(['Component', 'Current Version', 'Target Version', 'Change']);
        $table->addRows([
            [
                'Magento',
                $current->toString(),
                $target->toString(),
                $this->getVersionChange($current, $target)
            ],
            [
                'PHP',
                $currentPhp->toString(),
                $targetPhp->toString(),
                $this->getPhpVersionChange($currentPhp, $targetPhp)
            ],
        ]);
        $table->render();
    }

    /**
     * Display console output
     */
    private function displayConsoleOutput(
        SymfonyStyle $io,
        $result,
        InputInterface $input
    ): void {
        // Executive Summary — always uses full-scan stats (unaffected by display filters)
        $io->section('📊 Executive Summary');

        $stats = $result->getFullScanStats();
        $readinessScore  = $stats['score']    ?? $result->getUpgradeReadinessScore();
        $riskLevel       = $stats['risk']     ?? $result->getUpgradeRiskLevel();
        $upgradePossible = $result->isUpgradePossible();
        $totalIssues     = $stats['total']    ?? $result->getTotalIssueCount();
        $criticalCount   = $stats['critical'] ?? $result->getIssueCountByPriority('critical');
        $highCount       = $stats['high']     ?? $result->getIssueCountByPriority('high');
        $mediumCount     = $stats['medium']   ?? $result->getIssueCountByPriority('medium');
        $lowCount        = $stats['low']      ?? $result->getIssueCountByPriority('low');

        $filterActive = $input->getOption('priority') || $input->getOption('min-priority') || $input->getOption('issue-type');

        $io->definitionList(
            ['Readiness Score' => $this->formatReadinessScore($readinessScore)],
            ['Risk Level' => $this->formatRiskLevel($riskLevel)],
            ['Upgrade Possible' => $upgradePossible ? '✅ Yes' : '❌ No (blockers present)'],
            ['Total Issues' => $totalIssues . ($filterActive ? ' (full scan)' : '')],
            ['Critical Issues' => $criticalCount],
            ['High Priority' => $highCount],
            ['Medium Priority' => $mediumCount],
            ['Low Priority' => $lowCount]
        );

        if ($filterActive) {
            $io->note('Display filter active — score and issue counts reflect the complete scan. Showing filtered issue list below.');
        }

        // Issues by Priority
        $io->section('🎯 Issues by Priority');
        $this->displayPrioritizedIssues($io, $result->getPrioritizedIssues());

        // Effort Estimation
        $io->section('⏱️  Effort Estimation');
        $this->displayEffortEstimation($io, $result->getEffortEstimation());

        // Risk Assessment
        if (!empty($result->getRiskAssessment())) {
            $io->section('⚠️  Risk Assessment');
            $this->displayRiskAssessment($io, $result->getRiskAssessment());
        }

        // Enhanced Timeline
        if (!empty($result->getEnhancedTimeline())) {
            $io->section('📅 Enhanced Timeline');
            $this->displayEnhancedTimeline($io, $result->getEnhancedTimeline());
        }

        // Database Analysis (if included)
        if ($input->getOption('include-database') && !empty($result->getDatabaseIssues())) {
            $io->section('💾 Database Compatibility');
            $this->displayDatabaseAnalysis($io, $result->getDatabaseIssues());
        }

        // Upgrade Roadmap
        $io->section('🗺️  Upgrade Roadmap');
        $this->displayUpgradeRoadmap($io, $result->getUpgradeRoadmap());

        // Recommendations
        $io->section('💡 Recommendations');
        $this->displayRecommendations($io, $result);
    }

    /**
     * Display prioritized issues
     */
    private function displayPrioritizedIssues(SymfonyStyle $io, array $prioritized): void
    {
        foreach ($prioritized as $priority => $issues) {
            if (empty($issues)) {
                continue;
            }

            $icon = $this->getPriorityIcon($priority);
            $count = count($issues);
            
            $io->writeln(sprintf(
                '%s <fg=%s>%s Priority: %d issues</>',
                $icon,
                $this->getPriorityColor($priority),
                ucfirst($priority),
                $count
            ));

            // Show detailed issues (max 10 for critical/high, 5 for others)
            $maxDisplay = ($priority === 'critical' || $priority === 'high') ? 10 : 5;
            $sample = array_slice($issues, 0, $maxDisplay);
            
            foreach ($sample as $issue) {
                $this->displayDetailedIssue($io, $issue, $priority);
            }

            if ($count > $maxDisplay) {
                $io->text(sprintf('  <comment>... and %d more %s priority issues</comment>', $count - $maxDisplay, $priority));
            }
            
            $io->newLine();
        }
    }

    /**
     * Display a single issue with full details
     */
    private function displayDetailedIssue(SymfonyStyle $io, array $issue, string $priority): void
    {
        $filePath = $issue['file'] ?? 'unknown';
        $line = $issue['line'] ?? 0;
        $message = $issue['message'] ?? 'No message';
        
        // Extract vendor and module from file path
        list($vendor, $module) = $this->extractVendorModule($filePath);
        
        // Display header with file info
        $io->writeln(sprintf(
            '  <fg=cyan>•</> <comment>%s</comment> › <info>%s</info>',
            $vendor,
            $module
        ));
        
        // Display file and line
        $io->writeln(sprintf(
            '    <fg=gray>File:</> %s<fg=gray>:%d</>',
            $this->truncatePath($filePath, 80),
            $line
        ));
        
        // Display issue description
        $io->writeln(sprintf(
            '    <fg=gray>Issue:</> %s',
            $message
        ));
        
        // Display risk explanation based on issue type
        $riskExplanation = $this->getRiskExplanation($issue, $priority);
        if ($riskExplanation) {
            $io->writeln(sprintf(
                '    <fg=yellow>Risk:</> %s',
                $riskExplanation
            ));
        }
        
        // Display fix suggestion if available
        if (isset($issue['fix']) && !empty($issue['fix'])) {
            $io->writeln(sprintf(
                '    <fg=green>Fix:</> %s',
                $issue['fix']
            ));
        }

        // Display upgrade recommendation if engine is active
        if ($this->recommendationEngine !== null) {
            $rec = $this->recommendationEngine->getRecommendation($issue);
            if ($rec !== null) {
                $source = $rec->isFromFluffyJaws ? '(FluffyJaws)' : '(static template — run with ACQC_IMS_TOKEN for live recommendations)';
                $io->writeln(sprintf('  → <fg=cyan>Recommendation:</> %s', $rec->rootCause));
                $fixLines = array_slice(explode("\n", trim($rec->codeExample)), 0, 3);
                $io->writeln('  → <fg=green>Fix:</> ' . implode(' ', array_map('trim', $fixLines)) . ' ' . $source);
            }
        }

        $io->newLine();
    }

    /**
     * Extract vendor and module name from file path
     */
    private function extractVendorModule(string $filePath): array
    {
        // Normalize path separators
        $filePath = str_replace('\\', '/', $filePath);

        // Look for app/code/Vendor/Module pattern
        if (preg_match('#app/code/([^/]+)/([^/]+)/#', $filePath, $matches)) {
            return [$matches[1], $matches[2]];
        }
        
        // Look for vendor/vendor_name/module_name pattern
        if (preg_match('#vendor/([^/]+)/([^/]+)/#', $filePath, $matches)) {
            return [$matches[1], $matches[2]];
        }
        
        // Try to extract from file path structure
        $parts = explode('/', $filePath);
        if (count($parts) >= 2) {
            return [
                $parts[count($parts) - 3] ?? 'Unknown',
                $parts[count($parts) - 2] ?? 'Unknown'
            ];
        }
        
        return ['Unknown', 'Unknown'];
    }

    /**
     * Truncate path for display
     */
    private function truncatePath(string $path, int $maxLength): string
    {
        if (strlen($path) <= $maxLength) {
            return $path;
        }
        
        $parts = explode('/', $path);
        if (count($parts) > 3) {
            return '.../' . implode('/', array_slice($parts, -3));
        }
        
        return substr($path, -$maxLength);
    }

    /**
     * Get risk explanation for an issue
     */
    private function getRiskExplanation(array $issue, string $priority): ?string
    {
        $message = strtolower($issue['message'] ?? '');
        
        // Security risks
        if (strpos($message, 'unserialize') !== false) {
            return 'Remote code execution vulnerability - attackers can execute arbitrary code';
        }
        if (strpos($message, 'serialize') !== false) {
            return 'Data integrity risk - serialization usage should be reviewed for security';
        }
        if (strpos($message, 'direct sql') !== false) {
            return 'SQL injection vulnerability - direct queries bypass Magento\'s protection';
        }
        if (strpos($message, 'objectmanager') !== false) {
            return 'Architectural violation - breaks dependency injection, causes maintenance issues';
        }
        
        // Deprecated features
        if (strpos($message, 'registry') !== false) {
            return 'Will be removed in future versions - use proper data providers instead';
        }
        if (strpos($message, 'setup script') !== false) {
            return 'Not supported in Magento 2.4+ - must convert to declarative schema';
        }
        if (strpos($message, 'zend') !== false) {
            return 'Zend Framework replaced by Laminas - code will fail in Magento 2.4+';
        }
        
        // Model/Repository issues
        if (strpos($message, 'direct model') !== false) {
            return 'API contract violation - use repositories for data access';
        }
        if (strpos($message, 'collection') !== false) {
            return 'Performance impact - inefficient data loading, can cause slow page loads';
        }
        
        // PHP compatibility
        if (strpos($message, 'each()') !== false) {
            return 'PHP 8.0 incompatibility - function removed, will cause fatal error';
        }
        if (strpos($message, 'create_function') !== false) {
            return 'PHP 8.0 incompatibility - deprecated function, use closures instead';
        }
        
        // Default explanations by priority
        $defaults = [
            'critical' => 'High impact issue requiring immediate attention before upgrade',
            'high' => 'Significant issue that should be resolved before upgrade',
            'medium' => 'Moderate issue recommended to fix during upgrade',
            'low' => 'Minor issue that can be addressed after upgrade'
        ];
        
        return $defaults[$priority] ?? null;
    }
    /**
     * Display granular 10-category effort estimation
     */
    private function displayEffortEstimation(SymfonyStyle $io, array $estimation): void
    {
        if (empty($estimation)) {
            $io->warning('Effort estimation not available');
            return;
        }

        $totals = $estimation['totals'] ?? [];
        $io->definitionList(
            ['Ideal Total Effort' => sprintf('<info>%.1f hours (%d days)</info>', $totals['ideal_hours'] ?? 0, $totals['ideal_days'] ?? 0)],
            ['Realistic Range'    => sprintf('%s–%s hours', $totals['range_min_hours'] ?? 0, $totals['range_max_hours'] ?? 0)],
            ['Estimated Timeline' => sprintf('%d–%d weeks', $totals['ideal_weeks'] ?? 0, ceil(($totals['range_days_max'] ?? 0) / 5))],
            ['Overall Complexity' => strtoupper($totals['overall_complexity'] ?? 'N/A')],
            ['Confidence Level'   => $totals['confidence_level'] ?? 'N/A'],
        );

        $categories = $estimation['categories'] ?? [];
        if (!empty($categories)) {
            $table = new Table($io);
            $table->setStyle('box');
            $table->setHeaders(['Category', 'Complexity', 'Ideal hrs', 'Range', 'Team', 'Risk']);
            foreach ($categories as $cat) {
                $table->addRow([
                    $cat['name'],
                    strtoupper($cat['complexity'] ?? 'M'),
                    number_format($cat['ideal_hours'] ?? 0, 1),
                    sprintf('%s–%s h', number_format($cat['range_min'] ?? 0, 0), number_format($cat['range_max'] ?? 0, 0)),
                    $cat['team_dependency'] ?? '-',
                    sprintf('×%.1f', $cat['risk_multiplier'] ?? 1.0),
                ]);
            }
            $table->render();
        }
    }

    /**
     * Display database analysis
     */
    private function displayDatabaseAnalysis(SymfonyStyle $io, array $dbIssues): void
    {
        // ── Schema Issues ──────────────────────────────────────────────────────
        $schemaIssues = $dbIssues['schema'] ?? [];
        $totalSchemaIssues = array_sum(array_map('count', $schemaIssues));
        $io->writeln('<comment>📋 Declarative Schema (db_schema.xml)</comment>');
        if ($totalSchemaIssues > 0) {
            $io->writeln("   <fg=yellow>{$totalSchemaIssues} issue(s) found across " . count($schemaIssues) . " file(s)</>");
            foreach ($schemaIssues as $file => $fileIssues) {
                $short = $this->shortenPath($file);
                foreach (array_slice($fileIssues, 0, 3) as $issue) {
                    $io->writeln("   • [{$issue['severity']}] {$issue['message']}");
                    if (!empty($issue['fix'])) {
                        $io->writeln("     Fix: {$issue['fix']}");
                    }
                }
            }
        } else {
            $io->writeln('   <fg=green>✓ No deprecated column types or missing FK indexes detected.</>');
        }
        $io->newLine();

        // ── Legacy Setup Scripts ───────────────────────────────────────────────
        $setupScripts = $dbIssues['setup_scripts'] ?? [];
        $totalScripts = array_sum(array_map('count', $setupScripts));
        $io->writeln('<comment>🔧 Legacy Setup Scripts</comment>');
        if ($totalScripts > 0) {
            $io->warning(sprintf('%d legacy setup script(s) must be converted to declarative schema/patches', $totalScripts));
            $scriptTable = new Table($io);
            $scriptTable->setStyle('compact');
            $scriptTable->setHeaders(['Script Type', 'Count', 'Severity', 'Action Required']);
            $scriptMap = [
                'install_schema' => ['Severity' => 'CRITICAL', 'Action' => 'Convert to db_schema.xml'],
                'upgrade_schema' => ['Severity' => 'CRITICAL', 'Action' => 'Convert to declarative schema patches'],
                'install_data'   => ['Severity' => 'HIGH',     'Action' => 'Convert to DataPatch class'],
                'upgrade_data'   => ['Severity' => 'HIGH',     'Action' => 'Convert to DataPatch class'],
                'recurring'      => ['Severity' => 'MEDIUM',   'Action' => 'Review and convert to cron + DataPatch'],
            ];
            foreach ($setupScripts as $type => $scripts) {
                if (!empty($scripts)) {
                    $meta = $scriptMap[$type] ?? ['Severity' => 'MEDIUM', 'Action' => 'Review'];
                    $scriptTable->addRow([
                        ucwords(str_replace('_', ' ', $type)),
                        count($scripts),
                        $meta['Severity'],
                        $meta['Action'],
                    ]);
                }
            }
            $scriptTable->render();
        } else {
            $io->writeln('   <fg=green>✓ No legacy setup scripts found — declarative schema in use.</>');
        }
        $io->newLine();

        // ── Data Patches ───────────────────────────────────────────────────────
        $dataPatches = $dbIssues['data_patches'] ?? [];
        $totalPatchIssues = array_sum(array_map('count', $dataPatches));
        $io->writeln('<comment>🗂️  Data Patches</comment>');
        if ($totalPatchIssues > 0) {
            $io->writeln("   <fg=yellow>{$totalPatchIssues} data patch issue(s) detected</>");
            foreach (array_slice($dataPatches, 0, 5, true) as $file => $patchIssues) {
                $short = basename($file);
                foreach ($patchIssues as $issue) {
                    $io->writeln("   • [{$issue['severity']}] {$short}: {$issue['message']}");
                }
            }
        } else {
            $io->writeln('   <fg=green>✓ Data patches are well-formed.</>');
        }
        $io->newLine();

        // ── Direct SQL Queries ────────────────────────────────────────────────
        $directSql = $dbIssues['direct_sql'] ?? [];
        $io->writeln('<comment>🔍 Direct SQL Queries</comment>');
        if (!empty($directSql)) {
            $io->writeln("   <fg=yellow>" . count($directSql) . " direct SQL query/queries found — review for schema compatibility</>");
            foreach (array_slice($directSql, 0, 5) as $sql) {
                $io->writeln("   • " . $this->shortenPath($sql['file']) . ":{$sql['line']}");
            }
            if (count($directSql) > 5) {
                $io->writeln("   ... and " . (count($directSql) - 5) . " more");
            }
        } else {
            $io->writeln('   <fg=green>✓ No raw SQL queries detected.</>');
        }
        $io->newLine();

        // ── EAV Attributes ────────────────────────────────────────────────────
        $eavIssues = $dbIssues['eav_attributes'] ?? [];
        $io->writeln('<comment>🏷️  EAV Attribute Analysis</comment>');
        if (!empty($eavIssues)) {
            $io->writeln("   <fg=yellow>" . count($eavIssues) . " EAV issue(s) detected</>");
            foreach (array_slice($eavIssues, 0, 5) as $issue) {
                $io->writeln("   • [{$issue['severity']}] {$issue['message']}");
                $io->writeln("     Fix: {$issue['fix']}");
            }
        } else {
            $io->writeln('   <fg=green>✓ No EAV attribute issues detected.</>');
        }
        $io->newLine();

        // ── Indexer Configuration ──────────────────────────────────────────────
        $indexerIssues = $dbIssues['indexers'] ?? [];
        $io->writeln('<comment>📊 Indexer Configuration</comment>');
        if (!empty($indexerIssues)) {
            $io->writeln("   <fg=yellow>" . count($indexerIssues) . " indexer configuration issue(s)</>");
            foreach ($indexerIssues as $issue) {
                $io->writeln("   • [{$issue['severity']}] " . ($issue['indexer'] ?? '') . ": {$issue['message']}");
                $io->writeln("     Fix: {$issue['fix']}");
            }
        } else {
            $io->writeln('   <fg=green>✓ Indexer configurations look healthy.</>');
        }
        $io->newLine();

        // ── Large Table Risks ──────────────────────────────────────────────────
        $largeTableRisks = $dbIssues['large_table_risks'] ?? [];
        $io->writeln('<comment>🏔️  Large Table Upgrade Risks</comment>');
        if (!empty($largeTableRisks)) {
            foreach ($largeTableRisks as $risk) {
                $icon = $risk['rollback_risk'] === 'HIGH' ? '🔴' : ($risk['rollback_risk'] === 'MEDIUM' ? '🟡' : '🟢');
                $io->writeln("   {$icon} [{$risk['severity']}] Table: {$risk['table']}");
                $io->writeln("     {$risk['message']}");
                $io->writeln("     Fix: {$risk['fix']}");
                $io->writeln("     Rollback Risk: {$risk['rollback_risk']}");
            }
        } else {
            $io->writeln('   <fg=green>✓ No high-risk large table issues detected.</>');
        }
        $io->newLine();

        // ── Backward Compatibility ────────────────────────────────────────────
        $bcIssues = $dbIssues['backward_compatibility'] ?? [];
        $io->writeln('<comment>🔄 Backward Compatibility</comment>');
        if (!empty($bcIssues)) {
            $io->writeln("   <fg=yellow>" . count($bcIssues) . " backward compatibility issue(s)</>");
            foreach (array_slice($bcIssues, 0, 5) as $issue) {
                $io->writeln("   • [{$issue['severity']}] {$issue['message']}");
                $io->writeln("     Fix: {$issue['fix']}");
            }
        } else {
            $io->writeln('   <fg=green>✓ No backward compatibility issues detected.</>');
        }
        $io->newLine();

        // ── Rollback Plan Summary ──────────────────────────────────────────────
        $rollback = $dbIssues['rollback_plan'] ?? [];
        if (!empty($rollback)) {
            $rollbackRisk = $rollback['risk_level'] ?? 'UNKNOWN';
            $rollbackColor = $rollbackRisk === 'HIGH' ? 'red' : ($rollbackRisk === 'MEDIUM' ? 'yellow' : 'green');
            $io->writeln('<comment>🛡️  Rollback Preparedness</comment>');
            $io->writeln("   Overall Rollback Risk: <fg={$rollbackColor}>{$rollbackRisk}</>");
            $io->newLine();
            $io->writeln('   <fg=cyan>Deployment Sequence:</>');
            foreach ($rollback['deployment_sequence'] ?? [] as $step) {
                $io->writeln("   {$step}");
            }
            $io->newLine();
            $io->writeln('   <fg=cyan>Data Migration Risks:</>');
            foreach ($rollback['data_migration_risks'] ?? [] as $risk) {
                $io->writeln("   • {$risk}");
            }
            $io->newLine();
            $io->writeln('   <fg=cyan>Rollback Checklist:</>');
            foreach ($rollback['checklist'] ?? [] as $item) {
                $io->writeln("   ☐ {$item}");
            }
            $io->newLine();
        }

        // ── Effort Summary ────────────────────────────────────────────────────
        if (isset($dbIssues['effort_estimation'])) {
            $effort = $dbIssues['effort_estimation'];
            $io->writeln('<comment>⏱️  Database Migration Effort Estimate</comment>');
            $io->definitionList(
                ['Schema Analysis'     => sprintf('%.1fh', $effort['schema_analysis_hours'] ?? 0)],
                ['Script Conversion'   => sprintf('%.1fh', $effort['script_conversion_hours'] ?? 0)],
                ['Testing & Validation' => sprintf('%.1fh', ($effort['testing_hours'] ?? 0) + ($effort['validation_hours'] ?? 0))],
                ['Buffer (20%)'        => sprintf('%.1fh', $effort['buffer_hours'] ?? 0)],
                ['Total DB Effort'     => sprintf('<fg=cyan>%.1fh</>', $effort['total_hours'] ?? 0)],
            );
        }
    }

    private function shortenPath(string $path): string
    {
        $parts = explode('/', $path);
        $relevant = array_slice($parts, -4);
        return '.../' . implode('/', $relevant);
    }

    private function displayRiskAssessment(SymfonyStyle $io, array $risk): void
    {
        if (empty($risk)) {
            $io->warning('Risk assessment not available');
            return;
        }

        // Overall Risk Summary
        $io->writeln('<info>Overall Risk Analysis:</info>');
        $io->newLine();

        $table = new Table($io);
        $table->setStyle('box');
        $table->setHeaders(['Risk Metric', 'Score', 'Level']);
        
        $riskLevelColor = $this->getRiskColor($risk['risk_level']);
        
        $table->addRows([
            ['Overall Risk Score', $risk['overall_risk_score'] . '/100', "<{$riskLevelColor}>{$risk['risk_level']}</{$riskLevelColor}>"],
            ['Technical Risk', $risk['technical_risk']['score'] . '/100', "<{$riskLevelColor}>{$risk['technical_risk']['level']}</{$riskLevelColor}>"],
            ['Business Risk', $risk['business_risk']['score'] . '/100', "<{$riskLevelColor}>{$risk['business_risk']['level']}</{$riskLevelColor}>"],
        ]);
        
        $table->render();
        $io->newLine();

        // Technical Risk Breakdown
        $io->writeln('<comment>🔧 Technical Risk Factors:</comment>');
        $techTable = new Table($io);
        $techTable->setStyle('compact');
        $techTable->setHeaders(['Factor', 'Score', 'Impact', 'Description']);
        
        foreach ($risk['technical_risk']['factors'] as $name => $factor) {
            $techTable->addRow([
                ucwords(str_replace('_', ' ', $name)),
                $factor['score'],
                $factor['impact'],
                $factor['description']
            ]);
        }
        
        $techTable->render();
        $io->newLine();
        $io->note($risk['technical_risk']['summary']);
        $io->newLine();

        // Business Risk Breakdown
        $io->writeln('<comment>💼 Business Risk Factors:</comment>');
        $bizTable = new Table($io);
        $bizTable->setStyle('compact');
        $bizTable->setHeaders(['Factor', 'Score', 'Impact', 'Description']);
        
        foreach ($risk['business_risk']['factors'] as $name => $factor) {
            $bizTable->addRow([
                ucwords(str_replace('_', ' ', $name)),
                $factor['score'],
                $factor['impact'],
                $factor['description']
            ]);
        }
        
        $bizTable->render();
        $io->newLine();
        $io->note($risk['business_risk']['summary']);
        $io->newLine();

        // Key Risk Factors
        if (!empty($risk['risk_factors'])) {
            $io->writeln('<error>⚠️  Key Risk Factors:</error>');
            foreach ($risk['risk_factors'] as $factor) {
                $severityColor = $this->getRiskColor($factor['severity']);
                $io->writeln(sprintf(
                    '  • <%s>%s</%s>: %s',
                    $severityColor,
                    $factor['factor'],
                    $severityColor,
                    $factor['description']
                ));
            }
            $io->newLine();
        }

        // Mitigation Strategies
        if (!empty($risk['mitigation_strategies'])) {
            $io->writeln('<info>🛡️  Mitigation Strategies:</info>');
            $io->newLine();
            
            $mitTable = new Table($io);
            $mitTable->setStyle('box');
            $mitTable->setHeaders(['Priority', 'Category', 'Strategy', 'Timeline Impact']);
            
            foreach ($risk['mitigation_strategies'] as $strategy) {
                $priorityColor = $this->getRiskColor($strategy['priority']);
                $mitTable->addRow([
                    "<{$priorityColor}>{$strategy['priority']}</{$priorityColor}>",
                    $strategy['category'],
                    $strategy['strategy'] . "\n" . $strategy['details'],
                    $strategy['timeline_impact']
                ]);
            }
            
            $mitTable->render();
            $io->newLine();
        }

        // Risk-Adjusted Timeline
        if (isset($risk['risk_timeline'])) {
            $timeline = $risk['risk_timeline'];
            $io->writeln('<comment>📅 Risk-Adjusted Timeline:</comment>');
            $io->definitionList(
                ['Base Timeline' => $timeline['base_timeline_weeks'] . ' weeks'],
                ['Risk Multiplier' => $timeline['risk_multiplier'] . 'x'],
                ['Additional Buffer' => '+' . $timeline['additional_buffer_weeks'] . ' weeks'],
                ['<info>Risk-Adjusted Timeline</info>' => '<info>' . $timeline['risk_adjusted_weeks'] . ' weeks</info>']
            );
            $io->newLine();
        }

        // Success Probability
        if (isset($risk['success_probability'])) {
            $success = $risk['success_probability'];
            $io->writeln('<info>✅ Success Probability:</info>');
            $io->newLine();
            
            $successTable = new Table($io);
            $successTable->setStyle('box');
            $successTable->setHeaders(['Metric', 'Value']);
            
            $successTable->addRows([
                ['Success Probability', $success['percentage'] . '%'],
                ['Confidence Level', $success['confidence']],
            ]);
            
            $successTable->render();
            $io->newLine();
            
            if (!empty($success['factors'])) {
                $io->writeln('<comment>Key Success Factors:</comment>');
                foreach ($success['factors'] as $factor) {
                    $io->writeln("  • {$factor}");
                }
                $io->newLine();
            }
        }

        // Contingency Recommendations
        if (!empty($risk['contingency_recommendations'])) {
            $io->writeln('<info>🚨 Contingency Recommendations:</info>');
            foreach ($risk['contingency_recommendations'] as $recommendation) {
                $io->writeln("  • {$recommendation}");
            }
            $io->newLine();
        }

        // Final Risk Assessment
        if ($risk['overall_risk_score'] >= 75) {
            $io->error('CRITICAL RISK: This upgrade requires extensive planning and risk mitigation.');
        } elseif ($risk['overall_risk_score'] >= 50) {
            $io->warning('HIGH RISK: Significant planning and mitigation strategies required.');
        } elseif ($risk['overall_risk_score'] >= 25) {
            $io->note('MEDIUM RISK: Standard planning and monitoring recommended.');
        } else {
            $io->success('LOW RISK: Upgrade can proceed with standard procedures.');
        }
    }

    /**
     * Get color based on risk level
     */
    private function getRiskColor(string $level): string
    {
        $colors = [
            'CRITICAL' => 'error',
            'HIGH' => 'error',
            'MEDIUM' => 'comment',
            'LOW' => 'info',
        ];
        
        return $colors[$level] ?? 'comment';
    }

    /**
     * Display enhanced timeline with phases and milestones
     */
    private function displayEnhancedTimeline(SymfonyStyle $io, array $timeline): void
    {
        if (empty($timeline)) {
            $io->warning('Enhanced timeline not available');
            return;
        }

        // Summary
        $io->writeln('<info>Project Timeline Summary:</info>');
        $io->definitionList(
            ['Total Duration' => $timeline['total_duration_weeks'] . ' weeks'],
            ['Total Effort' => $timeline['total_hours'] . ' hours']
        );
        $io->newLine();

        // Phase Breakdown
        $io->writeln('<comment>📊 Project Phases:</comment>');
        $io->newLine();
        
        if (!empty($timeline['phases'])) {
            foreach ($timeline['phases'] as $phase) {
                $io->writeln(sprintf('<info>%s</info>', $phase['name']));
                $io->writeln(sprintf('  Duration: %s weeks (Week %s - %s)', 
                    $phase['duration_weeks'],
                    $phase['start_week'],
                    $phase['end_week']
                ));
                $io->writeln(sprintf('  Effort: %s hours', $phase['hours']));
                
                if (!empty($phase['activities'])) {
                    $io->writeln('  <comment>Key Activities:</comment>');
                    foreach (array_slice($phase['activities'], 0, 3) as $activity) {
                        $io->writeln("    • {$activity}");
                    }
                }
                $io->newLine();
            }
        }

        // Milestones
        if (!empty($timeline['milestones'])) {
            $io->writeln('<comment>🎯 Key Milestones:</comment>');
            $io->newLine();
            
            $milestoneTable = new Table($io);
            $milestoneTable->setStyle('box');
            $milestoneTable->setHeaders(['Week', 'Milestone', 'Critical']);
            
            foreach ($timeline['milestones'] as $milestone) {
                $critical = $milestone['is_critical'] ? '✓ Yes' : 'No';
                $milestoneTable->addRow([
                    $milestone['week'],
                    $milestone['name'] . "\n" . $milestone['description'],
                    $critical
                ]);
            }
            
            $milestoneTable->render();
            $io->newLine();
        }

        // Resource Allocation
        if (!empty($timeline['resource_allocation'])) {
            $io->writeln('<comment>👥 Resource Allocation:</comment>');
            $io->newLine();
            
            $resourceTable = new Table($io);
            $resourceTable->setStyle('compact');
            $resourceTable->setHeaders(['Phase', 'Duration', 'Hours', 'Team Size', 'Hours/Week']);
            
            foreach ($timeline['resource_allocation'] as $resource) {
                $resourceTable->addRow([
                    $resource['phase'],
                    $resource['duration_weeks'] . 'w',
                    $resource['total_hours'],
                    $resource['team_size'] . ($resource['team_size'] === 1 ? ' person' : ' people'),
                    $resource['hours_per_week'] . ' hrs'
                ]);
            }
            
            $resourceTable->render();
            $io->newLine();
        }

        // Critical Path
        if (!empty($timeline['critical_path'])) {
            $cp = $timeline['critical_path'];
            $io->writeln('<error>⚡ Critical Path:</error>');
            foreach ($cp['path'] as $activity) {
                $io->writeln("  → {$activity}");
            }
            $io->newLine();
            
            if (!empty($cp['risk_areas'])) {
                $io->writeln('<comment>Risk Areas:</comment>');
                foreach ($cp['risk_areas'] as $risk) {
                    $io->writeln("  ⚠️  {$risk}");
                }
                $io->newLine();
            }
        }

        $io->success('Enhanced timeline generated successfully!');
    }

    private function displayUpgradeRoadmap(SymfonyStyle $io, array $roadmap): void
    {
        if (empty($roadmap)) {
            $io->success('No upgrade roadmap needed - all clear!');
            return;
        }

        $seqNum = 1;
        foreach ($roadmap as $phase) {
            $icon      = $this->getPhaseIcon($phase['priority'] ?? 'LOW');
            $canDeploy = ($phase['can_deploy_without_fixing'] ?? true) ? '(can deploy)' : '(must fix)';
            $issueCount = count($phase['issues'] ?? []);

            $io->writeln(sprintf(
                '%s <fg=cyan>Phase %d: %s</> <fg=gray>%s</>',
                $icon,
                $seqNum,
                $phase['name'],
                $canDeploy
            ));
            $io->text('   ' . $phase['description']);
            $io->text(sprintf('   Issues: %d', $issueCount));
            $io->newLine();
            $seqNum++;
        }
    }

    /**
     * Enterprise-grade 12-section Action Plan
     */
    private function displayRecommendations(SymfonyStyle $io, $result): void
    {
        $stats = $result->getFullScanStats();
        $score         = $stats['score']    ?? $result->getUpgradeReadinessScore();
        $riskLevel     = $stats['risk']     ?? $result->getUpgradeRiskLevel();
        $criticalCount = $stats['critical'] ?? $result->getIssueCountByPriority('critical');
        $highCount     = $stats['high']     ?? $result->getIssueCountByPriority('high');
        $mediumCount   = $stats['medium']   ?? $result->getIssueCountByPriority('medium');
        $effort        = $result->getEffortEstimation();
        $totalHours    = (float)($effort['total_hours'] ?? 0);
        $totalWeeks    = $effort['timeline_weeks'] ?? round($totalHours / 25, 1);
        $prioritized   = $result->getPrioritizedIssues();
        $currentV      = $result->getCurrentMagentoVersion()->toString();
        $targetV       = $result->getTargetMagentoVersion()->toString();
        $upgradePossible = $result->isUpgradePossible();

        // ── 1. EXECUTIVE SUMMARY ──────────────────────────────────────────────
        $io->writeln('<fg=cyan;options=bold>═══════════════════════════════════════════════════════════════</>');
        $io->writeln('<fg=cyan;options=bold>  ENTERPRISE UPGRADE ACTION PLAN                               </>');
        $io->writeln('<fg=cyan;options=bold>  Adobe Commerce ' . $currentV . ' → ' . $targetV . '             </>');
        $io->writeln('<fg=cyan;options=bold>═══════════════════════════════════════════════════════════════</>');
        $io->newLine();

        $io->writeln('<options=bold>1. EXECUTIVE SUMMARY</>');
        $io->writeln('   ─────────────────────────────────────────────────────────────');

        $complexityLabel = match(true) {
            $criticalCount > 20 || $highCount > 40 => 'EXTREME — Multi-sprint, dedicated team required',
            $criticalCount > 10 || $highCount > 20 => 'HIGH — Significant engineering effort, senior team needed',
            $criticalCount > 5  || $highCount > 10 => 'MEDIUM — Structured sprint planning recommended',
            $criticalCount > 0                      => 'LOW-MEDIUM — Focused effort with standard team',
            default                                 => 'LOW — Routine upgrade, minimal risk',
        };

        $verdict = $upgradePossible
            ? ($score >= 75 ? '✅ PROCEED — Code is upgrade-ready with minor remediation'
                            : ($score >= 50 ? '⚠️  PROCEED WITH CAUTION — Address critical issues first'
                                           : '🛑 PLAN REQUIRED — Significant work needed before upgrading'))
            : '❌ BLOCKED — Critical issues with no known fix detected';

        $io->writeln("   Upgrade {$currentV} → {$targetV}");
        $io->writeln("   Readiness Score : {$score}/100  |  Risk Level : {$riskLevel}");
        $io->writeln("   Complexity       : {$complexityLabel}");
        $io->writeln("   Estimated Effort : " . number_format($totalHours, 0) . "h (" . number_format($totalWeeks, 1) . " weeks)");
        $io->writeln("   Verdict          : {$verdict}");
        $io->newLine();

        // ── 2. IMMEDIATE BLOCKERS ─────────────────────────────────────────────
        $io->writeln('<options=bold>2. IMMEDIATE BLOCKERS (Must Fix Before Upgrade)</>');
        $io->writeln('   ─────────────────────────────────────────────────────────────');
        $criticalIssues = $prioritized['critical'] ?? [];
        if (empty($criticalIssues)) {
            $io->writeln('   <fg=green>✓ No critical blockers detected.</>');
        } else {
            $io->writeln("   <fg=red>{$criticalCount} critical issue(s) are upgrade blockers:</>");
            $io->newLine();

            // Group by type for concise blocker listing
            $blockerGroups = [];
            foreach ($criticalIssues as $issue) {
                $type = $issue['type'] ?? 'unknown';
                $blockerGroups[$type][] = $issue;
            }

            foreach ($blockerGroups as $type => $group) {
                $count    = count($group);
                $sample   = $group[0];
                $typeLabel = ucwords(str_replace(['_', '-'], ' ', $type));

                $io->writeln("   <fg=red>▶ [{$count}x] {$typeLabel}</>");
                $io->writeln("     Root cause : " . ($sample['risk'] ?? 'Compatibility break in target version'));
                $io->writeln("     Impact     : Upgrade will fail or produce runtime errors");
                $io->writeln("     Fix        : " . ($sample['fix'] ?? 'Review Adobe Commerce migration guide'));
                $io->writeln("     Priority   : P1 — Fix in Sprint 1");
                $io->writeln("     Effort     : ~" . max(1, intval($count * 0.5)) . "–" . max(2, $count) . "h");
                $io->writeln("     Validate   : Run unit tests + integration tests after each fix");
                $io->newLine();
            }
        }

        // ── 3. UPGRADE COMPLEXITY RATING ─────────────────────────────────────
        $io->writeln('<options=bold>3. UPGRADE COMPLEXITY RATING</>');
        $io->writeln('   ─────────────────────────────────────────────────────────────');
        $complexityTable = new Table($io);
        $complexityTable->setStyle('compact');
        $complexityTable->setHeaders(['Dimension', 'Status', 'Risk']);
        $complexityTable->addRows([
            ['Critical Issues',    $criticalCount . ' found', $criticalCount > 10 ? '<fg=red>HIGH</>' : ($criticalCount > 0 ? '<fg=yellow>MEDIUM</>' : '<fg=green>NONE</>')],
            ['High-Priority Issues', $highCount . ' found',   $highCount > 20 ? '<fg=red>HIGH</>' : ($highCount > 0 ? '<fg=yellow>MEDIUM</>' : '<fg=green>NONE</>')],
            ['Medium Issues',      $mediumCount . ' found',   $mediumCount > 50 ? '<fg=yellow>ELEVATED</>' : '<fg=green>STANDARD</>'],
            ['Upgrade Effort',     number_format($totalHours, 0) . 'h',         $totalHours > 400 ? '<fg=red>HIGH</>' : ($totalHours > 160 ? '<fg=yellow>MEDIUM</>' : '<fg=green>LOW</>')],
            ['Timeline',          $totalWeeks . ' weeks',    $totalWeeks > 12 ? '<fg=red>EXTENDED</>' : ($totalWeeks > 6 ? '<fg=yellow>STANDARD</>' : '<fg=green>SHORT</>')],
            ['Overall',           $riskLevel,               $riskLevel === 'HIGH' ? '<fg=red>HIGH</>' : ($riskLevel === 'MEDIUM' ? '<fg=yellow>MEDIUM</>' : '<fg=green>LOW</>')],
        ]);
        $complexityTable->render();
        $io->newLine();

        // ── 4. PHASE-WISE REMEDIATION ROADMAP ────────────────────────────────
        $io->writeln('<options=bold>4. PHASE-WISE REMEDIATION ROADMAP</>');
        $io->writeln('   ─────────────────────────────────────────────────────────────');

        $phases = [
            [
                'num'   => 1,
                'name'  => 'Environment Preparation & Code Audit',
                'weeks' => '1–2',
                'goal'  => 'Set up upgrade branch, run ACQC, establish baseline metrics',
                'tasks' => [
                    'Create upgrade branch from production HEAD',
                    'Run full ACQC audit on codebase (all categories)',
                    'Update composer.json to target version constraints',
                    'Verify CI/CD pipeline works on upgrade branch',
                    'Establish code quality baseline (PHPStan, PHPCS)',
                ],
                'owner'  => 'Tech Lead + DevOps',
                'exit'   => 'Audit report signed off, branch created, CI green',
            ],
            [
                'num'   => 2,
                'name'  => 'Critical Issue Remediation (Blockers)',
                'weeks' => '2–' . max(3, 2 + (int)ceil($criticalCount / 5)),
                'goal'  => "Resolve all {$criticalCount} critical issues — no deployment without this",
                'tasks' => [
                    'Fix unserialize() → json_decode() conversions (security critical)',
                    'Replace deprecated Registry pattern with proper state management',
                    'Remove/replace all ObjectManager::getInstance() direct calls',
                    'Convert legacy setup scripts to declarative schema + data patches',
                    'Fix PHP 8.x type errors and removed function usage',
                ],
                'owner'  => 'Senior Backend Engineers',
                'exit'   => 'Zero critical issues in ACQC rescan, unit tests pass',
            ],
            [
                'num'   => 3,
                'name'  => 'High-Priority & Deprecated Code Fixes',
                'weeks' => ($highCount > 20 ? '4–6' : '2–3'),
                'goal'  => 'Eliminate deprecated APIs that will be removed in target version',
                'tasks' => [
                    'Replace deprecated method calls with current equivalents',
                    'Update Zend Framework → Laminas class references',
                    'Migrate serialize() calls to json_encode() where appropriate',
                    'Fix direct model->save() to repository->save() patterns',
                    'Update deprecated constructor injection patterns',
                ],
                'owner'  => 'Backend Engineers',
                'exit'   => 'High-priority issues reduced by 80%+, peer review complete',
            ],
            [
                'num'   => 4,
                'name'  => 'Database Schema & Migration Preparation',
                'weeks' => '1–2',
                'goal'  => 'Ensure all schema changes are declarative and migrations are safe',
                'tasks' => [
                    'Convert legacy InstallSchema/UpgradeSchema → db_schema.xml',
                    'Create data patches for all InstallData/UpgradeData scripts',
                    'Validate db_schema.xml files pass bin/magento setup:db:schema:upgrade --dry-run',
                    'Test bin/magento setup:upgrade on staging with production data snapshot',
                    'Document all table changes for rollback planning',
                ],
                'owner'  => 'Backend Engineers + DBA',
                'exit'   => 'setup:upgrade completes cleanly, no schema delta errors',
            ],
            [
                'num'   => 5,
                'name'  => 'Testing & QA Validation',
                'weeks' => '2–4',
                'goal'  => 'Comprehensive test coverage to prevent regression in production',
                'tasks' => [
                    'Run full unit test suite — fix any failures',
                    'Run integration test suite against target version',
                    'Execute MFTF (Magento Functional Testing Framework) critical flows',
                    'Performance test: measure page load, checkout, indexer times vs baseline',
                    'Security scan: OWASP ZAP + Magento security scanner',
                    'UAT with QA team on staging environment',
                ],
                'owner'  => 'QA Team + Backend Engineers',
                'exit'   => '95%+ test pass rate, zero critical UAT defects, performance within 10% of baseline',
            ],
            [
                'num'   => 6,
                'name'  => 'Staging Deployment & Stabilization',
                'weeks' => '1',
                'goal'  => 'Validate full upgrade procedure in production-equivalent environment',
                'tasks' => [
                    'Deploy upgrade to staging with production data clone',
                    'Run bin/magento setup:upgrade + setup:di:compile + SCD',
                    'Full regression smoke test on all critical commerce flows',
                    'Load test with representative traffic',
                    'Validate all third-party integrations (ERP, PIM, payment gateways)',
                ],
                'owner'  => 'DevOps + QA + Business',
                'exit'   => 'Staging sign-off from Business, DevOps, and Tech Lead',
            ],
            [
                'num'   => 7,
                'name'  => 'Production Deployment & Go-Live',
                'weeks' => '0.5',
                'goal'  => 'Zero-downtime production upgrade with rollback readiness',
                'tasks' => [
                    'Schedule maintenance window (off-peak)',
                    'Take full database backup',
                    'Deploy codebase via CI/CD',
                    'Run setup:upgrade with monitoring active',
                    'Smoke test critical paths (checkout, cart, admin)',
                    'Disable maintenance mode — monitor for 60 minutes',
                ],
                'owner'  => 'DevOps + On-call Engineer',
                'exit'   => 'Green monitoring dashboards, zero P1 errors in first hour',
            ],
        ];

        foreach ($phases as $phase) {
            $io->writeln(sprintf(
                '   <fg=cyan;options=bold>Phase %d: %s</> <fg=gray>[Weeks %s]</>',
                $phase['num'], $phase['name'], $phase['weeks']
            ));
            $io->writeln("   Goal  : " . $phase['goal']);
            $io->writeln("   Owner : " . $phase['owner']);
            foreach ($phase['tasks'] as $task) {
                $io->writeln("     • {$task}");
            }
            $io->writeln("   Exit Criteria : " . $phase['exit']);
            $io->newLine();
        }

        // ── 5. MODULE-WISE ACTION ITEMS ───────────────────────────────────────
        $io->writeln('<options=bold>5. MODULE-WISE ACTION ITEMS</>');
        $io->writeln('   ─────────────────────────────────────────────────────────────');

        $moduleIssues = [];
        // Tag each issue with its priority before flattening so the module table can count by priority
        foreach ($prioritized as $prio => $issues) {
            foreach ($issues as $issue) {
                $issue['_priority'] = $prio;
                $file = $issue['file'] ?? '';
                if (preg_match('#(?:app/code)/([A-Z][^/\\\\]+)[/\\\\]([A-Z][^/\\\\]+)#', $file, $m)) {
                    $module = $m[1] . '_' . $m[2];
                } elseif (preg_match('#([A-Z][^/\\\\]+)[/\\\\]([A-Z][^/\\\\]+)[/\\\\]#', $file, $m)) {
                    $module = $m[1] . '_' . $m[2];
                } else {
                    $module = 'Unknown';
                }
                $moduleIssues[$module][] = $issue;
            }
        }

        if (empty($moduleIssues)) {
            $io->writeln('   <fg=green>✓ No module-specific issues found.</>');
        } else {
            uasort($moduleIssues, fn($a, $b) => count($b) - count($a));
            $moduleTable = new Table($io);
            $moduleTable->setStyle('box');
            $moduleTable->setHeaders(['Module', 'Total', 'Critical', 'High', 'Medium', 'Action']);
            foreach (array_slice($moduleIssues, 0, 15, true) as $module => $issues) {
                $crit = count(array_filter($issues, fn($i) => ($i['_priority'] ?? '') === 'critical'));
                $high = count(array_filter($issues, fn($i) => ($i['_priority'] ?? '') === 'high'));
                $med  = count(array_filter($issues, fn($i) => ($i['_priority'] ?? '') === 'medium'));
                $action = $crit > 0 ? '<fg=red>P1 — Sprint 1</>' : ($high > 5 ? '<fg=yellow>P2 — Sprint 2</>' : 'P3 — Schedule');
                $moduleTable->addRow([
                    $module, count($issues),
                    $crit ? "<fg=red>{$crit}</>" : '-',
                    $high ? "<fg=yellow>{$high}</>" : '-',
                    $med  ? "<fg=cyan>{$med}</>"   : '-',
                    $action,
                ]);
            }
            $moduleTable->render();
        }
        $io->newLine();

        // ── 6. OWNERSHIP RECOMMENDATIONS ─────────────────────────────────────
        $io->writeln('<options=bold>6. OWNERSHIP RECOMMENDATIONS</>');
        $io->writeln('   ─────────────────────────────────────────────────────────────');
        $ownershipRows = [
            ['Critical security fixes (unserialize, XSS, CSRF)', 'Security-focused Senior Engineer', 'P1'],
            ['Deprecated API migration (Registry, ObjectManager)', 'Senior Backend Engineer', 'P1'],
            ['Database schema & data patch conversion', 'Backend Engineer + DBA', 'P1'],
            ['PHP 8.x type safety & compatibility', 'Backend Engineer', 'P2'],
            ['Zend → Laminas class migration', 'Backend Engineer', 'P2'],
            ['Unit & integration test updates', 'QA Engineer', 'P2'],
            ['Third-party extension compatibility', 'Tech Lead', 'P2'],
            ['Performance baseline & load testing', 'Performance Engineer / DevOps', 'P3'],
            ['Deployment pipeline & rollback procedure', 'DevOps Engineer', 'P2'],
            ['Business UAT sign-off', 'Product Owner + QA Lead', 'P3'],
        ];
        $ownerTable = new Table($io);
        $ownerTable->setStyle('compact');
        $ownerTable->setHeaders(['Responsibility', 'Recommended Owner', 'Priority']);
        $ownerTable->addRows($ownershipRows);
        $ownerTable->render();
        $io->newLine();

        // ── 7. DEPENDENCY RESOLUTION SEQUENCE ────────────────────────────────
        $io->writeln('<options=bold>7. DEPENDENCY RESOLUTION SEQUENCE</>');
        $io->writeln('   ─────────────────────────────────────────────────────────────');
        $deps = [
            '1st' => 'Security vulnerabilities (unserialize, eval, exec with user input)',
            '2nd' => 'Removed classes / interfaces (will cause fatal errors on boot)',
            '3rd' => 'Deprecated DI patterns (ObjectManager, Helper injection)',
            '4th' => 'Database schema conversions (must complete before setup:upgrade)',
            '5th' => 'Deprecated event observers (may silently fail in target version)',
            '6th' => 'Soft deprecations (serialize, direct SQL, model->save())',
            '7th' => 'Code quality improvements (medium/low priority issues)',
        ];
        foreach ($deps as $seq => $desc) {
            $io->writeln("   [{$seq}] {$desc}");
        }
        $io->newLine();

        // ── 8. TESTING STRATEGY ───────────────────────────────────────────────
        $io->writeln('<options=bold>8. TESTING STRATEGY</>');
        $io->writeln('   ─────────────────────────────────────────────────────────────');
        $testingMatrix = [
            ['Unit Tests',        'PHPUnit (vendor/magento/magento2-base)',   'After each critical fix',   'Pass rate ≥ 95%'],
            ['Integration Tests', 'Magento integration test suite',           'After schema changes',       'Pass rate ≥ 90%'],
            ['Functional Tests',  'MFTF — checkout, catalog, orders, admin',  'After Phase 3',              'All critical paths pass'],
            ['Performance Tests', 'Apache JMeter / k6 on staging',           'Pre-production',             'Within 10% of baseline'],
            ['Security Tests',    'OWASP ZAP + Magento scanner',             'Pre-production',             'Zero high/critical findings'],
            ['UAT',               'Business stakeholders on staging',         'Pre-production sign-off',    'Business approval'],
            ['Smoke Tests',       'Manual critical path verification',         'Post-deployment',            '< 30 min to confirm go/no-go'],
        ];
        $testTable = new Table($io);
        $testTable->setStyle('compact');
        $testTable->setHeaders(['Test Type', 'Tool / Scope', 'When', 'Acceptance Criteria']);
        $testTable->addRows($testingMatrix);
        $testTable->render();
        $io->newLine();

        // ── 9. DEPLOYMENT READINESS CHECKLIST ────────────────────────────────
        $io->writeln('<options=bold>9. DEPLOYMENT READINESS CHECKLIST</>');
        $io->writeln('   ─────────────────────────────────────────────────────────────');
        $checklist = [
            '[ ] ACQC rescan shows 0 critical issues',
            '[ ] All unit and integration tests pass on upgrade branch',
            '[ ] Staging upgrade completed successfully with production data',
            '[ ] Business UAT sign-off received',
            '[ ] Performance benchmarks within acceptable range',
            '[ ] All third-party integrations validated',
            '[ ] Security scan completed — no high/critical findings',
            '[ ] Deployment runbook reviewed by DevOps',
            '[ ] Maintenance window scheduled and communicated',
            '[ ] Full database backup verified and restorable',
            '[ ] Rollback procedure tested on staging',
            '[ ] On-call rotation set for 48h post-deployment',
            '[ ] bin/magento setup:db:status shows "All modules are up to date"',
            '[ ] Admin panel accessible and functional post-upgrade',
            '[ ] Monitoring dashboards configured for target version metrics',
        ];
        foreach ($checklist as $item) {
            $io->writeln("   {$item}");
        }
        $io->newLine();

        // ── 10. ROLLBACK PREPAREDNESS PLAN ────────────────────────────────────
        $io->writeln('<options=bold>10. ROLLBACK PREPAREDNESS PLAN</>');
        $io->writeln('    ─────────────────────────────────────────────────────────────');
        $rollbackSteps = [
            'Pre-upgrade' => [
                'Full database backup with mysqldump --single-transaction --all-databases',
                'Tag current production codebase as release/pre-upgrade-YYYYMMDD',
                'Document all configuration changes (admin, env.php)',
                'Capture current indexer states and scheduled job configuration',
            ],
            'Trigger criteria (rollback if)' => [
                'Fatal PHP errors on any critical commerce path (checkout, orders)',
                'Admin panel inaccessible after 15 minutes post-deployment',
                'Payment gateway integration failures',
                'Database integrity errors in logs',
                'Performance degradation > 30% on key transactions',
            ],
            'Rollback procedure' => [
                '1. Enable maintenance mode immediately',
                '2. Restore database from pre-upgrade backup',
                '3. Deploy previous codebase tag via CI/CD (< 5 min)',
                '4. Run bin/magento setup:upgrade on previous version',
                '5. Verify smoke tests pass on previous version',
                '6. Disable maintenance mode',
                '7. Communicate status to stakeholders',
            ],
        ];
        foreach ($rollbackSteps as $section => $steps) {
            $io->writeln("    <fg=yellow>{$section}:</>");
            foreach ($steps as $step) {
                $io->writeln("      • {$step}");
            }
            $io->newLine();
        }

        // ── 11. TIMELINE GUIDANCE ─────────────────────────────────────────────
        $io->writeln('<options=bold>11. TIMELINE GUIDANCE</>');
        $io->writeln('    ─────────────────────────────────────────────────────────────');
        $effort = $result->getEffortEstimation();
        $categories = $effort['categories'] ?? [];
        if (!empty($categories)) {
            $tlTable = new Table($io);
            $tlTable->setStyle('compact');
            $tlTable->setHeaders(['Work Category', 'Ideal Hours', 'Realistic Range', 'Priority']);
            foreach ($categories as $cat) {
                $tlTable->addRow([
                    $cat['name'],
                    number_format((float)($cat['ideal_hours'] ?? 0), 0) . 'h',
                    number_format((float)($cat['range_min'] ?? 0), 0) . '–' . number_format((float)($cat['range_max'] ?? 0), 0) . 'h',
                    ucfirst($cat['priority'] ?? 'medium'),
                ]);
            }
            $tlTable->render();
            $io->newLine();
        }
        $io->writeln(sprintf(
            "    Total Estimated: %sh ideal  |  %s–%sh realistic  |  %s weeks",
            number_format($totalHours, 0),
            number_format((float)($effort['range_min_hours'] ?? $totalHours * 0.8), 0),
            number_format((float)($effort['range_max_hours'] ?? $totalHours * 1.3), 0),
            number_format($totalWeeks, 1)
        ));
        $io->newLine();

        // ── 12. RISK MITIGATION STEPS ─────────────────────────────────────────
        $io->writeln('<options=bold>12. RISK MITIGATION STEPS</>');
        $io->writeln('    ─────────────────────────────────────────────────────────────');
        $mitigations = [
            [
                'risk'       => 'Undiscovered breaking changes',
                'likelihood' => 'HIGH',
                'impact'     => 'HIGH',
                'mitigation' => 'Run Adobe Commerce Upgrade Compatibility Tool (UCT) alongside ACQC; cross-check with official migration guide',
            ],
            [
                'risk'       => 'Third-party extension incompatibility',
                'likelihood' => 'MEDIUM',
                'impact'     => 'HIGH',
                'mitigation' => 'Contact each extension vendor; check Marketplace listing for target version support; test extensions on upgrade branch',
            ],
            [
                'risk'       => 'Performance regression post-upgrade',
                'likelihood' => 'MEDIUM',
                'impact'     => 'MEDIUM',
                'mitigation' => 'Establish performance baseline before upgrade; load test staging; review new caching and indexer configurations',
            ],
            [
                'risk'       => 'Database migration timeout on large tables',
                'likelihood' => $criticalCount > 5 ? 'HIGH' : 'LOW',
                'impact'     => 'CRITICAL',
                'mitigation' => 'Use pt-online-schema-change (Percona) for large table DDL; test migration timing on staging with production data volume',
            ],
            [
                'risk'       => 'Team knowledge gap on target version changes',
                'likelihood' => 'MEDIUM',
                'impact'     => 'MEDIUM',
                'mitigation' => 'Schedule team training on Adobe Commerce ' . $targetV . ' release notes; assign migration guide review per engineer',
            ],
            [
                'risk'       => 'Rollback failure (corrupted backup)',
                'likelihood' => 'LOW',
                'impact'     => 'CRITICAL',
                'mitigation' => 'Verify backup restoration before upgrade day; test restore on non-production environment',
            ],
        ];
        $riskTable = new Table($io);
        $riskTable->setStyle('box');
        $riskTable->setHeaders(['Risk', 'Likelihood', 'Impact', 'Mitigation']);
        foreach ($mitigations as $m) {
            $llColor = $m['likelihood'] === 'HIGH' ? 'red' : ($m['likelihood'] === 'MEDIUM' ? 'yellow' : 'green');
            $imColor = $m['impact'] === 'CRITICAL' || $m['impact'] === 'HIGH' ? 'red' : ($m['impact'] === 'MEDIUM' ? 'yellow' : 'green');
            $riskTable->addRow([
                $m['risk'],
                "<fg={$llColor}>{$m['likelihood']}</>",
                "<fg={$imColor}>{$m['impact']}</>",
                $m['mitigation'],
            ]);
        }
        $riskTable->render();
        $io->newLine();

        $io->writeln('<fg=cyan;options=bold>═══════════════════════════════════════════════════════════════</>');
        $io->writeln('<fg=cyan;options=bold>  END OF ACTION PLAN</>');
        $io->writeln('<fg=cyan;options=bold>═══════════════════════════════════════════════════════════════</>');
        $io->newLine();
    }

    /**
     * Output JSON format
     */
    private function outputJson(OutputInterface $output, $result, ?string $outputFile): void
    {
        $json = json_encode($result->toArray(), JSON_PRETTY_PRINT);
        
        if ($outputFile) {
            file_put_contents($outputFile, $json);
            $output->writeln(sprintf('<info>JSON report saved to: %s</info>', $outputFile));
        } else {
            $output->writeln($json);
        }
    }

    /**
     * Output Excel format
     */
    private function outputExcel(
        SymfonyStyle $io,
        $result,
        ?string $outputFile,
        ?\ACQC\Core\Upgrade\URIScore $uri = null,
        ?\ACQC\Analyzers\Version\DBSchemaAnalysisResult $dbSchema = null,
        ?\ACQC\Analyzers\Version\ComposerAnalysisResult $composer = null,
        ?array $recommendations = null,
    ): void {
        $outputPath = $outputFile ?? 'upgrade-readiness-' . date('Y-m-d_H-i-s') . '.xlsx';

        try {
            $exporter = new ExcelExporter();
            $exporter->export($result, $outputPath, $uri, $dbSchema, $composer, $recommendations);

            $io->success('Excel report generated successfully!');
            $io->writeln(sprintf('  Location: <info>%s</info>', realpath($outputPath)));
            $io->writeln(sprintf('  Size: <info>%s</info>', $this->formatFileSize(filesize($outputPath))));
        } catch (\Exception $e) {
            $io->error('Failed to generate Excel report: ' . $e->getMessage());
        }
    }

    /**
     * Format file size for display
     */
    private function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Apply filters to results based on command options
     */
    private function applyFilters($result, InputInterface $input): void
    {
        // Priority filter
        $priorityFilter = $input->getOption('priority');
        $minPriority = $input->getOption('min-priority');
        $issueTypeFilter = $input->getOption('issue-type');
        
        if (!$priorityFilter && !$minPriority && !$issueTypeFilter) {
            return; // No filters to apply
        }
        
        $priorityLevels = ['critical', 'high', 'medium', 'low'];
        $allowedPriorities = [];
        
        // Handle specific priority filter
        if ($priorityFilter) {
            $allowedPriorities = array_map('trim', explode(',', strtolower($priorityFilter)));
        }
        
        // Handle min-priority filter
        if ($minPriority) {
            $minPriority = strtolower($minPriority);
            $minIndex = array_search($minPriority, $priorityLevels);
            if ($minIndex !== false) {
                $allowedPriorities = array_merge(
                    $allowedPriorities,
                    array_slice($priorityLevels, 0, $minIndex + 1)
                );
            }
        }
        
        // Handle issue type filter
        $allowedTypes = [];
        if ($issueTypeFilter) {
            $allowedTypes = array_map('trim', explode(',', strtolower($issueTypeFilter)));
        }
        
        // Apply filters
        $prioritizedIssues = $result->getPrioritizedIssues();
        $filteredIssues = [];
        
        foreach ($prioritizedIssues as $priority => $issues) {
            // Filter by priority
            if (!empty($allowedPriorities) && !in_array($priority, $allowedPriorities)) {
                continue;
            }
            
            // Filter by issue type
            if (!empty($allowedTypes)) {
                $issues = array_filter($issues, function($issue) use ($allowedTypes) {
                    $issueType = strtolower($issue['type'] ?? '');
                    return in_array($issueType, $allowedTypes);
                });
            }
            
            if (!empty($issues)) {
                $filteredIssues[$priority] = array_values($issues);
            }
        }
        
        // Update result with filtered issues
        $result->setPrioritizedIssues($filteredIssues);
    }

    /**
     * Generate an enterprise-grade self-contained HTML upgrade-readiness report.
     * Includes tabbed navigation, drilldowns, risk heatmap, search/filter/export.
     */
    private function generateHtmlReport(
        $result,
        string $outputPath,
        ?\ACQC\Core\Upgrade\URIScore $uri = null,
        ?\ACQC\Analyzers\Version\DBSchemaAnalysisResult $dbSchema = null,
        ?\ACQC\Analyzers\Version\ComposerAnalysisResult $composer = null,
    ): void {
        $data        = $result->toArray();
        $vi          = $data['version_info'] ?? [];
        $summary     = $data['summary'] ?? [];
        $effort      = $result->getEffortEstimation();
        $roadmap     = $result->getUpgradeRoadmap();
        $risk        = $result->getRiskAssessment();
        $prioritized = $result->getPrioritizedIssues();
        $timeline    = $result->getEnhancedTimeline();

        $generatedAt   = date('Y-m-d H:i:s');
        $score         = (int)($summary['readiness_score'] ?? 0);
        $riskLevel     = (string)($summary['risk_level'] ?? 'UNKNOWN');
        $totalIssues   = (int)($summary['total_issues'] ?? 0);
        $critCount     = (int)($summary['critical'] ?? 0);
        $highCount     = (int)($summary['high'] ?? 0);
        $medCount      = (int)($summary['medium'] ?? 0);
        $lowCount      = (int)($summary['low'] ?? 0);
        $upgradePossible = ($summary['upgrade_possible'] ?? true) ? 'Yes' : 'No — blockers present';

        $scoreColor  = $score >= 75 ? '#16a34a' : ($score >= 50 ? '#d97706' : '#dc2626');
        $riskColors  = ['LOW' => '#16a34a','MEDIUM-LOW' => '#65a30d','MEDIUM' => '#d97706','HIGH' => '#dc2626','CRITICAL' => '#7c3aed'];
        $riskColor   = $riskColors[$riskLevel] ?? '#6b7280';

        // Effort totals
        $totals      = $effort['totals'] ?? [];
        $idealHours  = $totals['ideal_hours'] ?? ($effort['total_hours'] ?? 0);
        $minHours    = $totals['range_min_hours'] ?? round($idealHours * 0.75, 1);
        $maxHours    = $totals['range_max_hours'] ?? round($idealHours * 1.5, 1);
        $idealWeeks  = $totals['ideal_weeks'] ?? ($effort['timeline_weeks'] ?? 0);
        $confidence  = $totals['confidence_level'] ?? ($effort['confidence_level'] ?? '—');

        // Risk scores
        $overallRisk = round((float)($risk['overall_risk_score'] ?? 0), 1);
        $techRisk    = round((float)($risk['technical_risk']['score'] ?? 0), 1);
        $bizRisk     = round((float)($risk['business_risk']['score'] ?? 0), 1);

        // Build module-grouped data
        $moduleData = $this->groupIssuesByModule($prioritized);

        // Build deprecated-grouped data (by issue type)
        $deprecatedGroups = $this->groupIssuesByType($prioritized);

        // Build JSON blobs for JS use
        $moduleDataJson    = json_encode($moduleData, JSON_HEX_TAG | JSON_HEX_APOS);
        $effortCategoriesJson = json_encode(array_values($effort['categories'] ?? []), JSON_HEX_TAG | JSON_HEX_APOS);

        // Flat issue list for the searchable table (max 500)
        $allIssues = [];
        foreach ($prioritized as $prio => $issues) {
            foreach ($issues as $issue) {
                $recData = null;
                if ($this->recommendationEngine !== null) {
                    $rec = $this->recommendationEngine->getRecommendation($issue);
                    if ($rec !== null) {
                        $recData = [
                            'root_cause'   => $rec->rootCause,
                            'code_example' => $rec->codeExample,
                            'step_by_step' => $rec->stepByStep,
                            'doc_links'    => $rec->docLinks,
                            'from_fj'      => $rec->isFromFluffyJaws,
                        ];
                    }
                }
                $allIssues[] = [
                    'priority'       => $prio,
                    'file'           => $issue['file'] ?? '',
                    'line'           => (int)($issue['line'] ?? 0),
                    'type'           => $issue['type'] ?? '',
                    'message'        => $issue['message'] ?? '',
                    'fix'            => (string)($issue['fix'] ?? ''),
                    'module'         => $this->extractModuleFromPath($issue['file'] ?? ''),
                    'recommendation' => $recData,
                ];
                if (count($allIssues) >= 500) break 2;
            }
        }
        $allIssuesJson = json_encode($allIssues, JSON_HEX_TAG | JSON_HEX_APOS);

        // Effort categories table HTML (server-side render)
        $effortCatHtml = '';
        $effortRankColors = ['critical'=>'#dc2626','high'=>'#d97706','medium'=>'#2563eb','low'=>'#16a34a'];
        foreach ($effort['categories'] ?? [] as $cat) {
            $cx    = $cat['complexity'] ?? 'medium';
            $cxCol = $effortRankColors[$cx] ?? '#6b7280';
            $subTasksHtml = '';
            foreach ($cat['sub_tasks'] ?? [] as $st) {
                $subTasksHtml .= '<li>' . htmlspecialchars((string)$st['task']) . ' — <strong>' . htmlspecialchars((string)($st['hours'] ?? 0)) . 'h</strong></li>';
            }
            $effortCatHtml .= sprintf(
                '<tr class="effort-row" onclick="toggleEffortDetail(this)">
                  <td><strong>%s</strong><p class="sub-text">%s</p></td>
                  <td><span class="badge" style="background:%s">%s</span></td>
                  <td><strong>%s h</strong></td>
                  <td>%s–%s h</td>
                  <td>×%s</td>
                  <td>%s</td>
                 </tr>
                 <tr class="effort-detail hidden">
                   <td colspan="6">
                     <div class="detail-box">
                       <strong>Team:</strong> %s &nbsp;|&nbsp; <strong>Sequence:</strong> #%d<br>
                       <strong>Sub-tasks:</strong><ul>%s</ul>
                     </div>
                   </td>
                 </tr>',
                htmlspecialchars($cat['name']),
                htmlspecialchars($cat['description']),
                $cxCol, strtoupper($cx),
                number_format((float)($cat['ideal_hours'] ?? 0), 1),
                number_format((float)($cat['range_min'] ?? 0), 1),
                number_format((float)($cat['range_max'] ?? 0), 1),
                number_format((float)($cat['risk_multiplier'] ?? 1), 1),
                '#' . ($cat['priority_sequence'] ?? 0),
                htmlspecialchars($cat['team_dependency'] ?? ''),
                (int)($cat['priority_sequence'] ?? 0),
                $subTasksHtml
            );
        }

        // Module heatmap HTML
        $heatmapHtml = '';
        $maxIssues   = max(1, max(array_column($moduleData, 'total')));
        foreach (array_slice($moduleData, 0, 30) as $mod) {
            $pct      = min(100, (int)(($mod['total'] / $maxIssues) * 100));
            $heat     = $pct >= 75 ? '#dc2626' : ($pct >= 50 ? '#d97706' : ($pct >= 25 ? '#2563eb' : '#16a34a'));
            $heatmapHtml .= sprintf(
                '<div class="heat-cell" onclick="filterModuleIssues(\'%s\')" title="%s: %d issues">
                  <div class="heat-bar" style="height:%d%%;background:%s;"></div>
                  <div class="heat-label">%s</div>
                  <div class="heat-count">%d</div>
                </div>',
                htmlspecialchars($mod['name']),
                htmlspecialchars($mod['name']),
                $mod['total'],
                $pct, $heat,
                htmlspecialchars(substr($mod['module'], 0, 12)),
                $mod['total']
            );
        }

        // Roadmap HTML
        $roadmapHtml = '';
        $phaseColors = ['critical'=>'#fee2e2','immediate'=>'#fee2e2','high'=>'#fef3c7','medium'=>'#dbeafe','low'=>'#f0fdf4'];
        foreach ($roadmap as $idx => $phase) {
            $col  = $phaseColors[strtolower((string)($phase['priority'] ?? ''))] ?? '#f8fafc';
            $tasks = implode('', array_map(fn($t) => '<li>' . htmlspecialchars((string)$t) . '</li>', $phase['tasks'] ?? []));
            $roadmapHtml .= sprintf(
                '<div class="roadmap-phase" style="border-left:4px solid %s;">
                  <div class="phase-header">
                    <span class="phase-num">Phase %d</span>
                    <strong>%s</strong>
                    <span class="phase-dur">%s</span>
                  </div>
                  <p>%s</p>
                  <ul>%s</ul>
                </div>',
                $riskColor,
                $idx + 1,
                htmlspecialchars((string)($phase['phase'] ?? '')),
                htmlspecialchars((string)($phase['duration'] ?? '')),
                htmlspecialchars((string)($phase['description'] ?? '')),
                $tasks
            );
        }

        // Deprecated groups HTML
        $deprecatedHtml = '';
        foreach ($deprecatedGroups as $type => $items) {
            $cnt = count($items);
            $rows = '';
            foreach (array_slice($items, 0, 20) as $item) {
                $rows .= sprintf(
                    '<tr><td>%s</td><td class="mono">%s:%d</td><td>%s</td></tr>',
                    htmlspecialchars($item['priority']),
                    htmlspecialchars(basename($item['file'])),
                    $item['line'],
                    htmlspecialchars(substr($item['message'], 0, 100))
                );
            }
            $deprecatedHtml .= sprintf(
                '<details class="dep-group">
                  <summary><strong>%s</strong> <span class="badge-sm">%d occurrences</span></summary>
                  <table class="inner-table"><tr><th>Priority</th><th>File:Line</th><th>Issue</th></tr>%s</table>
                  %s
                </details>',
                htmlspecialchars(ucwords(str_replace('_', ' ', $type))),
                $cnt,
                $rows,
                $cnt > 20 ? "<p class='note'>…and " . ($cnt-20) . " more</p>" : ''
            );
        }

        // Testing scope summary
        $testCat = $effort['categories']['testing'] ?? [];
        $testHtml = '';
        foreach ($testCat['sub_tasks'] ?? [] as $st) {
            $testHtml .= sprintf('<li><strong>%s</strong> — %sh</li>', htmlspecialchars((string)$st['task']), htmlspecialchars((string)($st['hours'] ?? 0)));
        }


        // Enterprise report sections (built from new helper methods)
        $actionPlanHtml = $this->buildActionPlanHtml($result);
        $testingHtml    = $this->buildTestingMatrixHtml($result);
        $governanceHtml = $this->buildGovernanceHtml($result);
        $cautionHtml    = $this->buildCautionAreasHtml($result);
        $rollbackHtml   = $this->buildRollbackHtml($result);
        $databaseHtml   = $this->buildDatabaseHtml($result);

        // SVG circular progress (score ring)
        $radius = 54; $circumference = 2 * M_PI * $radius;
        $dashoffset = $circumference * (1 - $score / 100);
        $svgRing = sprintf(
            '<svg width="140" height="140" viewBox="0 0 140 140">
              <circle cx="70" cy="70" r="%d" fill="none" stroke="#e5e7eb" stroke-width="12"/>
              <circle cx="70" cy="70" r="%d" fill="none" stroke="%s" stroke-width="12"
                stroke-dasharray="%.2f" stroke-dashoffset="%.2f"
                stroke-linecap="round" transform="rotate(-90 70 70)"/>
              <text x="70" y="70" text-anchor="middle" dominant-baseline="central"
                font-size="28" font-weight="800" fill="%s">%d</text>
              <text x="70" y="90" text-anchor="middle" font-size="11" fill="#6b7280">/ 100</text>
            </svg>',
            $radius, $radius, $scoreColor, $circumference, $dashoffset, $scoreColor, $score
        );

        // ── URI Executive Brief HTML ───────────────────────────────────────────
        $uriHtml = '';
        if ($uri !== null) {
            $uriGradeColor = $uri->gradeColor();
            $top3Blockers  = '';
            $blockersFound = 0;
            // Use Sprint 1 findings (blockers) from the URI sprint breakdown
            $sprint1Findings = $uri->sprintBreakdown[0]['findings'] ?? [];
            foreach ($sprint1Findings as $f) {
                if ($blockersFound < 3) {
                    $top3Blockers .= '<li>' . htmlspecialchars(substr($f['message'] ?? '', 0, 120)) . '</li>';
                    $blockersFound++;
                }
            }

            $sprintHtml = '';
            foreach ($uri->sprintBreakdown as $sp) {
                $fc = count($sp['findings'] ?? []);
                $rows = '';
                foreach (array_slice($sp['findings'] ?? [], 0, 10) as $f) {
                    $rows .= sprintf(
                        '<tr><td>%s</td><td class="mono">%s</td><td>%s</td></tr>',
                        htmlspecialchars($f['type'] ?? ''),
                        htmlspecialchars(basename($f['file'] ?? 'n/a')),
                        htmlspecialchars(substr($f['message'] ?? '', 0, 80))
                    );
                }
                $tableHtml = $fc > 0
                    ? '<table class="inner-table"><tr><th>Type</th><th>File</th><th>Issue</th></tr>' . $rows . '</table>'
                    : '<p class="note">No findings in this sprint</p>';
                $sprintHtml .= sprintf(
                    '<details class="dep-group">
                      <summary><span class="phase-num">Sprint %d</span> <strong>%s</strong> <span class="badge-sm">%d findings · %dh</span></summary>
                      %s
                    </details>',
                    $sp['sprint'], htmlspecialchars($sp['name']), $fc, $sp['total_hours'] ?? 0, $tableHtml
                );
            }

            $uriHtml = sprintf(
                '<div class="card uri-brief no-print-sprint">
                  <div style="display:flex;align-items:center;gap:20px;flex-wrap:wrap;margin-bottom:16px">
                    <div style="text-align:center">
                      <div style="font-size:52px;font-weight:900;color:%s;line-height:1">%s</div>
                      <div style="font-size:13px;color:#64748b">Grade</div>
                    </div>
                    <div>
                      <div style="font-size:36px;font-weight:800;color:%s">%s<span style="font-size:16px;font-weight:400;color:#64748b"> / 100</span></div>
                      <div style="font-size:14px;color:#475569;margin-bottom:4px">%s</div>
                      <div style="font-size:13px;color:#64748b">Upgrade path: <strong>%s → %s</strong></div>
                      <div style="font-size:12px;color:#94a3b8">Generated %s</div>
                    </div>
                    <div style="margin-left:auto;text-align:right">
                      <button class="btn btn-secondary" onclick="window.print()" style="margin-bottom:8px">Print Executive Brief</button>
                      <div style="font-size:12px;color:#64748b">Earliest go-live: <strong>%s</strong></div>
                      <div style="font-size:12px;color:#64748b">Timeline: <strong>%s</strong></div>
                    </div>
                  </div>
                  <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:16px">
                    <div class="kpi"><div class="kpi-val" style="color:#1e3a8a">%dh</div><div class="kpi-lbl">Engineering</div></div>
                    <div class="kpi"><div class="kpi-val" style="color:#7c3aed">%dh</div><div class="kpi-lbl">QA</div></div>
                    <div class="kpi"><div class="kpi-val" style="color:#0891b2">%dh</div><div class="kpi-lbl">Infra</div></div>
                  </div>
                  <div style="margin-bottom:16px">
                    <strong>Top blockers:</strong>
                    <ul style="margin:6px 0 0 20px;font-size:13px;color:#475569">%s</ul>
                  </div>
                  <a href="#tab-sprint" onclick="showTab(\'sprint\',document.querySelector(\'[onclick*=sprint]\'))">↓ View Full Sprint Plan</a>
                </div>
                <div class="card no-print-sprint" id="tab-sprint-anchor">
                  <h2>Sprint Action Plan</h2>
                  %s
                </div>',
                $uriGradeColor, $uri->grade,
                $uriGradeColor, $uri->score,
                htmlspecialchars($uri->gradeDescription),
                htmlspecialchars($vi['current_magento'] ?? ''), htmlspecialchars($vi['target_magento'] ?? ''),
                $generatedAt,
                $uri->goLiveRecommendation,
                $uri->recommendedTimeline,
                $uri->engineeringHours, $uri->qaHours, $uri->infraHours,
                $top3Blockers ?: '<li>No blockers detected</li>',
                $sprintHtml
            );
        }

        // ── DB Risk Register HTML ──────────────────────────────────────────────
        $dbRiskRegisterHtml = '';
        if ($dbSchema !== null && !empty($dbSchema->findings)) {
            $rows = '';
            foreach ($dbSchema->findings as $f) {
                $sev    = $f['severity'] ?? 'info';
                $bgCol  = $sev === 'error' ? '#fee2e2' : '#fefce8';
                $rows  .= sprintf(
                    '<tr style="background:%s"><td class="mono">%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                    $bgCol,
                    htmlspecialchars($f['table'] ?? basename($f['file'] ?? '—')),
                    htmlspecialchars($f['type'] ?? 'schema'),
                    htmlspecialchars(ucfirst($sev)),
                    htmlspecialchars($f['message'] ?? ''),
                    htmlspecialchars($f['remediation'] ?? $f['fix'] ?? '—'),
                );
            }
            $dbRiskRegisterHtml = '<div class="card"><h2>DB Risk Register</h2>
              <table>
                <tr><th>Table / File</th><th>Change Type</th><th>Risk</th><th>Est Duration</th><th>Recommendation</th></tr>
                ' . $rows . '
              </table></div>';
        }

        // ── Composer Audit HTML ────────────────────────────────────────────────
        $composerAuditHtml = '';
        if ($composer !== null && !empty($composer->findings)) {
            $rows = '';
            foreach ($composer->findings as $f) {
                $rows .= sprintf(
                    '<tr><td class="mono">%s</td><td>%s</td><td>—</td><td>%s</td></tr>',
                    htmlspecialchars($f['package'] ?? ''),
                    htmlspecialchars($f['type'] ?? ''),
                    htmlspecialchars(substr($f['message'] ?? '', 0, 100)),
                );
            }
            $composerAuditHtml = '<div class="card"><h2>Composer Audit</h2>
              <table>
                <tr><th>Package</th><th>Issue Type</th><th>Installed Version</th><th>Fix / Replacement</th></tr>
                ' . $rows . '
              </table></div>';
        }

        // Build full HTML
        $html = '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Upgrade Readiness — ' . htmlspecialchars($vi['current_magento'] ?? '') . ' → ' . htmlspecialchars($vi['target_magento'] ?? '') . '</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f1f5f9;color:#1e293b;font-size:14px;line-height:1.5}
a{color:inherit;text-decoration:none}
/* Layout */
.header{background:linear-gradient(135deg,#1e3a8a 0%,#6d28d9 100%);color:#fff;padding:24px 40px;display:flex;justify-content:space-between;align-items:center}
.header h1{font-size:22px;font-weight:700}
.header .meta{font-size:12px;opacity:.75;margin-top:4px}
.header .badge-version{background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.3);border-radius:20px;padding:4px 14px;font-size:13px;font-weight:600;white-space:nowrap}
.container{max-width:1280px;margin:0 auto;padding:28px 20px}
/* Tabs */
.tabs{display:flex;gap:4px;background:#fff;border-radius:10px;padding:6px;box-shadow:0 1px 3px rgba(0,0,0,.08);margin-bottom:24px;flex-wrap:wrap}
.tab-btn{padding:8px 18px;border-radius:7px;border:none;background:none;cursor:pointer;font-size:13px;font-weight:500;color:#64748b;transition:.15s}
.tab-btn.active{background:#1e3a8a;color:#fff}
.tab-btn:hover:not(.active){background:#f1f5f9}
.tab-panel{display:none}.tab-panel.active{display:block}
/* Cards */
.card{background:#fff;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,.08);padding:24px;margin-bottom:20px}
.card h2{font-size:16px;font-weight:700;color:#1e293b;margin-bottom:16px;padding-bottom:10px;border-bottom:2px solid #e2e8f0}
/* KPI grid */
.kpi-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:14px;margin-bottom:20px}
.kpi{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:16px;text-align:center}
.kpi-val{font-size:26px;font-weight:800;line-height:1}
.kpi-lbl{font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.4px;margin-top:4px}
/* Badges */
.badge{display:inline-block;padding:2px 9px;border-radius:20px;font-size:11px;font-weight:700;color:#fff}
.badge-sm{display:inline-block;padding:1px 7px;border-radius:10px;font-size:11px;font-weight:600;background:#e2e8f0;color:#475569}
/* Tables */
table{width:100%;border-collapse:collapse}
th{background:#f8fafc;padding:9px 12px;text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:#64748b;font-weight:600;border-bottom:2px solid #e2e8f0}
td{padding:9px 12px;border-bottom:1px solid #f1f5f9;vertical-align:top}
tr:hover td{background:#fafafa}
.mono{font-family:ui-monospace,monospace;font-size:12px}
/* Search */
.search-bar{display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap}
.search-bar input,.search-bar select{padding:8px 12px;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;background:#fff}
.search-bar input{flex:1;min-width:200px}
.btn{padding:8px 16px;border-radius:8px;border:none;cursor:pointer;font-size:13px;font-weight:600}
.btn-primary{background:#1e3a8a;color:#fff}.btn-secondary{background:#e2e8f0;color:#475569}
/* Heatmap */
.heatmap{display:flex;gap:6px;flex-wrap:wrap;align-items:flex-end;padding:16px 0;min-height:120px}
.heat-cell{display:flex;flex-direction:column;align-items:center;cursor:pointer;width:50px}
.heat-bar{width:38px;border-radius:4px 4px 0 0;min-height:4px;transition:.2s}
.heat-label{font-size:10px;color:#64748b;text-align:center;margin-top:4px;word-break:break-all}
.heat-count{font-size:11px;font-weight:700;color:#374151}
.heat-cell:hover .heat-bar{opacity:.8}
/* Effort */
.effort-row{cursor:pointer}
.effort-row:hover td{background:#eff6ff}
.effort-detail.hidden{display:none}
.detail-box{background:#f8fafc;border-radius:8px;padding:14px;margin:4px 0}
.detail-box ul{margin:8px 0 0 20px}
.sub-text{font-size:12px;color:#64748b;margin-top:2px;font-weight:400}
/* Roadmap */
.roadmap-phase{background:#fff;border-radius:8px;padding:16px;margin-bottom:12px;border-left:4px solid #1e3a8a}
.phase-header{display:flex;align-items:center;gap:10px;margin-bottom:8px}
.phase-num{background:#1e3a8a;color:#fff;border-radius:4px;padding:1px 8px;font-size:11px;font-weight:700}
.phase-dur{margin-left:auto;font-size:12px;color:#64748b}
.roadmap-phase ul{margin:8px 0 0 20px;color:#475569;font-size:13px}
/* Deprecated */
.dep-group{border:1px solid #e2e8f0;border-radius:8px;margin-bottom:8px;overflow:hidden}
.dep-group summary{padding:12px 16px;cursor:pointer;background:#f8fafc;font-size:13px;list-style:none;display:flex;justify-content:space-between;align-items:center}
.dep-group summary:hover{background:#f1f5f9}
.dep-group[open] summary{border-bottom:1px solid #e2e8f0}
.inner-table{font-size:12px}
.inner-table td{padding:6px 10px}
.note{font-size:12px;color:#94a3b8;padding:8px 16px}
/* Module card */
.module-card{border:1px solid #e2e8f0;border-radius:8px;padding:14px;margin-bottom:8px}
.module-card h4{font-size:14px;font-weight:600;margin-bottom:4px}
.module-card .module-stats{display:flex;gap:12px;margin-bottom:8px}
.module-card .module-stat span{font-size:11px;color:#64748b}
.module-card details summary{font-size:12px;color:#2563eb;cursor:pointer;margin-top:6px}
/* Priority colors */
.p-critical{color:#dc2626;font-weight:700}
.p-high{color:#d97706;font-weight:600}
.p-medium{color:#2563eb}
.p-low{color:#6b7280}
/* Risk bars */
.risk-bar-wrap{background:#e5e7eb;border-radius:4px;height:16px;overflow:hidden;margin-top:4px}
.risk-bar{height:16px;border-radius:4px;transition:.4s}
/* Misc */
.two-col{display:grid;grid-template-columns:1fr 1fr;gap:20px}
@media(max-width:700px){.two-col{grid-template-columns:1fr}}
.score-wrap{display:flex;align-items:center;gap:24px}
.score-meta p{margin-bottom:6px;font-size:14px}
footer{text-align:center;padding:24px;color:#94a3b8;font-size:12px}
/* URI grade badge */
.uri-brief{border-left:5px solid #1e3a8a}
/* Print: show only executive brief, hide navigation */
@media print{.tabs,.no-print-sprint{display:none!important}.tab-panel{display:block!important}.header{-webkit-print-color-adjust:exact;print-color-adjust:exact}}
/* Database tab */
.db-ok{background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:14px;color:#16a34a;font-weight:600}
.db-item{border:1px solid #e2e8f0;border-radius:8px;padding:14px;margin-bottom:10px}
.db-sev{display:inline-block;color:#fff;border-radius:4px;padding:2px 8px;font-size:11px;font-weight:700;margin-right:8px}
.db-file{font-family:ui-monospace,monospace;font-size:11px;color:#64748b;margin:6px 0 4px}
.db-fix{font-size:12px;color:#2563eb;margin:4px 0 0}
.db-group{border:1px solid #e2e8f0;border-radius:8px;margin-bottom:8px;overflow:hidden}
.db-group summary{padding:12px 16px;cursor:pointer;background:#f8fafc;font-size:13px;list-style:none;display:flex;align-items:center;gap:10px}
.db-group summary:hover{background:#f1f5f9}.db-group[open] summary{border-bottom:1px solid #e2e8f0}
.db-group-body{padding:14px 16px}
.db-file-row{padding:8px 0;border-bottom:1px solid #f1f5f9}
.db-cnt{background:#e2e8f0;color:#475569;border-radius:10px;padding:1px 8px;font-size:11px;font-weight:600}
/* Action Plan sections */
.ap-section{margin-bottom:24px}.ap-st{font-size:16px;font-weight:700;color:#1e293b;padding-bottom:10px;border-bottom:2px solid #e2e8f0;margin-bottom:16px;display:flex;align-items:center;gap:10px}
.ap-sum-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:14px;margin-bottom:16px}
.ap-sum-card{background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:16px}.ap-big{font-size:24px;font-weight:800;line-height:1.2;margin-top:6px}
.ap-blocker{background:#fff5f5;border:1px solid #fecaca;border-radius:8px;padding:16px;margin-bottom:12px}
.ap-bl-head{display:flex;align-items:center;gap:10px}.ap-tag-crit{background:#dc2626;color:#fff;border-radius:4px;padding:2px 8px;font-size:11px;font-weight:700}
.ap-cnt{font-size:12px;color:#64748b;margin-left:auto}.ap-cnt-badge{color:#fff;border-radius:20px;padding:2px 10px;font-size:12px;font-weight:700}
.ap-grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px}@media(max-width:700px){.ap-grid-3{grid-template-columns:1fr}}
.ap-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:12px}@media(max-width:700px){.ap-grid-2{grid-template-columns:1fr}}
.ap-lbl{font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:#64748b;font-weight:600}
.ap-meta{margin-top:12px;display:flex;gap:20px;font-size:12px;color:#64748b;flex-wrap:wrap;border-top:1px solid #fecaca;padding-top:8px}
.ap-ok{background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:14px;color:#16a34a;font-weight:600}
.ap-hint{font-size:12px;color:#64748b;margin-bottom:8px}
.ap-phase{border:1px solid #e2e8f0;border-radius:8px;margin-bottom:10px;overflow:hidden}
.ap-phase summary{padding:14px 16px;cursor:pointer;display:flex;align-items:center;gap:12px;list-style:none;background:#f8fafc}
.ap-phase summary:hover{background:#eff6ff}.ap-phase[open] summary{border-bottom:1px solid #e2e8f0}
.ap-ph-num{color:#fff;border-radius:4px;padding:2px 8px;font-size:11px;font-weight:700;white-space:nowrap}
.ap-ph-name{font-weight:600;font-size:14px;flex:1}.ap-ph-wk{font-size:12px;color:#64748b;white-space:nowrap}
.ap-ph-body{padding:16px}.ap-tl{margin:6px 0 0 20px;line-height:1.9}.ap-exit{color:#16a34a;font-weight:600}
.ap-dep-list{display:flex;flex-direction:column;gap:8px}.ap-dep{display:flex;align-items:flex-start;gap:12px;background:#f8fafc;border-radius:8px;padding:12px}
.ap-dep-seq{color:#fff;border-radius:4px;padding:2px 8px;font-size:11px;font-weight:700;white-space:nowrap;min-width:36px;text-align:center}
.rag{display:inline-block;color:#fff;border-radius:4px;padding:2px 8px;font-size:11px;font-weight:700}
/* Governance */
.gov-progress-bar{background:#e5e7eb;border-radius:8px;height:12px;overflow:hidden;margin-bottom:4px}
.gov-progress-fill{background:linear-gradient(90deg,#1e3a8a,#16a34a);height:12px;border-radius:8px;transition:.4s}
.gov-status{display:inline-block;border-radius:4px;padding:2px 8px;font-size:11px;font-weight:700;color:#fff}
.gov-req{background:#dc2626}.gov-nice{background:#6b7280}
.gov-check{width:16px;height:16px;cursor:pointer}.gov-owner{background:#eff6ff;color:#1e3a8a;border-radius:4px;padding:2px 8px;font-size:11px;font-weight:600}
/* Caution / Rollback */
.caut-tag{display:inline-block;border-radius:4px;padding:2px 8px;font-size:11px;font-weight:700;white-space:nowrap}
.rb-step{display:flex;align-items:flex-start;gap:12px;padding:10px 0;border-bottom:1px solid #f1f5f9}
.rb-num{color:#fff;border-radius:50%;width:26px;height:26px;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;flex-shrink:0}
.rb-trigger{display:flex;align-items:flex-start;gap:8px;padding:6px 0;font-size:13px}.rb-tri{color:#dc2626;font-size:16px;flex-shrink:0}
.test-num{background:#1e3a8a;color:#fff;border-radius:4px;padding:1px 7px;font-size:11px;font-weight:700}
</style>
</head>
<body>

<div class="header">
  <div>
    <h1>Adobe Commerce Upgrade Readiness Report</h1>
    <div class="meta">Generated ' . $generatedAt . ' &bull; ACQC Enterprise</div>
  </div>
  <div class="badge-version">' . htmlspecialchars(($vi['current_magento'] ?? '?') . ' (PHP ' . ($vi['current_php'] ?? '?') . ') → ' . ($vi['target_magento'] ?? '?') . ' (PHP ' . ($vi['target_php'] ?? '?') . ')') . '</div>
</div>

<div class="container">

<!-- TABS -->
<div class="tabs">
  <button class="tab-btn active" onclick="showTab(\'uri\',this)">URI Score</button>
  <button class="tab-btn" onclick="showTab(\'overview\',this)">Overview</button>
  <button class="tab-btn" onclick="showTab(\'issues\',this)">Issues</button>
  <button class="tab-btn" onclick="showTab(\'effort\',this)">Effort Estimation</button>
  <button class="tab-btn" onclick="showTab(\'modules\',this)">Modules</button>
  <button class="tab-btn" onclick="showTab(\'deprecated\',this)">Deprecated Code</button>
  <button class="tab-btn" onclick="showTab(\'sprint\',this)">Sprint Plan</button>
  <button class="tab-btn" onclick="showTab(\'actionplan\',this)">Action Plan</button>
  <button class="tab-btn" onclick="showTab(\'testing\',this)">Testing Scope</button>
  <button class="tab-btn" onclick="showTab(\'database\',this)">Database</button>
  <button class="tab-btn" onclick="showTab(\'dbrisk\',this)">DB Risk Register</button>
  <button class="tab-btn" onclick="showTab(\'composer\',this)">Composer Audit</button>
  <button class="tab-btn" onclick="showTab(\'governance\',this)">Governance</button>
  <button class="tab-btn" onclick="showTab(\'caution\',this)">Caution Areas</button>
  <button class="tab-btn" onclick="showTab(\'rollback\',this)">Rollback Plan</button>
</div>

<!-- ══ TAB: URI EXECUTIVE BRIEF ════════════════════════════════════════════ -->
<div id="tab-uri" class="tab-panel active">
' . $uriHtml . '
</div>

<!-- ══ TAB: OVERVIEW ══════════════════════════════════════════════════════ -->
<div id="tab-overview" class="tab-panel">

  <div class="two-col">
    <div class="card">
      <h2>Readiness Score</h2>
      <div class="score-wrap">
        ' . $svgRing . '
        <div class="score-meta">
          <p><strong style="color:' . $riskColor . ';font-size:18px;">' . $riskLevel . ' RISK</strong></p>
          <p>Upgrade Possible: <strong>' . $upgradePossible . '</strong></p>
          <p>Total Issues: <strong>' . $totalIssues . '</strong></p>
          <p>Confidence: <strong>' . $confidence . '</strong></p>
        </div>
      </div>
    </div>

    <div class="card">
      <h2>Issue Summary</h2>
      <div class="kpi-grid">
        <div class="kpi"><div class="kpi-val" style="color:#dc2626">' . $critCount . '</div><div class="kpi-lbl">Critical</div></div>
        <div class="kpi"><div class="kpi-val" style="color:#d97706">' . $highCount . '</div><div class="kpi-lbl">High</div></div>
        <div class="kpi"><div class="kpi-val" style="color:#2563eb">' . $medCount . '</div><div class="kpi-lbl">Medium</div></div>
        <div class="kpi"><div class="kpi-val" style="color:#6b7280">' . $lowCount . '</div><div class="kpi-lbl">Low</div></div>
      </div>
      <table>
        <tr><th>Component</th><th>Current</th><th>Target</th></tr>
        <tr><td>Adobe Commerce</td><td>' . htmlspecialchars($vi['current_magento'] ?? '') . '</td><td>' . htmlspecialchars($vi['target_magento'] ?? '') . '</td></tr>
        <tr><td>PHP</td><td>' . htmlspecialchars($vi['current_php'] ?? '') . '</td><td>' . htmlspecialchars($vi['target_php'] ?? '') . '</td></tr>
      </table>
    </div>
  </div>

  <div class="two-col">
    <div class="card">
      <h2>Risk Assessment</h2>
      <p style="margin-bottom:12px">Overall risk score: <strong style="font-size:20px;color:' . $riskColor . '">' . $overallRisk . '/100</strong></p>
      <div style="margin-bottom:10px">
        <div style="display:flex;justify-content:space-between"><span>Technical Risk</span><strong>' . $techRisk . '</strong></div>
        <div class="risk-bar-wrap"><div class="risk-bar" style="width:' . min(100,$techRisk) . '%;background:' . ($techRisk>=70?'#dc2626':($techRisk>=40?'#d97706':'#16a34a')) . '"></div></div>
      </div>
      <div style="margin-bottom:10px">
        <div style="display:flex;justify-content:space-between"><span>Business Risk</span><strong>' . $bizRisk . '</strong></div>
        <div class="risk-bar-wrap"><div class="risk-bar" style="width:' . min(100,$bizRisk) . '%;background:' . ($bizRisk>=70?'#dc2626':($bizRisk>=40?'#d97706':'#16a34a')) . '"></div></div>
      </div>
    </div>

    <div class="card">
      <h2>Effort Summary</h2>
      <div class="kpi-grid">
        <div class="kpi"><div class="kpi-val">' . $idealHours . 'h</div><div class="kpi-lbl">Ideal Effort</div></div>
        <div class="kpi"><div class="kpi-val">' . $idealWeeks . ' wk</div><div class="kpi-lbl">Timeline</div></div>
        <div class="kpi"><div class="kpi-val" style="font-size:18px">' . $minHours . '–' . $maxHours . 'h</div><div class="kpi-lbl">Realistic Range</div></div>
      </div>
    </div>
  </div>

  <div class="card">
    <h2>Module Risk Heatmap <span style="font-size:12px;font-weight:400;color:#64748b">(click to filter issues)</span></h2>
    <div class="heatmap" id="heatmap">' . $heatmapHtml . '</div>
    <p style="font-size:11px;color:#94a3b8;margin-top:8px">Bar height = relative issue density. Red = high risk, green = low risk. Showing top 30 modules.</p>
  </div>

</div><!-- /tab-overview -->

<!-- ══ TAB: ISSUES ════════════════════════════════════════════════════════ -->
<div id="tab-issues" class="tab-panel">
  <div class="card">
    <h2>All Issues</h2>
    <div class="search-bar">
      <input type="text" id="issueSearch" placeholder="Search issues, files, messages…" oninput="filterIssues()">
      <select id="issuePriority" onchange="filterIssues()">
        <option value="">All Priorities</option>
        <option value="critical">Critical</option>
        <option value="high">High</option>
        <option value="medium">Medium</option>
        <option value="low">Low</option>
      </select>
      <select id="issueType" onchange="filterIssues()">
        <option value="">All Types</option>
        <option value="deprecated">Deprecated</option>
        <option value="anti-pattern">Anti-Pattern</option>
        <option value="breaking-change">Breaking Change</option>
        <option value="php_compatibility">PHP Compat</option>
      </select>
      <button class="btn btn-secondary" onclick="exportIssuesCsv()">Export CSV</button>
    </div>
    <p id="issueCount" style="font-size:12px;color:#64748b;margin-bottom:10px"></p>
    <div style="overflow-x:auto;max-height:600px;overflow-y:auto">
    <table id="issueTable">
      <thead><tr>
        <th>Priority</th><th>Module</th><th>File:Line</th><th>Type</th><th>Issue</th><th>Fix</th>
      </tr></thead>
      <tbody id="issueBody"></tbody>
    </table>
    </div>
  </div>
</div>

<!-- ══ TAB: EFFORT ════════════════════════════════════════════════════════ -->
<div id="tab-effort" class="tab-panel">
  <div class="card">
    <h2>Granular Effort Estimation</h2>
    <div class="kpi-grid" style="margin-bottom:20px">
      <div class="kpi"><div class="kpi-val">' . $idealHours . 'h</div><div class="kpi-lbl">Ideal Effort</div></div>
      <div class="kpi"><div class="kpi-val" style="font-size:16px">' . $minHours . '–' . $maxHours . 'h</div><div class="kpi-lbl">Realistic Range</div></div>
      <div class="kpi"><div class="kpi-val">' . $idealWeeks . ' wk</div><div class="kpi-lbl">Ideal Timeline</div></div>
      <div class="kpi"><div class="kpi-val">' . $confidence . '</div><div class="kpi-lbl">Confidence</div></div>
    </div>
    <p style="font-size:12px;color:#64748b;margin-bottom:14px">Click any row to expand sub-tasks and team details.</p>
    <div style="overflow-x:auto">
    <table>
      <thead><tr><th>Work Category</th><th>Complexity</th><th>Ideal</th><th>Range</th><th>Risk Mult.</th><th>Seq.</th></tr></thead>
      <tbody>' . $effortCatHtml . '</tbody>
    </table>
    </div>
    <p style="font-size:11px;color:#94a3b8;margin-top:12px">
      Estimates based on issue count, version gap, module count, and historical Commerce upgrade patterns.
      Realistic range = ideal ×0.75 – ideal ×1.5. Confidence: ' . $confidence . '.
    </p>
  </div>
</div>

<!-- ══ TAB: MODULES ═══════════════════════════════════════════════════════ -->
<div id="tab-modules" class="tab-panel">
  <div class="card">
    <h2>Module-by-Module Drilldown</h2>
    <p style="font-size:13px;color:#64748b;margin-bottom:14px">Issues grouped by module, segregated by type. Expand any module for file-level detail.</p>
    <div id="modCatBar" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px"></div>
    <div class="search-bar">
      <input type="text" id="modSearch" placeholder="Filter modules by name or issue type…" oninput="filterModules()">
      <select id="modCatFilter" onchange="filterModules()" style="padding:8px 12px;border:1px solid #e2e8f0;border-radius:8px;font-size:13px">
        <option value="">All categories</option>
        <option value="custom">Custom Modules</option>
        <option value="extension">Third-Party Extensions</option>
        <option value="core">Magento Core</option>
        <option value="theme">Themes</option>
        <option value="unknown">Other / Unclassified</option>
      </select>
    </div>
    <div id="moduleList"></div>
  </div>
</div>

<!-- ══ TAB: DEPRECATED ════════════════════════════════════════════════════ -->
<div id="tab-deprecated" class="tab-panel">
  <div class="card">
    <h2>Deprecated Code Drilldown</h2>
    <p style="margin-bottom:14px;font-size:13px;color:#64748b">Grouped by issue type. Expand each group to see exact file locations.</p>
    ' . $deprecatedHtml . '
  </div>
</div>

<!-- ══ TAB: ACTION PLAN ═══════════════════════════════════════════════════ -->
<div id="tab-actionplan" class="tab-panel">
  ' . $actionPlanHtml . '
</div>

<!-- ══ TAB: TESTING ═══════════════════════════════════════════════════════ -->
<div id="tab-testing" class="tab-panel">
  ' . $testingHtml . '
</div>

<!-- ══ TAB: DATABASE ══════════════════════════════════════════════════════ -->
<div id="tab-database" class="tab-panel">
  ' . $databaseHtml . '
</div>

<!-- ══ TAB: GOVERNANCE ════════════════════════════════════════════════════ -->
<div id="tab-governance" class="tab-panel">
  ' . $governanceHtml . '
</div>

<!-- ══ TAB: CAUTION AREAS ════════════════════════════════════════════════ -->
<div id="tab-caution" class="tab-panel">
  ' . $cautionHtml . '
</div>

<!-- ══ TAB: SPRINT PLAN ══════════════════════════════════════════════════ -->
<div id="tab-sprint" class="tab-panel no-print-sprint">
  ' . ($uri !== null ? '<div class="card"><h2>Sprint Action Plan</h2>' . $sprintHtml . '</div>' : '<div class="card"><p>Run with --composer-deep and all analyzers to generate sprint plan.</p></div>') . '
</div>

<!-- ══ TAB: DB RISK REGISTER ════════════════════════════════════════════ -->
<div id="tab-dbrisk" class="tab-panel">
  ' . ($dbRiskRegisterHtml ?: '<div class="card"><p>No DB schema findings. Run with --include-database for full DB analysis.</p></div>') . '
</div>

<!-- ══ TAB: COMPOSER AUDIT ══════════════════════════════════════════════ -->
<div id="tab-composer" class="tab-panel">
  ' . ($composerAuditHtml ?: '<div class="card"><p>No Composer findings. Run with --composer-deep to enable Composer audit.</p></div>') . '
</div>

<!-- ══ TAB: ROLLBACK PLAN ════════════════════════════════════════════════ -->
<div id="tab-rollback" class="tab-panel">
  ' . $rollbackHtml . '
</div>

</div><!-- /container -->
<footer>Adobe Commerce Quality Control (ACQC) &bull; Upgrade Readiness &bull; ' . $generatedAt . '</footer>

<script>
const ISSUES = ' . $allIssuesJson . ';
const MODULES = ' . $moduleDataJson . ';

// Tab navigation
function showTab(id, btn) {
  document.querySelectorAll(".tab-panel").forEach(p => p.classList.remove("active"));
  document.querySelectorAll(".tab-btn").forEach(b => b.classList.remove("active"));
  document.getElementById("tab-" + id).classList.add("active");
  btn.classList.add("active");
  if (id === "issues") renderIssues(ISSUES);
  if (id === "modules") { buildModCatBar(MODULES); renderModules(MODULES); }
}

// Effort row toggle
function toggleEffortDetail(row) {
  const next = row.nextElementSibling;
  if (next && next.classList.contains("effort-detail")) {
    next.classList.toggle("hidden");
  }
}

// Issues table
const PRIO_COLORS = {critical:"#dc2626",high:"#d97706",medium:"#2563eb",low:"#6b7280"};
let filteredIssues = ISSUES;
function renderIssues(data) {
  const tbody = document.getElementById("issueBody");
  const cnt = document.getElementById("issueCount");
  tbody.innerHTML = "";
  cnt.textContent = data.length + " issues shown (total scanned: " + ISSUES.length + ")";
  data.slice(0, 300).forEach(i => {
    const tr = document.createElement("tr");
    const col = PRIO_COLORS[i.priority] || "#6b7280";
    const file = i.file.split("/").slice(-2).join("/");
    var recHtml = "";
    if (i.recommendation) {
      var badge = i.recommendation.from_fj ? \'<span style="background:#2563eb;color:#fff;border-radius:3px;padding:1px 5px;font-size:10px">FluffyJaws</span>\' : \'<span style="background:#6b7280;color:#fff;border-radius:3px;padding:1px 5px;font-size:10px">Static</span>\';
      var steps = i.recommendation.step_by_step.split(/\n/).filter(function(l){return l.trim();}).map(function(l){return "<li>"+escHtml(l.replace(/^\d+\.\s*/,""))+"</li>";}).join("");
      var links = i.recommendation.doc_links.map(function(u){return \'<a href="\'+escHtml(u)+\'" target="_blank" style="font-size:11px;color:#2563eb">\'+escHtml(u)+"</a>";}).join(" ");
      recHtml = \'<details style="margin-top:4px"><summary style="cursor:pointer;color:#2563eb;font-size:11px">\'+badge+" Upgrade Recommendation</summary>"
        + \'<div style="padding:6px;background:#f8fafc;border-radius:4px;margin-top:4px">\'
        + \'<p style="margin:0 0 4px"><strong>Root Cause:</strong> \'+escHtml(i.recommendation.root_cause)+"</p>"
        + \'<pre style="margin:0 0 4px;font-size:11px;white-space:pre-wrap">\'+escHtml(i.recommendation.code_example)+"</pre>"
        + \'<ol style="margin:0 0 4px;padding-left:18px;font-size:11px">\'+steps+"</ol>"
        + links+"</div></details>";
    }
    tr.innerHTML = \'<td><span class="badge" style="background:\'+col+\'\">\'+i.priority+"</span></td>"
      + "<td>"+escHtml(i.module)+"</td>"
      + \'<td class="mono">\'+escHtml(file)+":"+i.line+"</td>"
      + "<td>"+escHtml(i.type)+"</td>"
      + "<td>"+escHtml(i.message)+recHtml+"</td>"
      + \'<td style="color:#6b7280;font-size:12px">\'+escHtml(i.fix)+"</td>";
    tbody.appendChild(tr);
  });
}
function filterIssues() {
  const q = document.getElementById("issueSearch").value.toLowerCase();
  const p = document.getElementById("issuePriority").value;
  const t = document.getElementById("issueType").value;
  filteredIssues = ISSUES.filter(i => {
    if (p && i.priority !== p) return false;
    if (t && i.type !== t) return false;
    if (q && !(i.file+i.message+i.module+i.fix).toLowerCase().includes(q)) return false;
    return true;
  });
  renderIssues(filteredIssues);
}
function exportIssuesCsv() {
  const rows = [["Priority","Module","File","Line","Type","Issue","Fix"]];
  filteredIssues.forEach(i => rows.push([i.priority, i.module, i.file, i.line, i.type, i.message, i.fix]));
  const csv = rows.map(r => r.map(c => `"${String(c).replace(/"/g,\'""\')}"`).join(",")).join("\n");
  const a = document.createElement("a");
  a.href = "data:text/csv;charset=utf-8," + encodeURIComponent(csv);
  a.download = "upgrade-issues.csv";
  a.click();
}

// Module drilldown
function filterModuleIssues(name) {
  showTab("issues", document.querySelectorAll(".tab-btn")[1]);
  document.getElementById("modSearch") && (document.getElementById("modSearch").value = "");
  document.getElementById("issueSearch").value = name;
  filterIssues();
}
const CAT_META = {
  custom:    { label: "Custom Modules",          color: "#2563eb", bg: "#eff6ff", icon: "⚙" },
  extension: { label: "Third-Party Extensions",  color: "#d97706", bg: "#fef3c7", icon: "🔌" },
  core:      { label: "Magento Core",            color: "#6b7280", bg: "#f8fafc", icon: "🏛" },
  theme:     { label: "Themes",                  color: "#16a34a", bg: "#f0fdf4", icon: "🎨" },
  unknown:   { label: "Other / Unclassified",    color: "#64748b", bg: "#f8fafc", icon: "📁" },
};

function buildModCatBar(mods) {
  const bar = document.getElementById("modCatBar");
  if (!bar) return;
  const counts = {};
  mods.forEach(m => { counts[m.category] = (counts[m.category]||0) + m.total; });
  bar.innerHTML = "";
  Object.entries(CAT_META).forEach(([key, meta]) => {
    if (!counts[key]) return;
    const chip = document.createElement("span");
    chip.style.cssText = `display:inline-flex;align-items:center;gap:6px;background:${meta.bg};border:1px solid ${meta.color}30;border-radius:20px;padding:5px 12px;font-size:12px;font-weight:600;cursor:pointer;color:${meta.color}`;
    chip.innerHTML = `${meta.icon} ${meta.label} <span style="background:${meta.color};color:#fff;border-radius:10px;padding:1px 7px;font-size:11px">${counts[key]}</span>`;
    chip.onclick = () => {
      document.getElementById("modCatFilter").value = key;
      filterModules();
    };
    bar.appendChild(chip);
  });
}

function renderModules(mods) {
  const q       = (document.getElementById("modSearch")?.value || "").toLowerCase();
  const catF    = document.getElementById("modCatFilter")?.value || "";
  const list    = document.getElementById("moduleList");
  list.innerHTML = "";

  let filtered = mods.filter(m => {
    if (catF && m.category !== catF) return false;
    if (q && !(m.name + (m.topTypes||[]).join(" ")).toLowerCase().includes(q)) return false;
    return true;
  });

  // Group by category
  const byCategory = {};
  filtered.forEach(m => {
    if (!byCategory[m.category]) byCategory[m.category] = [];
    byCategory[m.category].push(m);
  });

  const catOrder = ["custom","extension","core","theme","unknown"];
  let rendered = 0;

  catOrder.forEach(cat => {
    const catMods = byCategory[cat];
    if (!catMods || catMods.length === 0) return;
    const meta = CAT_META[cat];

    // Category header
    const catTotal = catMods.reduce((s, m) => s + m.total, 0);
    const catCrit  = catMods.reduce((s, m) => s + m.critical, 0);
    const hdr = document.createElement("div");
    hdr.style.cssText = `background:${meta.bg};border-left:4px solid ${meta.color};border-radius:8px;padding:12px 16px;margin:16px 0 10px;display:flex;align-items:center;gap:12px`;
    hdr.innerHTML = `<span style="font-size:18px">${meta.icon}</span>
      <div style="flex:1">
        <strong style="font-size:15px;color:${meta.color}">${meta.label}</strong>
        <span style="font-size:12px;color:#64748b;margin-left:10px">${catMods.length} module${catMods.length!==1?"s":""}</span>
      </div>
      <span style="background:${meta.color};color:#fff;border-radius:20px;padding:4px 12px;font-size:13px;font-weight:700">${catTotal} issues</span>
      ${catCrit > 0 ? `<span style="background:#dc2626;color:#fff;border-radius:20px;padding:4px 10px;font-size:12px;font-weight:700">${catCrit} critical</span>` : ""}`;
    list.appendChild(hdr);

    // Module cards within category
    catMods.forEach(m => {
      const div  = document.createElement("div");
      div.className = "module-card";
      div.style.borderLeft = `3px solid ${meta.color}`;

      const heat   = m.critical > 0 ? "#dc2626" : m.high > 0 ? "#d97706" : m.total >= 10 ? "#d97706" : "#16a34a";
      const topT   = (m.topTypes||[]).slice(0,3).map(t => `<span style="background:#e2e8f0;color:#475569;border-radius:4px;padding:1px 6px;font-size:11px;margin-right:4px">${escHtml(t)}</span>`).join("");
      const modLabel = m.vendor && m.module && m.name !== m.module ? `<span style="font-size:11px;color:#64748b;font-weight:400"> (${escHtml(m.vendor)} / ${escHtml(m.module)})</span>` : "";

      let rows = m.issues.slice(0,8).map(i => {
        const pCol = {critical:"#dc2626",high:"#d97706",medium:"#2563eb",low:"#6b7280"}[i.priority]||"#6b7280";
        const fname = i.file.split("/").slice(-2).join("/");
        return `<tr>
          <td><span style="background:${pCol};color:#fff;border-radius:3px;padding:1px 6px;font-size:10px;font-weight:700">${i.priority}</span></td>
          <td class="mono" style="font-size:11px;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${escHtml(i.file)}">${escHtml(fname)}:${i.line}</td>
          <td style="font-size:11px;color:#475569">${escHtml(i.type||"")}</td>
          <td style="font-size:11px;max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${escHtml(i.message)}">${escHtml(i.message.substring(0,80))}</td>
          <td style="font-size:10px;color:#94a3b8;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${escHtml(i.fix||"")}">${escHtml((i.fix||"").substring(0,50))}</td>
        </tr>`;
      }).join("");
      if (m.issues.length > 8) rows += `<tr><td colspan="5" style="color:#94a3b8;font-size:11px;padding:6px 12px">…and ${m.issues.length-8} more issues in this module</td></tr>`;

      div.innerHTML = `
        <div style="display:flex;align-items:flex-start;gap:10px;flex-wrap:wrap">
          <div style="flex:1;min-width:200px">
            <strong style="font-size:14px">${escHtml(m.name)}</strong>${modLabel}
            <div style="margin-top:6px">${topT}</div>
          </div>
          <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
            <span class="badge" style="background:${heat};font-size:12px">${m.total} issues</span>
            <span style="font-size:12px;color:#64748b">${m.fileCount||"?"} file${m.fileCount!==1?"s":""}</span>
          </div>
        </div>
        <div style="margin:10px 0;display:flex;gap:14px;font-size:12px">
          ${m.critical>0?`<span><strong style="color:#dc2626">${m.critical}</strong> critical</span>`:""}
          ${m.high>0?`<span><strong style="color:#d97706">${m.high}</strong> high</span>`:""}
          ${m.medium>0?`<span><strong style="color:#2563eb">${m.medium}</strong> medium</span>`:""}
          ${m.low>0?`<span><strong style="color:#6b7280">${m.low}</strong> low</span>`:""}
        </div>
        <details>
          <summary style="font-size:12px;color:#2563eb;cursor:pointer;margin-top:4px;list-style:none;display:flex;align-items:center;gap:6px">
            <span>▶ Show issues</span>
          </summary>
          <div style="overflow-x:auto;margin-top:10px">
          <table style="min-width:600px">
            <thead><tr><th>Priority</th><th>File:Line</th><th>Type</th><th>Issue</th><th>Fix</th></tr></thead>
            <tbody>${rows}</tbody>
          </table>
          </div>
        </details>
        <div style="margin-top:10px">
          <button class="btn btn-secondary" style="font-size:11px;padding:4px 10px" onclick="filterModuleIssues(\'${escHtml(m.name)}\')">View all in Issues tab</button>
        </div>`;
      list.appendChild(div);
      rendered++;
    });
  });

  if (rendered === 0) {
    list.innerHTML = "<p style=\'color:#94a3b8;text-align:center;padding:30px\'>No modules match your filter.</p>";
  }
}
function filterModules() { renderModules(MODULES); }
function escHtml(s) { return String(s).replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;"); }

// On load
document.addEventListener("DOMContentLoaded", () => {
  renderIssues(ISSUES);
  buildModCatBar(MODULES);
  renderModules(MODULES);
});
</script>
</body>
</html>';

        // Ensure output directory exists
        $dir = dirname($outputPath);
        if ($dir && !is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($outputPath, $html);
    }


    // ═══════════════════════════════════════════════════════════════════════════
    // HTML REPORT HELPER METHODS
    // ═══════════════════════════════════════════════════════════════════════════

    private function buildDatabaseHtml($result): string
    {
        $db         = $result->getDatabaseIssues();
        $effort     = $db['effort_estimation'] ?? [];
        $rollback   = $db['rollback_plan']    ?? [];
        $riskLevel  = $rollback['risk_level'] ?? 'UNKNOWN';
        $riskColor  = $riskLevel === 'HIGH' ? '#dc2626' : ($riskLevel === 'MEDIUM' ? '#d97706' : '#16a34a');
        $riskBg     = $riskLevel === 'HIGH' ? '#fef2f2' : ($riskLevel === 'MEDIUM' ? '#fef3c7' : '#f0fdf4');

        // ── Counts ──
        $schemaCount = count($db['schema'] ?? []);
        $directSql   = $db['direct_sql'] ?? [];
        $sqlCount    = count($directSql);
        $eavCount    = count($db['eav_attributes'] ?? []);
        $indexCount  = count($db['indexers'] ?? []);
        $largeCount  = count($db['large_table_risks'] ?? []);
        $bcCount     = count($db['backward_compatibility'] ?? []);
        $scripts     = $db['setup_scripts'] ?? [];
        $scriptCount = array_sum(array_map('count', $scripts));
        $patchCount  = count($db['data_patches'] ?? []);

        $totalDbIssues = $schemaCount + $sqlCount + $eavCount + $indexCount + $largeCount + $bcCount + $scriptCount;

        // ── Overview KPIs ──
        $overviewHtml = sprintf('
<div class="kpi-grid" style="margin-bottom:24px">
  <div class="kpi"><div class="kpi-val" style="color:%s">%s</div><div class="kpi-lbl">Rollback Risk</div></div>
  <div class="kpi"><div class="kpi-val">%d</div><div class="kpi-lbl">Total DB Issues</div></div>
  <div class="kpi"><div class="kpi-val">%d</div><div class="kpi-lbl">Direct SQL Queries</div></div>
  <div class="kpi"><div class="kpi-val">%d</div><div class="kpi-lbl">EAV Issues</div></div>
  <div class="kpi"><div class="kpi-val">%d</div><div class="kpi-lbl">Large Table Risks</div></div>
  <div class="kpi"><div class="kpi-val">%d</div><div class="kpi-lbl">BC Issues</div></div>
  <div class="kpi"><div class="kpi-val">%d</div><div class="kpi-lbl">Legacy Scripts</div></div>
</div>',
            $riskColor, htmlspecialchars($riskLevel),
            $totalDbIssues, $sqlCount, $eavCount, $largeCount, $bcCount, $scriptCount
        );

        // ── 1. Schema Analysis ──
        $schemaHtml = '';
        if (empty($db['schema'])) {
            $schemaHtml = '<div class="db-ok">✓ No db_schema.xml issues detected — declarative schema is healthy.</div>';
        } else {
            foreach ($db['schema'] as $item) {
                $sev   = $item['severity'] ?? 'medium';
                $sColor = ['critical'=>'#dc2626','high'=>'#d97706','medium'=>'#2563eb','low'=>'#16a34a'][$sev] ?? '#6b7280';
                $schemaHtml .= sprintf(
                    '<div class="db-item"><span class="db-sev" style="background:%s">%s</span><strong>%s</strong><p class="db-file">%s</p><p class="db-fix">Fix: %s</p></div>',
                    $sColor, strtoupper($sev),
                    htmlspecialchars($item['message'] ?? ''),
                    htmlspecialchars($this->shortenPath($item['file'] ?? '')),
                    htmlspecialchars($item['fix'] ?? '')
                );
            }
        }

        // ── 2. Direct SQL (grouped by severity) ──
        $sqlBySev = [];
        foreach ($directSql as $item) {
            $s = $item['severity'] ?? 'medium';
            $sqlBySev[$s][] = $item;
        }
        $sqlHtml = '';
        if (empty($directSql)) {
            $sqlHtml = '<div class="db-ok">✓ No direct SQL queries detected.</div>';
        } else {
            $sqlHtml = sprintf(
                '<div style="background:#fef3c7;border:1px solid #fcd34d;border-radius:8px;padding:14px;margin-bottom:16px">
                  <strong style="color:#92400e">⚠ %d direct SQL query/queries detected</strong>
                  <p style="font-size:13px;color:#78350f;margin-top:6px">Direct SQL bypasses Magento\'s schema compatibility layer. Review each query for compatibility with the target version schema.</p>
                </div>', $sqlCount
            );
            $sevOrder = ['critical','high','medium','low'];
            foreach ($sevOrder as $sev) {
                if (empty($sqlBySev[$sev])) continue;
                $items  = $sqlBySev[$sev];
                $sColor = ['critical'=>'#dc2626','high'=>'#d97706','medium'=>'#2563eb','low'=>'#16a34a'][$sev] ?? '#6b7280';
                // Group by file to reduce noise
                $byFile = [];
                foreach ($items as $item) {
                    $f = $this->shortenPath($item['file'] ?? 'unknown');
                    $byFile[$f][] = $item;
                }
                $sqlHtml .= sprintf('<details class="db-group"><summary><span class="db-sev" style="background:%s">%s</span> %d occurrence%s</summary><div class="db-group-body">',
                    $sColor, strtoupper($sev), count($items), count($items) !== 1 ? 's' : '');
                foreach (array_slice($byFile, 0, 20, true) as $file => $fileItems) {
                    $sqlHtml .= sprintf('<div class="db-file-row"><strong class="mono">%s</strong> <span class="db-cnt">%d hit%s</span>',
                        htmlspecialchars($file), count($fileItems), count($fileItems) !== 1 ? 's' : '');
                    foreach (array_slice($fileItems, 0, 3) as $fi) {
                        $sqlHtml .= sprintf('<p style="margin:4px 0 0 12px;font-size:12px;color:#475569">Line %d — %s</p>', (int)($fi['line'] ?? 0), htmlspecialchars($fi['message'] ?? ''));
                    }
                    if (count($fileItems) > 3) {
                        $sqlHtml .= sprintf('<p style="margin:4px 0 0 12px;font-size:11px;color:#94a3b8">…and %d more hits in this file</p>', count($fileItems) - 3);
                    }
                    $sqlHtml .= '</div>';
                }
                if (count($byFile) > 20) {
                    $sqlHtml .= sprintf('<p style="color:#94a3b8;font-size:12px;padding:8px">…and %d more files</p>', count($byFile) - 20);
                }
                $sqlHtml .= '</div></details>';
            }
        }

        // ── 3. EAV Attributes ──
        $eavHtml = '';
        if (empty($db['eav_attributes'])) {
            $eavHtml = '<div class="db-ok">✓ No EAV attribute issues detected.</div>';
        } else {
            foreach ($db['eav_attributes'] as $item) {
                $sev    = $item['severity'] ?? 'high';
                $sColor = ['critical'=>'#dc2626','high'=>'#d97706','medium'=>'#2563eb','low'=>'#16a34a'][$sev] ?? '#6b7280';
                $eavHtml .= sprintf(
                    '<div class="db-item"><span class="db-sev" style="background:%s">%s</span><strong>%s</strong><p class="db-file">%s</p><p class="db-fix">Fix: %s</p></div>',
                    $sColor, strtoupper($sev),
                    htmlspecialchars($item['message'] ?? ''),
                    htmlspecialchars($this->shortenPath($item['file'] ?? '')),
                    htmlspecialchars($item['fix'] ?? '')
                );
            }
        }

        // ── 4. Setup Scripts ──
        $setupHtml = '';
        $hasScripts = false;
        foreach ($scripts as $type => $items) {
            if (empty($items)) continue;
            $hasScripts = true;
            $typeLabel  = ucwords(str_replace('_', ' ', $type));
            $setupHtml .= sprintf('<details class="db-group"><summary><strong>%s</strong> <span class="db-cnt">%d file%s</span></summary><div class="db-group-body">', htmlspecialchars($typeLabel), count($items), count($items) !== 1 ? 's' : '');
            foreach ($items as $item) {
                $setupHtml .= sprintf('<div class="db-file-row"><strong class="mono">%s</strong><p style="font-size:12px;color:#475569;margin-top:4px">%s</p><p class="db-fix">Fix: %s</p></div>',
                    htmlspecialchars($this->shortenPath($item['file'] ?? '')),
                    htmlspecialchars($item['message'] ?? ''),
                    htmlspecialchars($item['fix'] ?? ''));
            }
            $setupHtml .= '</div></details>';
        }
        if (!$hasScripts) {
            $setupHtml = '<div class="db-ok">✓ No legacy setup scripts found — declarative schema in use.</div>';
        }

        // ── 5. Indexers ──
        $idxHtml = '';
        if (empty($db['indexers'])) {
            $idxHtml = '<div class="db-ok">✓ Indexer configurations look healthy.</div>';
        } else {
            foreach ($db['indexers'] as $item) {
                $sev    = $item['severity'] ?? 'medium';
                $sColor = ['critical'=>'#dc2626','high'=>'#d97706','medium'=>'#2563eb','low'=>'#16a34a'][$sev] ?? '#6b7280';
                $idxHtml .= sprintf(
                    '<div class="db-item"><span class="db-sev" style="background:%s">%s</span><strong>%s</strong><p class="db-fix">Fix: %s</p></div>',
                    $sColor, strtoupper($sev),
                    htmlspecialchars($item['message'] ?? ''),
                    htmlspecialchars($item['fix'] ?? '')
                );
            }
        }

        // ── 6. Large Table Risks ──
        $ltHtml = '';
        if (empty($db['large_table_risks'])) {
            $ltHtml = '<div class="db-ok">✓ No large table upgrade risks detected.</div>';
        } else {
            $ltHtml = '<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:14px;margin-bottom:16px">
              <strong style="color:#dc2626">⚠ Large tables detected — ALTER TABLE operations may cause extended locks</strong>
              <p style="font-size:13px;color:#7f1d1d;margin-top:6px">Use pt-online-schema-change or gh-ost for zero-downtime schema changes on these tables.</p>
            </div>';
            $ltHtml .= '<table><thead><tr><th>Table</th><th>Issue</th><th>Rollback Risk</th><th>Recommended Fix</th></tr></thead><tbody>';
            foreach ($db['large_table_risks'] as $risk) {
                $rr  = $risk['rollback_risk'] ?? 'UNKNOWN';
                $rrC = $rr === 'HIGH' ? '#dc2626' : ($rr === 'MEDIUM' ? '#d97706' : '#16a34a');
                $ltHtml .= sprintf(
                    '<tr><td><strong class="mono">%s</strong></td><td>%s</td><td><span style="color:%s;font-weight:700">%s</span></td><td style="font-size:12px">%s</td></tr>',
                    htmlspecialchars($risk['table'] ?? ''),
                    htmlspecialchars($risk['message'] ?? ''),
                    $rrC, htmlspecialchars($rr),
                    htmlspecialchars($risk['fix'] ?? '')
                );
            }
            $ltHtml .= '</tbody></table>';
        }

        // ── 7. Backward Compatibility ──
        $bcHtml = '';
        if (empty($db['backward_compatibility'])) {
            $bcHtml = '<div class="db-ok">✓ No backward compatibility issues detected.</div>';
        } else {
            foreach ($db['backward_compatibility'] as $item) {
                $sev    = $item['severity'] ?? 'high';
                $sColor = ['critical'=>'#dc2626','high'=>'#d97706','medium'=>'#2563eb','low'=>'#16a34a'][$sev] ?? '#6b7280';
                $bcHtml .= sprintf(
                    '<div class="db-item"><span class="db-sev" style="background:%s">%s</span><strong>%s</strong>%s<p class="db-file">%s</p><p class="db-fix">Fix: %s</p></div>',
                    $sColor, strtoupper($sev),
                    htmlspecialchars($item['message'] ?? ''),
                    !empty($item['class']) ? '<p style="font-size:12px;color:#2563eb;margin-top:4px">Class: ' . htmlspecialchars($item['class']) . '</p>' : '',
                    htmlspecialchars($this->shortenPath($item['file'] ?? '')),
                    htmlspecialchars($item['fix'] ?? '')
                );
            }
        }

        // ── 8. Rollback Plan ──
        $deploySeq   = $rollback['deployment_sequence']   ?? [];
        $migRisks    = $rollback['data_migration_risks']  ?? [];
        $checklist   = $rollback['checklist']             ?? [];
        $deployHtml  = implode('', array_map(fn($s, $i) => sprintf(
            '<div style="display:flex;gap:10px;padding:8px 0;border-bottom:1px solid #f1f5f9"><span style="background:#1e3a8a;color:#fff;border-radius:50%%;width:24px;height:24px;display:inline-flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0">%d</span><span style="font-size:13px">%s</span></div>',
            $i + 1, htmlspecialchars($s)
        ), $deploySeq, array_keys($deploySeq)));
        $migHtml     = implode('', array_map(fn($r) => '<li style="line-height:1.9;font-size:13px">' . htmlspecialchars($r) . '</li>', $migRisks));
        $checkHtml   = implode('', array_map(fn($c) => "<li><label><input type='checkbox' style='margin-right:6px'><span style='font-size:13px'>" . htmlspecialchars($c) . "</span></label></li>", $checklist));

        return '
<div class="card">
  <h2>Database Upgrade Analysis</h2>
  ' . $overviewHtml . '
</div>

<div class="two-col">
  <div class="card">
    <h2>📋 Declarative Schema (db_schema.xml)</h2>
    ' . $schemaHtml . '
  </div>
  <div class="card">
    <h2>🔧 Legacy Setup Scripts</h2>
    ' . $setupHtml . '
  </div>
</div>

<div class="card">
  <h2>🗂 Direct SQL Queries <span style="background:#d97706;color:#fff;border-radius:20px;padding:2px 10px;font-size:12px;font-weight:700;margin-left:8px">' . $sqlCount . ' found</span></h2>
  <p style="font-size:13px;color:#64748b;margin-bottom:14px">Direct SQL queries bypass the schema abstraction layer. Grouped by severity — expand to drill down to individual files and lines.</p>
  ' . $sqlHtml . '
</div>

<div class="two-col">
  <div class="card">
    <h2>🏷 EAV Attribute Analysis <span style="background:' . ($eavCount > 0 ? '#d97706' : '#16a34a') . ';color:#fff;border-radius:20px;padding:2px 10px;font-size:12px;font-weight:700;margin-left:8px">' . $eavCount . ' issue' . ($eavCount !== 1 ? 's' : '') . '</span></h2>
    ' . $eavHtml . '
  </div>
  <div class="card">
    <h2>📊 Indexer Configuration</h2>
    ' . $idxHtml . '
  </div>
</div>

<div class="card">
  <h2>🏔 Large Table Upgrade Risks <span style="background:' . ($largeCount > 0 ? '#dc2626' : '#16a34a') . ';color:#fff;border-radius:20px;padding:2px 10px;font-size:12px;font-weight:700;margin-left:8px">' . $largeCount . ' table' . ($largeCount !== 1 ? 's' : '') . '</span></h2>
  ' . $ltHtml . '
</div>

<div class="card">
  <h2>🔄 Backward Compatibility <span style="background:' . ($bcCount > 0 ? '#d97706' : '#16a34a') . ';color:#fff;border-radius:20px;padding:2px 10px;font-size:12px;font-weight:700;margin-left:8px">' . $bcCount . ' issue' . ($bcCount !== 1 ? 's' : '') . '</span></h2>
  ' . $bcHtml . '
</div>

<div class="two-col">
  <div class="card" style="background:' . $riskBg . ';border:1px solid ' . $riskColor . '40">
    <h2 style="color:' . $riskColor . '">🛡 Deployment Sequence</h2>
    <p style="font-size:12px;color:#64748b;margin-bottom:12px">Follow this exact order on upgrade day. Do not skip steps.</p>
    ' . ($deployHtml ?: '<p style="color:#94a3b8">No sequence defined.</p>') . '
  </div>
  <div class="card">
    <h2>⚠ Data Migration Risks</h2>
    <ul style="padding-left:18px;margin-top:0">' . $migHtml . '</ul>
  </div>
</div>

<div class="card">
  <h2>✅ Database Pre-deployment Checklist</h2>
  <p style="font-size:13px;color:#64748b;margin-bottom:12px">Complete all items before running setup:upgrade on production.</p>
  <ul style="list-style:none;line-height:2.3;padding-left:0">' . $checkHtml . '</ul>
</div>
';
    }

    private function buildActionPlanHtml($result): string
    {
        $stats      = $result->getFullScanStats();
        $score      = (int)($stats['score']    ?? $result->getUpgradeReadinessScore());
        $critCount  = (int)($stats['critical'] ?? 0);
        $highCount  = (int)($stats['high']     ?? 0);
        $medCount   = (int)($stats['medium']   ?? 0);
        $effort     = $result->getEffortEstimation();
        $totalHours = (float)($effort['total_hours'] ?? 0);
        $totalWeeks = (float)($effort['timeline_weeks'] ?? round($totalHours / 25, 1));
        $prioritized = $result->getPrioritizedIssues();
        $currentV   = $result->getCurrentMagentoVersion()->toString();
        $targetV    = $result->getTargetMagentoVersion()->toString();
        $possible   = $result->isUpgradePossible();

        $complexityLabel = match(true) {
            $critCount > 20 || $highCount > 40 => 'EXTREME',
            $critCount > 10 || $highCount > 20 => 'HIGH',
            $critCount > 5  || $highCount > 10 => 'MEDIUM',
            $critCount > 0                     => 'LOW-MEDIUM',
            default                            => 'LOW',
        };
        $verdict   = !$possible ? 'BLOCKED' : ($score >= 75 ? 'PROCEED' : ($score >= 50 ? 'PROCEED WITH CAUTION' : 'PLAN REQUIRED'));
        $vColor    = match($verdict) { 'PROCEED' => '#16a34a', 'BLOCKED' => '#7c3aed', default => '#d97706' };
        $vBg       = match($verdict) { 'PROCEED' => '#f0fdf4', 'BLOCKED' => '#f5f3ff', default => '#fef3c7' };
        $sColor    = $score >= 75 ? '#16a34a' : ($score >= 50 ? '#d97706' : '#dc2626');

        // 2. Immediate Blockers
        $critIssues  = $prioritized['critical'] ?? [];
        $bGroups     = [];
        foreach ($critIssues as $i) { $bGroups[$i['type'] ?? 'unknown'][] = $i; }
        $blockersHtml = empty($bGroups)
            ? '<div class="ap-ok">✓ No immediate blockers detected.</div>'
            : '';
        foreach ($bGroups as $type => $issues) {
            $cnt  = count($issues);
            $s    = $issues[0];
            $eMin = max(1, (int)($cnt * 0.5));
            $eMax = max(2, $cnt);
            $blockersHtml .= sprintf(
                '<div class="ap-blocker">
                  <div class="ap-bl-head">
                    <span class="ap-tag-crit">BLOCKER</span>
                    <strong>%s</strong>
                    <span class="ap-cnt">%d occurrence%s</span>
                  </div>
                  <div class="ap-grid-3" style="margin-top:10px">
                    <div><span class="ap-lbl">Root Cause</span><p>%s</p></div>
                    <div><span class="ap-lbl">Business Impact</span><p>Upgrade will fail or produce runtime errors in production</p></div>
                    <div><span class="ap-lbl">Recommended Fix</span><p>%s</p></div>
                  </div>
                  <div class="ap-meta"><span><b>Priority:</b> P1 — Sprint 1</span><span><b>Effort:</b> %d–%dh</span><span><b>Validate:</b> Unit tests + integration tests after each fix</span></div>
                </div>',
                htmlspecialchars(ucwords(str_replace(['_','-'],' ',$type))),
                $cnt, $cnt !== 1 ? 's' : '',
                htmlspecialchars($s['risk'] ?? 'Compatibility break — will fail in target version'),
                htmlspecialchars($s['fix']  ?? 'Review Adobe Commerce migration guide'),
                $eMin, $eMax
            );
        }

        // 3. Complexity Rating
        $dbCount = count($result->getDatabaseIssues());
        $testH   = (float)(($effort['categories']['testing'] ?? [])['ideal_hours'] ?? 0);
        $themeH  = (float)(($effort['categories']['theme']   ?? [])['ideal_hours'] ?? 12);
        $ragRows = [
            ['Code Customization', $critCount.' critical + '.$medCount.' medium issues',
                $critCount > 10 ? 'RED' : ($critCount > 0 ? 'AMBER' : 'GREEN')],
            ['Extensions',          'Third-party compatibility check recommended', 'AMBER'],
            ['Theme / Frontend',    number_format($themeH,0).'h estimated frontend effort',
                $themeH > 40 ? 'RED' : ($themeH > 20 ? 'AMBER' : 'GREEN')],
            ['Infrastructure',      'PHP & environment compatibility analysed', 'GREEN'],
            ['Database / Schema',   $dbCount > 3 ? $dbCount.' DB issues detected' : 'No schema blockers',
                $dbCount > 5 ? 'RED' : ($dbCount > 0 ? 'AMBER' : 'GREEN')],
            ['Testing Scope',       number_format($testH,0).'h test effort required',
                $testH > 200 ? 'RED' : ($testH > 80 ? 'AMBER' : 'GREEN')],
        ];
        $ragHtml = '';
        foreach ($ragRows as $r) {
            $rC = $r[2]==='RED' ? '#dc2626' : ($r[2]==='AMBER' ? '#d97706' : '#16a34a');
            $ragHtml .= sprintf('<tr><td><strong>%s</strong></td><td>%s</td><td><span class="rag" style="background:%s">%s</span></td></tr>',
                htmlspecialchars($r[0]), htmlspecialchars($r[1]), $rC, $r[2]);
        }

        // 4. Phase-wise roadmap
        $phases = [
            ['num'=>1,'c'=>'#2563eb','name'=>'Environment Preparation & Code Audit','weeks'=>'1–2',
             'goal'=>'Upgrade branch created, full ACQC audit run, CI green',
             'owner'=>'Tech Lead + DevOps',
             'tasks'=>['Create upgrade branch from production HEAD','Run full ACQC audit — all 12 categories','Update composer.json to target version constraints','Verify CI/CD pipeline on upgrade branch','Establish PHPStan + PHPCS quality baseline'],
             'exit'=>'Audit report signed off, branch ready, CI green'],
            ['num'=>2,'c'=>'#dc2626','name'=>'Critical Issue Remediation','weeks'=>'2–'.max(3,2+(int)ceil($critCount/5)),
             'goal'=>"Resolve all {$critCount} critical blockers — zero critical before any deployment",
             'owner'=>'Senior Backend Engineers',
             'tasks'=>['Fix unserialize() → json_decode() (RCE security critical)','Replace deprecated Registry pattern with state management','Remove ObjectManager::getInstance() direct calls','Convert legacy setup scripts to declarative schema','Fix PHP 8.x type errors and removed function usage'],
             'exit'=>'Zero critical issues in ACQC rescan, unit tests pass'],
            ['num'=>3,'c'=>'#d97706','name'=>'High-Priority & Deprecated Code Fixes','weeks'=>($highCount>20?'4–6':'2–3'),
             'goal'=>'Eliminate deprecated APIs removed in target version',
             'owner'=>'Backend Engineers',
             'tasks'=>['Replace deprecated method calls with current equivalents','Migrate Zend Framework → Laminas class references','Convert serialize() → json_encode() where appropriate','Replace model->save() with repository->save() patterns','Update constructor injection anti-patterns'],
             'exit'=>'High-priority issues reduced ≥80%, peer review complete'],
            ['num'=>4,'c'=>'#d97706','name'=>'Database Schema & Migration Preparation','weeks'=>'1–2',
             'goal'=>'All schema changes declarative, migrations safe for production run',
             'owner'=>'Backend Engineers + DBA',
             'tasks'=>['Convert InstallSchema/UpgradeSchema → db_schema.xml','Create DataPatch classes for all InstallData/UpgradeData scripts','Run setup:db:schema:upgrade --dry-run on staging','Test bin/magento setup:upgrade with production data snapshot','Document table changes for rollback reference'],
             'exit'=>'setup:upgrade completes cleanly, zero schema delta errors'],
            ['num'=>5,'c'=>'#2563eb','name'=>'Testing & QA Validation','weeks'=>'2–4',
             'goal'=>'Comprehensive coverage to prevent production regression',
             'owner'=>'QA Team + Backend Engineers',
             'tasks'=>['Run full PHPUnit suite — fix all failures','Run Magento integration tests against target version','Execute MFTF functional tests (checkout, catalog, admin, orders)','Performance baseline comparison (load time, indexer, checkout funnel)','OWASP ZAP security scan + Magento security scanner','Business UAT on staging with production data'],
             'exit'=>'≥95% test pass rate, zero critical UAT defects, perf within 10% of baseline'],
            ['num'=>6,'c'=>'#16a34a','name'=>'Staging Deployment & Stabilization','weeks'=>'1',
             'goal'=>'Full upgrade validated in production-equivalent environment',
             'owner'=>'DevOps + QA + Business',
             'tasks'=>['Deploy upgrade to staging with production data clone','Run setup:upgrade + setup:di:compile + SCD','Full regression smoke test on all critical commerce flows','Load test with representative traffic profile','Validate all third-party integrations (ERP, PIM, payment, shipping)'],
             'exit'=>'Staging sign-off from Business, DevOps, and Tech Lead'],
            ['num'=>7,'c'=>'#16a34a','name'=>'Production Deployment & Go-Live','weeks'=>'0.5',
             'goal'=>'Zero-downtime production upgrade with instant rollback readiness',
             'owner'=>'DevOps + On-call Engineer',
             'tasks'=>['Schedule off-peak maintenance window','Verify database backup can be fully restored','Deploy via CI/CD pipeline','Run setup:upgrade with monitoring dashboards active','Smoke test: checkout, cart, admin, GraphQL APIs','Disable maintenance mode — monitor 60 minutes'],
             'exit'=>'Green monitoring, zero P1 errors in first hour post-deploy'],
        ];
        $phasesHtml = '';
        foreach ($phases as $ph) {
            $liHtml = implode('',array_map(fn($t)=>'<li>'.htmlspecialchars($t).'</li>',$ph['tasks']));
            $phasesHtml .= sprintf(
                '<details class="ap-phase" style="border-left:4px solid %s">
                  <summary><span class="ap-ph-num" style="background:%s">Phase %d</span><span class="ap-ph-name">%s</span><span class="ap-ph-wk">Weeks %s</span></summary>
                  <div class="ap-ph-body">
                    <div class="ap-grid-2"><div><span class="ap-lbl">Goal</span><p>%s</p></div><div><span class="ap-lbl">Owner</span><p>%s</p></div></div>
                    <div style="margin:10px 0"><span class="ap-lbl">Key Tasks</span><ul class="ap-tl">%s</ul></div>
                    <div><span class="ap-lbl">Exit Criteria</span><p class="ap-exit">✓ %s</p></div>
                  </div>
                </details>',
                $ph['c'],$ph['c'],$ph['num'],
                htmlspecialchars($ph['name']),htmlspecialchars($ph['weeks']),
                htmlspecialchars($ph['goal']),htmlspecialchars($ph['owner']),
                $liHtml,htmlspecialchars($ph['exit'])
            );
        }

        // 5. Module action items
        $mIssues = [];
        foreach ($prioritized as $prio => $issues) {
            foreach ($issues as $issue) {
                $issue['_p'] = $prio;
                $f = $issue['file'] ?? '';
                if (preg_match('#(?:app/code)/([A-Z][^/\\\\]+)[/\\\\]([A-Z][^/\\\\]+)#',$f,$m)) $mod=$m[1].'_'.$m[2];
                else $mod='Unknown';
                $mIssues[$mod][] = $issue;
            }
        }
        uasort($mIssues, fn($a,$b)=>count($b)-count($a));
        $modRows = '';
        $rank = 1;
        foreach (array_slice($mIssues,0,15,true) as $mod => $issues) {
            $cr = count(array_filter($issues, fn($i)=>($i['_p']??'')==='critical'));
            $hi = count(array_filter($issues, fn($i)=>($i['_p']??'')==='high'));
            $me = count(array_filter($issues, fn($i)=>($i['_p']??'')==='medium'));
            $ri = $cr>5?'HIGH':($cr>0?'MEDIUM':($hi>5?'MEDIUM':'LOW'));
            $rc = $ri==='HIGH'?'#dc2626':($ri==='MEDIUM'?'#d97706':'#16a34a');
            $ac = $cr>0?'P1 — Sprint 1':($hi>5?'P2 — Sprint 2':'P3 — Schedule');
            $aColor = $cr>0?'#dc2626':($hi>5?'#d97706':'#6b7280');
            $modRows .= sprintf(
                '<tr><td>%d</td><td><strong>%s</strong></td><td>%d</td><td>%s</td><td>%s</td><td>%s</td><td><span style="color:%s;font-weight:700">%s</span></td></tr>',
                $rank++,
                htmlspecialchars($mod),count($issues),
                $cr?"<span style='color:#dc2626;font-weight:700'>{$cr}</span>":'-',
                $hi?"<span style='color:#d97706;font-weight:600'>{$hi}</span>":'-',
                $me?"<span style='color:#2563eb'>{$me}</span>":'-',
                $aColor,htmlspecialchars($ac)
            );
        }

        // 6. Ownership matrix
        $ownerRows = [
            ['Critical security fixes (unserialize, XSS, CSRF)','Security-focused Senior Engineer','P1','#dc2626'],
            ['Deprecated API migration (Registry, ObjectManager)','Senior Backend Engineer','P1','#dc2626'],
            ['Database schema & data patch conversion','Backend Engineer + DBA','P1','#dc2626'],
            ['PHP 8.x type safety & compatibility','Backend Engineer','P2','#d97706'],
            ['Zend → Laminas class migration','Backend Engineer','P2','#d97706'],
            ['Unit & integration test updates','QA Engineer','P2','#d97706'],
            ['Third-party extension compatibility','Tech Lead / Architect','P2','#d97706'],
            ['Performance baseline & load testing','Performance Engineer / DevOps','P3','#6b7280'],
            ['Deployment pipeline & rollback procedure','DevOps Engineer','P2','#d97706'],
            ['Business UAT sign-off','Product Owner + QA Lead','P3','#6b7280'],
        ];
        $ownerHtml = '';
        foreach ($ownerRows as $r) {
            $ownerHtml .= sprintf('<tr><td>%s</td><td>%s</td><td><span style="color:%s;font-weight:700">%s</span></td></tr>',
                htmlspecialchars($r[0]),htmlspecialchars($r[1]),$r[3],htmlspecialchars($r[2]));
        }

        // 7. Dependency sequence
        $deps = [
            ['1st','#dc2626','Security vulnerabilities','unserialize/eval/exec with user input — RCE risk'],
            ['2nd','#dc2626','Removed classes / interfaces','Fatal errors on boot — resolve before any other fix'],
            ['3rd','#d97706','Deprecated DI patterns','ObjectManager, Helper injection — class-loading risk'],
            ['4th','#d97706','Database schema conversions','Must complete before setup:upgrade runs'],
            ['5th','#d97706','Removed event observers','May silently fail in target version — hard to debug'],
            ['6th','#6b7280','Soft deprecations','serialize(), direct SQL, model->save()'],
            ['7th','#6b7280','Code quality improvements','Medium/low issues — schedule for sprint 3+'],
        ];
        $depsHtml = '';
        foreach ($deps as $d) {
            $depsHtml .= sprintf(
                '<div class="ap-dep"><div class="ap-dep-seq" style="background:%s">%s</div><div><strong>%s</strong><p>%s</p></div></div>',
                $d[1],$d[0],htmlspecialchars($d[2]),htmlspecialchars($d[3])
            );
        }

        // 11. Timeline
        $categories = $effort['categories'] ?? [];
        $tlRows = '';
        foreach ($categories as $cat) {
            $ih  = (float)($cat['ideal_hours']??0);
            $mn  = (float)($cat['range_min']??$ih*0.75);
            $mx  = (float)($cat['range_max']??$ih*1.5);
            $cx  = strtolower($cat['complexity']??'low');
            $cxC = ['critical'=>'#dc2626','high'=>'#d97706','medium'=>'#2563eb','low'=>'#16a34a'][$cx]??'#6b7280';
            $tlRows .= sprintf(
                '<tr><td><strong>%s</strong></td><td><span style="color:%s;font-weight:700">%s</span></td><td><strong>%sh</strong></td><td>%s–%sh</td><td>%s</td></tr>',
                htmlspecialchars($cat['name']),$cxC,strtoupper($cx),
                number_format($ih,0),number_format($mn,0),number_format($mx,0),
                ucfirst($cat['priority']??'medium')
            );
        }
        $rangeMin = number_format((float)($effort['range_min_hours']??$totalHours*0.75),0);
        $rangeMax = number_format((float)($effort['range_max_hours']??$totalHours*1.5),0);

        // 12. Risk mitigation
        $mitRows = [
            ['Undiscovered breaking changes','HIGH','HIGH','Run UCT alongside ACQC; cross-check official migration guide','Tech Lead'],
            ['Third-party extension incompatibility','MEDIUM','HIGH','Contact vendors; check Marketplace for target version support','Tech Lead'],
            ['Performance regression','MEDIUM','MEDIUM','Establish baseline; load test staging; review new caching configs','DevOps'],
            ['DB migration timeout on large tables',$critCount>5?'HIGH':'LOW','CRITICAL','Use pt-online-schema-change; test migration timing on staging','DBA'],
            ['Team knowledge gap on target version','MEDIUM','MEDIUM','Schedule team training; assign migration guide review per engineer','Tech Lead'],
            ['Rollback failure (corrupted backup)','LOW','CRITICAL','Verify restore before upgrade day; test on non-production','DevOps'],
        ];
        $mitHtml = '';
        foreach ($mitRows as $r) {
            $lC = $r[1]==='HIGH'?'#dc2626':($r[1]==='MEDIUM'?'#d97706':'#16a34a');
            $iC = in_array($r[2],['CRITICAL','HIGH'])?'#dc2626':($r[2]==='MEDIUM'?'#d97706':'#16a34a');
            $mitHtml .= sprintf(
                '<tr><td>%s</td><td><span style="color:%s;font-weight:700">%s</span></td><td><span style="color:%s;font-weight:700">%s</span></td><td>%s</td><td>%s</td></tr>',
                htmlspecialchars($r[0]),$lC,$r[1],$iC,$r[2],htmlspecialchars($r[3]),htmlspecialchars($r[4])
            );
        }

        return '
<div class="ap-section">
  <h2 class="ap-st">1 &middot; Executive Summary</h2>
  <div class="ap-sum-grid">
    <div class="ap-sum-card"><span class="ap-lbl">Readiness Score</span><div class="ap-big" style="color:'.$sColor.'">'.$score.'/100</div></div>
    <div class="ap-sum-card"><span class="ap-lbl">Upgrade Path</span><div class="ap-big" style="font-size:18px;color:#1e3a8a">'.htmlspecialchars($currentV.' → '.$targetV).'</div></div>
    <div class="ap-sum-card"><span class="ap-lbl">Complexity</span><div class="ap-big" style="font-size:20px;color:#1e3a8a">'.$complexityLabel.'</div></div>
    <div class="ap-sum-card"><span class="ap-lbl">Estimated Effort</span><div class="ap-big" style="font-size:20px">'.number_format($totalHours,0).'h &middot; '.number_format($totalWeeks,0).' wks</div></div>
    <div class="ap-sum-card" style="background:'.$vBg.'"><span class="ap-lbl">Verdict</span><div class="ap-big" style="font-size:16px;color:'.$vColor.'">'.$verdict.'</div></div>
  </div>
</div>
<div class="ap-section">
  <h2 class="ap-st">2 &middot; Immediate Blockers <span class="ap-cnt-badge" style="background:#dc2626">'.$critCount.' critical</span></h2>
  '.$blockersHtml.'
</div>
<div class="ap-section">
  <h2 class="ap-st">3 &middot; Upgrade Complexity Rating</h2>
  <table><thead><tr><th>Dimension</th><th>Status</th><th>RAG</th></tr></thead><tbody>'.$ragHtml.'</tbody></table>
</div>
<div class="ap-section">
  <h2 class="ap-st">4 &middot; Phase-wise Remediation Roadmap</h2>
  <p class="ap-hint">Click each phase to expand goals, tasks, and exit criteria.</p>
  '.$phasesHtml.'
</div>
<div class="ap-section">
  <h2 class="ap-st">5 &middot; Module-wise Action Items <span class="ap-hint">Top 15 by issue count</span></h2>
  <div style="overflow-x:auto"><table><thead><tr><th>#</th><th>Module</th><th>Total</th><th>Critical</th><th>High</th><th>Medium</th><th>Action</th></tr></thead><tbody>'.$modRows.'</tbody></table></div>
</div>
<div class="ap-section">
  <h2 class="ap-st">6 &middot; Ownership Recommendations</h2>
  <table><thead><tr><th>Responsibility</th><th>Recommended Owner</th><th>Priority</th></tr></thead><tbody>'.$ownerHtml.'</tbody></table>
</div>
<div class="ap-section">
  <h2 class="ap-st">7 &middot; Dependency Resolution Sequence</h2>
  <div class="ap-dep-list">'.$depsHtml.'</div>
</div>
<div class="ap-section">
  <h2 class="ap-st">11 &middot; Timeline Guidance</h2>
  <div style="overflow-x:auto"><table><thead><tr><th>Category</th><th>Complexity</th><th>Ideal</th><th>Realistic Range</th><th>Priority</th></tr></thead><tbody>'.$tlRows.'</tbody></table></div>
  <p class="ap-hint" style="margin-top:12px">Total: <strong>'.number_format($totalHours,0).'h ideal</strong> &middot; <strong>'.$rangeMin.'–'.$rangeMax.'h realistic</strong> &middot; <strong>'.number_format($totalWeeks,1).' weeks</strong></p>
</div>
<div class="ap-section">
  <h2 class="ap-st">12 &middot; Risk Mitigation Steps</h2>
  <div style="overflow-x:auto"><table><thead><tr><th>Risk</th><th>Likelihood</th><th>Impact</th><th>Mitigation</th><th>Owner</th></tr></thead><tbody>'.$mitHtml.'</tbody></table></div>
</div>
';
    }

    private function buildTestingMatrixHtml($result): string
    {
        $effort  = $result->getEffortEstimation();
        $testCat = $effort['categories']['testing'] ?? [];
        $testH   = number_format((float)($testCat['ideal_hours']??0),1);
        $testMin = number_format((float)($testCat['range_min']??0),0);
        $testMax = number_format((float)($testCat['range_max']??0),0);
        $stats   = $result->getFullScanStats();
        $crit    = (int)($stats['critical']??0);
        $med     = (int)($stats['medium']??0);

        $layers = [
            ['Unit Tests',      'PHPUnit (magento/magento2-base)',    'After each critical/high fix',    '≥95% pass rate, 0 failures on critical paths',           '#2563eb', 'Backend Engineers'],
            ['Integration',     'Magento integration test suite',      'After schema changes',            '≥90% pass rate, all DB interactions verified',           '#2563eb', 'Backend Engineers'],
            ['Functional',      'MFTF — checkout, catalog, admin',     'After Phase 3 complete',          'All critical commerce flows pass end-to-end',            '#d97706', 'QA Team'],
            ['Regression',      'Full MFTF + custom E2E suite',        'Before staging deployment',       'Zero regressions vs pre-upgrade baseline',               '#d97706', 'QA Team'],
            ['Performance',     'Apache JMeter / k6 on staging',       'Pre-production with prod data',   'Page load, checkout, indexer within 10% of baseline',    '#dc2626', 'DevOps + QA'],
            ['Security',        'OWASP ZAP + Magento security scanner','Pre-production sign-off',         'Zero high/critical findings in custom code',             '#dc2626', 'Security Engineer'],
            ['UAT',             'Business stakeholders on staging',    'Pre-production sign-off',         'Business approval — all critical commerce scenarios',    '#16a34a', 'Product Owner + QA'],
        ];
        $layerRows = '';
        foreach ($layers as $idx => $l) {
            $layerRows .= sprintf(
                '<tr><td><span class="test-num">%d</span></td><td><strong style="color:%s">%s</strong></td><td class="mono" style="font-size:11px">%s</td><td>%s</td><td><em>%s</em></td><td>%s</td></tr>',
                $idx+1,$l[4],$l[0],$l[1],$l[2],$l[3],$l[5]
            );
        }

        // Smart recommendations based on issue counts
        $recs = [];
        if ($crit > 10) $recs[] = '🔴 <strong>High critical count</strong> — unit tests must cover every fix; no critical issue closes without a passing test';
        if ($med > 50)  $recs[] = '🟡 <strong>High medium count</strong> — regression suite required before each sprint merge to main';
        $recs[] = '✅ Establish performance baseline <strong>before</strong> any code changes — not after';
        $recs[] = '✅ Security scan should run on <strong>remediated</strong> code, not original — schedule after Phase 3';
        $recs[] = '✅ MFTF critical path: Checkout → Order Placement → Invoice → Shipment → Admin Order Management';
        $recs[] = '✅ UAT must include: B2B workflows (if applicable), guest checkout, registered checkout, coupon codes, tax calculation';
        $recHtml = implode('', array_map(fn($r) => "<li style='line-height:1.8'>{$r}</li>", $recs));

        // Testing checklist
        $checklist = [
            'Set up upgrade branch with identical environment to production',
            'Run ACQC audit and confirm 0 critical issues before test pass',
            'Execute bin/magento setup:upgrade on staging — confirm success',
            'Run full PHPUnit suite — record pass/fail delta vs pre-upgrade',
            'Execute Magento integration tests — flag any DB-related failures',
            'Run MFTF functional tests — checkout, catalog, admin, orders',
            'Performance benchmark — measure 10 key pages vs baseline',
            'Run OWASP ZAP on checkout flow and admin panel',
            'UAT session with business stakeholders — document sign-off',
            'Post-UAT regression run — confirm no new issues introduced',
            'Load test with 2× expected peak traffic on staging',
            'Confirm all third-party integrations pass smoke tests',
            'Review error logs for any new exceptions post-upgrade',
            'Validate GraphQL / REST API endpoints with consumer teams',
            'Obtain written sign-off from Tech Lead, QA Lead, and Product Owner',
        ];
        $checkHtml = implode('', array_map(fn($c) => "<li><label><input type='checkbox' style='margin-right:6px'><span>{$c}</span></label></li>", $checklist));

        return '
<div class="card">
  <h2>Testing Strategy — 7-Layer Matrix</h2>
  <div class="kpi-grid" style="margin-bottom:20px">
    <div class="kpi"><div class="kpi-val">'.$testH.'h</div><div class="kpi-lbl">Testing Effort</div></div>
    <div class="kpi"><div class="kpi-val" style="font-size:16px">'.$testMin.'–'.$testMax.'h</div><div class="kpi-lbl">Realistic Range</div></div>
    <div class="kpi"><div class="kpi-val">×'.number_format((float)($testCat['risk_multiplier']??1),1).'</div><div class="kpi-lbl">Risk Multiplier</div></div>
    <div class="kpi"><div class="kpi-val">7</div><div class="kpi-lbl">Test Layers</div></div>
  </div>
  <div style="overflow-x:auto">
  <table>
    <thead><tr><th>#</th><th>Layer</th><th>Tool / Scope</th><th>When to Run</th><th>Acceptance Criteria</th><th>Owner</th></tr></thead>
    <tbody>'.$layerRows.'</tbody>
  </table>
  </div>
</div>
<div class="card">
  <h2>Smart Recommendations</h2>
  <ul style="padding-left:20px;line-height:2">'.$recHtml.'</ul>
</div>
<div class="card">
  <h2>Testing Readiness Checklist</h2>
  <p style="margin-bottom:12px;font-size:13px;color:#64748b">Tick items as completed before each phase gate. All 15 must be ✓ before production go-live.</p>
  <ul style="list-style:none;line-height:2.2;font-size:13px">'.$checkHtml.'</ul>
</div>
';
    }

    private function buildGovernanceHtml($result): string
    {
        $stats = $result->getFullScanStats();
        $score = (int)($stats['score']??$result->getUpgradeReadinessScore());
        $crit  = (int)($stats['critical']??0);
        $effort = $result->getEffortEstimation();
        $totalH = (float)($effort['total_hours']??0);

        $mustHave = [
            ['Full Database Backup', 'Complete backup verified restorable before any migration step', $crit>0?'REQUIRED':'REQUIRED', true],
            ['Staging Dry Run', 'Full setup:upgrade on staging with production data clone', true, true],
            ['Extension Compatibility', 'All third-party extensions validated against target version', true, true],
            ['Security Patch Alignment', 'Target version includes all applicable security patches', true, true],
            ['Rollback Plan Documented', 'Trigger criteria, procedure, and responsibility chain defined', true, true],
            ['Regression Suite Ready', 'MFTF + unit test suite runnable and baseline captured', true, true],
            ['Zero Critical Issues', "All {$crit} critical ACQC findings resolved before deployment", $crit>0, true],
            ['PHP Compatibility Confirmed', 'Running PHP version is within target version supported range', true, true],
            ['CI/CD Pipeline Validated', 'Deployment pipeline tested on upgrade branch', true, true],
            ['Maintenance Window Scheduled', 'Off-peak window communicated to all stakeholders', true, true],
        ];

        $niceToHave = [
            ['Performance Optimization', 'Profile and tune slow queries, caches, and indexers before upgrade'],
            ['UI / Frontend Modernization', 'Upgrade Luma → Hyva or custom theme improvements'],
            ['Code Quality Cleanup', 'Address medium/low ACQC findings beyond critical scope'],
            ['Test Automation Expansion', 'Add Cypress or Playwright E2E coverage for key flows'],
            ['Analytics Enhancements', 'Upgrade GTM/GA4 integration alongside commerce upgrade'],
            ['API Documentation Update', 'Regenerate REST/GraphQL API docs for target version changes'],
            ['Admin UX Improvements', 'Leverage new admin grid capabilities in target version'],
            ['Performance Baseline Dashboard', 'Set up Datadog/New Relic baseline before upgrade starts'],
        ];

        $mhRows = '';
        foreach ($mustHave as $i => $item) {
            $mhRows .= sprintf(
                '<tr><td><strong>%s</strong></td><td>%s</td><td><span class="gov-status gov-req">REQUIRED</span></td><td><input type="checkbox" class="gov-check"></td></tr>',
                htmlspecialchars($item[0]), htmlspecialchars($item[1])
            );
        }
        $nthRows = '';
        foreach ($niceToHave as $item) {
            $nthRows .= sprintf(
                '<tr><td><strong>%s</strong></td><td>%s</td><td><span class="gov-status gov-nice">OPTIONAL</span></td><td><input type="checkbox" class="gov-check"></td></tr>',
                htmlspecialchars($item[0]), htmlspecialchars($item[1])
            );
        }

        // Governance ownership matrix
        $govMatrix = [
            ['Sponsor Approval', 'Approve upgrade investment and timeline', 'Engineering Director / CTO', 'Pre-Phase 1'],
            ['Architecture Sign-off', 'Review breaking changes and DI changes', 'Lead Architect', 'Phase 1 exit'],
            ['Security Review', 'Validate all critical security fixes', 'Security Lead', 'Phase 2 exit'],
            ['DBA Approval', 'Approve schema migrations and rollback plan', 'DBA', 'Phase 4 exit'],
            ['QA Gate', 'Confirm test results meet acceptance criteria', 'QA Lead', 'Phase 5 exit'],
            ['Performance Gate', 'Confirm performance within 10% of baseline', 'DevOps / SRE', 'Phase 5 exit'],
            ['Business UAT', 'Business stakeholder sign-off on staging', 'Product Owner', 'Phase 6 exit'],
            ['Security Sign-off', 'Zero high/critical findings in final security scan', 'Security Lead', 'Phase 6 exit'],
            ['Go/No-Go Decision', 'Final production deployment authorisation', 'Eng Director + PO', 'Pre-Phase 7'],
            ['Post-Deploy Review', '48h post-deployment health check and report', 'Tech Lead + DevOps', 'Phase 7+48h'],
        ];
        $govRows = '';
        foreach ($govMatrix as $r) {
            $govRows .= sprintf('<tr><td><strong>%s</strong></td><td>%s</td><td><span class="gov-owner">%s</span></td><td>%s</td></tr>',
                htmlspecialchars($r[0]),htmlspecialchars($r[1]),htmlspecialchars($r[2]),htmlspecialchars($r[3]));
        }

        $readyCount = 0; // Would be dynamic with user input; shown as 0/10 progress
        $totalMH    = count($mustHave);

        return '
<div class="card">
  <h2>Must Have — Upgrade Readiness Controls</h2>
  <p style="margin-bottom:14px;font-size:13px;color:#64748b">These controls are non-negotiable. Every item must be completed before production deployment.</p>
  <div class="gov-progress-bar"><div class="gov-progress-fill" id="govFill" style="width:0%"></div></div>
  <p style="font-size:12px;color:#64748b;margin:6px 0 14px" id="govCount">0 / '.$totalMH.' controls completed</p>
  <table>
    <thead><tr><th>Control</th><th>Requirement</th><th>Status</th><th>Done</th></tr></thead>
    <tbody>'.$mhRows.'</tbody>
  </table>
</div>
<div class="card">
  <h2>Nice to Have — Enhancement Opportunities</h2>
  <p style="margin-bottom:14px;font-size:13px;color:#64748b">Recommended improvements that add value but are not blockers for the upgrade.</p>
  <table>
    <thead><tr><th>Enhancement</th><th>Description</th><th>Type</th><th>Consider</th></tr></thead>
    <tbody>'.$nthRows.'</tbody>
  </table>
</div>
<div class="card">
  <h2>Upgrade Readiness Governance — Approval Gates</h2>
  <p style="margin-bottom:14px;font-size:13px;color:#64748b">Each gate must receive explicit sign-off before the next phase begins.</p>
  <table>
    <thead><tr><th>Gate</th><th>Purpose</th><th>Owner</th><th>Timing</th></tr></thead>
    <tbody>'.$govRows.'</tbody>
  </table>
</div>
<script>
document.querySelectorAll(".gov-check").forEach(function(cb) {
  cb.addEventListener("change", function() {
    var total = document.querySelectorAll(".gov-check").length;
    var checked = document.querySelectorAll(".gov-check:checked").length;
    document.getElementById("govFill") && (document.getElementById("govFill").style.width = Math.round(checked/total*100) + "%");
    document.getElementById("govCount") && (document.getElementById("govCount").textContent = checked + " / " + total + " controls completed");
  });
});
</script>
';
    }

    private function buildCautionAreasHtml($result): string
    {
        $stats   = $result->getFullScanStats();
        $crit    = (int)($stats['critical']??0);
        $score   = (int)($stats['score']??0);
        $dbIssues = $result->getDatabaseIssues();
        $effort  = $result->getEffortEstimation();
        $totalH  = (float)($effort['total_hours']??0);
        $vi      = $result->toArray()['version_info']??[];
        $currentPhp = $vi['current_php']??'?';
        $targetPhp  = $vi['target_php']??'?';

        // Smart status derivation
        $phpOk   = $currentPhp === $targetPhp || version_compare($currentPhp,$targetPhp,'>=');
        $largeDB = !empty(($dbIssues['large_table_risks']??[]));
        $legacyScripts = array_sum(array_map('count',$dbIssues['setup_scripts']??[])) > 0;
        $bcIssues = count($dbIssues['backward_compatibility']??[]);

        $before = [
            ['Database Backup Integrity',
             'Verify full backup can be completely restored in a staging environment. Test restore timing — factor this into the maintenance window.',
             'REQUIRED', '#dc2626', true],
            ['PHP Compatibility Verification',
             "Current: PHP {$currentPhp} → Target: PHP {$targetPhp}. Confirm all custom extensions support target PHP.",
             $phpOk?'VERIFIED':'CHECK REQUIRED', $phpOk?'#16a34a':'#d97706', $phpOk],
            ['Composer Lock Conflicts',
             'Run `composer update --dry-run` on upgrade branch. Resolve all dependency conflicts before proceeding.',
             'VERIFY BEFORE UPGRADE', '#d97706', false],
            ['Disk & Storage Capacity',
             'Ensure ≥ 30% free disk space on all nodes. Schema upgrade generates temp tables. Log volume increases during migration.',
             'VERIFY CAPACITY', '#d97706', false],
            ['Queue Jobs Paused',
             'Pause all message queue consumers (RabbitMQ/MySQL queue) before upgrade. Resume after post-upgrade validation.',
             'PAUSE BEFORE UPGRADE', '#dc2626', false],
            ['Cron Impact Assessment',
             'Disable all cron jobs on the upgrade node. Document which jobs need manual re-run after upgrade completes.',
             'DISABLE FOR WINDOW', '#d97706', false],
            ['Third-Party Extensions',
             'Confirm compatibility of all installed extensions with target version. Contact vendors for untested extensions.',
             'VALIDATE ALL EXTENSIONS', '#d97706', false],
            ['Backward Compatibility Issues',
             "{$bcIssues} backward compatibility issue(s) detected. Review before starting code fixes.",
             $bcIssues>0?'ISSUES DETECTED':'CLEAR', $bcIssues>0?'#dc2626':'#16a34a', $bcIssues===0],
        ];

        $during = [
            ['Maintenance Mode Active',
             'Enable maintenance mode on ALL nodes before starting setup:upgrade. Do not disable until smoke tests pass.',
             'CRITICAL', '#dc2626'],
            ['Long-Running Schema Changes',
             $largeDB?'Large high-volume tables detected. Monitor ALTER TABLE progress. Use pt-online-schema-change if lock exceeds 30s.':'No large table risks detected from schema scan.',
             $largeDB?'MONITOR CLOSELY':'LOW RISK', $largeDB?'#d97706':'#16a34a'],
            ['Memory Spike Monitoring',
             'setup:upgrade and setup:di:compile can spike PHP memory to 2–4GB. Monitor memory on all app nodes.',
             'MONITOR MEMORY', '#d97706'],
            ['Failed Dependency Installs',
             'If `composer install` fails, do not proceed. Rollback to previous codebase immediately.',
             'ABORT IF FAILS', '#dc2626'],
            ['Indexer State Reset',
             'Upgrade resets all indexer states. Full reindex required after upgrade completes. Plan for reindex time.',
             'PLAN REINDEX TIME', '#d97706'],
            ['Deployment Sequencing',
             'Sequence: Deploy code → setup:upgrade → setup:di:compile → SCD → enable maintenance off. Do not change sequence.',
             'FOLLOW EXACT SEQUENCE', '#dc2626'],
            ['Legacy Setup Scripts',
             $legacyScripts?'Legacy setup scripts detected. These still execute but are not tracked in schema_whitelist. Verify output carefully.':'No legacy setup scripts detected.',
             $legacyScripts?'VERIFY OUTPUT':'CLEAR', $legacyScripts?'#d97706':'#16a34a'],
        ];

        $after = [
            ['Cache Warmup',
             'Run `bin/magento cache:flush` then warm all cache types. Consider running cache warmup script on key pages before disabling maintenance.',
             'REQUIRED', '#dc2626'],
            ['Reindex Health Check',
             'Run `bin/magento indexer:status` — all indexers should be VALID. Schedule full reindex if any show INVALID.',
             'VERIFY ALL VALID', '#dc2626'],
            ['Checkout Flow Validation',
             'Test guest checkout and logged-in checkout end-to-end. Include coupon codes, tax calculation, and address validation.',
             'SMOKE TEST REQUIRED', '#dc2626'],
            ['Payment & Shipping Validation',
             'Process a test order through every active payment method. Validate shipping rate calculation from all carriers.',
             'TEST ALL METHODS', '#dc2626'],
            ['Admin Functionality',
             'Verify: order management, product creation, customer management, promotions, reporting. Log any missing admin functionality.',
             'VERIFY KEY FLOWS', '#d97706'],
            ['GraphQL / REST API Validation',
             'Test all GraphQL queries and REST endpoints consumed by frontend/mobile. Validate schema changes have not broken consumer contracts.',
             'API CONTRACT CHECK', '#d97706'],
            ['Performance Baseline Comparison',
             'Compare page load, TTFB, Core Web Vitals, and checkout funnel time vs pre-upgrade baseline. Flag any >10% degradation.',
             'COMPARE BASELINES', '#d97706'],
            ['Error Log Review',
             'Review /var/log/system.log, /var/log/exception.log, and PHP error logs for 30 minutes post-deployment. Investigate any new errors.',
             'REVIEW LOGS', '#d97706'],
        ];

        $buildSection = function(array $items, string $title, string $icon) {
            $rows = '';
            foreach ($items as $item) {
                $sColor = $item[3];
                $sBg    = isset($item[4]) && $item[4] ? '#f0fdf4' : 'transparent';
                $icon2  = isset($item[4]) && $item[4] ? '✓' : '⚠';
                $rows .= sprintf(
                    '<tr style="background:%s"><td style="width:30px;text-align:center;color:%s;font-size:16px">%s</td><td><strong>%s</strong><p style="margin-top:4px;color:#64748b;font-size:12px">%s</p></td><td><span class="caut-tag" style="background:%s;color:#fff">%s</span></td></tr>',
                    $sBg, $sColor, $icon2,
                    htmlspecialchars($item[0]),
                    htmlspecialchars($item[1]),
                    $sColor, htmlspecialchars($item[2])
                );
            }
            return '<div class="card"><h2>'.$icon.' '.$title.'</h2><table style="border:none"><tbody>'.$rows.'</tbody></table></div>';
        };

        return
            $buildSection($before, 'Before Upgrade — Pre-flight Checks', '🔍') .
            $buildSection($during, 'During Upgrade — Monitor These Areas', '⚡') .
            $buildSection($after,  'After Upgrade — Validation Checklist', '✅');
    }

    private function buildRollbackHtml($result): string
    {
        $stats   = $result->getFullScanStats();
        $crit    = (int)($stats['critical']??0);
        $dbIssues = $result->getDatabaseIssues();
        $rollback = $dbIssues['rollback_plan'] ?? [];
        $riskLevel = $rollback['risk_level'] ?? 'UNKNOWN';
        $riskColor = $riskLevel==='HIGH'?'#dc2626':($riskLevel==='MEDIUM'?'#d97706':'#16a34a');

        $triggers = [
            'Fatal PHP errors on checkout, product detail, or order management pages',
            'Admin panel inaccessible > 15 minutes post-deployment',
            'Payment gateway integration failures (transaction declines, API errors)',
            'Database integrity errors or schema corruption detected in logs',
            'Performance degradation > 30% on Core Web Vitals or checkout funnel',
            'Critical functionality unavailable (cart, checkout, order placement)',
            'Any new P1 bug reported by business within first 2 hours',
        ];

        $preRollback = [
            'Notify all stakeholders immediately (Slack/email war-room channel)',
            'Capture full current error logs (/var/log/system.log, exception.log)',
            'Screenshot / record the exact failure symptoms for post-mortem',
            'Confirm database backup timestamp and file integrity',
            'Alert on-call DBA to standby for database restore',
            'Do NOT attempt to patch production in-place — rollback first, fix later',
        ];

        $procedure = [
            'Enable maintenance mode on all nodes immediately',
            'Deploy previous codebase tag via CI/CD (tag: release/pre-upgrade-YYYYMMDD)',
            'Restore database from verified pre-upgrade backup',
            'Run bin/magento setup:upgrade on the restored (previous) codebase',
            'Run bin/magento setup:di:compile on previous codebase',
            'Run bin/magento setup:static-content:deploy on previous codebase',
            'Smoke test: homepage, product page, cart, checkout, admin',
            'Disable maintenance mode once smoke tests pass',
        ];

        $postRollback = [
            'Confirm all monitoring is green on previous version',
            'Notify stakeholders of successful rollback and ETA for re-attempt',
            'Conduct post-mortem within 24 hours — document root cause',
            'Review all error logs from failed deployment',
            'Update upgrade runbook with new findings',
            'Schedule re-attempt with fixes applied — do not re-attempt same day',
        ];

        $buildList = function(array $items, string $color = '#1e3a8a') {
            return implode('', array_map(fn($i,$idx) => sprintf(
                '<div class="rb-step"><div class="rb-num" style="background:%s">%d</div><div>%s</div></div>',
                $color, $idx+1, htmlspecialchars($i)
            ), $items, array_keys($items)));
        };
        $triggerHtml = implode('', array_map(fn($t) =>
            '<div class="rb-trigger"><span class="rb-tri">⚡</span> '.htmlspecialchars($t).'</div>', $triggers
        ));

        $checkHtml = implode('', array_map(fn($c) =>
            "<li><label><input type='checkbox' style='margin-right:6px'><span>{$c}</span></label></li>",
            $rollback['checklist'] ?? [
                'Full database backup taken before upgrade (mysqldump --single-transaction)',
                'Backup restoration tested on staging environment',
                'Previous codebase tagged in git (release/pre-upgrade-YYYYMMDD)',
                'Rollback procedure rehearsed in staging',
                'On-call DBA available for 48h post-deployment',
                'Maintenance window run-book shared with all stakeholders',
                'Monitoring dashboards configured for immediate alert',
            ]
        ));

        $deploySeq = $rollback['deployment_sequence'] ?? [
            '1. Deploy new codebase to all nodes (maintenance mode ON)',
            '2. Run bin/magento setup:upgrade (schema + data patches apply)',
            '3. Run bin/magento setup:di:compile',
            '4. Run bin/magento setup:static-content:deploy',
            '5. Run bin/magento indexer:reindex (all indexers)',
            '6. Smoke test critical paths',
            '7. Disable maintenance mode',
            '8. Monitor error logs for 30 minutes',
        ];
        $deployHtml = implode('', array_map(fn($s) => '<li style="line-height:2">'.htmlspecialchars($s).'</li>', $deploySeq));

        $migRisks = $rollback['data_migration_risks'] ?? [
            'Declarative schema changes apply automatically on setup:upgrade — no manual SQL needed',
            'Data patches run once — ensure getDependencies() ordering is correct',
            'Legacy InstallSchema/UpgradeSchema scripts still execute — verify output carefully',
            'EAV attribute removal leaves orphaned rows in eav_attribute_option tables',
            'Indexer state is reset on schema upgrade — plan for full reindex after deployment',
        ];
        $migHtml = implode('', array_map(fn($r) => '<li style="line-height:1.8">'.htmlspecialchars($r).'</li>', $migRisks));

        return '
<div class="card">
  <h2>Rollback Risk Assessment</h2>
  <div class="kpi-grid" style="margin-bottom:16px">
    <div class="kpi"><div class="kpi-val" style="color:'.$riskColor.'">'.$riskLevel.'</div><div class="kpi-lbl">Rollback Risk</div></div>
    <div class="kpi"><div class="kpi-val">'.$crit.'</div><div class="kpi-lbl">Critical Issues</div></div>
    <div class="kpi"><div class="kpi-val">' . (count($dbIssues['large_table_risks']??[])) . '</div><div class="kpi-lbl">Large Table Risks</div></div>
    <div class="kpi"><div class="kpi-val">' . (array_sum(array_map('count',$dbIssues['setup_scripts']??[]))) . '</div><div class="kpi-lbl">Legacy Scripts</div></div>
  </div>
  <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:14px;margin-bottom:16px">
    <strong style="color:#dc2626">⚠️ Rollback Trigger Criteria</strong>
    <p style="font-size:12px;color:#64748b;margin:6px 0 10px">Initiate rollback IMMEDIATELY if any of the following occur within 2 hours of deployment:</p>
    '.$triggerHtml.'
  </div>
</div>
<div class="two-col">
  <div class="card">
    <h2>Pre-Rollback Actions</h2>
    '.$buildList($preRollback, '#d97706').'
  </div>
  <div class="card">
    <h2>Rollback Procedure</h2>
    '.$buildList($procedure, '#dc2626').'
  </div>
</div>
<div class="two-col">
  <div class="card">
    <h2>Deployment Sequence</h2>
    <ul style="padding-left:20px">'.$deployHtml.'</ul>
  </div>
  <div class="card">
    <h2>Data Migration Risks</h2>
    <ul style="padding-left:20px">'.$migHtml.'</ul>
  </div>
</div>
<div class="card">
  <h2>Post-Rollback Validation</h2>
  '.$buildList($postRollback, '#16a34a').'
</div>
<div class="card">
  <h2>Rollback Readiness Checklist</h2>
  <p style="margin-bottom:12px;font-size:13px;color:#64748b">Complete before production deployment day.</p>
  <ul style="list-style:none;line-height:2.2;font-size:13px">'.$checkHtml.'</ul>
</div>
';
    }


    private function groupIssuesByModule(array $prioritized): array
    {
        $modules = [];
        foreach ($prioritized as $prio => $issues) {
            foreach ($issues as $issue) {
                $info = $this->extractModuleInfo($issue['file'] ?? '');
                $name = $info['name'];
                if (!isset($modules[$name])) {
                    $modules[$name] = [
                        'name'     => $name,
                        'category' => $info['category'],
                        'vendor'   => $info['vendor'],
                        'module'   => $info['module'],
                        'total'    => 0,
                        'critical' => 0,
                        'high'     => 0,
                        'medium'   => 0,
                        'low'      => 0,
                        'types'    => [],
                        'files'    => [],
                        'issues'   => [],
                    ];
                }
                $modules[$name]['total']++;
                $modules[$name][$prio] = ($modules[$name][$prio] ?? 0) + 1;
                $type = $issue['type'] ?? 'other';
                $modules[$name]['types'][$type] = ($modules[$name]['types'][$type] ?? 0) + 1;
                $file = $issue['file'] ?? '';
                if ($file && !in_array($file, $modules[$name]['files'])) {
                    $modules[$name]['files'][] = $file;
                }
                if (count($modules[$name]['issues']) < 30) {
                    $modules[$name]['issues'][] = [
                        'priority' => $prio,
                        'file'     => $file,
                        'line'     => (int)($issue['line'] ?? 0),
                        'type'     => $type,
                        'message'  => $issue['message'] ?? '',
                        'fix'      => (string)($issue['fix'] ?? ''),
                    ];
                }
            }
        }
        // Convert types map to sorted array for JS
        foreach ($modules as &$mod) {
            arsort($mod['types']);
            $mod['topTypes'] = array_slice(array_keys($mod['types']), 0, 3);
            $mod['fileCount'] = count($mod['files']);
            unset($mod['files']); // keep payload lean
        }
        unset($mod);
        uasort($modules, fn($a, $b) => $b['total'] - $a['total']);
        return array_values($modules);
    }

    private function groupIssuesByType(array $prioritized): array
    {
        $groups = [];
        foreach ($prioritized as $prio => $issues) {
            foreach ($issues as $issue) {
                $type = $issue['type'] ?? 'other';
                $groups[$type][] = ['priority'=>$prio,'file'=>$issue['file']??'','line'=>(int)($issue['line']??0),'message'=>$issue['message']??''];
            }
        }
        arsort($groups);
        return $groups;
    }

    /**
     * Extract structured module info from a file path.
     * Returns ['name' => string, 'category' => custom|extension|core|theme|unknown, 'vendor' => string, 'module' => string]
     */
    private function extractModuleInfo(string $filePath): array
    {
        $fp = str_replace('\\', '/', $filePath);

        // 1. Custom module: app/code/Vendor/Module
        if (preg_match('#app/code/([A-Za-z][^/]+)/([A-Za-z][^/]+)#', $fp, $m)) {
            return ['name' => $m[1].'_'.$m[2], 'category' => 'custom', 'vendor' => $m[1], 'module' => $m[2]];
        }

        // 2. Magento core: vendor/magento/module-xxx or vendor/magento/framework
        if (preg_match('#vendor/magento/([^/]+)#', $fp, $m)) {
            $raw    = $m[1];
            $module = str_replace('-', ' ', $raw);
            $module = str_replace(' ', '', ucwords($module));
            // Strip leading "Module" prefix if it became "ModuleXxx"
            $label  = preg_match('/^module/i', $raw) ? 'Magento_' . preg_replace('/^Module/i', '', $module) : 'Magento_' . $module;
            return ['name' => $label, 'category' => 'core', 'vendor' => 'Magento', 'module' => $label];
        }

        // 3. Third-party extension: vendor/Vendor/module-xxx (non-magento)
        if (preg_match('#vendor/([A-Za-z][^/]+)/([^/]+)#', $fp, $m)) {
            $v   = $m[1];
            $raw = $m[2];
            $mod = str_replace(['-', '_'], ' ', $raw);
            $mod = str_replace(' ', '', ucwords($mod));
            return ['name' => $v.'_'.$mod, 'category' => 'extension', 'vendor' => $v, 'module' => $mod];
        }

        // 4. Theme: app/design/frontend/Vendor/Theme or app/design/adminhtml/Vendor/Theme
        if (preg_match('#app/design/(frontend|adminhtml)/([^/]+)/([^/]+)#', $fp, $m)) {
            $name = $m[2].'/'.$m[3];
            return ['name' => $name, 'category' => 'theme', 'vendor' => $m[2], 'module' => $m[3]];
        }

        // 5. Fallback: use the last 2 meaningful directory segments (reading from the end of the path)
        //    This handles absolute paths on any OS: /var/www/magento/src/Foo/Bar/File.php → Foo_Bar
        $parts = array_values(array_filter(explode('/', $fp), fn($p) => $p !== '' && $p !== '.'));
        // Remove the filename itself
        array_pop($parts);
        // Single-segment directories that carry no module identity on their own
        $trivial = ['src', 'lib', 'app', 'tests', 'test', 'vendor', 'code', 'bin', 'api'];
        // Walk from the end and collect up to 2 non-trivial segment names
        $collected = [];
        for ($i = count($parts) - 1; $i >= 0 && count($collected) < 2; $i--) {
            $seg = $parts[$i];
            if (strlen($seg) < 2 || is_numeric($seg)) continue;
            if (in_array(strtolower($seg), $trivial) && count($collected) === 0) continue; // skip only if nothing collected yet
            $collected[] = ucfirst($seg);
        }
        $collected = array_reverse($collected);
        if (count($collected) >= 2) {
            return ['name' => implode('_', $collected), 'category' => 'unknown', 'vendor' => $collected[0], 'module' => $collected[1]];
        }
        if (count($collected) === 1) {
            return ['name' => $collected[0], 'category' => 'unknown', 'vendor' => '', 'module' => $collected[0]];
        }

        // Last resort: filename
        $base = basename($fp, '.php');
        return ['name' => $base ?: 'Unknown', 'category' => 'unknown', 'vendor' => '', 'module' => $base ?: 'Unknown'];
    }

    private function extractModuleFromPath(string $filePath): string
    {
        return $this->extractModuleInfo($filePath)['name'];
    }


    // Helper methods for parsing versions
    private function parseCurrentVersion(?string $version, string $path, VersionDetector $detector): ?MagentoVersion
    {
        if (!$version || $version === 'auto') {
            return $detector->detectMagentoVersion($path);
        }
        return $this->parseVersion($version);
    }

    private function parseCurrentPhp(?string $version, VersionDetector $detector): PhpVersion
    {
        if (!$version || $version === 'auto') {
            return $detector->detectPhpVersion();
        }
        return $this->parsePhpVersion($version);
    }

    private function parseTargetVersion(string $version): MagentoVersion
    {
        return $this->parseVersion($version);
    }

    private function parseTargetPhp(string $version): PhpVersion
    {
        return $this->parsePhpVersion($version);
    }

    private function parseVersion(string $versionString): MagentoVersion
    {
        // Preserve patch suffix (e.g., 2.4.8-p3)
        $securityPatch = null;
        if (preg_match('/-p(\d+)$/', $versionString, $matches)) {
            $securityPatch = (int)$matches[1];
            $versionString = preg_replace('/-p\d+$/', '', $versionString);
        }
        
        $parts = explode('.', $versionString);
        
        $version = new MagentoVersion(
            (int)($parts[0] ?? 2),
            (int)($parts[1] ?? 4),
            (int)($parts[2] ?? 0),
            $securityPatch // Pass security patch to constructor
        );
        
        // Log the parsed version for debugging
        if ($securityPatch !== null) {
            $this->logger->info("Parsed version with security patch", [
                'version' => $versionString, 
                'security_patch' => $securityPatch,
                'full_version' => $version->toString()
            ]);
        }
        
        return $version;
    }

    private function parsePhpVersion(string $versionString): PhpVersion
    {
        $parts = explode('.', $versionString);
        return new PhpVersion(
            (int)($parts[0] ?? 8),
            (int)($parts[1] ?? 1),
            (int)($parts[2] ?? 0)
        );
    }

    // Helper methods for formatting
    private function getVersionChange(MagentoVersion $current, MagentoVersion $target): string
    {
        if ($current->isEqual($target)) {
            return '→ No change';
        }
        return $target->isGreaterThan($current) ? '↑ Upgrade' : '↓ Downgrade';
    }

    private function getPhpVersionChange(PhpVersion $current, PhpVersion $target): string
    {
        if ($current->isEqual($target)) {
            return '→ No change';
        }
        // When current release > target release but same major.minor, the target is a minimum
        // requirement, not a downgrade (e.g., running 8.4.20 against min target 8.4.0).
        if ($current->getMajor() === $target->getMajor()
            && $current->getMinor() === $target->getMinor()
            && $current->getRelease() >= $target->getRelease()
        ) {
            return '✓ Compatible';
        }
        return $target->isGreaterThan($current) ? '↑ Upgrade' : '↓ Downgrade';
    }

    private function formatReadinessScore(int $score): string
    {
        if ($score >= 80) {
            return sprintf('<fg=green>%d/100 (Excellent)</>', $score);
        } elseif ($score >= 60) {
            return sprintf('<fg=yellow>%d/100 (Good)</>', $score);
        } elseif ($score >= 40) {
            return sprintf('<fg=yellow>%d/100 (Fair)</>', $score);
        } else {
            return sprintf('<fg=red>%d/100 (Poor)</>', $score);
        }
    }

    private function formatRiskLevel(string $level): string
    {
        $colors = [
            'LOW' => 'green',
            'MEDIUM-LOW' => 'yellow',
            'MEDIUM' => 'yellow',
            'HIGH' => 'red',
        ];
        
        $color = $colors[$level] ?? 'white';
        return sprintf('<fg=%s>%s</>', $color, $level);
    }

    private function getPriorityIcon(string $priority): string
    {
        return match($priority) {
            'critical' => '🔴',
            'high' => '🟠',
            'medium' => '🟡',
            'low' => '🟢',
            default => '⚪',
        };
    }

    private function getPriorityColor(string $priority): string
    {
        return match($priority) {
            'critical' => 'red',
            'high' => 'yellow',
            'medium' => 'cyan',
            'low' => 'green',
            default => 'white',
        };
    }

    private function getPhaseIcon(string $priority): string
    {
        return match($priority) {
            'IMMEDIATE' => '🔴',
            'HIGH' => '🟠',
            'MEDIUM' => '🟡',
            'LOW' => '🟢',
            default => '⚪',
        };
    }

    private function estimateCustomLoc(string $projectRoot): int
    {
        $appCode = $projectRoot . '/app/code';
        if (!is_dir($appCode)) {
            return 0;
        }
        $count = 0;
        $it    = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($appCode, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->getExtension() === 'php') {
                $count += 200; // estimate 200 LOC per file
            }
        }
        return $count;
    }

    private function runVersionComparison(
        \Symfony\Component\Console\Style\SymfonyStyle $io,
        \Symfony\Component\Console\Input\InputInterface $input,
        string $path,
        \ACQC\Core\Version\MagentoVersion $currentVersion,
        \ACQC\Core\Version\PhpVersion $currentPhp,
        \ACQC\Core\Version\PhpVersion $targetPhp,
    ): void {
        $versionsRaw = $input->getOption('compare-versions') ?? '';
        $versions    = array_filter(array_map('trim', explode(',', $versionsRaw)));
        if (empty($versions)) {
            return;
        }

        $io->section('VERSION COMPARISON');

        $rows      = [];
        $customLoc = $this->estimateCustomLoc($path);
        $analyzer  = new \ACQC\Analyzers\Version\UpgradeAnalyzer();

        foreach ($versions as $version) {
            try {
                $targetMv  = $this->parseTargetVersion($version);
                $res       = $analyzer->analyzeUpgrade($path, $currentVersion, $targetMv, $currentPhp, $targetPhp);
                $findings  = [];
                foreach ($res->getPrioritizedIssues() as $issues) {
                    foreach ($issues as $issue) {
                        $findings[] = $issue;
                    }
                }
                $uri      = (new \ACQC\Core\Upgrade\UpgradeRiskIndex())->calculate($findings, 0, $customLoc);
                $blockers = count(array_filter($findings, fn($f) => ($f['severity'] ?? '') === 'error' && in_array($f['type'] ?? '', ['class_removed', 'db_schema_blocker', 'legacy_setup_script_detected'], true)));
                $errors   = $res->getIssueCountByPriority('critical') + $res->getIssueCountByPriority('high');
                $warnings = $res->getIssueCountByPriority('medium') + $res->getIssueCountByPriority('low');
                $rows[]   = [
                    $version,
                    $uri->score,
                    $uri->grade,
                    $uri->engineeringHours . 'h',
                    $blockers,
                    $errors,
                    $warnings,
                ];
            } catch (\Throwable $e) {
                $rows[] = [$version, 'N/A', 'N/A', 'N/A', 'N/A', 'N/A', 'Error: ' . $e->getMessage()];
            }
        }

        $table = new \Symfony\Component\Console\Helper\Table($io);
        $table->setHeaders(['Target', 'URI Score', 'Grade', 'Eng Hours', 'Blockers', 'Errors', 'Warnings']);
        $table->addRows($rows);
        $table->render();
    }
}


<?php

declare(strict_types=1);

namespace ACQC\Core;

use ACQC\Commands\AuditCommand;
use ACQC\Commands\AnalyzeCommand;
use ACQC\Commands\ReportCommand;
use ACQC\Commands\ConfigCommand;
use ACQC\Commands\ChecklistCommand;
use ACQC\Commands\TechnicalSpecCommand;
use ACQC\Commands\BaselineCommand;
use ACQC\Commands\DetectVersionCommand;
use ACQC\Commands\UpgradeReadinessCommand;
use ACQC\Commands\BackupCommand;
use ACQC\Commands\AutoFixCommand;
use ACQC\Commands\ProjectQualityCommand;
use ACQC\Commands\HistoryCommand;
use ACQC\Commands\AggregateCommand;
use ACQC\Commands\WatchCommand;
use ACQC\Commands\PolicyCommand;
use ACQC\Commands\DashboardCommand;
use ACQC\Commands\ExportCommand;
use ACQC\Commands\InitCommand;
use ACQC\Commands\DoctorCommand;
use ACQC\Commands\ScanCommand;
use ACQC\Commands\ValidateCommand;
use ACQC\Commands\Rules\ListCommand as RulesListCommand;
use ACQC\Commands\Rules\SuppressCommand;
use ACQC\Commands\Rules\UnsuppressCommand;
use ACQC\Commands\Rules\ValidateCommand as RulesValidateCommand;
use ACQC\Commands\Rules\SyncCommand;
use ACQC\Commands\Rules\NewCommand as RulesNewCommand;
use ACQC\Commands\Version\ListCommand as VersionListCommand;
use ACQC\Commands\Version\ShowCommand as VersionShowCommand;
use ACQC\Commands\Version\SyncCommand as VersionSyncCommand;
use ACQC\Commands\Version\DiffCommand as VersionDiffCommand;
use ACQC\Commands\Version\EolReportCommand as VersionEolReportCommand;
use ACQC\Commands\Version\PathCommand as VersionPathCommand;
use ACQC\Commands\Version\AdvisoriesCommand as VersionAdvisoriesCommand;
use ACQC\Commands\Version\DownloadCommand as VersionDownloadCommand;
use Symfony\Component\Console\Application as ConsoleApplication;

class Application extends ConsoleApplication
{
    public const VERSION = '3.1.0';
    private const NAME = 'Adobe Commerce Quality Control';

    public function __construct()
    {
        parent::__construct(self::NAME, self::VERSION);

        $this->addCommands([
            new AuditCommand(),
            new AnalyzeCommand(),
            new ReportCommand(),
            new ConfigCommand(),
            new ChecklistCommand(),
            new TechnicalSpecCommand(),
            new BaselineCommand(),
            new DetectVersionCommand(),
            new UpgradeReadinessCommand(),
            new BackupCommand(),
            new AutoFixCommand(),
            new ProjectQualityCommand(),
            new HistoryCommand(),
            new AggregateCommand(),
            new WatchCommand(),
            new PolicyCommand(),
            new DashboardCommand(),
            new ExportCommand(),
            new InitCommand(),
            new DoctorCommand(),
            new ScanCommand(),
            new ValidateCommand(),
            new RulesListCommand(),
            new SuppressCommand(),
            new UnsuppressCommand(),
            new RulesValidateCommand(),
            new SyncCommand(),
            new RulesNewCommand(),
            new VersionListCommand(),
            new VersionShowCommand(),
            new VersionSyncCommand(),
            new VersionDiffCommand(),
            new VersionEolReportCommand(),
            new VersionPathCommand(),
            new VersionAdvisoriesCommand(),
            new VersionDownloadCommand(),
        ]);
    }
}

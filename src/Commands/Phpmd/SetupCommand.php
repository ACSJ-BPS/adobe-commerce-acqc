<?php

declare(strict_types=1);

namespace ACQC\Commands\Phpmd;

use ACQC\Core\Config\ProjectConfigLocator;
use ACQC\Core\Setup\MagentoPhpmdInstaller;
use ACQC\Core\Version\MagentoVersion;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Exception\ParseException;

#[AsCommand(name: 'phpmd:setup', description: 'Install version-matched Magento PHPMD rules and configure the project')]
class SetupCommand extends Command
{
    public function __construct(private readonly MagentoPhpmdInstaller $installer = new MagentoPhpmdInstaller())
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('path', InputArgument::OPTIONAL, 'Project root or a directory inside the project', '.')
            ->addOption('commerce-version', null, InputOption::VALUE_REQUIRED, 'Exact Commerce version; defaults to project.platform_version')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Reinstall managed files, preserving the previous directory as a backup')
            ->setHelp('Downloads only Magento static-test rule resources and license files. PHPMD itself is supplied by ACQC Composer dependencies. Project YAML is reformatted when settings change; unrelated settings are preserved.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $path = realpath((string) $input->getArgument('path'));
            if ($path === false || !is_dir($path)) {
                throw new \InvalidArgumentException('The setup path must be an existing project directory.');
            }
            $located = (new ProjectConfigLocator())->loadNearest($path);
            if ($located['path'] === null || $located['project_root'] === null) {
                throw new \InvalidArgumentException('No project config found. Run acqc init --commerce-version=<exact-version> from the project root.');
            }
            $project = $located['config']['project'] ?? [];
            if (!is_array($project)) {
                throw new \InvalidArgumentException('project must be a mapping.');
            }
            $version = $input->getOption('commerce-version') ?? $project['platform_version'] ?? null;
            if ($input->getOption('commerce-version') === null
                && (!is_string($version) || !MagentoVersion::isValidString($version))
                && $input->isInteractive()) {
                $version = $io->ask('Exact Adobe Commerce version (for example 2.4.8-p5)', null, static function ($value): string {
                    if (!is_string($value) || !MagentoVersion::isValidString($value)) {
                        throw new \InvalidArgumentException('Enter an exact Commerce release, not a Composer constraint.');
                    }
                    return $value;
                });
            }
            if (!is_string($version) || !MagentoVersion::isValidString($version)) {
                throw new \InvalidArgumentException('Set project.platform_version to an exact Commerce release or pass --commerce-version.');
            }
            $io->note("Setting up Magento {$version} rules. The bootstrap will load the project autoloader in PHPMD subprocesses. Changed YAML settings are reformatted; comments are not retained.");
            $result = $this->installer->setup($located['project_root'], $located['path'], $located['config'], $version, (bool) $input->getOption('force'));
            $io->success(($result['reused'] ? 'Verified existing' : 'Installed') . " Magento {$version} PHPMD rules and configured the project.");
            if ($result['backup'] !== null) {
                $io->note('Previous rule files preserved at: ' . $result['backup']);
            }
            return Command::SUCCESS;
        } catch (\InvalidArgumentException | ParseException $e) {
            $io->error('Configuration error: ' . $e->getMessage());
            return Command::INVALID;
        } catch (\Throwable $e) {
            $io->error('PHPMD setup failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}

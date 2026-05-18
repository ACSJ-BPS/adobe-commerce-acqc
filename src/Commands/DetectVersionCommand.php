<?php

declare(strict_types=1);

namespace ACQC\Commands;

use ACQC\Core\Version\VersionDetector;
use ACQC\Core\Version\MagentoVersion;
use ACQC\Core\Version\PhpVersion;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Detect Magento 2 and PHP versions command
 */
#[AsCommand(name: 'detect-version')]
class DetectVersionCommand extends Command
{
    protected static $defaultName = 'detect-version';

    protected function configure(): void
    {
        $this
            ->setName('detect-version')
            ->setDescription('Detect Magento 2 and PHP versions from project')
            ->addArgument(
                'path',
                InputArgument::OPTIONAL,
                'Path to Magento project',
                getcwd()
            )
            ->addOption(
                'check-compatibility',
                'c',
                InputOption::VALUE_NONE,
                'Check version compatibility'
            )
            ->addOption(
                'show-matrix',
                'm',
                InputOption::VALUE_NONE,
                'Show compatibility matrix'
            )
            ->addOption(
                'format',
                'f',
                InputOption::VALUE_REQUIRED,
                'Output format (table, json)',
                'table'
            )
            ->setHelp(<<<'HELP'
The <info>detect-version</info> command detects Magento 2 and PHP versions from a project:

  <info>php bin/acqc detect-version /path/to/magento</info>

Check compatibility:
  <info>php bin/acqc detect-version --check-compatibility</info>

Show compatibility matrix:
  <info>php bin/acqc detect-version --show-matrix</info>

Output as JSON:
  <info>php bin/acqc detect-version --format json</info>
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = $input->getArgument('path');
        $format = $input->getOption('format');
        $showMatrix = $input->getOption('show-matrix');

        $io->title('ACQC Version Detection');

        $detector = new VersionDetector();

        // If only showing matrix, display generic compatibility matrix
        if ($showMatrix && !$input->getOption('check-compatibility')) {
            $this->displayGenericMatrix($io, $detector);
            return Command::SUCCESS;
        }

        // Detect versions
        $io->section('Detecting Versions...');
        
        $magentoVersion = $detector->detectMagentoVersion($path);
        $phpVersion = $detector->detectPhpVersion();

        if (!$magentoVersion) {
            $io->error('Could not detect Magento version. Please ensure you are in a Magento project directory.');
            return Command::FAILURE;
        }

        // Display results based on format
        if ($format === 'json') {
            $this->outputJson($output, $magentoVersion, $phpVersion, $detector, $input);
        } else {
            $this->outputTable($io, $magentoVersion, $phpVersion, $detector, $input);
        }

        return Command::SUCCESS;
    }

    private function outputTable(
        SymfonyStyle $io,
        MagentoVersion $magentoVersion,
        PhpVersion $phpVersion,
        VersionDetector $detector,
        InputInterface $input
    ): void {
        // Version Information
        $io->section('Detected Versions');
        
        $io->table(
            ['Component', 'Version', 'Status'],
            [
                [
                    'Magento',
                    $magentoVersion->toString(),
                    $magentoVersion->isEol() ? '🔴 EOL' : '✅ Active'
                ],
                [
                    'PHP',
                    $phpVersion->toString(),
                    $phpVersion->isEol() ? '🔴 EOL' : '✅ Active'
                ],
            ]
        );

        // Compatibility Check
        if ($input->getOption('check-compatibility')) {
            $this->displayCompatibility($io, $magentoVersion, $phpVersion, $detector);
        }

        // Compatibility Matrix
        if ($input->getOption('show-matrix')) {
            $this->displayMatrix($io, $magentoVersion, $phpVersion, $detector);
        }

        // Features and Deprecations
        $this->displayFeatures($io, $magentoVersion, $phpVersion);
    }

    private function displayCompatibility(
        SymfonyStyle $io,
        MagentoVersion $magentoVersion,
        PhpVersion $phpVersion,
        VersionDetector $detector
    ): void {
        $io->section('Compatibility Check');

        $matrix = $detector->getCompatibilityMatrix($magentoVersion, $phpVersion);
        $status = $matrix->getCompatibilityStatus();

        if ($status['compatible']) {
            $io->success('✅ Versions are compatible');
        } else {
            $io->error('❌ Version combination is not supported');
        }

        // Warnings
        if (!empty($status['warnings'])) {
            $io->warning($status['warnings']);
        }

        // Recommendations
        if (!empty($status['recommendations'])) {
            $io->note($status['recommendations']);
        }
    }

    private function displayGenericMatrix(SymfonyStyle $io, VersionDetector $detector): void
    {
        $io->section('Magento & PHP Compatibility Matrix');

        // Generic compatibility matrix without specific version detection
        $compatibilityData = [
            ['Magento 2.4.8', 'PHP 8.2, 8.3', '✅ Current'],
            ['Magento 2.4.7', 'PHP 8.2, 8.3', '✅ Supported'],
            ['Magento 2.4.6', 'PHP 8.1, 8.2', '✅ Supported'],
            ['Magento 2.4.5', 'PHP 8.1, 8.2', '⚠️  Limited Support'],
            ['Magento 2.4.4', 'PHP 8.1', '⚠️  Limited Support'],
            ['Magento 2.4.3', 'PHP 7.4, 8.1', '🔴 EOL'],
            ['Magento 2.4.2', 'PHP 7.4', '🔴 EOL'],
        ];

        $io->table(
            ['Magento Version', 'Compatible PHP Versions', 'Status'],
            $compatibilityData
        );

        $io->note([
            'Use with a Magento project path to detect specific versions.',
            'Example: php bin/acqc detect-version /path/to/magento'
        ]);
    }

    private function displayMatrix(
        SymfonyStyle $io,
        MagentoVersion $magentoVersion,
        PhpVersion $phpVersion,
        VersionDetector $detector
    ): void {
        $io->section('Compatibility Matrix');

        $matrix = $detector->getCompatibilityMatrix($magentoVersion, $phpVersion);

        // Supported PHP versions for current Magento
        $io->writeln(sprintf(
            '<info>Magento %s</info> supports PHP: <comment>%s</comment>',
            $magentoVersion->toString(),
            implode(', ', $matrix->getSupportedPhpVersions())
        ));

        // Supported Magento versions for current PHP
        $io->writeln(sprintf(
            '<info>PHP %s</info> supports Magento: <comment>%s</comment>',
            $phpVersion->toString(),
            implode(', ', $matrix->getSupportedMagentoVersions())
        ));
    }

    private function displayFeatures(
        SymfonyStyle $io,
        MagentoVersion $magentoVersion,
        PhpVersion $phpVersion
    ): void {
        // Magento Deprecated Features
        $deprecated = $magentoVersion->getDeprecatedFeatures();
        if (!empty($deprecated)) {
            $io->section('Magento Deprecated Features');
            $rows = [];
            foreach ($deprecated as $feature => $description) {
                $rows[] = [$feature, $description];
            }
            $io->table(['Feature', 'Description'], $rows);
        }

        // Magento New Features
        $newFeatures = $magentoVersion->getNewFeatures();
        if (!empty($newFeatures)) {
            $io->section('Magento New Features');
            $rows = [];
            foreach ($newFeatures as $feature => $description) {
                $rows[] = [$feature, $description];
            }
            $io->table(['Feature', 'Description'], $rows);
        }

        // PHP Breaking Changes
        $phpChanges = $phpVersion->getBreakingChanges();
        if (!empty($phpChanges)) {
            $io->section('PHP Breaking Changes');
            $rows = [];
            foreach ($phpChanges as $change => $description) {
                $rows[] = [$change, $description];
            }
            $io->table(['Change', 'Description'], $rows);
        }
    }

    private function outputJson(
        OutputInterface $output,
        MagentoVersion $magentoVersion,
        PhpVersion $phpVersion,
        VersionDetector $detector,
        InputInterface $input
    ): void {
        $data = [
            'magento' => [
                'version' => $magentoVersion->toString(),
                'major' => $magentoVersion->getMajor(),
                'minor' => $magentoVersion->getMinor(),
                'patch' => $magentoVersion->getPatch(),
                'edition' => $magentoVersion->getEdition(),
                'eol' => $magentoVersion->isEol(),
                'deprecated_features' => $magentoVersion->getDeprecatedFeatures(),
                'new_features' => $magentoVersion->getNewFeatures(),
            ],
            'php' => [
                'version' => $phpVersion->toString(),
                'major' => $phpVersion->getMajor(),
                'minor' => $phpVersion->getMinor(),
                'release' => $phpVersion->getRelease(),
                'eol' => $phpVersion->isEol(),
                'breaking_changes' => $phpVersion->getBreakingChanges(),
            ],
        ];

        if ($input->getOption('check-compatibility')) {
            $matrix = $detector->getCompatibilityMatrix($magentoVersion, $phpVersion);
            $data['compatibility'] = $matrix->getCompatibilityStatus();
        }

        $output->writeln(json_encode($data, JSON_PRETTY_PRINT));
    }
}


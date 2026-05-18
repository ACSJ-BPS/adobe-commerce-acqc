<?php

declare(strict_types=1);

namespace ACQC\Commands;

use ACQC\Core\Config\ConfigManager;
use ACQC\Core\Config\RoleConfig;
use ACQC\Core\Config\RuleConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Yaml\Yaml;
use Psr\Log\LoggerInterface;

/**
 * Enhanced Configuration Management Command
 * 
 * Comprehensive configuration management with features:
 * - Interactive configuration wizard
 * - Profile and role management
 * - Rule configuration and validation
 * - Configuration import/export
 * - Template generation
 * - Configuration validation and testing
 * - Environment-specific configurations
 * - Configuration migration and upgrades
 */
#[AsCommand(name: 'config', description: 'Manage ACQC configuration with advanced features')]
class ConfigCommand extends Command
{
    protected static $defaultName = 'config';
    protected static $defaultDescription = 'Manage ACQC configuration with advanced features';

    private ConfigManager $configManager;
    private LoggerInterface $logger;

    // Configuration categories
    private const CONFIG_CATEGORIES = [
        'general' => 'General application settings',
        'rules' => 'Code analysis rules configuration',
        'profiles' => 'User role profiles and permissions',
        'thresholds' => 'Quality score thresholds and limits',
        'integrations' => 'External integrations (CI/CD, notifications)',
        'ai' => 'AI and predictive analytics settings',
        'reports' => 'Report generation configuration',
        'performance' => 'Performance and optimization settings'
    ];

    // Predefined configuration templates
    private const CONFIG_TEMPLATES = [
        'minimal' => 'Minimal configuration for basic usage',
        'developer' => 'Developer-focused configuration',
        'enterprise' => 'Enterprise-grade configuration',
        'ci-cd' => 'Continuous Integration/Deployment optimized',
        'security' => 'Security-focused configuration',
        'performance' => 'Performance optimization focused'
    ];

    // Environment types
    private const ENVIRONMENTS = [
        'development' => 'Development environment',
        'testing' => 'Testing/QA environment',
        'staging' => 'Staging environment',
        'production' => 'Production environment'
    ];

    public function __construct(
        ?ConfigManager $configManager = null,
        ?LoggerInterface $logger = null
    ) {
        parent::__construct();
        
        $this->configManager = $configManager ?? new ConfigManager();
        $this->logger = $logger ?? new \Psr\Log\NullLogger();
    }

    protected function configure(): void
    {
        $this
            ->setName('config')
            ->setDescription('Comprehensive configuration management for ACQC')
            ->setHelp($this->getDetailedHelp())
            
            // Main configuration actions
            ->addArgument(
                'action',
                InputArgument::OPTIONAL,
                'Action: init, show, set, get, validate, export, import, reset, upgrade',
                'show'
            )
            ->addArgument(
                'key',
                InputArgument::OPTIONAL,
                'Configuration key (dot notation: category.setting.option)'
            )
            ->addArgument(
                'value',
                InputArgument::OPTIONAL,
                'Configuration value to set'
            )
            
            // Configuration file options
            ->addOption(
                'config',
                'c',
                InputOption::VALUE_REQUIRED,
                'Configuration file path',
                'config/default.yaml'
            )
            ->addOption(
                'profile',
                'p',
                InputOption::VALUE_REQUIRED,
                'Configuration profile to use'
            )
            ->addOption(
                'environment',
                'e',
                InputOption::VALUE_REQUIRED,
                'Environment: ' . implode(', ', array_keys(self::ENVIRONMENTS)),
                'development'
            )
            
            // Initialization options
            ->addOption(
                'template',
                't',
                InputOption::VALUE_REQUIRED,
                'Configuration template: ' . implode(', ', array_keys(self::CONFIG_TEMPLATES)),
                'developer'
            )
            ->addOption(
                'interactive',
                'i',
                InputOption::VALUE_NONE,
                'Interactive configuration wizard'
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Force action without confirmation'
            )
            
            // Display options
            ->addOption(
                'format',
                null,
                InputOption::VALUE_REQUIRED,
                'Output format: yaml, json, table, list',
                'table'
            )
            ->addOption(
                'category',
                null,
                InputOption::VALUE_REQUIRED,
                'Show specific category: ' . implode(', ', array_keys(self::CONFIG_CATEGORIES))
            )
            ->addOption(
                'detailed',
                'd',
                InputOption::VALUE_NONE,
                'Show detailed configuration with descriptions'
            )
            ->addOption(
                'defaults',
                null,
                InputOption::VALUE_NONE,
                'Show default values only'
            )
            
            // Import/Export options
            ->addOption(
                'input',
                null,
                InputOption::VALUE_REQUIRED,
                'Input file for import operation'
            )
            ->addOption(
                'output',
                'o',
                InputOption::VALUE_REQUIRED,
                'Output file for export operation'
            )
            ->addOption(
                'merge',
                'm',
                InputOption::VALUE_NONE,
                'Merge imported configuration with existing'
            )
            
            // Validation options
            ->addOption(
                'strict',
                's',
                InputOption::VALUE_NONE,
                'Enable strict validation'
            )
            ->addOption(
                'warnings-as-errors',
                'w',
                InputOption::VALUE_NONE,
                'Treat warnings as errors in validation'
            )
            
            // Advanced options
            ->addOption(
                'backup',
                'b',
                InputOption::VALUE_NONE,
                'Create backup before making changes'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Preview changes without applying them'
            )
            ->addOption(
                'migrate',
                null,
                InputOption::VALUE_NONE,
                'Migrate configuration to latest version'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $action = strtolower($input->getArgument('action') ?? 'show');

        try {
            // Initialize configuration manager with options
            $this->initializeConfigManager($input);

            switch ($action) {
                case 'init':
                    return $this->initializeConfiguration($input, $io);
                    
                case 'show':
                    return $this->showConfiguration($input, $io);
                    
                case 'set':
                    return $this->setConfiguration($input, $io);
                    
                case 'get':
                    return $this->getConfiguration($input, $io);
                    
                case 'validate':
                    return $this->validateConfiguration($input, $io);
                    
                case 'export':
                    return $this->exportConfiguration($input, $io);
                    
                case 'import':
                    return $this->importConfiguration($input, $io);
                    
                case 'reset':
                    return $this->resetConfiguration($input, $io);
                    
                case 'upgrade':
                    return $this->upgradeConfiguration($input, $io);
                    
                case 'wizard':
                    return $this->runConfigurationWizard($input, $io);
                    
                default:
                    $io->error("Unknown action: $action");
                    $io->writeln('Available actions: init, show, set, get, validate, export, import, reset, upgrade, wizard');
                    return Command::FAILURE;
            }
        } catch (\Exception $e) {
            $io->error("Configuration operation failed: " . $e->getMessage());
            $this->logger->error('Configuration operation failed', [
                'action' => $action,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return Command::FAILURE;
        }
    }

    private function initializeConfiguration(InputInterface $input, SymfonyStyle $io): int
    {
        $template = $input->getOption('template');
        $interactive = $input->getOption('interactive');
        $configFile = $input->getOption('config');

        $io->title('🔧 ACQC Configuration Initialization');

        // Check if configuration already exists
        if (file_exists($configFile) && !$input->getOption('force')) {
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion(
                "Configuration file already exists. Overwrite? ",
                false
            );
            
            if (!$helper->ask($input, $io, $question)) {
                $io->info('Configuration initialization cancelled.');
                return Command::SUCCESS;
            }
        }

        if ($interactive) {
            return $this->runConfigurationWizard($input, $io);
        }

        // Generate configuration from template
        $config = $this->generateConfigurationFromTemplate($template, $input);

        // Create backup if file exists
        if (file_exists($configFile) && $input->getOption('backup')) {
            $backupFile = $configFile . '.backup.' . date('Y-m-d_H-i-s');
            copy($configFile, $backupFile);
            $io->info("Backup created: $backupFile");
        }

        // Save configuration
        $this->saveConfiguration($config, $configFile);

        $io->success("Configuration initialized successfully: $configFile");
        $io->writeln("Template used: $template");

        // Display next steps
        $this->displayNextSteps($io);

        return Command::SUCCESS;
    }

    private function showConfiguration(InputInterface $input, SymfonyStyle $io): int
    {
        $format = $input->getOption('format');
        $category = $input->getOption('category');
        $detailed = $input->getOption('detailed');

        $io->title('📋 ACQC Configuration');

        // Load current configuration
        $config = $this->configManager->loadConfig();

        // Filter by category if specified
        if ($category) {
            if (!isset(self::CONFIG_CATEGORIES[$category])) {
                $io->error("Invalid category: $category");
                return Command::FAILURE;
            }
            $config = [$category => $config[$category] ?? []];
        }

        // Display configuration based on format
        switch ($format) {
            case 'yaml':
                $io->writeln(Yaml::dump($config, 4, 2));
                break;
                
            case 'json':
                $io->writeln(json_encode($config, JSON_PRETTY_PRINT));
                break;
                
            case 'list':
                $this->displayConfigurationList($config, $io, $detailed);
                break;
                
            case 'table':
            default:
                $this->displayConfigurationTable($config, $io, $detailed);
                break;
        }

        // Show configuration file info
        $configFile = $input->getOption('config') ?: 'config/default.yaml';
        $io->writeln('');
        $io->writeln("<info>Configuration file:</info> $configFile");
        if (file_exists($configFile)) {
            $io->writeln("<info>Last modified:</info> " . date('Y-m-d H:i:s', filemtime($configFile)));
        } else {
            $io->writeln("<comment>Configuration file not found</comment>");
        }

        return Command::SUCCESS;
    }

    private function setConfiguration(InputInterface $input, SymfonyStyle $io): int
    {
        $key = $input->getArgument('key');
        $value = $input->getArgument('value');

        if (!$key) {
            $io->error('Configuration key is required for set operation');
            return Command::FAILURE;
        }

        if (!$value) {
            $io->error('Configuration value is required for set operation');
            return Command::FAILURE;
        }

        $io->title('⚙️  Setting Configuration');

        // Parse value (try to detect type)
        $parsedValue = $this->parseConfigurationValue($value);

        // Create backup if requested
        if ($input->getOption('backup')) {
            $this->createConfigurationBackup();
        }

        // Dry run mode
        if ($input->getOption('dry-run')) {
            $io->writeln("Would set: <info>$key</info> = <comment>" . json_encode($parsedValue) . "</comment>");
            return Command::SUCCESS;
        }

        // Set the configuration
        $oldValue = $this->configManager->get($key);
        $this->configManager->set($key, $parsedValue);

        // Save configuration
        $this->configManager->save();

        $io->success("Configuration updated successfully");
        $io->writeln("Key: <info>$key</info>");
        $io->writeln("Old value: <comment>" . json_encode($oldValue) . "</comment>");
        $io->writeln("New value: <comment>" . json_encode($parsedValue) . "</comment>");

        // Validate configuration after change
        $validation = $this->configManager->validate();
        if (!$validation['valid']) {
            $io->warning('Configuration validation warnings:');
            foreach ($validation['warnings'] as $warning) {
                $io->writeln("  - $warning");
            }
        }

        return Command::SUCCESS;
    }

    private function getConfiguration(InputInterface $input, SymfonyStyle $io): int
    {
        $key = $input->getArgument('key');

        if (!$key) {
            $io->error('Configuration key is required for get operation');
            return Command::FAILURE;
        }

        $value = $this->configManager->get($key);

        if ($value === null) {
            $io->error("Configuration key not found: $key");
            return Command::FAILURE;
        }

        $format = $input->getOption('format');

        switch ($format) {
            case 'json':
                $io->writeln(json_encode($value, JSON_PRETTY_PRINT));
                break;
                
            case 'yaml':
                $io->writeln(Yaml::dump([$key => $value], 4, 2));
                break;
                
            default:
                $io->writeln("<info>$key:</info> " . json_encode($value));
                break;
        }

        return Command::SUCCESS;
    }

    private function validateConfiguration(InputInterface $input, SymfonyStyle $io): int
    {
        $strict = $input->getOption('strict');
        $warningsAsErrors = $input->getOption('warnings-as-errors');

        $io->title('✅ Configuration Validation');

        $validation = $this->configManager->validate($strict);

        if ($validation['valid']) {
            $io->success('Configuration is valid');
        } else {
            $io->error('Configuration validation failed');
        }

        // Display errors
        if (!empty($validation['errors'])) {
            $io->writeln('');
            $io->writeln('<error>Errors:</error>');
            foreach ($validation['errors'] as $error) {
                $io->writeln("  ❌ $error");
            }
        }

        // Display warnings
        if (!empty($validation['warnings'])) {
            $io->writeln('');
            $io->writeln('<comment>Warnings:</comment>');
            foreach ($validation['warnings'] as $warning) {
                $io->writeln("  ⚠️  $warning");
            }
        }

        // Display suggestions
        if (!empty($validation['suggestions'] ?? [])) {
            $io->writeln('');
            $io->writeln('<info>Suggestions:</info>');
            foreach ($validation['suggestions'] as $suggestion) {
                $io->writeln("  💡 $suggestion");
            }
        }

        // Summary
        $io->writeln('');
        $io->writeln(sprintf(
            'Validation summary: %d errors, %d warnings, %d suggestions',
            count($validation['errors'] ?? []),
            count($validation['warnings'] ?? []),
            count($validation['suggestions'] ?? [])
        ));

        // Determine exit code
        if (!$validation['valid']) {
            return Command::FAILURE;
        }
        
        if ($warningsAsErrors && !empty($validation['warnings'])) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function exportConfiguration(InputInterface $input, SymfonyStyle $io): int
    {
        $outputFile = $input->getOption('output');
        $format = $input->getOption('format');
        $category = $input->getOption('category');

        if (!$outputFile) {
            $outputFile = 'config-export-' . date('Y-m-d_H-i-s') . '.' . ($format === 'json' ? 'json' : 'yaml');
        }

        $io->title('📤 Exporting Configuration');

        $config = $this->configManager->getAllConfig();

        // Filter by category if specified
        if ($category) {
            $config = [$category => $config[$category] ?? []];
        }

        // Add metadata
        $exportData = [
            'metadata' => [
                'exported_at' => date('c'),
                'exported_by' => get_current_user(),
                'version' => $this->configManager->getVersion(),
                'environment' => $input->getOption('environment')
            ],
            'configuration' => $config
        ];

        // Export based on format
        switch ($format) {
            case 'json':
                $content = json_encode($exportData, JSON_PRETTY_PRINT);
                break;
                
            case 'yaml':
            default:
                $content = Yaml::dump($exportData, 4, 2);
                break;
        }

        file_put_contents($outputFile, $content);

        $io->success("Configuration exported to: $outputFile");
        $io->writeln("Format: $format");
        $io->writeln("Size: " . $this->formatBytes(strlen($content)));

        return Command::SUCCESS;
    }

    private function importConfiguration(InputInterface $input, SymfonyStyle $io): int
    {
        $inputFile = $input->getOption('input');
        $merge = $input->getOption('merge');
        $dryRun = $input->getOption('dry-run');

        if (!$inputFile) {
            $io->error('Input file is required for import operation');
            return Command::FAILURE;
        }

        if (!file_exists($inputFile)) {
            $io->error("Input file not found: $inputFile");
            return Command::FAILURE;
        }

        $io->title('📥 Importing Configuration');

        // Load import data
        $content = file_get_contents($inputFile);
        $extension = pathinfo($inputFile, PATHINFO_EXTENSION);

        try {
            switch ($extension) {
                case 'json':
                    $importData = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
                    break;
                    
                case 'yaml':
                case 'yml':
                    $importData = Yaml::parse($content);
                    break;
                    
                default:
                    throw new \Exception("Unsupported file format: $extension");
            }
        } catch (\Exception $e) {
            $io->error("Failed to parse import file: " . $e->getMessage());
            return Command::FAILURE;
        }

        // Extract configuration from import data
        $importConfig = $importData['configuration'] ?? $importData;

        // Create backup
        if ($input->getOption('backup')) {
            $this->createConfigurationBackup();
        }

        // Dry run mode
        if ($dryRun) {
            $io->writeln('Dry run mode - showing what would be imported:');
            $this->displayConfigurationChanges($importConfig, $merge, $io);
            return Command::SUCCESS;
        }

        // Import configuration
        if ($merge) {
            $this->configManager->mergeConfig($importConfig);
        } else {
            $this->configManager->replaceConfig($importConfig);
        }

        // Save configuration
        $this->configManager->save();

        $io->success("Configuration imported successfully from: $inputFile");
        $io->writeln("Import mode: " . ($merge ? 'Merge' : 'Replace'));

        // Validate imported configuration
        $validation = $this->configManager->validate();
        if (!$validation['valid']) {
            $io->warning('Imported configuration has validation issues');
            foreach ($validation['warnings'] as $warning) {
                $io->writeln("  - $warning");
            }
        }

        return Command::SUCCESS;
    }

    private function runConfigurationWizard(InputInterface $input, SymfonyStyle $io): int
    {
        $io->title('🧙 ACQC Configuration Wizard');
        $io->writeln('This wizard will guide you through configuring ACQC for your project.');
        $io->writeln('');

        $helper = $this->getHelper('question');
        $config = [];

        // General settings
        $io->section('General Settings');
        
        $projectNameQuestion = new Question('Project name: ', 'My Project');
        $config['general']['project_name'] = $helper->ask($input, $io, $projectNameQuestion);

        $environmentQuestion = new ChoiceQuestion(
            'Environment type:',
            array_keys(self::ENVIRONMENTS),
            'development'
        );
        $config['general']['environment'] = $helper->ask($input, $io, $environmentQuestion);

        // Rules configuration
        $io->section('Rules Configuration');
        
        $rulesQuestion = new ChoiceQuestion(
            'Rule complexity level:',
            ['basic', 'standard', 'strict', 'custom'],
            'standard'
        );
        $ruleLevel = $helper->ask($input, $io, $rulesQuestion);
        $config['rules'] = $this->generateRulesConfig($ruleLevel);

        // Quality thresholds
        $io->section('Quality Thresholds');
        
        $qualityScoreQuestion = new Question('Minimum quality score (1-10): ', '7.0');
        $config['thresholds']['quality_score'] = (float)$helper->ask($input, $io, $qualityScoreQuestion);

        $coverageQuestion = new Question('Minimum test coverage (0-100): ', '80');
        $config['thresholds']['test_coverage'] = (int)$helper->ask($input, $io, $coverageQuestion);

        // AI features
        $io->section('AI Features');
        
        $aiQuestion = new ConfirmationQuestion('Enable AI-powered insights? ', true);
        $config['ai']['enabled'] = $helper->ask($input, $io, $aiQuestion);

        if ($config['ai']['enabled']) {
            $providerQuestion = new ChoiceQuestion(
                'AI provider:',
                ['openai', 'claude', 'local'],
                'local'
            );
            $config['ai']['provider'] = $helper->ask($input, $io, $providerQuestion);
        }

        // Reports configuration
        $io->section('Reports Configuration');
        
        $reportFormatsQuestion = new ChoiceQuestion(
            'Default report formats (comma-separated):',
            ['html', 'pdf', 'json', 'junit'],
            'html,json'
        );
        $reportFormatsQuestion->setMultiselect(true);
        $config['reports']['default_formats'] = $helper->ask($input, $io, $reportFormatsQuestion);

        // Integration settings
        $io->section('Integrations');
        
        $ciQuestion = new ConfirmationQuestion('Configure for CI/CD integration? ', true);
        if ($helper->ask($input, $io, $ciQuestion)) {
            $config['integrations']['ci_cd'] = $this->generateCIConfig();
        }

        // Save configuration
        $configFile = $input->getOption('config');
        $this->saveConfiguration($config, $configFile);

        $io->success("Configuration wizard completed successfully!");
        $io->writeln("Configuration saved to: $configFile");

        return Command::SUCCESS;
    }

    // Helper methods

    private function initializeConfigManager(InputInterface $input): void
    {
        $configFile = $input->getOption('config');
        $profile = $input->getOption('profile');
        $environment = $input->getOption('environment');

        // Build sources array for configuration loading
        $sources = [];
        
        if ($configFile) {
            $sources[] = $configFile;
        }
        
        if ($profile) {
            // Load profile-specific configuration
            $profileConfig = $this->configManager->loadRoleConfig($profile);
            $this->configManager->set('current_profile', $profile);
        }
        
        if ($environment) {
            $this->configManager->set('environment', $environment);
        }
        
        // Load the configuration with all sources
        if (!empty($sources)) {
            $this->configManager->loadConfig($sources);
        }
    }

    private function generateConfigurationFromTemplate(string $template, InputInterface $input): array
    {
        $templates = [
            'minimal' => [
                'general' => ['project_name' => 'Project'],
                'rules' => ['enabled' => ['basic-quality']],
                'thresholds' => ['quality_score' => 5.0]
            ],
            'developer' => [
                'general' => ['project_name' => 'Developer Project'],
                'rules' => ['enabled' => ['code-quality', 'performance']],
                'thresholds' => ['quality_score' => 7.0, 'test_coverage' => 70],
                'ai' => ['enabled' => true, 'provider' => 'local']
            ],
            'enterprise' => [
                'general' => ['project_name' => 'Enterprise Project'],
                'rules' => ['enabled' => ['all']],
                'thresholds' => ['quality_score' => 8.5, 'test_coverage' => 90],
                'ai' => ['enabled' => true, 'provider' => 'openai'],
                'integrations' => ['ci_cd' => true, 'notifications' => true]
            ]
        ];

        return $templates[$template] ?? $templates['developer'];
    }

    private function saveConfiguration(array $config, string $configFile): void
    {
        $directory = dirname($configFile);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $content = Yaml::dump($config, 4, 2);
        file_put_contents($configFile, $content);
    }

    private function displayConfigurationTable(array $config, SymfonyStyle $io, bool $detailed): void
    {
        $table = new Table($io);
        $table->setHeaders(['Category', 'Setting', 'Value', $detailed ? 'Description' : null]);

        foreach ($config as $category => $settings) {
            $this->addConfigurationRows($table, $category, $settings, '', $detailed);
        }

        $table->render();
    }

    private function addConfigurationRows(Table $table, string $category, $settings, string $prefix, bool $detailed): void
    {
        if (is_array($settings)) {
            foreach ($settings as $key => $value) {
                $fullKey = $prefix ? "$prefix.$key" : $key;
                
                if (is_array($value)) {
                    $this->addConfigurationRows($table, $category, $value, $fullKey, $detailed);
                } else {
                    $row = [$category, $fullKey, json_encode($value)];
                    if ($detailed) {
                        $row[] = $this->getConfigurationDescription($category, $fullKey);
                    }
                    $table->addRow($row);
                    $category = ''; // Only show category for first row
                }
            }
        }
    }

    private function parseConfigurationValue(string $value)
    {
        // Try to parse as JSON first
        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        // Try to parse as boolean
        if (in_array(strtolower($value), ['true', 'false'])) {
            return strtolower($value) === 'true';
        }

        // Try to parse as number
        if (is_numeric($value)) {
            return strpos($value, '.') !== false ? (float)$value : (int)$value;
        }

        // Return as string
        return $value;
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $unitIndex = 0;
        
        while ($bytes >= 1024 && $unitIndex < count($units) - 1) {
            $bytes /= 1024;
            $unitIndex++;
        }
        
        return round($bytes, 2) . ' ' . $units[$unitIndex];
    }

    private function getDetailedHelp(): string
    {
        return <<<'HELP'
The <info>config</info> command provides comprehensive configuration management for ACQC.

<comment>Actions:</comment>
  init        Initialize configuration from template
  show        Display current configuration
  set         Set configuration value
  get         Get configuration value
  validate    Validate configuration
  export      Export configuration to file
  import      Import configuration from file
  reset       Reset configuration to defaults
  upgrade     Upgrade configuration to latest version
  wizard      Interactive configuration wizard

<comment>Examples:</comment>
  # Initialize with interactive wizard
  acqc config init --interactive

  # Show all configuration
  acqc config show --detailed

  # Set a configuration value
  acqc config set thresholds.quality_score 8.5

  # Get a configuration value
  acqc config get ai.enabled

  # Export configuration
  acqc config export --output=my-config.yaml

  # Import configuration
  acqc config import --input=my-config.yaml --merge

  # Validate configuration
  acqc config validate --strict

  # Reset to defaults
  acqc config reset --template=enterprise --force
HELP;
    }

    // Placeholder methods for additional functionality
    private function displayNextSteps(SymfonyStyle $io): void {}
    private function displayConfigurationList(array $config, SymfonyStyle $io, bool $detailed): void {}
    private function createConfigurationBackup(): void {}
    private function displayConfigurationChanges(array $config, bool $merge, SymfonyStyle $io): void {}
    private function generateRulesConfig(string $level): array { return []; }
    private function generateCIConfig(): array { return []; }
    private function getConfigurationDescription(string $category, string $key): string { return ''; }
    private function resetConfiguration(InputInterface $input, SymfonyStyle $io): int { return Command::SUCCESS; }
    private function upgradeConfiguration(InputInterface $input, SymfonyStyle $io): int { return Command::SUCCESS; }
} 
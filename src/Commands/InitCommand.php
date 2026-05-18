<?php

declare(strict_types=1);

namespace ACQC\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Yaml\Yaml;

class InitCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('init')
            ->setDescription('Initialise ACQC for the current project')
            ->addOption('ci',      null, InputOption::VALUE_OPTIONAL, 'Generate CI workflow: github|gitlab|bitbucket')
            ->addOption('profile', null, InputOption::VALUE_OPTIONAL, 'Default profile', 'developer')
            ->addOption('force',   null, InputOption::VALUE_NONE,     'Overwrite existing .acqc/project.yaml without prompting')
            ->addOption('silent',  null, InputOption::VALUE_NONE,     'Suppress informational output');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $projectRoot  = getcwd();
        $acqcDir      = $projectRoot . '/.acqc';
        $configPath   = $acqcDir . '/project.yaml';
        $quiet        = (bool) $input->getOption('silent');
        $force        = (bool) $input->getOption('force');
        $ciPlatform   = is_string($input->getOption('ci')) ? $input->getOption('ci') : null;
        $profile      = $input->getOption('profile') ?? 'developer';

        // Guard: existing config
        if (file_exists($configPath) && !$force) {
            if (!$input->isInteractive()) {
                $output->writeln('<error>.acqc/project.yaml already exists. Use --force to overwrite.</error>');
                return Command::FAILURE;
            }

            $helper   = $this->getHelper('question');
            $question = new ConfirmationQuestion(
                '<question>.acqc/project.yaml already exists. Overwrite? [y/N] </question>',
                false
            );

            if (!$helper->ask($input, $output, $question)) {
                $output->writeln('Aborted.');
                return Command::SUCCESS;
            }
        }

        // Detect platform
        ['platform' => $platform, 'version' => $platformVersion] = $this->detectPlatform($projectRoot);
        $phpVersion = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;

        // Choose rule bundle
        $ruleBundle = 'adobe-commerce/latest';
        if ($platform !== 'unknown' && $platformVersion !== 'unknown') {
            $clean = ltrim((string) preg_replace('/[^0-9.]/', '', $platformVersion), '~^');
            $parts = explode('.', $clean);
            if (count($parts) >= 2) {
                $ruleBundle = "adobe-commerce/{$parts[0]}.{$parts[1]}";
            }
        }

        // Create .acqc directory
        if (!is_dir($acqcDir)) {
            mkdir($acqcDir, 0755, true);
        }

        // Write project.yaml
        $config = [
            'schema_version' => '1',
            'acqc_version'   => '>=3.0.0',
            'project'        => [
                'platform'         => $platform,
                'platform_version' => $platformVersion,
                'php_version'      => $phpVersion,
            ],
            'rule_bundles'  => [$ruleBundle],
            'suppressions'  => [],
        ];

        file_put_contents($configPath, Yaml::dump($config, 4, 2));

        if (!$quiet) {
            $output->writeln("<info>✓ Created .acqc/project.yaml</info>");
            $output->writeln("  Platform : {$platform} {$platformVersion}");
            $output->writeln("  PHP      : {$phpVersion}");
            $output->writeln("  Bundle   : {$ruleBundle}");
        }

        // CI workflow
        if ($ciPlatform !== null) {
            $ciResult = $this->writeCiWorkflow($projectRoot, (string) $ciPlatform, $quiet, $output);
            if ($ciResult !== Command::SUCCESS) {
                return $ciResult;
            }
        }

        if (!$quiet) {
            $output->writeln('');
            $output->writeln('<info>Next steps:</info>');
            $output->writeln('  php bin/acqc doctor          # verify setup');
            $output->writeln('  php bin/acqc rules:list      # browse rules');
            $output->writeln('  php bin/acqc scan .          # run first scan');
        }

        return Command::SUCCESS;
    }

    // ── private helpers ────────────────────────────────────────────────────────

    private function detectPlatform(string $projectRoot): array
    {
        $lockFile = $projectRoot . '/composer.lock';
        if (file_exists($lockFile)) {
            $lock = json_decode(file_get_contents($lockFile), true);
            foreach ($lock['packages'] ?? [] as $pkg) {
                $match = match ($pkg['name']) {
                    'magento/magento2-base'               => 'magento2',
                    'magento/product-community-edition'   => 'magento2-open-source',
                    'magento/product-enterprise-edition'  => 'magento2-enterprise',
                    default                               => null,
                };
                if ($match !== null) {
                    return ['platform' => $match, 'version' => $pkg['version']];
                }
            }
        }

        $composerFile = $projectRoot . '/composer.json';
        if (file_exists($composerFile)) {
            $data    = json_decode(file_get_contents($composerFile), true);
            $require = $data['require'] ?? [];

            $mapping = [
                'magento/magento2-base'              => 'magento2',
                'magento/product-community-edition'  => 'magento2-open-source',
                'magento/product-enterprise-edition' => 'magento2-enterprise',
            ];

            foreach ($mapping as $package => $label) {
                if (isset($require[$package])) {
                    return ['platform' => $label, 'version' => $require[$package]];
                }
            }
        }

        return ['platform' => 'unknown', 'version' => 'unknown'];
    }

    private function writeCiWorkflow(
        string $projectRoot,
        string $ciPlatform,
        bool   $quiet,
        OutputInterface $output,
    ): int {
        switch ($ciPlatform) {
            case 'github':
                $dir  = $projectRoot . '/.github/workflows';
                $file = $dir . '/acqc.yml';
                $yaml = $this->githubWorkflow();
                break;

            case 'gitlab':
                $dir  = $projectRoot;
                $file = $dir . '/.gitlab-ci-acqc.yml';
                $yaml = $this->gitlabCiSnippet();
                break;

            case 'bitbucket':
                $dir  = $projectRoot;
                $file = $dir . '/bitbucket-pipelines-acqc.yml';
                $yaml = $this->bitbucketPipelineSnippet();
                break;

            default:
                $output->writeln("<error>Unknown CI platform '{$ciPlatform}'. Valid: github, gitlab, bitbucket</error>");
                return Command::FAILURE;
        }

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($file, $yaml);

        if (!$quiet) {
            $relative = ltrim(str_replace($projectRoot, '', $file), '/');
            $output->writeln("<info>✓ Created {$relative}</info>");
        }

        return Command::SUCCESS;
    }

    private function githubWorkflow(): string
    {
        return <<<'YAML'
name: ACQC Quality Gate

on:
  pull_request:
  push:
    branches: [main, develop]

jobs:
  quality:
    name: ACQC Code Quality
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          coverage: none

      - name: Install dependencies
        run: composer install --no-interaction --prefer-dist --optimize-autoloader

      - name: Run ACQC quality scan
        run: php bin/acqc scan . --profile=ci --format=json
YAML;
    }

    private function gitlabCiSnippet(): string
    {
        return <<<'YAML'
acqc-quality:
  stage: test
  image: php:8.3-cli
  before_script:
    - composer install --no-interaction --prefer-dist
  script:
    - php bin/acqc scan . --profile=ci --format=json
YAML;
    }

    private function bitbucketPipelineSnippet(): string
    {
        return <<<'YAML'
pipelines:
  default:
    - step:
        name: ACQC Quality Gate
        image: php:8.3-cli
        script:
          - composer install --no-interaction --prefer-dist
          - php bin/acqc scan . --profile=ci --format=json
YAML;
    }
}

<?php

declare(strict_types=1);

namespace ACQC\Core;

use ACQC\Core\Config\ConfigManager;
use ACQC\Analyzers\Magento\ModuleStructureAnalyzer;
use ACQC\Analyzers\Magento\DIAnalyzer;
use ACQC\Analyzers\Magento\PluginAnalyzer;
use ACQC\Analyzers\Magento\ObserverAnalyzer;
use ACQC\Analyzers\Magento\DatabaseAnalyzer;
use ACQC\Analyzers\Magento\PerformanceAnalyzer as MagentoPerformanceAnalyzer;
use ACQC\Analyzers\Magento\SecurityAnalyzer as MagentoSecurityAnalyzer;
use ACQC\Analyzers\Magento\UpgradeCompatibilityAnalyzer;
use ACQC\Analyzers\Magento\MagentoSpecificAnalyzer;
use ACQC\Analyzers\Frontend\ReactAnalyzer;
use ACQC\Analyzers\Frontend\PWAAnalyzer;
use ACQC\Analyzers\Frontend\SEOAnalyzer;
use ACQC\Analyzers\Frontend\PerformanceAnalyzer as FrontendPerformanceAnalyzer;
use ACQC\Analyzers\Frontend\AccessibilityAnalyzer;
use ACQC\Analyzers\Frontend\SecurityAnalyzer as FrontendSecurityAnalyzer;
use ACQC\Analyzers\PHP\PHPCSAnalyzer;
use ACQC\Analyzers\PHP\PHPMDAnalyzer;
use ACQC\Analyzers\PHP\PHPStanAnalyzer;
use ACQC\Analyzers\PHP\PHPCPDAnalyzer;
use ACQC\Analyzers\PHP\SonarAnalyzer;
use ACQC\Analyzers\AppBuilder\AbqcAnalyzer;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class AnalyzerRegistry
{
    private array $analyzers = [];
    private array $categories = [];
    private ConfigManager $configManager;
    private LoggerInterface $logger;

    public function __construct(?ConfigManager $configManager = null, ?LoggerInterface $logger = null)
    {
        $this->configManager = $configManager ?? new ConfigManager();
        $this->logger = $logger ?? new NullLogger();
        $this->initializeAnalyzers();
    }

    /**
     * Initialize all available analyzers with their categories
     */
    private function initializeAnalyzers(): void
    {
        $config = $this->configManager->loadConfig();
        
        // Magento Backend Analyzers
        $this->registerAnalyzer(
            'magento-structure',
            new ModuleStructureAnalyzer($config['analyzers']['magento']['structure'] ?? [], $this->logger),
            'magento-backend',
            'Analyzes Magento module structure and organization'
        );

        $this->registerAnalyzer(
            'magento-di',
            new DIAnalyzer($config['analyzers']['magento']['dependency_injection'] ?? [], $this->logger),
            'magento-backend',
            'Analyzes dependency injection patterns and ObjectManager usage'
        );

        $this->registerAnalyzer(
            'magento-plugins',
            new PluginAnalyzer($config['analyzers']['magento']['plugins'] ?? [], $this->logger),
            'magento-backend',
            'Analyzes plugin implementation and best practices'
        );

        $this->registerAnalyzer(
            'magento-observers',
            new ObserverAnalyzer($config['analyzers']['magento']['observers'] ?? [], $this->logger),
            'magento-backend',
            'Analyzes event observers and event patterns'
        );

        $this->registerAnalyzer(
            'magento-database',
            new DatabaseAnalyzer($config['analyzers']['magento']['database'] ?? [], $this->logger),
            'magento-backend',
            'Analyzes database schemas, queries, and data operations'
        );

        $this->registerAnalyzer(
            'magento-performance',
            new MagentoPerformanceAnalyzer($config['analyzers']['magento']['performance'] ?? [], $this->logger),
            'magento-backend',
            'Analyzes backend performance patterns and optimizations'
        );

        $this->registerAnalyzer(
            'magento-security',
            new MagentoSecurityAnalyzer($config['analyzers']['magento']['security'] ?? [], $this->logger),
            'magento-backend',
            'Analyzes security vulnerabilities and best practices'
        );

        $this->registerAnalyzer(
            'magento-upgrade',
            new UpgradeCompatibilityAnalyzer($config['analyzers']['magento']['upgrade'] ?? [], $this->logger),
            'magento-backend',
            'Analyzes upgrade compatibility and deprecated APIs'
        );

        $this->registerAnalyzer(
            'magento-specific',
            new MagentoSpecificAnalyzer(),
            'magento-backend',
            'Comprehensive analysis of Magento 2 specific patterns, architecture, and best practices'
        );

        // Frontend Analyzers
        $this->registerAnalyzer(
            'react',
            new ReactAnalyzer($config['analyzers']['frontend']['react'] ?? [], $this->logger),
            'frontend',
            'Analyzes React components and JSX patterns'
        );

        $this->registerAnalyzer(
            'pwa',
            new PWAAnalyzer($config['analyzers']['frontend']['pwa'] ?? [], $this->logger),
            'frontend',
            'Analyzes Progressive Web App compliance and features'
        );

        $this->registerAnalyzer(
            'seo',
            new SEOAnalyzer($config['analyzers']['frontend']['seo'] ?? [], $this->logger),
            'frontend',
            'Analyzes SEO optimization and meta tag compliance'
        );

        $this->registerAnalyzer(
            'frontend-performance',
            new FrontendPerformanceAnalyzer($config['analyzers']['frontend']['performance'] ?? [], $this->logger),
            'frontend',
            'Analyzes frontend performance and bundle optimization'
        );

        $this->registerAnalyzer(
            'accessibility',
            new AccessibilityAnalyzer($config['analyzers']['frontend']['accessibility'] ?? [], $this->logger),
            'frontend',
            'Analyzes accessibility compliance and WCAG standards'
        );

        $this->registerAnalyzer(
            'frontend-security',
            new FrontendSecurityAnalyzer($config['analyzers']['frontend']['security'] ?? [], $this->logger),
            'frontend',
            'Analyzes frontend security patterns and vulnerabilities'
        );

        // PHP Quality Analyzers (optional external tools)
        if ($config['analyzers']['php']['phpcs']['enabled'] ?? false) {
            $this->registerAnalyzer(
                'phpcs',
                new PHPCSAnalyzer($config['analyzers']['php']['phpcs'] ?? [], $this->logger),
                'php-quality',
                'PHP CodeSniffer for coding standards compliance'
            );
        }

        if ($config['analyzers']['php']['phpmd']['enabled'] ?? false) {
            $this->registerAnalyzer(
                'phpmd',
                new PHPMDAnalyzer($config['analyzers']['php']['phpmd'] ?? [], $this->logger),
                'php-quality',
                'PHP Mess Detector for code quality metrics'
            );
        }

        if ($config['analyzers']['php']['phpstan']['enabled'] ?? false) {
            $this->registerAnalyzer(
                'phpstan',
                new PHPStanAnalyzer($config['analyzers']['php']['phpstan'] ?? [], $this->logger),
                'php-quality',
                'PHPStan for static analysis and type checking'
            );
        }

        if ($config['analyzers']['php']['phpcpd']['enabled'] ?? false) {
            $this->registerAnalyzer(
                'phpcpd',
                new PHPCPDAnalyzer($config['analyzers']['php']['phpcpd'] ?? [], $this->logger),
                'php-quality',
                'PHP Copy/Paste Detector for code duplication'
            );
        }

        if ($config['analyzers']['php']['sonar']['enabled'] ?? false) {
            $this->registerAnalyzer(
                'sonar',
                new SonarAnalyzer($config['analyzers']['php']['sonar'] ?? [], $this->logger),
                'php-quality',
                'SonarQube integration for comprehensive code analysis'
            );
        }

        // App Builder / Node.js — vendored abqc engine (ESLint-backed). Available
        // whenever `node` is resolvable (AbstractExternalToolAnalyzer::isAvailable),
        // same optional-external-tool contract as the PHP analyzers above.
        $this->registerAnalyzer(
            'appbuilder-abqc',
            new AbqcAnalyzer(),
            'appbuilder',
            'abqc — ESLint-backed App Builder / Node.js code-quality checks (mechanical + security rules)'
        );
    }

    /**
     * Register an analyzer with metadata
     */
    private function registerAnalyzer(string $id, object $analyzer, string $category, string $description): void
    {
        $this->analyzers[$id] = [
            'analyzer' => $analyzer,
            'category' => $category,
            'description' => $description,
            'available' => method_exists($analyzer, 'isAvailable') ? $analyzer->isAvailable() : true,
        ];

        if (!isset($this->categories[$category])) {
            $this->categories[$category] = [];
        }
        $this->categories[$category][] = $id;
    }

    /**
     * Get analyzer by ID
     */
    public function getAnalyzer(string $id): ?object
    {
        return $this->analyzers[$id]['analyzer'] ?? null;
    }

    /**
     * Get the category a registered analyzer id belongs to
     */
    public function getCategoryOf(string $id): ?string
    {
        return $this->analyzers[$id]['category'] ?? null;
    }

    /**
     * Get analyzers by category
     */
    public function getAnalyzersByCategory(string $category): array
    {
        $analyzers = [];
        foreach ($this->categories[$category] ?? [] as $id) {
            if ($this->analyzers[$id]['available']) {
                $analyzers[$id] = $this->analyzers[$id]['analyzer'];
            }
        }
        return $analyzers;
    }

    /**
     * Get analyzers by IDs
     */
    public function getAnalyzersById(array $ids): array
    {
        $analyzers = [];
        foreach ($ids as $id) {
            if (isset($this->analyzers[$id]) && $this->analyzers[$id]['available']) {
                $analyzers[$id] = $this->analyzers[$id]['analyzer'];
            }
        }
        return $analyzers;
    }

    /**
     * Get all available analyzers
     */
    public function getAllAnalyzers(): array
    {
        $analyzers = [];
        foreach ($this->analyzers as $id => $data) {
            if ($data['available']) {
                $analyzers[$id] = $data['analyzer'];
            }
        }
        return $analyzers;
    }

    /**
     * Get analyzers by use case
     */
    public function getAnalyzersByUseCase(string $useCase): array
    {
        $useCases = [
            'magento-full' => [
                'magento-structure', 'magento-di', 'magento-plugins', 'magento-observers',
                'magento-database', 'magento-performance', 'magento-security', 'magento-upgrade'
            ],
            'magento-basic' => [
                'magento-structure', 'magento-di', 'magento-security'
            ],
            'magento-performance' => [
                'magento-performance', 'magento-database', 'magento-di'
            ],
            'magento-security' => [
                'magento-security', 'magento-di', 'magento-upgrade'
            ],
            'pwa-full' => [
                'react', 'pwa', 'seo', 'frontend-performance', 'accessibility', 'frontend-security'
            ],
            'pwa-basic' => [
                'react', 'pwa', 'frontend-security'
            ],
            'pwa-performance' => [
                'frontend-performance', 'pwa', 'seo'
            ],
            'frontend-accessibility' => [
                'accessibility', 'seo', 'react'
            ],
            'complete-audit' => array_keys($this->analyzers),
            'quick-scan' => [
                'magento-structure', 'magento-security', 'frontend-security', 'phpcs'
            ],
            'pre-deployment' => [
                'magento-security', 'frontend-security', 'magento-performance', 
                'frontend-performance', 'phpstan'
            ],
            'code-review' => [
                'magento-di', 'magento-plugins', 'react', 'phpcs', 'phpmd'
            ]
        ];

        $analyzerIds = $useCases[$useCase] ?? [];
        return $this->getAnalyzersById($analyzerIds);
    }

    /**
     * Get available categories
     */
    public function getCategories(): array
    {
        return array_keys($this->categories);
    }

    /**
     * Get analyzer metadata
     */
    public function getAnalyzerInfo(): array
    {
        $info = [];
        foreach ($this->analyzers as $id => $data) {
            $info[$id] = [
                'name' => $data['analyzer']->getName() ?? $id,
                'version' => method_exists($data['analyzer'], 'getVersion') ? $data['analyzer']->getVersion() : '1.0.0',
                'category' => $data['category'],
                'description' => $data['description'],
                'available' => $data['available'],
            ];
        }
        return $info;
    }

    /**
     * Get available use cases
     */
    public function getUseCases(): array
    {
        return [
            'magento-full' => 'Complete Magento backend analysis',
            'magento-basic' => 'Essential Magento checks (structure, DI, security)',
            'magento-performance' => 'Magento performance optimization focus',
            'magento-security' => 'Magento security vulnerability assessment',
            'pwa-full' => 'Complete PWA and frontend analysis',
            'pwa-basic' => 'Essential PWA checks (React, PWA, security)',
            'pwa-performance' => 'Frontend performance optimization focus',
            'frontend-accessibility' => 'Accessibility and inclusive design focus',
            'complete-audit' => 'Full comprehensive analysis (all analyzers)',
            'quick-scan' => 'Fast essential checks for CI/CD',
            'pre-deployment' => 'Pre-production security and performance checks',
            'code-review' => 'Code review focused analysis'
        ];
    }

    /**
     * Run analyzers on files
     */
    public function runAnalyzers(array $analyzerIds, array $files, array $options = []): array
    {
        $analyzers = $this->getAnalyzersById($analyzerIds);
        $results = [
            'analyzers_run' => array_keys($analyzers),
            'files_processed' => count($files),
            'violations' => [],
            'metrics' => [],
            'summary' => []
        ];

        foreach ($analyzers as $id => $analyzer) {
            $startTime = microtime(true);
            
            try {
                $violations = $analyzer->analyze($files, $options);
                $results['violations'][$id] = $violations;
                $results['summary'][$id] = [
                    'analyzer' => $id,
                    'violations' => count($violations),
                    'execution_time' => microtime(true) - $startTime
                ];
                
                $this->logger->info("Analyzer '{$id}' completed", [
                    'violations' => count($violations),
                    'execution_time' => microtime(true) - $startTime
                ]);
                
            } catch (\Exception $e) {
                $this->logger->error("Analyzer '{$id}' failed", [
                    'error' => $e->getMessage(),
                    'execution_time' => microtime(true) - $startTime
                ]);
                
                $results['summary'][$id] = [
                    'analyzer' => $id,
                    'violations' => 0,
                    'error' => $e->getMessage(),
                    'execution_time' => microtime(true) - $startTime
                ];
            }
        }

        return $results;
    }
} 
<?php

declare(strict_types=1);

namespace ACQC\Analyzers\Frontend;

use ACQC\Analyzers\AbstractAnalyzer;
use ACQC\Analyzers\AnalyzerException;
use ACQC\Core\Rules\Violation;

class PWAAnalyzer extends AbstractAnalyzer
{
    private array $manifestData = [];
    private array $serviceWorkerFeatures = [];
    
    public function getName(): string
    {
        return 'pwa-analyzer';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    protected function getDefaultConfiguration(): array
    {
        return [
            'check_manifest' => true,
            'check_service_worker' => true,
            'check_offline_capability' => true,
            'check_caching_strategy' => true,
            'check_app_shell' => true,
            'check_lighthouse_requirements' => true,
            'check_installability' => true,
            'check_pwa_studio_compliance' => true,
            'severity_manifest_issues' => Violation::SEVERITY_ERROR,
            'severity_service_worker_issues' => Violation::SEVERITY_ERROR,
            'severity_performance_issues' => Violation::SEVERITY_WARNING,
            'severity_compliance_issues' => Violation::SEVERITY_WARNING,
            'required_manifest_fields' => [
                'name',
                'short_name',
                'start_url',
                'display',
                'theme_color',
                'background_color',
                'icons'
            ],
            'recommended_manifest_fields' => [
                'description',
                'lang',
                'scope',
                'orientation',
                'categories'
            ],
            'required_icon_sizes' => [
                '192x192',
                '512x512'
            ],
            'supported_file_types' => [
                'manifest' => ['.json', '.webmanifest'],
                'service_worker' => ['.js', '.ts'],
                'html' => ['.html', '.htm'],
                'config' => ['.json', '.js', '.ts']
            ],
            'pwa_studio_files' => [
                'pwa-studio.config.js',
                'webpack.config.js',
                'package.json',
                'upward.yml',
                'upward.yaml'
            ],
            'service_worker_patterns' => [
                'self.addEventListener',
                'importScripts',
                'caches.open',
                'fetch',
                'install',
                'activate',
                'message',
                'sync',
                'push',
                'notificationclick'
            ],
            'caching_strategies' => [
                'cache-first',
                'network-first',
                'cache-only',
                'network-only',
                'stale-while-revalidate'
            ],
            'exclude_paths' => [
                '*/node_modules/*',
                '*/dist/*',
                '*/build/*',
                '*/coverage/*'
            ],
        ];
    }

    public function __construct(array $configuration = [], ?\Psr\Log\LoggerInterface $logger = null)
    {
        parent::__construct($configuration, $logger);
        $this->supportedFileExtensions = ['json', 'webmanifest', 'js', 'ts', 'html', 'htm', 'yml', 'yaml'];
    }

    public function analyze(array $files, array $options = []): array
    {
        $filteredFiles = $this->filterSupportedFiles($files);
        
        if (empty($filteredFiles)) {
            return [];
        }
        
        $violations = $this->executeAnalysis($filteredFiles, $options);
        $this->lastAnalysisResults = $violations;
        
        return $violations;
    }

    protected function executeAnalysis(array $files, array $options = []): array
    {
        $this->logger->info('Starting PWA analysis');
        
        $violations = [];
        $manifestFiles = [];
        $serviceWorkerFiles = [];
        $htmlFiles = [];
        $configFiles = [];
        
        // Categorize files
        foreach ($files as $file) {
            if (!$this->shouldAnalyzeFile($file)) {
                continue;
            }
            
            if ($this->isManifestFile($file)) {
                $manifestFiles[] = $file;
            } elseif ($this->isServiceWorkerFile($file)) {
                $serviceWorkerFiles[] = $file;
            } elseif ($this->isHtmlFile($file)) {
                $htmlFiles[] = $file;
            } elseif ($this->isPWAConfigFile($file)) {
                $configFiles[] = $file;
            }
        }
        
        // Analyze manifest files
        $violations = array_merge($violations, $this->analyzeManifestFiles($manifestFiles));
        
        // Analyze service worker files
        $violations = array_merge($violations, $this->analyzeServiceWorkerFiles($serviceWorkerFiles));
        
        // Analyze HTML files for PWA features
        $violations = array_merge($violations, $this->analyzeHtmlFiles($htmlFiles));
        
        // Analyze PWA configuration
        $violations = array_merge($violations, $this->analyzePWAConfiguration($configFiles));
        
        // Check overall PWA compliance
        $violations = array_merge($violations, $this->checkPWACompliance($files));
        
        $this->logger->info('Found ' . count($violations) . ' PWA violations');
        
        return $violations;
    }

    protected function parseOutput(string $output, array $files): array
    {
        return [];
    }

    private function analyzeManifestFiles(array $manifestFiles): array
    {
        $violations = [];
        
        if (empty($manifestFiles)) {
            $violations[] = new Violation(
                'pwa-missing-manifest',
                'Missing web app manifest file (manifest.json or manifest.webmanifest)',
                '',
                0,
                0,
                $this->configuration['severity_manifest_issues'],
                Violation::TYPE_LOGIC
            );
            return $violations;
        }
        
        foreach ($manifestFiles as $manifestFile) {
            $violations = array_merge($violations, $this->analyzeManifestFile($manifestFile));
        }
        
        return $violations;
    }

    private function analyzeManifestFile(string $filePath): array
    {
        $violations = [];
        $content = file_get_contents($filePath);
        
        if (!$content) {
            $violations[] = new Violation(
                'pwa-manifest-unreadable',
                'Cannot read manifest file',
                $filePath,
                0,
                0,
                $this->configuration['severity_manifest_issues'],
                Violation::TYPE_LOGIC
            );
            return $violations;
        }
        
        $manifest = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $violations[] = new Violation(
                'pwa-manifest-invalid-json',
                'Manifest file contains invalid JSON: ' . json_last_error_msg(),
                $filePath,
                0,
                0,
                $this->configuration['severity_manifest_issues'],
                Violation::TYPE_SYNTAX
            );
            return $violations;
        }
        
        $this->manifestData = $manifest;
        
        // Check required fields
        foreach ($this->configuration['required_manifest_fields'] as $field) {
            if (!isset($manifest[$field]) || empty($manifest[$field])) {
                $violations[] = new Violation(
                    'pwa-manifest-missing-required-field',
                    "Required manifest field '{$field}' is missing or empty",
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_manifest_issues'],
                    Violation::TYPE_LOGIC
                );
            }
        }
        
        // Check recommended fields
        foreach ($this->configuration['recommended_manifest_fields'] as $field) {
            if (!isset($manifest[$field])) {
                $violations[] = new Violation(
                    'pwa-manifest-missing-recommended-field',
                    "Recommended manifest field '{$field}' is missing",
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_compliance_issues'],
                    Violation::TYPE_MAINTAINABILITY
                );
            }
        }
        
        // Validate icon requirements
        if (isset($manifest['icons']) && is_array($manifest['icons'])) {
            $violations = array_merge($violations, $this->validateManifestIcons($manifest['icons'], $filePath));
        }
        
        // Validate display mode
        if (isset($manifest['display'])) {
            $validDisplayModes = ['fullscreen', 'standalone', 'minimal-ui', 'browser'];
            if (!in_array($manifest['display'], $validDisplayModes)) {
                $violations[] = new Violation(
                    'pwa-manifest-invalid-display',
                    "Invalid display mode '{$manifest['display']}'. Valid options: " . implode(', ', $validDisplayModes),
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_manifest_issues'],
                    Violation::TYPE_LOGIC
                );
            }
        }
        
        // Validate start_url
        if (isset($manifest['start_url']) && !filter_var($manifest['start_url'], FILTER_VALIDATE_URL) && !str_starts_with($manifest['start_url'], '/')) {
            $violations[] = new Violation(
                'pwa-manifest-invalid-start-url',
                "start_url '{$manifest['start_url']}' should be a valid URL or relative path",
                $filePath,
                0,
                0,
                $this->configuration['severity_manifest_issues'],
                Violation::TYPE_LOGIC
            );
        }
        
        return $violations;
    }

    private function validateManifestIcons(array $icons, string $filePath): array
    {
        $violations = [];
        $foundSizes = [];
        
        foreach ($icons as $index => $icon) {
            if (!isset($icon['src'])) {
                $violations[] = new Violation(
                    'pwa-manifest-icon-missing-src',
                    "Icon at index {$index} is missing 'src' property",
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_manifest_issues'],
                    Violation::TYPE_LOGIC
                );
            }
            
            if (!isset($icon['sizes'])) {
                $violations[] = new Violation(
                    'pwa-manifest-icon-missing-sizes',
                    "Icon at index {$index} is missing 'sizes' property",
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_manifest_issues'],
                    Violation::TYPE_LOGIC
                );
            } else {
                $foundSizes[] = $icon['sizes'];
            }
            
            if (!isset($icon['type'])) {
                $violations[] = new Violation(
                    'pwa-manifest-icon-missing-type',
                    "Icon at index {$index} should specify 'type' property",
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_compliance_issues'],
                    Violation::TYPE_MAINTAINABILITY
                );
            }
        }
        
        // Check for required icon sizes
        foreach ($this->configuration['required_icon_sizes'] as $requiredSize) {
            if (!in_array($requiredSize, $foundSizes)) {
                $violations[] = new Violation(
                    'pwa-manifest-missing-required-icon-size',
                    "Missing required icon size: {$requiredSize}",
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_manifest_issues'],
                    Violation::TYPE_LOGIC
                );
            }
        }
        
        return $violations;
    }

    private function analyzeServiceWorkerFiles(array $serviceWorkerFiles): array
    {
        $violations = [];
        
        if (empty($serviceWorkerFiles)) {
            $violations[] = new Violation(
                'pwa-missing-service-worker',
                'No service worker file detected. PWA requires a service worker for offline functionality.',
                '',
                0,
                0,
                $this->configuration['severity_service_worker_issues'],
                Violation::TYPE_LOGIC
            );
            return $violations;
        }
        
        foreach ($serviceWorkerFiles as $swFile) {
            $violations = array_merge($violations, $this->analyzeServiceWorkerFile($swFile));
        }
        
        return $violations;
    }

    private function analyzeServiceWorkerFile(string $filePath): array
    {
        $violations = [];
        $content = file_get_contents($filePath);
        
        if (!$content) {
            return $violations;
        }
        
        // Check for essential service worker events
        $requiredEvents = ['install', 'activate', 'fetch'];
        foreach ($requiredEvents as $event) {
            if (!preg_match("/addEventListener\s*\(\s*['\"]" . $event . "['\"]/", $content)) {
                $violations[] = new Violation(
                    'pwa-sw-missing-event-listener',
                    "Service worker should handle '{$event}' event",
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_service_worker_issues'],
                    Violation::TYPE_LOGIC
                );
            }
        }
        
        // Check for caching implementation
        if (!str_contains($content, 'caches.open')) {
            $violations[] = new Violation(
                'pwa-sw-no-caching',
                'Service worker should implement caching for offline functionality',
                $filePath,
                0,
                0,
                $this->configuration['severity_service_worker_issues'],
                Violation::TYPE_LOGIC
            );
        }
        
        // Check for proper error handling in fetch events
        if (str_contains($content, 'fetch') && !str_contains($content, 'catch')) {
            $violations[] = new Violation(
                'pwa-sw-missing-error-handling',
                'Service worker fetch events should include error handling',
                $filePath,
                0,
                0,
                $this->configuration['severity_service_worker_issues'],
                Violation::TYPE_LOGIC
            );
        }
        
        // Check for cache strategy patterns
        $foundStrategy = false;
        foreach ($this->configuration['caching_strategies'] as $strategy) {
            if (str_contains($content, str_replace('-', '', $strategy))) {
                $foundStrategy = true;
                break;
            }
        }
        
        if (!$foundStrategy) {
            $violations[] = new Violation(
                'pwa-sw-no-caching-strategy',
                'Service worker should implement a clear caching strategy (cache-first, network-first, etc.)',
                $filePath,
                0,
                0,
                $this->configuration['severity_performance_issues'],
                Violation::TYPE_PERFORMANCE
            );
        }
        
        // Check for skipWaiting and claim patterns
        if (str_contains($content, 'install') && !str_contains($content, 'skipWaiting')) {
            $violations[] = new Violation(
                'pwa-sw-missing-skip-waiting',
                'Service worker install event should call skipWaiting() for immediate activation',
                $filePath,
                0,
                0,
                $this->configuration['severity_compliance_issues'],
                Violation::TYPE_LOGIC
            );
        }
        
        if (str_contains($content, 'activate') && !str_contains($content, 'claim')) {
            $violations[] = new Violation(
                'pwa-sw-missing-clients-claim',
                'Service worker activate event should call clients.claim() to control existing pages',
                $filePath,
                0,
                0,
                $this->configuration['severity_compliance_issues'],
                Violation::TYPE_LOGIC
            );
        }
        
        return $violations;
    }

    private function analyzeHtmlFiles(array $htmlFiles): array
    {
        $violations = [];
        
        foreach ($htmlFiles as $htmlFile) {
            $violations = array_merge($violations, $this->analyzeHtmlFile($htmlFile));
        }
        
        return $violations;
    }

    private function analyzeHtmlFile(string $filePath): array
    {
        $violations = [];
        $content = file_get_contents($filePath);
        
        if (!$content) {
            return $violations;
        }
        
        // Check for manifest link
        if (!preg_match('/<link[^>]+rel\s*=\s*["\']manifest["\'][^>]*>/i', $content)) {
            $violations[] = new Violation(
                'pwa-html-missing-manifest-link',
                'HTML should include a link to the web app manifest',
                $filePath,
                0,
                0,
                $this->configuration['severity_manifest_issues'],
                Violation::TYPE_LOGIC
            );
        }
        
        // Check for service worker registration
        if (!str_contains($content, 'serviceWorker') || !str_contains($content, 'register')) {
            $violations[] = new Violation(
                'pwa-html-missing-sw-registration',
                'HTML should include service worker registration script',
                $filePath,
                0,
                0,
                $this->configuration['severity_service_worker_issues'],
                Violation::TYPE_LOGIC
            );
        }
        
        // Check for theme-color meta tag
        if (!preg_match('/<meta[^>]+name\s*=\s*["\']theme-color["\'][^>]*>/i', $content)) {
            $violations[] = new Violation(
                'pwa-html-missing-theme-color',
                'HTML should include theme-color meta tag for better app appearance',
                $filePath,
                0,
                0,
                $this->configuration['severity_compliance_issues'],
                Violation::TYPE_MAINTAINABILITY
            );
        }
        
        // Check for viewport meta tag
        if (!preg_match('/<meta[^>]+name\s*=\s*["\']viewport["\'][^>]*>/i', $content)) {
            $violations[] = new Violation(
                'pwa-html-missing-viewport',
                'HTML should include proper viewport meta tag for responsive design',
                $filePath,
                0,
                0,
                $this->configuration['severity_compliance_issues'],
                Violation::TYPE_LOGIC
            );
        }
        
        // Check for apple-touch-icon (iOS PWA support)
        if (!preg_match('/<link[^>]+rel\s*=\s*["\']apple-touch-icon["\'][^>]*>/i', $content)) {
            $violations[] = new Violation(
                'pwa-html-missing-apple-touch-icon',
                'HTML should include apple-touch-icon for iOS PWA support',
                $filePath,
                0,
                0,
                $this->configuration['severity_compliance_issues'],
                Violation::TYPE_MAINTAINABILITY
            );
        }
        
        return $violations;
    }

    private function analyzePWAConfiguration(array $configFiles): array
    {
        $violations = [];
        
        foreach ($configFiles as $configFile) {
            if (basename($configFile) === 'package.json') {
                $violations = array_merge($violations, $this->analyzePackageJson($configFile));
            } elseif (str_contains($configFile, 'pwa-studio')) {
                $violations = array_merge($violations, $this->analyzePWAStudioConfig($configFile));
            }
        }
        
        return $violations;
    }

    private function analyzePackageJson(string $filePath): array
    {
        $violations = [];
        $content = file_get_contents($filePath);
        
        if (!$content) {
            return $violations;
        }
        
        $package = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $violations;
        }
        
        // Check for PWA-related dependencies
        $pwaPackages = [
            'workbox-webpack-plugin',
            'workbox-sw',
            '@magento/pwa-buildpack',
            'webpack-pwa-manifest'
        ];
        
        $dependencies = array_merge(
            $package['dependencies'] ?? [],
            $package['devDependencies'] ?? []
        );
        
        $foundPWAPackage = false;
        foreach ($pwaPackages as $pwaPackage) {
            if (isset($dependencies[$pwaPackage])) {
                $foundPWAPackage = true;
                break;
            }
        }
        
        if (!$foundPWAPackage) {
            $violations[] = new Violation(
                'pwa-package-missing-pwa-tools',
                'package.json should include PWA tooling (workbox, pwa-buildpack, etc.)',
                $filePath,
                0,
                0,
                $this->configuration['severity_compliance_issues'],
                Violation::TYPE_MAINTAINABILITY
            );
        }
        
        return $violations;
    }

    private function analyzePWAStudioConfig(string $filePath): array
    {
        $violations = [];
        $content = file_get_contents($filePath);
        
        if (!$content) {
            return $violations;
        }
        
        // Check for proper PWA Studio configuration
        if (!str_contains($content, 'manifestConfig') && str_contains($filePath, 'pwa-studio')) {
            $violations[] = new Violation(
                'pwa-studio-missing-manifest-config',
                'PWA Studio configuration should include manifestConfig',
                $filePath,
                0,
                0,
                $this->configuration['severity_compliance_issues'],
                Violation::TYPE_LOGIC
            );
        }
        
        if (!str_contains($content, 'serviceWorkerConfig') && str_contains($filePath, 'pwa-studio')) {
            $violations[] = new Violation(
                'pwa-studio-missing-sw-config',
                'PWA Studio configuration should include serviceWorkerConfig',
                $filePath,
                0,
                0,
                $this->configuration['severity_compliance_issues'],
                Violation::TYPE_LOGIC
            );
        }
        
        return $violations;
    }

    private function checkPWACompliance(array $files): array
    {
        $violations = [];
        
        // Overall PWA readiness check
        $hasManifest = false;
        $hasServiceWorker = false;
        $hasHTTPS = false;
        
        foreach ($files as $file) {
            if ($this->isManifestFile($file)) {
                $hasManifest = true;
            }
            if ($this->isServiceWorkerFile($file)) {
                $hasServiceWorker = true;
            }
        }
        
        if (!$hasManifest || !$hasServiceWorker) {
            $violations[] = new Violation(
                'pwa-incomplete-requirements',
                'PWA requires both a web app manifest and service worker for compliance',
                '',
                0,
                0,
                $this->configuration['severity_compliance_issues'],
                Violation::TYPE_LOGIC
            );
        }
        
        return $violations;
    }

    private function isManifestFile(string $filePath): bool
    {
        $fileName = basename($filePath);
        return $fileName === 'manifest.json' || 
               $fileName === 'manifest.webmanifest' ||
               str_ends_with($fileName, '.webmanifest');
    }

    private function isServiceWorkerFile(string $filePath): bool
    {
        $fileName = basename($filePath);
        return str_contains($fileName, 'service-worker') || 
               str_contains($fileName, 'sw.') ||
               $fileName === 'sw.js' ||
               $fileName === 'worker.js';
    }

    private function isHtmlFile(string $filePath): bool
    {
        return str_ends_with($filePath, '.html') || str_ends_with($filePath, '.htm');
    }

    private function isPWAConfigFile(string $filePath): bool
    {
        $fileName = basename($filePath);
        return in_array($fileName, $this->configuration['pwa_studio_files']);
    }

    private function shouldAnalyzeFile(string $filePath): bool
    {
        foreach ($this->configuration['exclude_paths'] as $excludePath) {
            if (fnmatch($excludePath, $filePath)) {
                return false;
            }
        }
        
        return true;
    }
} 
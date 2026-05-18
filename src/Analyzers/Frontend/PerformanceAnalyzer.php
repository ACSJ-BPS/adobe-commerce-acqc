<?php

declare(strict_types=1);

namespace ACQC\Analyzers\Frontend;

use ACQC\Analyzers\AbstractAnalyzer;
use ACQC\Analyzers\AnalyzerException;
use ACQC\Core\Rules\Violation;

class PerformanceAnalyzer extends AbstractAnalyzer
{
    private array $bundleStats = [];
    private array $resourceHints = [];
    
    public function getName(): string
    {
        return 'frontend-performance-analyzer';
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
            'check_bundle_size' => true,
            'check_resource_hints' => true,
            'check_critical_resources' => true,
            'check_lazy_loading' => true,
            'check_code_splitting' => true,
            'check_compression' => true,
            'check_core_web_vitals' => true,
            'check_render_blocking' => true,
            'severity_critical_performance' => Violation::SEVERITY_ERROR,
            'severity_performance_warning' => Violation::SEVERITY_WARNING,
            'severity_performance_info' => Violation::SEVERITY_INFO,
            'max_bundle_size_kb' => 250,
            'max_chunk_size_kb' => 100,
            'max_css_size_kb' => 50,
            'max_js_initial_kb' => 150,
            'max_image_size_kb' => 500,
            'max_critical_resources' => 10,
            'supported_file_types' => [
                'html' => ['.html', '.htm', '.phtml'],
                'javascript' => ['.js', '.jsx', '.ts', '.tsx'],
                'css' => ['.css', '.scss', '.sass', '.less'],
                'config' => ['.json', '.js'],
                'assets' => ['.jpg', '.jpeg', '.png', '.gif', '.svg', '.webp', '.avif']
            ],
            'critical_resources' => [
                'fonts',
                'above-the-fold CSS',
                'hero images',
                'critical JavaScript'
            ],
            'performance_patterns' => [
                'code_splitting' => [
                    'import(',
                    'React.lazy',
                    'dynamic import',
                    'lazy loading'
                ],
                'resource_hints' => [
                    'preload',
                    'prefetch',
                    'preconnect',
                    'dns-prefetch'
                ],
                'optimization' => [
                    'webpack optimization',
                    'tree shaking',
                    'dead code elimination',
                    'minification'
                ]
            ],
            'webpack_config_files' => [
                'webpack.config.js',
                'webpack.prod.js',
                'webpack.dev.js',
                'next.config.js',
                'vite.config.js'
            ],
            'lighthouse_thresholds' => [
                'first_contentful_paint' => 1800, // ms
                'largest_contentful_paint' => 2500, // ms
                'cumulative_layout_shift' => 0.1,
                'first_input_delay' => 100, // ms
                'time_to_interactive' => 3800 // ms
            ],
            'exclude_paths' => [
                '*/node_modules/*',
                '*/vendor/*',
                '*/dist/*',
                '*/build/*',
                '*/test/*',
                '*/tests/*'
            ],
        ];
    }


    public function __construct(array $configuration = [], ?\Psr\Log\LoggerInterface $logger = null)
    {
        parent::__construct($configuration, $logger);
        $this->supportedFileExtensions = ['html', 'htm', 'js', 'jsx', 'ts', 'tsx', 'css', 'scss', 'sass', 'json'];
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
        $this->logger->info('Starting frontend performance analysis');
        
        $violations = [];
        
        // Categorize files by type
        $categorizedFiles = $this->categorizeFiles($files);
        
        // Analyze HTML files for performance issues
        $violations = array_merge($violations, $this->analyzeHtmlPerformance($categorizedFiles['html'] ?? []));
        
        // Analyze JavaScript performance
        $violations = array_merge($violations, $this->analyzeJavaScriptPerformance($categorizedFiles['javascript'] ?? []));
        
        // Analyze CSS performance
        $violations = array_merge($violations, $this->analyzeCSSPerformance($categorizedFiles['css'] ?? []));
        
        // Analyze bundle configuration
        $violations = array_merge($violations, $this->analyzeBundleConfiguration($categorizedFiles['config'] ?? []));
        
        // Analyze asset optimization
        $violations = array_merge($violations, $this->analyzeAssetOptimization($categorizedFiles['assets'] ?? []));
        
        // Check for webpack/build optimization
        $violations = array_merge($violations, $this->analyzeWebpackConfiguration($files));
        
        $this->logger->info('Found ' . count($violations) . ' frontend performance violations');
        
        return $violations;
    }

    protected function parseOutput(string $output, array $files): array
    {
        return [];
    }

    private function categorizeFiles(array $files): array
    {
        $categorized = [];
        
        foreach ($files as $file) {
            if (!$this->shouldAnalyzeFile($file)) {
                continue;
            }
            
            $extension = $this->getFileExtension($file);
            
            foreach ($this->configuration['supported_file_types'] as $type => $extensions) {
                if (in_array($extension, $extensions)) {
                    $categorized[$type][] = $file;
                    break;
                }
            }
        }
        
        return $categorized;
    }

    private function analyzeHtmlPerformance(array $htmlFiles): array
    {
        $violations = [];
        
        foreach ($htmlFiles as $file) {
            $violations = array_merge($violations, $this->analyzeHtmlFile($file));
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
        
        $dom = $this->createDOMDocument($content);
        if (!$dom) {
            return $violations;
        }
        
        $xpath = new \DOMXPath($dom);
        
        // Check for render-blocking resources
        $violations = array_merge($violations, $this->checkRenderBlockingResources($xpath, $filePath));
        
        // Check for resource hints
        $violations = array_merge($violations, $this->checkResourceHints($xpath, $filePath));
        
        // Check for critical resource prioritization
        $violations = array_merge($violations, $this->checkCriticalResourcePrioritization($xpath, $filePath));
        
        // Check for lazy loading implementation
        $violations = array_merge($violations, $this->checkLazyLoading($xpath, $filePath));
        
        // Check for font optimization
        $violations = array_merge($violations, $this->checkFontOptimization($xpath, $filePath));
        
        return $violations;
    }

    private function checkRenderBlockingResources(\DOMXPath $xpath, string $filePath): array
    {
        $violations = [];
        
        // Check for blocking CSS
        $cssLinks = $xpath->query('//link[@rel="stylesheet"]');
        $blockingCssCount = 0;
        
        foreach ($cssLinks as $link) {
            $media = $link->getAttribute('media');
            if (empty($media) || $media === 'all' || $media === 'screen') {
                $blockingCssCount++;
                $href = $link->getAttribute('href');
                
                if ($blockingCssCount > 2) {
                    $violations[] = new Violation(
                        'performance-too-many-blocking-css',
                        "Too many render-blocking CSS files. Consider inlining critical CSS: {$href}",
                        $filePath,
                        0,
                        0,
                        $this->configuration['severity_performance_warning'],
                        Violation::TYPE_PERFORMANCE
                    );
                }
            }
        }
        
        // Check for blocking JavaScript in head
        $headScripts = $xpath->query('//head//script[@src]');
        foreach ($headScripts as $script) {
            if (!$script->hasAttribute('defer') && !$script->hasAttribute('async')) {
                $src = $script->getAttribute('src');
                $violations[] = new Violation(
                    'performance-blocking-javascript-head',
                    "Render-blocking JavaScript in head. Consider adding defer or async: {$src}",
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_performance_warning'],
                    Violation::TYPE_PERFORMANCE
                );
            }
        }
        
        return $violations;
    }

    private function checkResourceHints(\DOMXPath $xpath, string $filePath): array
    {
        $violations = [];
        
        // Check for missing preconnect to external domains
        $externalResources = $xpath->query('//link[@href[starts-with(., "http")]] | //script[@src[starts-with(., "http")]]');
        $externalDomains = [];
        
        foreach ($externalResources as $resource) {
            $url = $resource->getAttribute('href') ?: $resource->getAttribute('src');
            $domain = parse_url($url, PHP_URL_HOST);
            if ($domain && !in_array($domain, $externalDomains)) {
                $externalDomains[] = $domain;
            }
        }
        
        $preconnects = $xpath->query('//link[@rel="preconnect"]');
        $preconnectedDomains = [];
        foreach ($preconnects as $preconnect) {
            $href = $preconnect->getAttribute('href');
            $domain = parse_url($href, PHP_URL_HOST);
            if ($domain) {
                $preconnectedDomains[] = $domain;
            }
        }
        
        foreach ($externalDomains as $domain) {
            if (!in_array($domain, $preconnectedDomains)) {
                $violations[] = new Violation(
                    'performance-missing-preconnect',
                    "Consider adding preconnect for external domain: {$domain}",
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_performance_info'],
                    Violation::TYPE_PERFORMANCE
                );
            }
        }
        
        // Check for missing preload for critical resources
        $criticalFonts = $xpath->query('//link[@href[contains(., "font")]]');
        $preloadedFonts = $xpath->query('//link[@rel="preload"][@as="font"]');
        
        if ($criticalFonts->length > 0 && $preloadedFonts->length === 0) {
            $violations[] = new Violation(
                'performance-missing-font-preload',
                'Consider preloading critical fonts for better performance',
                $filePath,
                0,
                0,
                $this->configuration['severity_performance_info'],
                Violation::TYPE_PERFORMANCE
            );
        }
        
        return $violations;
    }

    private function checkCriticalResourcePrioritization(\DOMXPath $xpath, string $filePath): array
    {
        $violations = [];
        
        // Count total resources
        $totalResources = $xpath->query('//link | //script[@src] | //img')->length;
        
        if ($totalResources > $this->configuration['max_critical_resources']) {
            $violations[] = new Violation(
                'performance-too-many-critical-resources',
                "Too many resources ({$totalResources}) may impact initial load. Consider lazy loading non-critical resources.",
                $filePath,
                0,
                0,
                $this->configuration['severity_performance_warning'],
                Violation::TYPE_PERFORMANCE
            );
        }
        
        // Check for missing fetchpriority on LCP elements
        $heroImages = $xpath->query('//img[position()<=3]'); // First few images likely above fold
        foreach ($heroImages as $image) {
            if (!$image->hasAttribute('fetchpriority')) {
                $src = $image->getAttribute('src');
                $violations[] = new Violation(
                    'performance-missing-fetchpriority',
                    "Consider adding fetchpriority=\"high\" to above-the-fold image: {$src}",
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_performance_info'],
                    Violation::TYPE_PERFORMANCE
                );
            }
        }
        
        return $violations;
    }

    private function checkLazyLoading(\DOMXPath $xpath, string $filePath): array
    {
        $violations = [];
        
        // Check images for lazy loading
        $images = $xpath->query('//img[@src]');
        $lazyImages = 0;
        $totalImages = $images->length;
        
        foreach ($images as $index => $image) {
            $loading = $image->getAttribute('loading');
            
            // Skip first few images (likely above the fold)
            if ($index < 3) {
                if ($loading === 'lazy') {
                    $src = $image->getAttribute('src');
                    $violations[] = new Violation(
                        'performance-lazy-loading-above-fold',
                        "Above-the-fold image should not be lazy loaded: {$src}",
                        $filePath,
                        0,
                        0,
                        $this->configuration['severity_performance_warning'],
                        Violation::TYPE_PERFORMANCE
                    );
                }
            } else {
                if ($loading !== 'lazy') {
                    $src = $image->getAttribute('src');
                    $violations[] = new Violation(
                        'performance-missing-lazy-loading',
                        "Consider adding loading=\"lazy\" to below-the-fold image: {$src}",
                        $filePath,
                        0,
                        0,
                        $this->configuration['severity_performance_info'],
                        Violation::TYPE_PERFORMANCE
                    );
                } else {
                    $lazyImages++;
                }
            }
        }
        
        // Check iframes for lazy loading
        $iframes = $xpath->query('//iframe[@src]');
        foreach ($iframes as $iframe) {
            if (!$iframe->hasAttribute('loading')) {
                $src = $iframe->getAttribute('src');
                $violations[] = new Violation(
                    'performance-iframe-missing-lazy-loading',
                    "Consider adding loading=\"lazy\" to iframe: {$src}",
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_performance_info'],
                    Violation::TYPE_PERFORMANCE
                );
            }
        }
        
        return $violations;
    }

    private function checkFontOptimization(\DOMXPath $xpath, string $filePath): array
    {
        $violations = [];
        
        // Check for font-display optimization
        $fontFaces = $xpath->query('//style[contains(text(), "@font-face")]');
        foreach ($fontFaces as $style) {
            $content = $style->textContent;
            if (str_contains($content, '@font-face') && !str_contains($content, 'font-display')) {
                $violations[] = new Violation(
                    'performance-missing-font-display',
                    'Consider adding font-display: swap to @font-face rules for better performance',
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_performance_info'],
                    Violation::TYPE_PERFORMANCE
                );
            }
        }
        
        // Check for excessive font variants
        $fontLinks = $xpath->query('//link[@href[contains(., "fonts.googleapis.com")]]');
        foreach ($fontLinks as $link) {
            $href = $link->getAttribute('href');
            $weightCount = substr_count($href, ':') + substr_count($href, ';');
            
            if ($weightCount > 4) {
                $violations[] = new Violation(
                    'performance-too-many-font-variants',
                    "Too many font variants may impact performance: {$href}",
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_performance_warning'],
                    Violation::TYPE_PERFORMANCE
                );
            }
        }
        
        return $violations;
    }

    private function analyzeJavaScriptPerformance(array $jsFiles): array
    {
        $violations = [];
        
        foreach ($jsFiles as $file) {
            $violations = array_merge($violations, $this->analyzeJavaScriptFile($file));
        }
        
        return $violations;
    }

    private function analyzeJavaScriptFile(string $filePath): array
    {
        $violations = [];
        $content = file_get_contents($filePath);
        
        if (!$content) {
            return $violations;
        }
        
        // Check file size
        $fileSize = strlen($content) / 1024; // KB
        if ($fileSize > $this->configuration['max_js_initial_kb']) {
            $violations[] = new Violation(
                'performance-large-javascript-file',
                "JavaScript file size ({$fileSize}KB) exceeds recommended maximum of {$this->configuration['max_js_initial_kb']}KB",
                $filePath,
                0,
                0,
                $this->configuration['severity_performance_warning'],
                Violation::TYPE_PERFORMANCE
            );
        }
        
        // Check for code splitting patterns
        if (!str_contains($content, 'import(') && !str_contains($content, 'React.lazy') && $fileSize > 50) {
            $violations[] = new Violation(
                'performance-missing-code-splitting',
                'Large JavaScript file should consider code splitting for better performance',
                $filePath,
                0,
                0,
                $this->configuration['severity_performance_info'],
                Violation::TYPE_PERFORMANCE
            );
        }
        
        // Check for performance anti-patterns
        if (str_contains($content, 'document.write')) {
            $violations[] = new Violation(
                'performance-document-write',
                'document.write() can block HTML parsing and hurt performance',
                $filePath,
                0,
                0,
                $this->configuration['severity_performance_warning'],
                Violation::TYPE_PERFORMANCE
            );
        }
        
        // Check for synchronous XMLHttpRequest
        if (preg_match('/XMLHttpRequest.*open.*false/s', $content)) {
            $violations[] = new Violation(
                'performance-synchronous-xhr',
                'Synchronous XMLHttpRequest can block the main thread',
                $filePath,
                0,
                0,
                $this->configuration['severity_performance_warning'],
                Violation::TYPE_PERFORMANCE
            );
        }
        
        // Check for inefficient DOM queries
        if (preg_match_all('/document\.querySelector|document\.getElementById/', $content) > 10) {
            $violations[] = new Violation(
                'performance-excessive-dom-queries',
                'Consider caching DOM references instead of repeated queries',
                $filePath,
                0,
                0,
                $this->configuration['severity_performance_info'],
                Violation::TYPE_PERFORMANCE
            );
        }
        
        return $violations;
    }

    private function analyzeCSSPerformance(array $cssFiles): array
    {
        $violations = [];
        
        foreach ($cssFiles as $file) {
            $violations = array_merge($violations, $this->analyzeCSSFile($file));
        }
        
        return $violations;
    }

    private function analyzeCSSFile(string $filePath): array
    {
        $violations = [];
        $content = file_get_contents($filePath);
        
        if (!$content) {
            return $violations;
        }
        
        // Check file size
        $fileSize = strlen($content) / 1024; // KB
        if ($fileSize > $this->configuration['max_css_size_kb']) {
            $violations[] = new Violation(
                'performance-large-css-file',
                "CSS file size ({$fileSize}KB) exceeds recommended maximum of {$this->configuration['max_css_size_kb']}KB",
                $filePath,
                0,
                0,
                $this->configuration['severity_performance_warning'],
                Violation::TYPE_PERFORMANCE
            );
        }
        
        // Check for unused CSS (simplified heuristic)
        $selectorCount = preg_match_all('/[.#]?[a-zA-Z][a-zA-Z0-9_-]*\s*{/', $content);
        $contentLength = strlen($content);
        
        if ($selectorCount > 0 && $contentLength / $selectorCount > 100) {
            $violations[] = new Violation(
                'performance-potentially-unused-css',
                'Large CSS file may contain unused rules. Consider purging unused CSS.',
                $filePath,
                0,
                0,
                $this->configuration['severity_performance_info'],
                Violation::TYPE_PERFORMANCE
            );
        }
        
        // Check for expensive CSS selectors
        $expensiveSelectors = [
            '/\*.*\*.*{/',      // Universal selector descendants
            '/[^{]*:[^{]*:[^{]*{/', // Multiple pseudo-selectors
            '/[^{]*\[[^=]*\$=[^{]*{/', // Attribute selectors with $=
        ];
        
        foreach ($expensiveSelectors as $pattern) {
            if (preg_match($pattern, $content)) {
                $violations[] = new Violation(
                    'performance-expensive-css-selector',
                    'Expensive CSS selector detected. Consider simplifying for better performance.',
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_performance_info'],
                    Violation::TYPE_PERFORMANCE
                );
            }
        }
        
        // Check for missing will-change optimization
        if (str_contains($content, 'transform') && !str_contains($content, 'will-change')) {
            $violations[] = new Violation(
                'performance-missing-will-change',
                'Consider adding will-change property for elements with transforms',
                $filePath,
                0,
                0,
                $this->configuration['severity_performance_info'],
                Violation::TYPE_PERFORMANCE
            );
        }
        
        return $violations;
    }

    private function analyzeBundleConfiguration(array $configFiles): array
    {
        $violations = [];
        
        foreach ($configFiles as $file) {
            if (basename($file) === 'package.json') {
                $violations = array_merge($violations, $this->analyzePackageJson($file));
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
        
        // Check for performance-related dependencies
        $performancePackages = [
            'webpack-bundle-analyzer',
            'compression-webpack-plugin',
            'terser-webpack-plugin',
            'mini-css-extract-plugin',
            'optimize-css-assets-webpack-plugin'
        ];
        
        $dependencies = array_merge(
            $package['dependencies'] ?? [],
            $package['devDependencies'] ?? []
        );
        
        $foundPerformanceTools = 0;
        foreach ($performancePackages as $perfPackage) {
            if (isset($dependencies[$perfPackage])) {
                $foundPerformanceTools++;
            }
        }
        
        if ($foundPerformanceTools < 2) {
            $violations[] = new Violation(
                'performance-missing-optimization-tools',
                'Consider adding performance optimization tools (webpack-bundle-analyzer, compression plugins, etc.)',
                $filePath,
                0,
                0,
                $this->configuration['severity_performance_info'],
                Violation::TYPE_PERFORMANCE
            );
        }
        
        return $violations;
    }

    private function analyzeAssetOptimization(array $assetFiles): array
    {
        $violations = [];
        
        foreach ($assetFiles as $file) {
            $violations = array_merge($violations, $this->analyzeAssetFile($file));
        }
        
        return $violations;
    }

    private function analyzeAssetFile(string $filePath): array
    {
        $violations = [];
        
        if (!file_exists($filePath)) {
            return $violations;
        }
        
        $fileSize = filesize($filePath) / 1024; // KB
        $extension = strtolower($this->getFileExtension($filePath));
        
        // Check image file sizes
        if (in_array($extension, ['.jpg', '.jpeg', '.png', '.gif', '.webp', '.avif'])) {
            if ($fileSize > $this->configuration['max_image_size_kb']) {
                $violations[] = new Violation(
                    'performance-large-image-file',
                    "Image file size ({$fileSize}KB) exceeds recommended maximum of {$this->configuration['max_image_size_kb']}KB",
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_performance_warning'],
                    Violation::TYPE_PERFORMANCE
                );
            }
            
            // Check for modern image formats
            if (in_array($extension, ['.jpg', '.jpeg', '.png']) && $fileSize > 50) {
                $violations[] = new Violation(
                    'performance-legacy-image-format',
                    "Consider using modern image formats (WebP, AVIF) for better compression: {$filePath}",
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_performance_info'],
                    Violation::TYPE_PERFORMANCE
                );
            }
        }
        
        return $violations;
    }

    private function analyzeWebpackConfiguration(array $files): array
    {
        $violations = [];
        
        foreach ($files as $file) {
            $fileName = basename($file);
            if (in_array($fileName, $this->configuration['webpack_config_files'])) {
                $violations = array_merge($violations, $this->analyzeWebpackConfig($file));
            }
        }
        
        return $violations;
    }

    private function analyzeWebpackConfig(string $filePath): array
    {
        $violations = [];
        $content = file_get_contents($filePath);
        
        if (!$content) {
            return $violations;
        }
        
        // Check for production optimizations
        if (!str_contains($content, 'optimization') && str_contains($filePath, 'prod')) {
            $violations[] = new Violation(
                'performance-missing-webpack-optimization',
                'Production webpack config should include optimization settings',
                $filePath,
                0,
                0,
                $this->configuration['severity_performance_warning'],
                Violation::TYPE_PERFORMANCE
            );
        }
        
        // Check for code splitting configuration
        if (!str_contains($content, 'splitChunks') && !str_contains($content, 'chunks')) {
            $violations[] = new Violation(
                'performance-missing-code-splitting-config',
                'Webpack config should include code splitting configuration',
                $filePath,
                0,
                0,
                $this->configuration['severity_performance_info'],
                Violation::TYPE_PERFORMANCE
            );
        }
        
        // Check for compression
        if (!str_contains($content, 'CompressionPlugin') && !str_contains($content, 'gzip')) {
            $violations[] = new Violation(
                'performance-missing-compression',
                'Consider adding compression plugin for better performance',
                $filePath,
                0,
                0,
                $this->configuration['severity_performance_info'],
                Violation::TYPE_PERFORMANCE
            );
        }
        
        return $violations;
    }

    private function createDOMDocument(string $content): ?\DOMDocument
    {
        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = false;
        
        if (!str_contains($content, '<html') && !str_contains($content, '<!DOCTYPE')) {
            $content = '<!DOCTYPE html><html><head></head><body>' . $content . '</body></html>';
        }
        
        $previousUseErrors = libxml_use_internal_errors(true);
        $success = @$dom->loadHTML($content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_use_internal_errors($previousUseErrors);
        
        return $success ? $dom : null;
    }

    private function getFileExtension(string $filePath): string
    {
        $pathInfo = pathinfo($filePath);
        return '.' . ($pathInfo['extension'] ?? '');
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
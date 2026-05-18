<?php

declare(strict_types=1);

namespace ACQC\Analyzers\Infrastructure;

use ACQC\Analyzers\AbstractAnalyzer;
use ACQC\Core\Infrastructure\CDNDetector;
use ACQC\Core\Infrastructure\CDNInfo;
use ACQC\Core\Rules\Violation;

/**
 * CDN configuration and optimization analyzer
 * 
 * Detects which CDN/cache layer is being used and provides
 * CDN-specific optimization recommendations.
 */
class CDNAnalyzer extends AbstractAnalyzer
{
    private CDNDetector $detector;
    
    public function __construct(array $configuration = [], ?\Psr\Log\LoggerInterface $logger = null)
    {
        parent::__construct($configuration, $logger);
        $this->detector = new CDNDetector($this->logger);
    }
    
    public function getName(): string
    {
        return 'cdn-analyzer';
    }
    
    public function getVersion(): string
    {
        return '1.0.0';
    }
    
    public function isAvailable(): bool
    {
        return true;  // Always available
    }
    
    protected function getDefaultConfiguration(): array
    {
        return [
            'recommend_cdn' => true,
            'check_fastly_config' => true,
            'check_varnish_config' => true,
            'skip_http_detection' => false,  // Can be slow, disable if needed
            'severity_no_cdn' => Violation::SEVERITY_INFO,
            'severity_suboptimal_config' => Violation::SEVERITY_WARNING,
        ];
    }
    
    /**
     * Execute the analysis (not used for CDN detection)
     */
    protected function executeAnalysis(array $files, array $options = []): array
    {
        // CDN detection doesn't analyze individual files
        return [];
    }
    
    /**
     * Parse output (not used for CDN detection)
     */
    protected function parseOutput(string $output, array $files): array
    {
        // CDN detection doesn't parse tool output
        return [];
    }
    
    /**
     * Analyze files and detect CDN configuration
     * 
     * Note: CDN detection is project-level, not file-level, so we only
     * need to detect once per analysis run.
     */
    public function analyze(array $files, array $options = []): array
    {
        $violations = [];
        
        // Get Magento root from first file or options
        $magentoRoot = $options['magento_root'] ?? $this->findMagentoRoot($files);
        
        if (!$magentoRoot) {
            $this->logger->warning('Could not determine Magento root directory');
            return [];
        }
        
        // Detect CDN
        $cdn = $this->detector->detect($magentoRoot, [
            'skip_http' => $this->configuration['skip_http_detection'] ?? false
        ]);
        
        // Store CDN info for other analyzers to use
        $this->logger->info('CDN Detection Result', [
            'type' => $cdn->type,
            'confidence' => $cdn->confidence,
            'detection_method' => $cdn->detectionMethod
        ]);
        
        // Analyze based on CDN type
        if (!$cdn->hasCDN()) {
            $violations = array_merge($violations, $this->analyzeNoCDN($magentoRoot));
        } else {
            switch ($cdn->type) {
                case CDNInfo::TYPE_FASTLY:
                    $violations = array_merge($violations, $this->analyzeFastly($magentoRoot, $cdn));
                    break;
                case CDNInfo::TYPE_VARNISH:
                    $violations = array_merge($violations, $this->analyzeVarnish($magentoRoot, $cdn));
                    break;
                case CDNInfo::TYPE_CLOUDFLARE:
                    $violations = array_merge($violations, $this->analyzeCloudflare($magentoRoot, $cdn));
                    break;
            }
        }
        
        return $violations;
    }
    
    private function analyzeNoCDN(string $path): array
    {
        $violations = [];
        
        if (!$this->configuration['recommend_cdn']) {
            return $violations;
        }
        
        $violations[] = new Violation(
            'cdn-not-detected',
            'No CDN detected. Consider using Fastly, Varnish, or Cloudflare for better performance. ' .
            'CDNs provide: (1) Faster page loads via edge caching, (2) Reduced origin server load, ' .
            '(3) DDoS protection, (4) Global content delivery',
            $path . '/app/etc/env.php',
            0,
            0,
            $this->configuration['severity_no_cdn'],
            Violation::TYPE_PERFORMANCE
        );
        $violations[0]->setCategory('performance');
        $violations[0]->setSuggestion(
            "Consider implementing:\n" .
            "1. Varnish (free, most popular for Magento)\n" .
            "2. Fastly (enterprise, best for high-traffic sites)\n" .
            "3. Cloudflare (good balance of features and cost)"
        );
        
        return $violations;
    }
    
    private function analyzeFastly(string $path, CDNInfo $cdn): array
    {
        $violations = [];
        
        // Check if Fastly module is enabled
        $modules = $this->getModuleStatus($path);
        if (!isset($modules['Fastly_Cdn']) || $modules['Fastly_Cdn'] !== 1) {
            $violations[] = new Violation(
                'fastly-module-disabled',
                'Fastly CDN detected but Fastly_Cdn module is not enabled. Enable the module for full Fastly integration.',
                $path . '/app/etc/config.php',
                0,
                0,
                Violation::SEVERITY_ERROR,
                Violation::TYPE_PERFORMANCE
            );
            $violations[count($violations)-1]->setCategory('performance');
        }
        
        // Check Fastly cache backend configuration
        $envFile = $path . '/app/etc/env.php';
        if (file_exists($envFile)) {
            $env = include $envFile;
            $cacheBackend = $env['cache']['frontend']['default']['backend'] ?? null;
            
            if ($cacheBackend !== 'Fastly\\Magento2\\Model\\Cache\\Backend\\Adapter') {
                $violations[] = new Violation(
                    'fastly-cache-backend-not-configured',
                    'Fastly detected but cache backend not configured in env.php. Configure Fastly cache adapter for optimal performance.',
                    $envFile,
                    0,
                    0,
                    $this->configuration['severity_suboptimal_config'],
                    Violation::TYPE_PERFORMANCE
                );
                $violations[count($violations)-1]->setCategory('performance');
                $violations[count($violations)-1]->setSuggestion(
                    "Add to app/etc/env.php:\n" .
                    "'cache' => [\n" .
                    "    'frontend' => [\n" .
                    "        'default' => [\n" .
                    "            'backend' => 'Fastly\\\\Magento2\\\\Model\\\\Cache\\\\Backend\\\\Adapter'\n" .
                    "        ]\n" .
                    "    ]\n" .
                    "]"
                );
            }
        }
        
        // Check for ESI configuration (Edge Side Includes)
        $violations[] = new Violation(
            'fastly-esi-recommendation',
            'Consider enabling Fastly ESI (Edge Side Includes) for better cache granularity. ' .
            'ESI allows caching page parts independently, improving cache hit rates for dynamic pages.',
            $path . '/app/etc/config.php',
            0,
            0,
            Violation::SEVERITY_INFO,
            Violation::TYPE_PERFORMANCE
        );
        $violations[count($violations)-1]->setCategory('performance');
        
        return $violations;
    }
    
    private function analyzeVarnish(string $path, CDNInfo $cdn): array
    {
        $violations = [];
        
        // Check for Varnish VCL file
        $vclPaths = [
            '/etc/varnish/default.vcl',
            $path . '/var/varnish6.vcl',
            $path . '/var/varnish.vcl',
        ];
        
        $vclFound = false;
        foreach ($vclPaths as $vclPath) {
            if (file_exists($vclPath)) {
                $vclFound = true;
                break;
            }
        }
        
        if (!$vclFound) {
            $violations[] = new Violation(
                'varnish-vcl-not-found',
                'Varnish detected but VCL configuration file not found. Generate VCL with: bin/magento varnish:vcl:generate',
                $path . '/var',
                0,
                0,
                Violation::SEVERITY_WARNING,
                Violation::TYPE_PERFORMANCE
            );
            $violations[count($violations)-1]->setCategory('performance');
        }
        
        // Check FPC configuration
        $envFile = $path . '/app/etc/env.php';
        if (file_exists($envFile)) {
            $env = include $envFile;
            $httpCacheHosts = $env['http_cache_hosts'] ?? null;
            
            if (!$httpCacheHosts) {
                $violations[] = new Violation(
                    'varnish-http-cache-hosts-not-configured',
                    'Varnish detected but http_cache_hosts not configured in env.php. ' .
                    'Magento cannot properly communicate with Varnish.',
                    $envFile,
                    0,
                    0,
                    Violation::SEVERITY_ERROR,
                    Violation::TYPE_PERFORMANCE
                );
                $violations[count($violations)-1]->setCategory('performance');
                $violations[count($violations)-1]->setSuggestion(
                    "Add to app/etc/env.php:\n" .
                    "'http_cache_hosts' => [\n" .
                    "    [\n" .
                    "        'host' => '127.0.0.1',\n" .
                    "        'port' => '6081'\n" .
                    "    ]\n" .
                    "]"
                );
            }
        }
        
        // Recommend ESI for Varnish
        $violations[] = new Violation(
            'varnish-esi-recommendation',
            'Consider enabling Varnish ESI (Edge Side Includes) for better cache granularity. ' .
            'ESI allows caching block-level content separately.',
            $path . '/app/etc/config.php',
            0,
            0,
            Violation::SEVERITY_INFO,
            Violation::TYPE_PERFORMANCE
        );
        $violations[count($violations)-1]->setCategory('performance');
        
        return $violations;
    }
    
    private function analyzeCloudflare(string $path, CDNInfo $cdn): array
    {
        $violations = [];
        
        // Cloudflare-specific recommendations
        $violations[] = new Violation(
            'cloudflare-page-rules-recommendation',
            'Using Cloudflare: Configure page rules to cache dynamic content. ' .
            'Set up custom cache TTLs for different page types (product pages, categories, CMS).',
            $path . '/app/etc/config.php',
            0,
            0,
            Violation::SEVERITY_INFO,
            Violation::TYPE_PERFORMANCE
        );
        $violations[count($violations)-1]->setCategory('performance');
        
        $violations[] = new Violation(
            'cloudflare-bypass-cookie',
            'Configure Cloudflare to bypass cache for logged-in users using Cookie rules. ' .
            'This prevents showing cached personalized content to wrong users.',
            $path . '/app/etc/config.php',
            0,
            0,
            Violation::SEVERITY_WARNING,
            Violation::TYPE_PERFORMANCE
        );
        $violations[count($violations)-1]->setCategory('performance');
        
        return $violations;
    }
    
    private function getModuleStatus(string $path): array
    {
        $configFile = $path . '/app/etc/config.php';
        if (!file_exists($configFile)) {
            return [];
        }
        
        $config = include $configFile;
        return $config['modules'] ?? [];
    }
    
    /**
     * Get the detected CDN for the given path
     * 
     * Convenience method for other analyzers to check CDN status
     * 
     * @param string $path Path to Magento root
     * @return CDNInfo CDN information
     */
    public function getCDNInfo(string $path): CDNInfo
    {
        return $this->detector->detect($path);
    }
    
    /**
     * Find Magento root directory from file list
     * 
     * @param array<string> $files List of files being analyzed
     * @return string|null Magento root path or null if not found
     */
    private function findMagentoRoot(array $files): ?string
    {
        if (empty($files)) {
            return getcwd();
        }
        
        // Get directory from first file
        $firstFile = reset($files);
        $dir = is_dir($firstFile) ? $firstFile : dirname($firstFile);
        
        // Walk up directory tree looking for bin/magento
        $maxDepth = 10;
        $currentDepth = 0;
        
        while ($currentDepth < $maxDepth) {
            if (file_exists($dir . '/bin/magento')) {
                return $dir;
            }
            
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;  // Reached filesystem root
            }
            
            $dir = $parent;
            $currentDepth++;
        }
        
        return null;
    }
}

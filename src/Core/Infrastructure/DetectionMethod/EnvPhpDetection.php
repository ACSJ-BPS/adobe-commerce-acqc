<?php

declare(strict_types=1);

namespace ACQC\Core\Infrastructure\DetectionMethod;

use ACQC\Core\Infrastructure\CDNInfo;

/**
 * Detect CDN via app/etc/env.php configuration
 * 
 * Checks cache backend configuration for CDN-specific adapters
 */
class EnvPhpDetection implements DetectionMethodInterface
{
    public function getName(): string
    {
        return 'env_php';
    }
    
    public function isAvailable(string $magentoRoot): bool
    {
        return file_exists($magentoRoot . '/app/etc/env.php');
    }
    
    public function detect(string $magentoRoot): ?CDNInfo
    {
        $envFile = $magentoRoot . '/app/etc/env.php';
        
        if (!file_exists($envFile)) {
            return null;
        }
        
        $env = include $envFile;
        
        if (!is_array($env)) {
            return null;
        }
        
        // Check cache backend configuration for Fastly
        $cacheBackend = $env['cache']['frontend']['default']['backend'] ?? null;
        
        if ($cacheBackend === 'Fastly\\Magento2\\Model\\Cache\\Backend\\Adapter' ||
            $cacheBackend === 'Fastly_Cdn') {
            return new CDNInfo(
                type: CDNInfo::TYPE_FASTLY,
                version: null,
                detectionMethod: $this->getName(),
                confidence: CDNInfo::CONFIDENCE_HIGH,
                config: [
                    'backend' => $cacheBackend,
                    'cache_config' => $env['cache'] ?? []
                ]
            );
        }
        
        // Check for Varnish configuration
        $fpcBackend = $env['http_cache_hosts'] ?? null;
        if ($fpcBackend) {
            return new CDNInfo(
                type: CDNInfo::TYPE_VARNISH,
                version: null,
                detectionMethod: $this->getName(),
                confidence: CDNInfo::CONFIDENCE_HIGH,
                config: [
                    'http_cache_hosts' => $fpcBackend
                ]
            );
        }
        
        return null;
    }
}

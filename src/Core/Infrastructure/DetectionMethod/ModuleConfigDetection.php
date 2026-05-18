<?php

declare(strict_types=1);

namespace ACQC\Core\Infrastructure\DetectionMethod;

use ACQC\Core\Infrastructure\CDNInfo;

/**
 * Detect CDN via Magento module configuration
 * 
 * Checks if CDN-specific modules are installed and enabled
 */
class ModuleConfigDetection implements DetectionMethodInterface
{
    public function getName(): string
    {
        return 'module_config';
    }
    
    public function isAvailable(string $magentoRoot): bool
    {
        return file_exists($magentoRoot . '/app/etc/config.php');
    }
    
    public function detect(string $magentoRoot): ?CDNInfo
    {
        $configFile = $magentoRoot . '/app/etc/config.php';
        
        if (!file_exists($configFile)) {
            return null;
        }
        
        $config = include $configFile;
        
        if (!is_array($config) || !isset($config['modules'])) {
            return null;
        }
        
        $modules = $config['modules'];
        
        // Check for Fastly_Cdn module
        if (isset($modules['Fastly_Cdn']) && $modules['Fastly_Cdn'] === 1) {
            return new CDNInfo(
                type: CDNInfo::TYPE_FASTLY,
                version: $this->getFastlyModuleVersion($magentoRoot),
                detectionMethod: $this->getName(),
                confidence: CDNInfo::CONFIDENCE_HIGH,
                config: [
                    'module' => 'Fastly_Cdn',
                    'enabled' => true
                ]
            );
        }
        
        // Check for custom Varnish modules
        $varnishModules = array_filter(
            array_keys($modules),
            fn($module) => stripos($module, 'Varnish') !== false && $modules[$module] === 1
        );
        
        if (!empty($varnishModules)) {
            return new CDNInfo(
                type: CDNInfo::TYPE_VARNISH,
                version: null,
                detectionMethod: $this->getName(),
                confidence: CDNInfo::CONFIDENCE_MEDIUM,
                config: [
                    'modules' => $varnishModules,
                    'enabled' => true
                ]
            );
        }
        
        return null;
    }
    
    private function getFastlyModuleVersion(string $magentoRoot): ?string
    {
        // Try to read version from module's composer.json
        $modulePath = $magentoRoot . '/vendor/fastly/magento2/composer.json';
        
        if (file_exists($modulePath)) {
            $composer = json_decode(file_get_contents($modulePath), true);
            return $composer['version'] ?? null;
        }
        
        return null;
    }
}

<?php

declare(strict_types=1);

namespace ACQC\Core\Infrastructure\DetectionMethod;

use ACQC\Core\Infrastructure\CDNInfo;

/**
 * Detect CDN via composer.json dependencies
 * 
 * Looks for CDN-specific packages like fastly/magento2 in composer.json
 */
class ComposerDetection implements DetectionMethodInterface
{
    public function getName(): string
    {
        return 'composer';
    }
    
    public function isAvailable(string $magentoRoot): bool
    {
        return file_exists($magentoRoot . '/composer.json');
    }
    
    public function detect(string $magentoRoot): ?CDNInfo
    {
        $composerFile = $magentoRoot . '/composer.json';
        
        if (!file_exists($composerFile)) {
            return null;
        }
        
        $composerContent = file_get_contents($composerFile);
        $composer = json_decode($composerContent, true);
        
        if (!$composer || !isset($composer['require'])) {
            return null;
        }
        
        $allPackages = array_merge(
            $composer['require'] ?? [],
            $composer['require-dev'] ?? []
        );
        
        // Check for Fastly
        if (isset($allPackages['fastly/magento2'])) {
            return new CDNInfo(
                type: CDNInfo::TYPE_FASTLY,
                version: $allPackages['fastly/magento2'],
                detectionMethod: $this->getName(),
                confidence: CDNInfo::CONFIDENCE_HIGH,
                config: [
                    'package' => 'fastly/magento2',
                    'composer_version' => $allPackages['fastly/magento2']
                ]
            );
        }
        
        return null;
    }
}

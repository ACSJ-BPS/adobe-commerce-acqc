<?php

declare(strict_types=1);

namespace ACQC\Core\Infrastructure\DetectionMethod;

use ACQC\Core\Infrastructure\CDNInfo;

/**
 * Interface for CDN detection methods
 * 
 * Each detection method implements a different strategy for detecting
 * the CDN being used (composer, env.php, HTTP headers, module config).
 */
interface DetectionMethodInterface
{
    /**
     * Attempt to detect CDN using this method
     * 
     * @param string $magentoRoot Path to Magento root directory
     * @return CDNInfo|null CDN information if detected, null otherwise
     */
    public function detect(string $magentoRoot): ?CDNInfo;
    
    /**
     * Get the name of this detection method
     * 
     * @return string Method name (e.g., "composer", "env_php")
     */
    public function getName(): string;
    
    /**
     * Check if this detection method is available/supported
     * 
     * @param string $magentoRoot Path to Magento root directory
     * @return bool True if this method can be used
     */
    public function isAvailable(string $magentoRoot): bool;
}

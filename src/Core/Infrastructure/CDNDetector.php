<?php

declare(strict_types=1);

namespace ACQC\Core\Infrastructure;

use ACQC\Core\Infrastructure\DetectionMethod\ComposerDetection;
use ACQC\Core\Infrastructure\DetectionMethod\EnvPhpDetection;
use ACQC\Core\Infrastructure\DetectionMethod\HttpHeaderDetection;
use ACQC\Core\Infrastructure\DetectionMethod\ModuleConfigDetection;
use ACQC\Core\Infrastructure\DetectionMethod\DetectionMethodInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Main CDN detection orchestrator
 * 
 * Coordinates multiple detection methods to identify which CDN/cache layer
 * is being used. Tries methods in order of reliability:
 * 1. Composer (highest confidence)
 * 2. Module Config (high confidence)
 * 3. Env.php (high confidence)
 * 4. HTTP Headers (medium confidence, requires running site)
 */
class CDNDetector
{
    /** @var array<DetectionMethodInterface> */
    private array $detectionMethods = [];
    
    private LoggerInterface $logger;
    
    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
        
        // Register detection methods in order of reliability
        $this->detectionMethods = [
            new ComposerDetection(),
            new ModuleConfigDetection(),
            new EnvPhpDetection(),
            new HttpHeaderDetection(),
        ];
    }
    
    /**
     * Detect CDN being used
     * 
     * Tries all detection methods in sequence until one succeeds.
     * Returns the first successful detection result.
     * 
     * @param string $magentoRoot Path to Magento root directory
     * @param array{
     *   skip_http?: bool,
     *   methods?: array<string>,
     *   timeout?: int
     * } $options Detection options
     * @return CDNInfo CDN information (may indicate no CDN found)
     */
    public function detect(string $magentoRoot, array $options = []): CDNInfo
    {
        $skipHttp = $options['skip_http'] ?? false;
        $specificMethods = $options['methods'] ?? [];
        
        $this->logger->info('Starting CDN detection', [
            'magento_root' => $magentoRoot,
            'skip_http' => $skipHttp,
            'methods_count' => count($this->detectionMethods)
        ]);
        
        foreach ($this->detectionMethods as $method) {
            // Skip HTTP detection if requested
            if ($skipHttp && $method->getName() === 'http_headers') {
                $this->logger->debug('Skipping HTTP header detection (disabled)');
                continue;
            }
            
            // If specific methods requested, only use those
            if (!empty($specificMethods) && !in_array($method->getName(), $specificMethods, true)) {
                continue;
            }
            
            // Check if method is available
            if (!$method->isAvailable($magentoRoot)) {
                $this->logger->debug('Detection method not available', [
                    'method' => $method->getName()
                ]);
                continue;
            }
            
            try {
                $result = $method->detect($magentoRoot);
                
                if ($result) {
                    $this->logger->info('CDN detected', [
                        'type' => $result->type,
                        'method' => $method->getName(),
                        'confidence' => $result->confidence,
                        'version' => $result->version
                    ]);
                    return $result;
                }
                
            } catch (\Exception $e) {
                $this->logger->warning('Detection method failed', [
                    'method' => $method->getName(),
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        // No CDN detected
        $this->logger->info('No CDN detected');
        return new CDNInfo(
            type: CDNInfo::TYPE_NONE,
            detectionMethod: 'none',
            confidence: CDNInfo::CONFIDENCE_HIGH
        );
    }
    
    /**
     * Try all detection methods and return all results
     * 
     * Useful for debugging or when multiple CDNs might be in use
     * 
     * @param string $magentoRoot Path to Magento root directory
     * @return array<string, CDNInfo|null> Results from all methods
     */
    public function detectAll(string $magentoRoot): array
    {
        $results = [];
        
        foreach ($this->detectionMethods as $method) {
            try {
                if ($method->isAvailable($magentoRoot)) {
                    $results[$method->getName()] = $method->detect($magentoRoot);
                } else {
                    $results[$method->getName()] = null;
                }
            } catch (\Exception $e) {
                $this->logger->warning('Detection method failed', [
                    'method' => $method->getName(),
                    'error' => $e->getMessage()
                ]);
                $results[$method->getName()] = null;
            }
        }
        
        return $results;
    }
    
    /**
     * Register a custom detection method
     * 
     * @param DetectionMethodInterface $method Custom detection method
     * @return self For method chaining
     */
    public function registerMethod(DetectionMethodInterface $method): self
    {
        $this->detectionMethods[] = $method;
        $this->logger->info('Registered custom detection method', [
            'method' => $method->getName()
        ]);
        return $this;
    }
    
    /**
     * Get list of available detection methods
     * 
     * @return array<string> Method names
     */
    public function getAvailableMethods(): array
    {
        return array_map(
            fn($method) => $method->getName(),
            $this->detectionMethods
        );
    }
}

<?php

declare(strict_types=1);

namespace ACQC\Core\Infrastructure\DetectionMethod;

use ACQC\Core\Infrastructure\CDNInfo;

/**
 * Detect CDN via HTTP headers
 * 
 * Sends HTTP request to the site and analyzes response headers
 * for CDN-specific indicators like X-Cache, X-Fastly-*, X-Varnish, CF-Ray
 */
class HttpHeaderDetection implements DetectionMethodInterface
{
    private int $timeout = 5;
    
    public function getName(): string
    {
        return 'http_headers';
    }
    
    public function isAvailable(string $magentoRoot): bool
    {
        // HTTP detection requires network access and a running site
        return true;  // Always try if other methods fail
    }
    
    public function detect(string $magentoRoot): ?CDNInfo
    {
        $baseUrl = $this->getBaseUrl($magentoRoot);
        
        if (!$baseUrl) {
            return null;
        }
        
        try {
            $headers = $this->getHeaders($baseUrl);
            
            if (!$headers) {
                return null;
            }
            
            // Check for Fastly headers
            if ($this->hasFastlyHeaders($headers)) {
                return new CDNInfo(
                    type: CDNInfo::TYPE_FASTLY,
                    version: $this->extractFastlyVersion($headers),
                    detectionMethod: $this->getName(),
                    confidence: CDNInfo::CONFIDENCE_MEDIUM,
                    config: [
                        'headers' => $this->filterCDNHeaders($headers, 'fastly')
                    ]
                );
            }
            
            // Check for Varnish headers
            if ($this->hasVarnishHeaders($headers)) {
                return new CDNInfo(
                    type: CDNInfo::TYPE_VARNISH,
                    version: $this->extractVarnishVersion($headers),
                    detectionMethod: $this->getName(),
                    confidence: CDNInfo::CONFIDENCE_MEDIUM,
                    config: [
                        'headers' => $this->filterCDNHeaders($headers, 'varnish')
                    ]
                );
            }
            
            // Check for Cloudflare headers
            if ($this->hasCloudflareHeaders($headers)) {
                return new CDNInfo(
                    type: CDNInfo::TYPE_CLOUDFLARE,
                    version: null,
                    detectionMethod: $this->getName(),
                    confidence: CDNInfo::CONFIDENCE_HIGH,
                    config: [
                        'headers' => $this->filterCDNHeaders($headers, 'cloudflare')
                    ]
                );
            }
            
        } catch (\Exception $e) {
            // Network errors are expected, return null
            return null;
        }
        
        return null;
    }
    
    private function getBaseUrl(string $magentoRoot): ?string
    {
        // Try to get base URL from env.php
        $envFile = $magentoRoot . '/app/etc/env.php';
        if (file_exists($envFile)) {
            $env = include $envFile;
            if (is_array($env)) {
                $baseUrl = $env['system']['default']['web']['unsecure']['base_url'] ?? null;
                if ($baseUrl) {
                    return rtrim($baseUrl, '/');
                }
            }
        }
        
        // Try to get from config.php
        $configFile = $magentoRoot . '/app/etc/config.php';
        if (file_exists($configFile)) {
            $config = include $configFile;
            if (is_array($config)) {
                $baseUrl = $config['system']['default']['web']['unsecure']['base_url'] ?? null;
                if ($baseUrl) {
                    return rtrim($baseUrl, '/');
                }
            }
        }
        
        return null;
    }
    
    private function getHeaders(string $url): ?array
    {
        // Use get_headers() with timeout
        $context = stream_context_create([
            'http' => [
                'timeout' => $this->timeout,
                'method' => 'HEAD',
                'ignore_errors' => true
            ]
        ]);
        
        $headers = @get_headers($url, true, $context);
        
        return $headers ?: null;
    }
    
    private function hasFastlyHeaders(array $headers): bool
    {
        // Check for Fastly-specific headers
        $fastlyIndicators = [
            'X-Fastly-Request-ID',
            'Fastly-Restarts',
            'X-Served-By',  // Contains "cache-" for Fastly
        ];
        
        foreach ($fastlyIndicators as $indicator) {
            if (isset($headers[$indicator])) {
                return true;
            }
        }
        
        // Check X-Cache header for FASTLY string
        if (isset($headers['X-Cache'])) {
            $xCache = is_array($headers['X-Cache']) ? implode(' ', $headers['X-Cache']) : $headers['X-Cache'];
            if (stripos($xCache, 'FASTLY') !== false || stripos($xCache, 'HIT') !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    private function hasVarnishHeaders(array $headers): bool
    {
        // Check for Varnish-specific headers
        return isset($headers['X-Varnish']) || 
               isset($headers['Via']) && (
                   stripos($headers['Via'], 'varnish') !== false ||
                   stripos($headers['Via'], 'VCL') !== false
               );
    }
    
    private function hasCloudflareHeaders(array $headers): bool
    {
        // Cloudflare-specific headers
        return isset($headers['CF-Ray']) || 
               isset($headers['CF-Cache-Status']) ||
               isset($headers['Server']) && stripos($headers['Server'], 'cloudflare') !== false;
    }
    
    private function extractFastlyVersion(array $headers): ?string
    {
        // Fastly doesn't typically expose version in headers
        return null;
    }
    
    private function extractVarnishVersion(array $headers): ?string
    {
        if (isset($headers['Via'])) {
            $via = is_array($headers['Via']) ? $headers['Via'][0] : $headers['Via'];
            if (preg_match('/varnish\/(\d+\.\d+\.\d+)/i', $via, $matches)) {
                return $matches[1];
            }
        }
        return null;
    }
    
    private function filterCDNHeaders(array $headers, string $cdnType): array
    {
        $filtered = [];
        
        foreach ($headers as $key => $value) {
            $keyLower = strtolower($key);
            
            if (match($cdnType) {
                'fastly' => str_contains($keyLower, 'fastly') || 
                           str_contains($keyLower, 'x-cache') ||
                           str_contains($keyLower, 'x-served-by'),
                'varnish' => str_contains($keyLower, 'varnish') || 
                            str_contains($keyLower, 'via') ||
                            str_contains($keyLower, 'x-cache'),
                'cloudflare' => str_contains($keyLower, 'cf-') || 
                               str_contains($keyLower, 'cloudflare'),
                default => false
            }) {
                $filtered[$key] = $value;
            }
        }
        
        return $filtered;
    }
}

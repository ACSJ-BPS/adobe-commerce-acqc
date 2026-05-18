<?php

declare(strict_types=1);

namespace ACQC\Core\Infrastructure;

/**
 * Value object representing CDN information
 * 
 * Contains information about detected CDN including type, version,
 * detection method, and configuration details.
 */
class CDNInfo
{
    public const TYPE_FASTLY = 'fastly';
    public const TYPE_VARNISH = 'varnish';
    public const TYPE_CLOUDFLARE = 'cloudflare';
    public const TYPE_AKAMAI = 'akamai';
    public const TYPE_NONE = 'none';
    
    public const CONFIDENCE_HIGH = 'high';
    public const CONFIDENCE_MEDIUM = 'medium';
    public const CONFIDENCE_LOW = 'low';
    
    /**
     * @param string $type CDN type (fastly, varnish, cloudflare, none)
     * @param string|null $version CDN or module version
     * @param string $detectionMethod How the CDN was detected
     * @param string $confidence Detection confidence level (high, medium, low)
     * @param array<string, mixed> $config Additional configuration details
     */
    public function __construct(
        public readonly string $type,
        public readonly ?string $version = null,
        public readonly string $detectionMethod = 'unknown',
        public readonly string $confidence = self::CONFIDENCE_MEDIUM,
        public readonly array $config = []
    ) {
        if (!in_array($type, [self::TYPE_FASTLY, self::TYPE_VARNISH, self::TYPE_CLOUDFLARE, self::TYPE_AKAMAI, self::TYPE_NONE])) {
            throw new \InvalidArgumentException("Invalid CDN type: {$type}");
        }
        
        if (!in_array($confidence, [self::CONFIDENCE_HIGH, self::CONFIDENCE_MEDIUM, self::CONFIDENCE_LOW])) {
            throw new \InvalidArgumentException("Invalid confidence level: {$confidence}");
        }
    }
    
    /**
     * Check if a CDN is configured
     */
    public function hasCDN(): bool
    {
        return $this->type !== self::TYPE_NONE;
    }
    
    /**
     * Check if this is Fastly CDN
     */
    public function isFastly(): bool
    {
        return $this->type === self::TYPE_FASTLY;
    }
    
    /**
     * Check if this is Varnish
     */
    public function isVarnish(): bool
    {
        return $this->type === self::TYPE_VARNISH;
    }
    
    /**
     * Check if this is Cloudflare
     */
    public function isCloudflare(): bool
    {
        return $this->type === self::TYPE_CLOUDFLARE;
    }
    
    /**
     * Get a configuration value
     */
    public function getConfig(string $key, $default = null)
    {
        return $this->config[$key] ?? $default;
    }
    
    /**
     * Get display name for the CDN
     */
    public function getDisplayName(): string
    {
        return match($this->type) {
            self::TYPE_FASTLY => 'Fastly CDN',
            self::TYPE_VARNISH => 'Varnish Cache',
            self::TYPE_CLOUDFLARE => 'Cloudflare',
            self::TYPE_AKAMAI => 'Akamai',
            self::TYPE_NONE => 'No CDN',
            default => 'Unknown CDN'
        };
    }
    
    /**
     * Convert to array for serialization
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'version' => $this->version,
            'detection_method' => $this->detectionMethod,
            'confidence' => $this->confidence,
            'config' => $this->config,
            'display_name' => $this->getDisplayName(),
            'has_cdn' => $this->hasCDN(),
        ];
    }
    
    /**
     * Get a summary string
     */
    public function getSummary(): string
    {
        if (!$this->hasCDN()) {
            return 'No CDN detected';
        }
        
        $summary = $this->getDisplayName();
        
        if ($this->version) {
            $summary .= " v{$this->version}";
        }
        
        $summary .= " (detected via {$this->detectionMethod}, confidence: {$this->confidence})";
        
        return $summary;
    }
}

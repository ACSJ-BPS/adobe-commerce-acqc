<?php

declare(strict_types=1);

namespace ACQC\Core\Version;

/**
 * Magento 2 Version Value Object
 * 
 * Represents a specific Magento 2 version and provides
 * comparison and compatibility checking capabilities.
 */
class MagentoVersion
{
    private int $major;
    private int $minor;
    private int $patch;
    private ?int $securityPatch = null; // Security patch version (e.g., -p2, -p3)
    private string $edition = 'CE'; // CE, EE, B2B

    public function __construct(int $major, int $minor, int $patch, ?int $securityPatch = null, string $edition = 'CE')
    {
        $this->major = $major;
        $this->minor = $minor;
        $this->patch = $patch;
        $this->securityPatch = $securityPatch;
        $this->edition = $edition;
    }

    public function getMajor(): int
    {
        return $this->major;
    }

    public function getMinor(): int
    {
        return $this->minor;
    }

    public function getPatch(): int
    {
        return $this->patch;
    }

    public function getSecurityPatch(): ?int
    {
        return $this->securityPatch;
    }

    public function getEdition(): string
    {
        return $this->edition;
    }

    public function toString(): string
    {
        $version = sprintf('%d.%d.%d', $this->major, $this->minor, $this->patch);
        if ($this->securityPatch !== null) {
            $version .= '-p' . $this->securityPatch;
        }
        return $version;
    }

    public function toShortString(): string
    {
        return sprintf('%d.%d', $this->major, $this->minor);
    }

    /**
     * Check if this version is greater than another
     */
    public function isGreaterThan(MagentoVersion $other): bool
    {
        if ($this->major > $other->major) {
            return true;
        }
        if ($this->major < $other->major) {
            return false;
        }

        if ($this->minor > $other->minor) {
            return true;
        }
        if ($this->minor < $other->minor) {
            return false;
        }

        if ($this->patch > $other->patch) {
            return true;
        }
        if ($this->patch < $other->patch) {
            return false;
        }

        // Compare security patches (higher is newer)
        $thisSecurityPatch = $this->securityPatch ?? 0;
        $otherSecurityPatch = $other->securityPatch ?? 0;
        
        return $thisSecurityPatch > $otherSecurityPatch;
    }

    /**
     * Check if this version is greater than or equal to another
     */
    public function isGreaterThanOrEqual(MagentoVersion $other): bool
    {
        return $this->isGreaterThan($other) || $this->isEqual($other);
    }

    /**
     * Check if this version is less than another
     */
    public function isLessThan(MagentoVersion $other): bool
    {
        return !$this->isGreaterThanOrEqual($other);
    }

    /**
     * Check if this version is less than or equal to another
     */
    public function isLessThanOrEqual(MagentoVersion $other): bool
    {
        return !$this->isGreaterThan($other);
    }

    /**
     * Check if this version equals another
     */
    public function isEqual(MagentoVersion $other): bool
    {
        $thisSecurityPatch = $this->securityPatch ?? 0;
        $otherSecurityPatch = $other->securityPatch ?? 0;
        
        return $this->major === $other->major
            && $this->minor === $other->minor
            && $this->patch === $other->patch
            && $thisSecurityPatch === $otherSecurityPatch;
    }

    /**
     * Check if version is compatible with PHP version
     */
    public function isCompatibleWith(PhpVersion $php): bool
    {
        $compatibilityMap = $this->getPhpCompatibilityMap();
        
        // Try exact version match first (e.g., "2.4.8")
        $fullVersionKey = sprintf('%d.%d.%d', $this->major, $this->minor, $this->patch);
        if (isset($compatibilityMap[$fullVersionKey])) {
            $phpVersionString = $php->toShortString();
            return in_array($phpVersionString, $compatibilityMap[$fullVersionKey]);
        }
        
        // Fall back to major.minor (e.g., "2.4")
        $versionKey = $this->toShortString();
        if (!isset($compatibilityMap[$versionKey])) {
            return false;
        }

        $phpVersionString = $php->toShortString();
        return in_array($phpVersionString, $compatibilityMap[$versionKey]);
    }

    /**
     * Get deprecated features for this version
     */
    public function getDeprecatedFeatures(): array
    {
        $deprecations = [
            '2.4.0' => [
                'setup_scripts' => 'InstallSchema/UpgradeSchema classes',
                'web_api_xml' => 'Legacy webapi.xml format',
            ],
            '2.4.4' => [
                'zend_framework' => 'Zend Framework (use Laminas)',
                'mysql_search' => 'MySQL search engine',
            ],
            '2.4.6' => [
                'jquery_ui_components' => 'jQuery UI components',
            ],
        ];

        $versionKey = $this->toShortString();
        return $deprecations[$versionKey] ?? [];
    }

    /**
     * Get new features for this version
     */
    public function getNewFeatures(): array
    {
        $features = [
            '2.4.0' => [
                'declarative_schema' => 'Declarative schema (db_schema.xml)',
                'graphql_enhanced' => 'Enhanced GraphQL support',
            ],
            '2.4.4' => [
                'laminas' => 'Laminas Framework',
                'elasticsearch_7' => 'Elasticsearch 7.x support',
            ],
            '2.4.6' => [
                'php_8.2' => 'PHP 8.2 support',
                'opensearch' => 'OpenSearch support',
            ],
        ];

        $versionKey = $this->toShortString();
        return $features[$versionKey] ?? [];
    }

    /**
     * Get PHP compatibility map
     */
    private function getPhpCompatibilityMap(): array
    {
        return [
            // Magento 2.3.x
            '2.3' => ['7.2', '7.3', '7.4'],
            
            // Magento 2.4.x (specific versions for granular control)
            '2.4.0' => ['7.3', '7.4'],
            '2.4.1' => ['7.3', '7.4'],
            '2.4.2' => ['7.3', '7.4'],
            '2.4.3' => ['7.4', '8.1'],
            '2.4.4' => ['7.4', '8.1'],
            '2.4.5' => ['7.4', '8.1'],
            '2.4.6' => ['8.1', '8.2'],
            '2.4.7' => ['8.1', '8.2', '8.3'],
            '2.4.8' => ['8.2', '8.3'],
        ];
    }

    /**
     * Check if version is End-of-Life
     */
    public function isEol(): bool
    {
        $eolDates = [
            '2.3' => '2022-09-08',
            '2.4.0' => '2021-11-28',
            '2.4.1' => '2022-02-08',
            '2.4.2' => '2022-05-12',
        ];

        $versionKey = $this->toShortString();
        if (!isset($eolDates[$versionKey])) {
            return false;
        }

        $eolDate = strtotime($eolDates[$versionKey]);
        return time() > $eolDate;
    }
}


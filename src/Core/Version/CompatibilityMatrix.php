<?php

declare(strict_types=1);

namespace ACQC\Core\Version;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Magento 2 and PHP Version Compatibility Matrix
 * 
 * Validates version combinations and provides compatibility information
 */
class CompatibilityMatrix
{
    private MagentoVersion $magentoVersion;
    private PhpVersion $phpVersion;
    private LoggerInterface $logger;

    public function __construct(
        MagentoVersion $magentoVersion,
        PhpVersion $phpVersion,
        LoggerInterface $logger = null
    ) {
        $this->magentoVersion = $magentoVersion;
        $this->phpVersion = $phpVersion;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Check if the version combination is compatible
     */
    public function isCompatible(): bool
    {
        return $this->magentoVersion->isCompatibleWith($this->phpVersion);
    }

    /**
     * Get compatibility status with details
     */
    public function getCompatibilityStatus(): array
    {
        $compatible = $this->isCompatible();

        return [
            'compatible' => $compatible,
            'magento_version' => $this->magentoVersion->toString(),
            'php_version' => $this->phpVersion->toString(),
            'status' => $compatible ? 'supported' : 'unsupported',
            'warnings' => $this->getWarnings(),
            'recommendations' => $this->getRecommendations(),
        ];
    }

    /**
     * Get warnings for this version combination
     */
    private function getWarnings(): array
    {
        $warnings = [];

        // Check EOL status
        if ($this->magentoVersion->isEol()) {
            $warnings[] = sprintf(
                'Magento %s is End-of-Life and no longer receives security updates',
                $this->magentoVersion->toString()
            );
        }

        if ($this->phpVersion->isEol()) {
            $warnings[] = sprintf(
                'PHP %s is End-of-Life and no longer receives security updates',
                $this->phpVersion->toString()
            );
        }

        // Check compatibility
        if (!$this->isCompatible()) {
            $warnings[] = sprintf(
                'Magento %s is not officially compatible with PHP %s',
                $this->magentoVersion->toString(),
                $this->phpVersion->toString()
            );
        }

        return $warnings;
    }

    /**
     * Get recommendations for this version combination
     */
    private function getRecommendations(): array
    {
        $recommendations = [];

        // Recommend latest compatible versions
        if ($this->magentoVersion->isLessThan(new MagentoVersion(2, 4, 6, null, 'CE'))) {
            $recommendations[] = 'Consider upgrading to Magento 2.4.6 or later for better security and features';
        }

        if ($this->phpVersion->isLessThan(new PhpVersion(8, 1))) {
            $recommendations[] = 'Consider upgrading to PHP 8.1 or 8.2 for improved performance and security';
        }

        return $recommendations;
    }

    /**
     * Get supported PHP versions for current Magento version
     */
    public function getSupportedPhpVersions(): array
    {
        $matrix = [
            '2.3' => ['7.2', '7.3', '7.4'],
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

        // Try exact version first (e.g., "2.4.8")
        $fullKey = sprintf('%d.%d.%d', 
            $this->magentoVersion->getMajor(),
            $this->magentoVersion->getMinor(),
            $this->magentoVersion->getPatch()
        );
        if (isset($matrix[$fullKey])) {
            return $matrix[$fullKey];
        }

        // Fall back to major.minor (e.g., "2.4")
        $key = $this->magentoVersion->toShortString();
        return $matrix[$key] ?? [];
    }

    public function getRegistry(): VersionRegistry
    {
        $basePath = dirname(dirname(dirname(__DIR__)));
        $registryPath = $basePath . '/config/versions/adobe-commerce';
        return new VersionRegistry($registryPath, new ArrayAdapter());
    }

    /**
     * Get supported Magento versions for current PHP version
     */
    public function getSupportedMagentoVersions(): array
    {
        $phpKey = $this->phpVersion->toShortString();

        $matrix = [
            '7.2' => ['2.3'],
            '7.3' => ['2.3', '2.4.0', '2.4.1', '2.4.2'],
            '7.4' => ['2.3', '2.4.0', '2.4.1', '2.4.2', '2.4.3', '2.4.4', '2.4.5'],
            '8.1' => ['2.4.3', '2.4.4', '2.4.5', '2.4.6', '2.4.7'],
            '8.2' => ['2.4.6', '2.4.7', '2.4.8'],
            '8.3' => ['2.4.7', '2.4.8'],
        ];

        return $matrix[$phpKey] ?? [];
    }
}


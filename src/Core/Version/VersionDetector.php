<?php

declare(strict_types=1);

namespace ACQC\Core\Version;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Magento 2 and PHP Version Detection System
 * 
 * Automatically detects and validates Magento 2 and PHP versions
 * for version-aware code analysis and compatibility checking.
 */
class VersionDetector
{
    private LoggerInterface $logger;
    private ?MagentoVersion $detectedMagentoVersion = null;
    private ?PhpVersion $detectedPhpVersion = null;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Detect Magento 2 version from project path
     */
    public function detectMagentoVersion(string $projectPath): ?MagentoVersion
    {
        if ($this->detectedMagentoVersion) {
            return $this->detectedMagentoVersion;
        }

        // For module paths (app/code), try to find the root Magento installation
        $rootPath = $this->findMagentoRoot($projectPath);
        
        // Method 1: Check composer.json
        $version = $this->detectFromComposer($rootPath);
        if ($version) {
            $this->detectedMagentoVersion = $version;
            $this->logger->info('Magento version detected from composer.json', [
                'version' => $version->toString()
            ]);
            return $version;
        }

        // Method 2: Check vendor directory
        $version = $this->detectFromVendor($rootPath);
        if ($version) {
            $this->detectedMagentoVersion = $version;
            $this->logger->info('Magento version detected from vendor', [
                'version' => $version->toString()
            ]);
            return $version;
        }

        // Method 3: Check module.xml
        $version = $this->detectFromModuleXml($projectPath);
        if ($version) {
            $this->detectedMagentoVersion = $version;
            $this->logger->info('Magento version detected from module.xml', [
                'version' => $version->toString()
            ]);
            return $version;
        }

        $this->logger->warning('Could not detect Magento version', [
            'path' => $projectPath
        ]);

        return null;
    }

    /**
     * Find Magento root directory from a given path
     */
    private function findMagentoRoot(string $path): string
    {
        // If path contains app/code, go up to find root
        if (strpos($path, '/app/code') !== false) {
            $parts = explode('/app/code', $path);
            $rootPath = $parts[0];
            
            // Verify it's a Magento root by checking for key files
            if (file_exists($rootPath . '/app/etc/di.xml') || 
                file_exists($rootPath . '/composer.json') ||
                file_exists($rootPath . '/bin/magento')) {
                $this->logger->info('Found Magento root from module path', ['root' => $rootPath]);
                return $rootPath;
            }
        }
        
        // Try traversing up to find Magento root
        $currentPath = realpath($path);
        $maxLevels = 5;
        
        while ($maxLevels-- > 0 && $currentPath !== '/') {
            if (file_exists($currentPath . '/app/etc/di.xml') || 
                file_exists($currentPath . '/bin/magento')) {
                $this->logger->info('Found Magento root by traversal', ['root' => $currentPath]);
                return $currentPath;
            }
            
            $currentPath = dirname($currentPath);
        }
        
        // Fallback to original path
        return $path;
    }

    /**
     * Detect PHP version
     */
    public function detectPhpVersion(): PhpVersion
    {
        if ($this->detectedPhpVersion) {
            return $this->detectedPhpVersion;
        }

        $version = new PhpVersion(
            PHP_MAJOR_VERSION,
            PHP_MINOR_VERSION,
            PHP_RELEASE_VERSION
        );

        $this->detectedPhpVersion = $version;
        $this->logger->info('PHP version detected', [
            'version' => $version->toString()
        ]);

        return $version;
    }

    /**
     * Detect from composer.json
     */
    private function detectFromComposer(string $projectPath): ?MagentoVersion
    {
        $composerPath = $projectPath . '/composer.json';
        
        if (!file_exists($composerPath)) {
            return null;
        }

        $composer = json_decode(file_get_contents($composerPath), true);
        if (!$composer) {
            return null;
        }

        // Check root version field (common in Magento Cloud projects)
        if (isset($composer['version']) && $this->isMagentoVersion($composer['version'])) {
            $this->logger->info('Detected Magento version from root version field', [
                'version' => $composer['version']
            ]);
            return $this->parseVersionString($composer['version']);
        }

        // Check magento/product-* packages
        $packages = array_merge(
            $composer['require'] ?? [],
            $composer['require-dev'] ?? []
        );

        foreach ($packages as $package => $version) {
            if (strpos($package, 'magento/product-') === 0) {
                return $this->parseVersionString($version);
            }
        }

        // Check magento/magento-cloud-metapackage (Magento Cloud projects)
        if (isset($packages['magento/magento-cloud-metapackage'])) {
            $this->logger->info('Detected Magento Cloud project', [
                'version' => $packages['magento/magento-cloud-metapackage']
            ]);
            return $this->parseVersionString($packages['magento/magento-cloud-metapackage']);
        }

        // Check magento/framework
        if (isset($packages['magento/framework'])) {
            return $this->parseVersionString($packages['magento/framework']);
        }

        return null;
    }

    /**
     * Check if a version string looks like a Magento version
     */
    private function isMagentoVersion(string $version): bool
    {
        // Magento versions are 2.x.x or 2.x.x-px
        return preg_match('/^2\.\d+\.\d+(?:-p\d+)?$/', $version) === 1;
    }

    /**
     * Detect from vendor directory
     */
    private function detectFromVendor(string $projectPath): ?MagentoVersion
    {
        $composerPath = $projectPath . '/vendor/magento/framework/composer.json';
        
        if (!file_exists($composerPath)) {
            return null;
        }

        $composer = json_decode(file_get_contents($composerPath), true);
        if (!$composer || !isset($composer['version'])) {
            return null;
        }

        return $this->parseVersionString($composer['version']);
    }

    /**
     * Detect from module.xml (fallback)
     */
    private function detectFromModuleXml(string $projectPath): ?MagentoVersion
    {
        // Directories to exclude from scanning
        $excludeDirs = ['node_modules', 'vendor', 'var', 'pub', 'generated', '.git'];
        
        // Search for module.xml files
        $directory = new \RecursiveDirectoryIterator(
            $projectPath,
            \RecursiveDirectoryIterator::SKIP_DOTS
        );
        
        $filter = new \RecursiveCallbackFilterIterator(
            $directory,
            function ($current, $key, $iterator) use ($excludeDirs) {
                // Skip excluded directories
                if ($current->isDir()) {
                    $dirName = $current->getFilename();
                    return !in_array($dirName, $excludeDirs);
                }
                return true;
            }
        );
        
        $iterator = new \RecursiveIteratorIterator($filter);

        foreach ($iterator as $file) {
            if ($file->getFilename() === 'module.xml') {
                $version = $this->parseModuleXml($file->getPathname());
                if ($version) {
                    return $version;
                }
            }
        }

        return null;
    }

    /**
     * Parse module.xml for setup_version
     */
    private function parseModuleXml(string $filePath): ?MagentoVersion
    {
        $xml = simplexml_load_file($filePath);
        if (!$xml) {
            return null;
        }

        $setupVersion = (string)$xml->module['setup_version'];
        if ($setupVersion) {
            return $this->parseVersionString($setupVersion);
        }

        return null;
    }

    /**
     * Parse version string into MagentoVersion object
     */
    private function parseVersionString(string $versionString): ?MagentoVersion
    {
        // Handle complex constraints like ">=2.4.8 <2.4.9"
        // Extract the first valid version number found
        if (preg_match('/(\d+\.\d+\.\d+(?:-p\d+)?)/', $versionString, $matches)) {
            $cleanVersion = $matches[1];
            
            // Parse version with optional security patch (e.g., 2.4.7-p2)
            if (preg_match('/^(\d+)\.(\d+)\.(\d+)(?:-p(\d+))?/', $cleanVersion, $versionMatches)) {
                $securityPatch = isset($versionMatches[4]) ? (int)$versionMatches[4] : null;
                
                $this->logger->info('Parsed Magento version', [
                    'original' => $versionString,
                    'parsed' => sprintf('%d.%d.%d%s', 
                        (int)$versionMatches[1], 
                        (int)$versionMatches[2], 
                        (int)$versionMatches[3],
                        $securityPatch ? '-p' . $securityPatch : ''
                    )
                ]);
                
                return new MagentoVersion(
                    (int)$versionMatches[1],
                    (int)$versionMatches[2],
                    (int)$versionMatches[3],
                    $securityPatch
                );
            }
        }

        $this->logger->warning('Could not parse version string', [
            'version' => $versionString
        ]);

        return null;
    }

    /**
     * Get version compatibility matrix
     */
    public function getCompatibilityMatrix(
        MagentoVersion $magento,
        PhpVersion $php
    ): CompatibilityMatrix {
        return new CompatibilityMatrix($magento, $php, $this->logger);
    }

    /**
     * Check if versions are compatible
     */
    public function areVersionsCompatible(
        MagentoVersion $magento,
        PhpVersion $php
    ): bool {
        $matrix = $this->getCompatibilityMatrix($magento, $php);
        return $matrix->isCompatible();
    }
}


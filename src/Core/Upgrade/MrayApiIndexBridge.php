<?php

declare(strict_types=1);

namespace ACQC\Core\Upgrade;

use Symfony\Component\Yaml\Yaml;

/**
 * Implementation of ApiIndexBridgeInterface that delegates to magento/mray
 * when the package is installed, and falls back to the ACQC version YAML
 * breaking_changes / deprecated_symbols data when it is not.
 *
 * ## With mray (full UCT-level analysis)
 *
 * Uses Magento\Mray\MagentoApiIndex\Api::getApiIndex($version) which reads
 * from the magento-api-index.phar. Supports:
 *   - isPresent()     — class/method/property/constant existence per version
 *   - isPublicApi()   — @api annotation per FQSEN per version
 *   - isDeprecated()  — @deprecated annotation per FQSEN per version
 *
 * Install with:
 *   composer require magento/magento-api-index:5.4.23 magento/mray:10.0.27 \
 *     --repository-url=https://repo.magento.com
 *
 * ## Without mray (YAML fallback)
 *
 * Loads breaking_changes and deprecated_symbols from
 * config/versions/adobe-commerce/<version>.yaml for each version in the
 * [sourceVersion, targetVersion] range. Covers the hand-curated data that
 * ships with ACQC (currently 46 breaking change records for 2.4.5–2.4.8).
 */
class MrayApiIndexBridge implements ApiIndexBridgeInterface
{
    private readonly bool $mrayAvailable;

    /** @var object|null Magento\Mray\MagentoApiIndex\Api */
    private readonly mixed $mrayApi;

    /** Per-version mray ApiIndexInterface cache. */
    private array $indexCache = [];

    /**
     * YAML-derived symbol tables, keyed by targetVersion.
     * Each entry: ['removed' => [fqsen => since], 'deprecated' => [fqsen => ['since'=>…,'see'=>…]]]
     */
    private array $yamlCache = [];

    public function __construct(private readonly string $registryPath)
    {
        $this->mrayAvailable = class_exists('Magento\Mray\MagentoApiIndex\Api');

        if ($this->mrayAvailable) {
            // @phpstan-ignore-next-line — optional dependency
            $this->mrayApi = new \Magento\Mray\MagentoApiIndex\Api();
        } else {
            $this->mrayApi = null;
        }
    }

    public function isAvailable(): bool
    {
        return $this->mrayAvailable;
    }

    public function isPresent(string $fqsen, string $targetVersion): bool
    {
        if ($this->mrayAvailable) {
            // null = not in the @api index but not confirmed removed; only false = positively absent
            return $this->getMrayIndex($targetVersion)->isApi($fqsen) !== false;
        }

        // Fallback: only return false when we positively know it was removed
        $symbols = $this->loadYamlSymbols($targetVersion);
        return !isset($symbols['removed'][$fqsen]);
    }

    public function isPublicApi(string $fqsen, string $targetVersion): ?bool
    {
        if ($this->mrayAvailable) {
            return $this->getMrayIndex($targetVersion)->isApi($fqsen);
        }

        return null; // Cannot determine without the index
    }

    public function isDeprecated(string $fqsen, string $targetVersion): bool
    {
        if ($this->mrayAvailable) {
            return $this->getMrayIndex($targetVersion)->isDeprecated($fqsen);
        }

        $symbols = $this->loadYamlSymbols($targetVersion);
        return isset($symbols['deprecated'][$fqsen]);
    }

    public function getDeprecationInfo(string $fqsen, string $targetVersion): ?array
    {
        if ($this->mrayAvailable) {
            return $this->getMrayIndex($targetVersion)->getDeprecationDeclaration($fqsen);
        }

        $symbols = $this->loadYamlSymbols($targetVersion);
        return $symbols['deprecated'][$fqsen] ?? null;
    }

    public function getIndexedVersions(): array
    {
        if ($this->mrayAvailable) {
            // @phpstan-ignore-next-line
            $versions = (new \Magento\Mray\MagentoApiIndex\Api\GetIndexedVersions())->execute();
            usort($versions, 'version_compare');
            return $versions;
        }

        $seedFile = $this->registryPath . '/seed.php';
        if (file_exists($seedFile)) {
            $seed     = include $seedFile;
            $versions = array_keys($seed);
            usort($versions, 'version_compare');
            return $versions;
        }

        return [];
    }

    // -------------------------------------------------------------------------
    // mray delegation
    // -------------------------------------------------------------------------

    /** @return \Magento\Mray\MagentoApiIndex\ApiIndexInterface */
    private function getMrayIndex(string $version): mixed
    {
        if (!isset($this->indexCache[$version])) {
            $this->indexCache[$version] = $this->mrayApi->getApiIndex($version);
        }
        return $this->indexCache[$version];
    }

    // -------------------------------------------------------------------------
    // YAML fallback
    // -------------------------------------------------------------------------

    /**
     * Build symbol tables from YAML version manifests up to $targetVersion.
     * Accumulates all versions <= targetVersion that have data.
     *
     * @return array{removed: array<string,string>, deprecated: array<string, array{since:string,see:string}>}
     */
    private function loadYamlSymbols(string $targetVersion): array
    {
        if (isset($this->yamlCache[$targetVersion])) {
            return $this->yamlCache[$targetVersion];
        }

        $symbols = ['removed' => [], 'deprecated' => []];
        $versionDir = $this->registryPath . '/adobe-commerce';

        if (!is_dir($versionDir)) {
            return $this->yamlCache[$targetVersion] = $symbols;
        }

        foreach (glob($versionDir . '/*.yaml') as $yamlFile) {
            $data    = Yaml::parseFile($yamlFile);
            $version = $data['version'] ?? basename($yamlFile, '.yaml');

            // Only consider versions up to the target
            if (version_compare($version, $targetVersion, '>')) {
                continue;
            }

            // breaking_changes → removed symbols
            foreach ($data['breaking_changes'] ?? [] as $bc) {
                $type   = $bc['type'] ?? '';
                $symbol = $bc['symbol'] ?? ($bc['class'] ?? '');

                if ($type !== 'method_removed' && str_ends_with($type, '_removed') && $symbol !== '') {
                    $fqsen = $this->normalizeFqsen($symbol);
                    if (!isset($symbols['removed'][$fqsen])) {
                        $symbols['removed'][$fqsen] = $version;
                    }
                }

                // method_removed: build FQSEN from class + method
                if ($type === 'method_removed') {
                    $class  = ltrim($bc['class'] ?? '', '\\');
                    $method = $bc['method'] ?? '';
                    if ($class !== '' && $method !== '') {
                        $fqsen = $class . '::' . $method;
                        if (!isset($symbols['removed'][$fqsen])) {
                            $symbols['removed'][$fqsen] = $version;
                        }
                    }
                }
            }

            // deprecated_symbols.classes
            foreach ($data['deprecated_symbols']['classes'] ?? [] as $entry) {
                $fqsen = $this->normalizeFqsen($entry['symbol'] ?? '');
                if ($fqsen === '') {
                    continue;
                }
                $symbols['deprecated'][$fqsen] = [
                    'since' => $entry['since'] ?? $version,
                    'see'   => $entry['replacement'] ?? '',
                ];
            }

            // deprecated_symbols.methods
            foreach ($data['deprecated_symbols']['methods'] ?? [] as $entry) {
                $class  = ltrim($entry['class'] ?? '', '\\');
                $method = $entry['method'] ?? '';
                if ($class === '' || $method === '') {
                    continue;
                }
                $fqsen = $class . '::' . $method;
                $symbols['deprecated'][$fqsen] = [
                    'since' => $entry['since'] ?? $version,
                    'see'   => $entry['replacement'] ?? '',
                ];
            }
        }

        return $this->yamlCache[$targetVersion] = $symbols;
    }

    private function normalizeFqsen(string $symbol): string
    {
        return ltrim(str_replace('\\\\', '\\', $symbol), '\\');
    }
}

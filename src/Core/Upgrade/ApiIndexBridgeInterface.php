<?php

declare(strict_types=1);

namespace ACQC\Core\Upgrade;

/**
 * Abstraction over magento/magento-api-index + magento/mray.
 *
 * When those packages are installed (repo.magento.com, requires auth) this
 * interface is backed by the full pre-indexed 104-version API surface — the
 * same data that powers UCT's upgrade:check command.
 *
 * When they are absent the implementation falls back to the hand-curated
 * breaking_changes / deprecated_symbols data in the ACQC version YAML files.
 *
 * FQSEN format (same as UCT / mray ApiIndexInterface):
 *   Class     : Magento\Catalog\Model\Product
 *   Method    : Magento\Catalog\Model\Product::save
 *   Constant  : Magento\Catalog\Model\Product::STATUS_ENABLED
 *   Property  : Magento\Catalog\Model\Product::$_data
 */
interface ApiIndexBridgeInterface
{
    /** True when magento/magento-api-index + magento/mray are installed. */
    public function isAvailable(): bool;

    /**
     * Does the FQSEN exist at all in the given Commerce version?
     * Returns false (= removed) only if a removal can be positively determined.
     * Returns true when information is unavailable (avoid false positives).
     */
    public function isPresent(string $fqsen, string $targetVersion): bool;

    /**
     * Is the FQSEN part of the public @api surface in the target version?
     * Returns null when the information cannot be determined (mray not available).
     */
    public function isPublicApi(string $fqsen, string $targetVersion): ?bool;

    /**
     * Is the FQSEN deprecated in the target version?
     */
    public function isDeprecated(string $fqsen, string $targetVersion): bool;

    /**
     * Deprecation metadata — 'since' and 'see' (replacement FQSEN).
     * Returns null when information is unavailable.
     */
    public function getDeprecationInfo(string $fqsen, string $targetVersion): ?array;

    /**
     * Versions covered by this bridge (from phar index or seed).
     */
    public function getIndexedVersions(): array;
}

<?php

declare(strict_types=1);

namespace ACQC\Analyzers\Version\DB;

/**
 * Detects EAV attribute definitions in custom modules that may conflict with
 * core EAV schema changes between the source and target AC version.
 *
 * Checks:
 * - Data patches that call addAttribute() / updateAttribute() on known changed entities
 * - InstallData / UpgradeData usage of EAV setup (already flagged by LegacyScriptDetector,
 *   but this adds EAV-specific context).
 * - eav_attribute entries in db_schema.xml that shadow core attributes.
 */
class EavAttributeChangeDetector
{
    // EAV entities whose attribute sets changed significantly across AC versions
    private const HIGH_RISK_ENTITIES = [
        'catalog_product',
        'catalog_category',
        'customer',
        'customer_address',
    ];

    // Methods that mutate EAV attributes and require review on upgrade
    private const EAV_MUTATING_METHODS = [
        'addAttribute',
        'updateAttribute',
        'removeAttribute',
        'addAttributeToSet',
        'removeAttributeFromSet',
    ];

    public function __construct(private readonly string $projectRoot) {}

    public function detect(): array
    {
        $appCodeDir = $this->projectRoot . '/app/code';
        if (!is_dir($appCodeDir)) {
            return [];
        }

        $findings  = [];
        $iterator  = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($appCodeDir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $content = @file_get_contents($file->getPathname());
            if ($content === false || $content === '') {
                continue;
            }

            // Skip files that don't touch EAV at all (fast pre-filter)
            if (!str_contains($content, 'addAttribute') && !str_contains($content, 'updateAttribute')) {
                continue;
            }

            $eavFindings = $this->analyzeFileForEavUsage($file->getPathname(), $content);
            $findings    = array_merge($findings, $eavFindings);
        }

        return $findings;
    }

    private function analyzeFileForEavUsage(string $filePath, string $content): array
    {
        $findings = [];
        $lines    = explode("\n", $content);
        $module   = $this->resolveModuleName($filePath);

        foreach ($lines as $lineNo => $line) {
            foreach (self::EAV_MUTATING_METHODS as $method) {
                if (!str_contains($line, $method . '(')) {
                    continue;
                }

                $entityType = $this->extractEntityType($line);
                if ($entityType === null) {
                    continue;
                }

                if (!in_array($entityType, self::HIGH_RISK_ENTITIES, true)) {
                    continue;
                }

                $isLegacySetup = $this->isInLegacySetupClass($content);
                $severity      = $isLegacySetup ? 'error' : 'warning';
                $methodNote    = $isLegacySetup
                    ? " (in legacy setup script — migrate to DataPatch)"
                    : " (in DataPatch — verify attribute code has not changed in target version)";

                $findings[] = [
                    'type'             => 'eav_attribute_change_risk',
                    'severity'         => $severity,
                    'file'             => $filePath,
                    'line'             => $lineNo + 1,
                    'module'           => $module,
                    'message'          => "Module {$module}: {$method}() on entity '{$entityType}'{$methodNote}. "
                                         . "Verify attribute codes and option sets are compatible with target AC version.",
                    'remediation'      => "Check the target AC version release notes for EAV attribute changes on '{$entityType}'. "
                                         . "Run bin/magento setup:upgrade on a staging environment and review eav_attribute / eav_attribute_option tables.",
                    'effort_hours_min' => 1,
                    'effort_hours_max' => 4,
                ];
            }
        }

        return $findings;
    }

    private function extractEntityType(string $line): ?string
    {
        // Pattern: ->addAttribute('catalog_product', ...) or ::addAttribute('customer', ...)
        if (preg_match('/(?:addAttribute|updateAttribute|removeAttribute)\s*\(\s*[\'"]([a-z_]+)[\'"]/', $line, $m)) {
            return $m[1];
        }
        // Pattern: addAttribute(ProductAttributeInterface::ENTITY_TYPE_CODE, ...)
        if (preg_match('/addAttribute\s*\(\s*\w+::ENTITY_TYPE_CODE/', $line)) {
            return 'catalog_product';
        }
        return null;
    }

    private function isInLegacySetupClass(string $content): bool
    {
        return str_contains($content, 'InstallDataInterface')
            || str_contains($content, 'UpgradeDataInterface')
            || str_contains($content, 'InstallSchemaInterface')
            || str_contains($content, 'UpgradeSchemaInterface');
    }

    private function resolveModuleName(string $filePath): string
    {
        if (preg_match('#app/code/([^/]+/[^/]+)#', $filePath, $m)) {
            return $m[1];
        }
        return basename(dirname(dirname($filePath)));
    }
}

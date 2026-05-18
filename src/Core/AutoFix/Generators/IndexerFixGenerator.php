<?php
declare(strict_types=1);

namespace ACQC\Core\AutoFix\Generators;

use ACQC\Core\AutoFix\FixCommand;
use ACQC\Core\AutoFix\FixGeneratorInterface;
use ACQC\Core\Rules\Violation;

/**
 * Fix generator for Magento indexer-related issues
 *
 * Generates automated fixes for:
 * - Switching indexers to scheduled mode
 * - Reindexing invalid indexers
 * - Running index cron to clear backlog
 * - Disabling flat catalog
 *
 * @package ACQC\Core\AutoFix\Generators
 */
class IndexerFixGenerator implements FixGeneratorInterface
{
    private const FIXABLE_RULES = [
        'indexer-update-on-save',
        'indexer-invalid-status',
        'indexer-outdated-mview',
        'flat-catalog-without-schedule',
    ];

    public function canFix(Violation $violation): bool
    {
        return in_array($violation->getRuleId(), self::FIXABLE_RULES, true);
    }

    public function generateFix(Violation $violation): ?FixCommand
    {
        return match($violation->getRuleId()) {
            'indexer-update-on-save' => $this->fixIndexerMode($violation),
            'indexer-invalid-status' => $this->fixInvalidIndexer($violation),
            'indexer-outdated-mview' => $this->fixOutdatedMview($violation),
            'flat-catalog-without-schedule' => $this->fixFlatCatalog($violation),
            default => null,
        };
    }

    public function getFixableRules(): array
    {
        return self::FIXABLE_RULES;
    }

    /**
     * Fix indexer mode by switching to scheduled mode
     */
    private function fixIndexerMode(Violation $violation): FixCommand
    {
        $metadata = $violation->getMetadata();
        $indexer = $metadata['indexer'] ?? '';

        if ($indexer) {
            // Specific indexer
            $command = "bin/magento indexer:set-mode schedule {$indexer}";
            $rollback = "bin/magento indexer:set-mode realtime {$indexer}";
            $description = "Set indexer '{$indexer}' to scheduled mode";
        } else {
            // All recommended indexers
            $indexers = 'catalog_product_price catalog_category_product cataloginventory_stock catalogsearch_fulltext';
            $command = "bin/magento indexer:set-mode schedule {$indexers}";
            $rollback = "bin/magento indexer:set-mode realtime {$indexers}";
            $description = 'Set critical indexers to scheduled mode for production';
        }

        return new FixCommand(
            command: $command,
            description: $description,
            safetyChecks: [
                'test -f bin/magento || { echo "Error: bin/magento not found"; exit 1; }',
                'bin/magento --version > /dev/null 2>&1 || { echo "Error: bin/magento not executable"; exit 1; }',
            ],
            rollback: $rollback,
            requiresConfirmation: false,
            workingDirectory: null,
            dependencies: [],
            priority: 8
        );
    }

    /**
     * Fix invalid indexer by reindexing
     */
    private function fixInvalidIndexer(Violation $violation): FixCommand
    {
        $metadata = $violation->getMetadata();
        $indexer = $metadata['indexer'] ?? '';

        if ($indexer) {
            $command = "bin/magento indexer:reindex {$indexer}";
            $description = "Reindex invalid indexer: {$indexer}";
        } else {
            // Extract from message
            preg_match('/Indexer \'([a-z_]+)\'/', $violation->getMessage(), $matches);
            $indexer = $matches[1] ?? '';
            
            if ($indexer) {
                $command = "bin/magento indexer:reindex {$indexer}";
                $description = "Reindex invalid indexer: {$indexer}";
            } else {
                // Reindex all if specific indexer not found
                $command = 'bin/magento indexer:reindex';
                $description = 'Reindex all invalid indexers';
            }
        }

        return new FixCommand(
            command: $command,
            description: $description,
            safetyChecks: [
                'test -f bin/magento || { echo "Error: bin/magento not found"; exit 1; }',
            ],
            rollback: null, // Cannot rollback reindex
            requiresConfirmation: true, // Reindexing can be slow
            workingDirectory: null,
            dependencies: [],
            priority: 9
        );
    }

    /**
     * Fix outdated mview by running index cron
     */
    private function fixOutdatedMview(Violation $violation): FixCommand
    {
        return new FixCommand(
            command: 'bin/magento cron:run --group index',
            description: 'Run index cron to process mview backlog',
            safetyChecks: [
                'test -f bin/magento || { echo "Error: bin/magento not found"; exit 1; }',
            ],
            rollback: null,
            requiresConfirmation: false,
            workingDirectory: null,
            dependencies: [],
            priority: 7
        );
    }

    /**
     * Fix flat catalog by disabling it (recommended for most stores)
     */
    private function fixFlatCatalog(Violation $violation): FixCommand
    {
        $metadata = $violation->getMetadata();
        $flatProductEnabled = $metadata['flat_product_enabled'] ?? false;
        $flatCategoryEnabled = $metadata['flat_category_enabled'] ?? false;

        $commands = [];
        $rollbackCommands = [];

        if ($flatProductEnabled) {
            $commands[] = 'bin/magento config:set catalog/frontend/flat_catalog_product 0';
            $rollbackCommands[] = 'bin/magento config:set catalog/frontend/flat_catalog_product 1';
        }

        if ($flatCategoryEnabled) {
            $commands[] = 'bin/magento config:set catalog/frontend/flat_catalog_category 0';
            $rollbackCommands[] = 'bin/magento config:set catalog/frontend/flat_catalog_category 1';
        }

        $commands[] = 'bin/magento cache:flush';
        $rollbackCommands[] = 'bin/magento cache:flush';

        $command = implode(' && ', $commands);
        $rollback = implode(' && ', $rollbackCommands);

        return new FixCommand(
            command: $command,
            description: 'Disable flat catalog tables (recommended for most stores)',
            safetyChecks: [
                'test -f bin/magento || { echo "Error: bin/magento not found"; exit 1; }',
            ],
            rollback: $rollback,
            requiresConfirmation: true, // Changes catalog behavior
            workingDirectory: null,
            dependencies: [],
            priority: 6
        );
    }
}

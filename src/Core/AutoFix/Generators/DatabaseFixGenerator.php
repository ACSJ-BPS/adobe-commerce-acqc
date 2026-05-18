<?php

declare(strict_types=1);

namespace ACQC\Core\AutoFix\Generators;

use ACQC\Core\AutoFix\FixCommand;
use ACQC\Core\AutoFix\FixGeneratorInterface;
use ACQC\Core\Rules\Violation;

/**
 * Fix generator for database performance and integrity issues
 * 
 * Generates fix commands for:
 * - Missing indexes on frequently queried columns
 * - Foreign key constraints
 * - Query optimization
 * - Database maintenance
 */
class DatabaseFixGenerator implements FixGeneratorInterface
{
    private const FIXABLE_RULES = [
        'database-missing-index',
        'database-missing-foreign-key',
        'database-slow-query',
        'database-table-not-optimized',
        'database-index-not-used',
        'performance-database-missing-index',
        'performance-n-plus-one-query',
    ];
    
    public function getName(): string
    {
        return 'database';
    }
    
    public function getSupportedRules(): array
    {
        return self::FIXABLE_RULES;
    }
    
    public function supports(Violation $violation): bool
    {
        return in_array($violation->getRuleId(), self::FIXABLE_RULES, true);
    }
    
    public function generate(Violation $violation): ?FixCommand
    {
        return match($violation->getRuleId()) {
            'database-missing-index',
            'performance-database-missing-index' => $this->fixMissingIndex($violation),
            
            'database-missing-foreign-key' => $this->fixMissingForeignKey($violation),
            
            'database-slow-query' => $this->fixSlowQuery($violation),
            
            'database-table-not-optimized' => $this->fixTableOptimization($violation),
            
            'database-index-not-used' => $this->fixUnusedIndex($violation),
            
            'performance-n-plus-one-query' => $this->fixNPlusOneQuery($violation),
            
            default => null
        };
    }
    
    private function fixMissingIndex(Violation $violation): FixCommand
    {
        // Extract table and column from violation metadata
        $metadata = $violation->getMetadata();
        $table = $metadata['table'] ?? 'unknown_table';
        $column = $metadata['column'] ?? 'unknown_column';
        $indexName = "idx_{$table}_{$column}";
        
        return new FixCommand(
            command: sprintf(
                'bin/magento setup:db-schema:upgrade && ' .
                'mysql -h"$(bin/magento config:get db/connection/default/host)" ' .
                '-u"$(bin/magento config:get db/connection/default/username)" ' .
                '-p"$(bin/magento config:get db/connection/default/password)" ' .
                '"$(bin/magento config:get db/connection/default/dbname)" ' .
                '-e "CREATE INDEX %s ON %s (%s);" 2>/dev/null || echo "Index may already exist"',
                $indexName,
                $table,
                $column
            ),
            description: "Create missing index on {$table}.{$column}",
            safetyChecks: [
                [
                    'condition' => '[ -f "bin/magento" ]',
                    'message' => 'Magento CLI not found'
                ],
                [
                    'condition' => 'command -v mysql >/dev/null 2>&1',
                    'message' => 'MySQL client not found'
                ],
            ],
            rollback: sprintf(
                'mysql -h"$(bin/magento config:get db/connection/default/host)" ' .
                '-u"$(bin/magento config:get db/connection/default/username)" ' .
                '-p"$(bin/magento config:get db/connection/default/password)" ' .
                '"$(bin/magento config:get db/connection/default/dbname)" ' .
                '-e "DROP INDEX %s ON %s;" 2>/dev/null',
                $indexName,
                $table
            ),
            requiresConfirmation: true,
            priority: 9  // High priority for performance
        );
    }
    
    private function fixMissingForeignKey(Violation $violation): FixCommand
    {
        $metadata = $violation->getMetadata();
        $table = $metadata['table'] ?? 'unknown_table';
        $column = $metadata['column'] ?? 'unknown_column';
        $referencedTable = $metadata['referenced_table'] ?? 'unknown_table';
        $referencedColumn = $metadata['referenced_column'] ?? 'id';
        $fkName = "fk_{$table}_{$column}_{$referencedTable}";
        
        return new FixCommand(
            command: sprintf(
                'bin/magento setup:db-schema:upgrade && ' .
                'mysql -h"$(bin/magento config:get db/connection/default/host)" ' .
                '-u"$(bin/magento config:get db/connection/default/username)" ' .
                '-p"$(bin/magento config:get db/connection/default/password)" ' .
                '"$(bin/magento config:get db/connection/default/dbname)" ' .
                '-e "ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s(%s) ON DELETE CASCADE;" 2>/dev/null',
                $table,
                $fkName,
                $column,
                $referencedTable,
                $referencedColumn
            ),
            description: "Create foreign key constraint on {$table}.{$column}",
            safetyChecks: [
                [
                    'condition' => '[ -f "bin/magento" ]',
                    'message' => 'Magento CLI not found'
                ],
                [
                    'condition' => 'command -v mysql >/dev/null 2>&1',
                    'message' => 'MySQL client not found'
                ],
            ],
            rollback: sprintf(
                'mysql -h"$(bin/magento config:get db/connection/default/host)" ' .
                '-u"$(bin/magento config:get db/connection/default/username)" ' .
                '-p"$(bin/magento config:get db/connection/default/password)" ' .
                '"$(bin/magento config:get db/connection/default/dbname)" ' .
                '-e "ALTER TABLE %s DROP FOREIGN KEY %s;" 2>/dev/null',
                $table,
                $fkName
            ),
            requiresConfirmation: true,
            priority: 7
        );
    }
    
    private function fixSlowQuery(Violation $violation): FixCommand
    {
        // For slow queries, we provide analysis recommendation rather than automatic fix
        return new FixCommand(
            command: 'echo "Please review the slow query log and optimize queries manually" && ' .
                    'bin/magento dev:query-log:enable && ' .
                    'echo "Query logging enabled. Review var/log/db.log after running operations"',
            description: 'Enable query logging for slow query analysis',
            safetyChecks: [
                [
                    'condition' => '[ -f "bin/magento" ]',
                    'message' => 'Magento CLI not found'
                ],
            ],
            priority: 5
        );
    }
    
    private function fixTableOptimization(Violation $violation): FixCommand
    {
        $metadata = $violation->getMetadata();
        $table = $metadata['table'] ?? 'unknown_table';
        
        return new FixCommand(
            command: sprintf(
                'mysql -h"$(bin/magento config:get db/connection/default/host)" ' .
                '-u"$(bin/magento config:get db/connection/default/username)" ' .
                '-p"$(bin/magento config:get db/connection/default/password)" ' .
                '"$(bin/magento config:get db/connection/default/dbname)" ' .
                '-e "OPTIMIZE TABLE %s;"',
                $table
            ),
            description: "Optimize database table: {$table}",
            safetyChecks: [
                [
                    'condition' => 'command -v mysql >/dev/null 2>&1',
                    'message' => 'MySQL client not found'
                ],
            ],
            priority: 4
        );
    }
    
    private function fixUnusedIndex(Violation $violation): FixCommand
    {
        $metadata = $violation->getMetadata();
        $table = $metadata['table'] ?? 'unknown_table';
        $indexName = $metadata['index_name'] ?? 'unknown_index';
        
        return new FixCommand(
            command: sprintf(
                'echo "⚠️  WARNING: Dropping unused index %s from %s" && ' .
                'echo "This can improve INSERT/UPDATE performance but may affect some queries" && ' .
                'mysql -h"$(bin/magento config:get db/connection/default/host)" ' .
                '-u"$(bin/magento config:get db/connection/default/username)" ' .
                '-p"$(bin/magento config:get db/connection/default/password)" ' .
                '"$(bin/magento config:get db/connection/default/dbname)" ' .
                '-e "DROP INDEX %s ON %s;" 2>/dev/null',
                $indexName,
                $table,
                $indexName,
                $table
            ),
            description: "Remove unused index {$indexName} from {$table}",
            safetyChecks: [
                [
                    'condition' => 'command -v mysql >/dev/null 2>&1',
                    'message' => 'MySQL client not found'
                ],
            ],
            requiresConfirmation: true,  // Requires confirmation as it could affect queries
            priority: 3
        );
    }
    
    private function fixNPlusOneQuery(Violation $violation): FixCommand
    {
        // N+1 queries typically require code changes, so we provide guidance
        $metadata = $violation->getMetadata();
        $file = $violation->getFilePath();
        $line = $violation->getLine();
        
        return new FixCommand(
            command: sprintf(
                'echo "⚠️  N+1 Query detected in %s at line %d" && ' .
                'echo "This requires code changes to eager load related data" && ' .
                'echo "Consider using:" && ' .
                'echo "  - Collection addFieldToFilter() with array of IDs" && ' .
                'echo "  - JOIN queries instead of separate queries" && ' .
                'echo "  - addAttributeToSelect() for EAV entities" && ' .
                'echo "" && ' .
                'echo "Review the code and implement proper eager loading"',
                basename($file),
                $line
            ),
            description: "N+1 query guidance for {$file}:{$line}",
            safetyChecks: [],
            priority: 6
        );
    }
}

<?php

declare(strict_types=1);

namespace ACQC\Analyzers\Magento;

use ACQC\Analyzers\AbstractAnalyzer;
use ACQC\Analyzers\AnalyzerException;
use ACQC\Core\Rules\Violation;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

class DatabaseAnalyzer extends AbstractAnalyzer
{
    private Parser $parser;
    private array $dbSchemas = [];
    private array $resourceModels = [];
    
    public function __construct(array $configuration = [], ?\Psr\Log\LoggerInterface $logger = null)
    {
        parent::__construct($configuration, $logger);
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
    }

    public function getName(): string
    {
        return 'magento-database-analyzer';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function isAvailable(): bool
    {
        return class_exists('PhpParser\ParserFactory');
    }

    protected function getDefaultConfiguration(): array
    {
        return [
            'check_db_schema' => true,
            'check_resource_models' => true,
            'check_collections' => true,
            'check_query_performance' => true,
            'check_migration_scripts' => true,
            'check_indexers' => true,
            'validate_foreign_keys' => true,
            'check_table_naming' => true,
            'severity_schema_issues' => Violation::SEVERITY_ERROR,
            'severity_performance_issues' => Violation::SEVERITY_WARNING,
            'severity_naming_issues' => Violation::SEVERITY_WARNING,
            'severity_migration_issues' => Violation::SEVERITY_ERROR,
            'max_table_name_length' => 64,
            'max_column_name_length' => 64,
            'max_index_name_length' => 64,
            'required_indexes' => [
                'created_at',
                'updated_at',
                'entity_id',
                'store_id',
                'customer_id',
                'order_id',
                'product_id',
            ],
            'performance_antipatterns' => [
                'SELECT *',
                'addFieldToFilter',
                'getSelect()->where',
                'JOIN without ON',
                'ORDER BY RAND()',
                'LIKE "%pattern%"',
            ],
            'deprecated_methods' => [
                'Zend_Db_',
                'Varien_Db_',
                'getReadConnection',
                'getWriteConnection',
                '_getReadAdapter',
                '_getWriteAdapter',
            ],
            'exclude_paths' => [
                '*/Test/*',
                '*/tests/*',
                '*/dev/*',
            ],
        ];
    }
    public function analyze(array $files, array $options = []): array
    {
        $filteredFiles = $this->filterSupportedFiles($files);
        
        if (empty($filteredFiles)) {
            return [];
        }
        
        $violations = $this->executeAnalysis($filteredFiles, $options);
        $this->lastAnalysisResults = $violations;
        
        return $violations;
    }


    protected function executeAnalysis(array $files, array $options = []): array
    {
        $this->logger->info('Starting Magento database analysis');
        
        $violations = [];
        
        // First pass: analyze db_schema.xml files
        foreach ($files as $file) {
            if (str_ends_with($file, 'db_schema.xml')) {
                $violations = array_merge($violations, $this->analyzeDbSchema($file));
            }
        }
        
        // Second pass: analyze InstallSchema/UpgradeSchema files
        foreach ($files as $file) {
            if ($this->isSchemaFile($file)) {
                $violations = array_merge($violations, $this->analyzeSchemaFile($file));
            }
        }
        
        // Third pass: analyze ResourceModel classes
        foreach ($files as $file) {
            if ($this->isResourceModelFile($file) && $this->shouldAnalyzeFile($file)) {
                $violations = array_merge($violations, $this->analyzeResourceModel($file));
            }
        }
        
        // Fourth pass: analyze Collection classes
        foreach ($files as $file) {
            if ($this->isCollectionFile($file) && $this->shouldAnalyzeFile($file)) {
                $violations = array_merge($violations, $this->analyzeCollection($file));
            }
        }
        
        $this->logger->info('Found ' . count($violations) . ' database violations');
        
        return $violations;
    }

    protected function parseOutput(string $output, array $files): array
    {
        return [];
    }

    private function analyzeDbSchema(string $filePath): array
    {
        $violations = [];
        $content = file_get_contents($filePath);
        
        if (!$content) {
            return $violations;
        }
        
        $dom = new \DOMDocument();
        if (!@$dom->loadXML($content)) {
            $violations[] = new Violation(
                'magento-db-schema-invalid-xml',
                'Invalid XML in db_schema.xml',
                $filePath,
                0,
                0,
                $this->configuration['severity_schema_issues'],
                Violation::TYPE_DATABASE
            );
            return $violations;
        }
        
        $xpath = new \DOMXPath($dom);
        
        // Analyze tables
        $tables = $xpath->query('//table');
        foreach ($tables as $table) {
            $violations = array_merge($violations, $this->analyzeTable($table, $filePath, $xpath));
        }
        
        return $violations;
    }

    private function analyzeTable(\DOMElement $table, string $filePath, \DOMXPath $xpath): array
    {
        $violations = [];
        $tableName = $table->getAttribute('name');
        
        // Check table naming conventions
        if (strlen($tableName) > $this->configuration['max_table_name_length']) {
            $violations[] = new Violation(
                'magento-db-table-name-too-long',
                "Table name '{$tableName}' exceeds maximum length of {$this->configuration['max_table_name_length']}",
                $filePath,
                0,
                0,
                $this->configuration['severity_naming_issues'],
                Violation::TYPE_DATABASE
            );
        }
        
        if (!preg_match('/^[a-z][a-z0-9_]*[a-z0-9]$/', $tableName)) {
            $violations[] = new Violation(
                'magento-db-table-naming-convention',
                "Table name '{$tableName}' doesn't follow naming conventions (lowercase with underscores)",
                $filePath,
                0,
                0,
                $this->configuration['severity_naming_issues'],
                Violation::TYPE_DATABASE
            );
        }
        
        // Analyze columns
        $columns = $xpath->query('.//column', $table);
        $columnNames = [];
        $hasEntityId = false;
        $hasCreatedAt = false;
        $hasUpdatedAt = false;
        
        foreach ($columns as $column) {
            $columnName = $column->getAttribute('name');
            $columnNames[] = $columnName;
            
            if ($columnName === 'entity_id') {
                $hasEntityId = true;
            }
            if ($columnName === 'created_at') {
                $hasCreatedAt = true;
            }
            if ($columnName === 'updated_at') {
                $hasUpdatedAt = true;
            }
            
            $violations = array_merge($violations, $this->analyzeColumn($column, $tableName, $filePath));
        }
        
        // Check for required columns
        if (!$hasEntityId && $this->shouldHaveEntityId($tableName)) {
            $violations[] = new Violation(
                'magento-db-missing-entity-id',
                "Table '{$tableName}' should have an 'entity_id' column",
                $filePath,
                0,
                0,
                $this->configuration['severity_schema_issues'],
                Violation::TYPE_LOGIC
            );
        }
        
        if (!$hasCreatedAt) {
            $violations[] = new Violation(
                'magento-db-missing-created-at',
                "Table '{$tableName}' should have a 'created_at' column for auditing",
                $filePath,
                0,
                0,
                Violation::SEVERITY_INFO,
                Violation::TYPE_MAINTAINABILITY
            );
        }
        
        if (!$hasUpdatedAt) {
            $violations[] = new Violation(
                'magento-db-missing-updated-at',
                "Table '{$tableName}' should have an 'updated_at' column for auditing",
                $filePath,
                0,
                0,
                Violation::SEVERITY_INFO,
                Violation::TYPE_MAINTAINABILITY
            );
        }
        
        // Analyze constraints
        $constraints = $xpath->query('.//constraint', $table);
        $violations = array_merge($violations, $this->analyzeConstraints($constraints, $tableName, $filePath));
        
        // Analyze indexes
        $indexes = $xpath->query('.//index', $table);
        $violations = array_merge($violations, $this->analyzeIndexes($indexes, $tableName, $columnNames, $filePath));
        
        return $violations;
    }

    private function analyzeColumn(\DOMElement $column, string $tableName, string $filePath): array
    {
        $violations = [];
        $columnName = $column->getAttribute('name');
        $columnType = $column->getAttribute('xsi:type');
        $nullable = $column->getAttribute('nullable') !== 'false';
        $unsigned = $column->getAttribute('unsigned') === 'true';
        
        // Check column naming
        if (strlen($columnName) > $this->configuration['max_column_name_length']) {
            $violations[] = new Violation(
                'magento-db-column-name-too-long',
                "Column '{$columnName}' in table '{$tableName}' exceeds maximum length",
                $filePath,
                0,
                0,
                $this->configuration['severity_naming_issues'],
                Violation::TYPE_DATABASE
            );
        }
        
        if (!preg_match('/^[a-z][a-z0-9_]*[a-z0-9]$/', $columnName)) {
            $violations[] = new Violation(
                'magento-db-column-naming-convention',
                "Column '{$columnName}' doesn't follow naming conventions",
                $filePath,
                0,
                0,
                $this->configuration['severity_naming_issues'],
                Violation::TYPE_DATABASE
            );
        }
        
        // Check data types
        if ($columnType === 'int' && !$unsigned && str_contains($columnName, '_id')) {
            $violations[] = new Violation(
                'magento-db-id-column-not-unsigned',
                "ID column '{$columnName}' should be unsigned",
                $filePath,
                0,
                0,
                $this->configuration['severity_schema_issues'],
                Violation::TYPE_LOGIC
            );
        }
        
        // Check for TEXT columns without length
        if ($columnType === 'text') {
            $length = $column->getAttribute('length');
            if (empty($length)) {
                $violations[] = new Violation(
                    'magento-db-text-column-no-length',
                    "TEXT column '{$columnName}' should specify length for better performance",
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_performance_issues'],
                    Violation::TYPE_DATABASE
                );
            }
        }
        
        return $violations;
    }

    private function analyzeConstraints(\DOMNodeList $constraints, string $tableName, string $filePath): array
    {
        $violations = [];
        
        foreach ($constraints as $constraint) {
            $constraintType = $constraint->getAttribute('xsi:type');
            $referenceTable = $constraint->getAttribute('referenceTable');
            
            if ($constraintType === 'foreign' && !empty($referenceTable)) {
                // Check foreign key naming
                $constraintId = $constraint->getAttribute('referenceId');
                if (empty($constraintId)) {
                    $violations[] = new Violation(
                        'magento-db-foreign-key-no-id',
                        "Foreign key constraint in table '{$tableName}' should have a referenceId",
                        $filePath,
                        0,
                        0,
                        $this->configuration['severity_schema_issues'],
                        Violation::TYPE_LOGIC
                    );
                }
                
                // Check cascade actions
                $onDelete = $constraint->getAttribute('onDelete');
                if (empty($onDelete)) {
                    $violations[] = new Violation(
                        'magento-db-foreign-key-no-cascade',
                        "Foreign key constraint should specify onDelete action",
                        $filePath,
                        0,
                        0,
                        $this->configuration['severity_schema_issues'],
                        Violation::TYPE_LOGIC
                    );
                }
            }
        }
        
        return $violations;
    }

    private function analyzeIndexes(\DOMNodeList $indexes, string $tableName, array $columnNames, string $filePath): array
    {
        $violations = [];
        $indexedColumns = [];
        
        foreach ($indexes as $index) {
            $indexId = $index->getAttribute('referenceId');
            
            if (strlen($indexId) > $this->configuration['max_index_name_length']) {
                $violations[] = new Violation(
                    'magento-db-index-name-too-long',
                    "Index '{$indexId}' name exceeds maximum length",
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_naming_issues'],
                    Violation::TYPE_DATABASE
                );
            }
            
            // Collect indexed columns
            $columns = $index->getElementsByTagName('column');
            foreach ($columns as $column) {
                $columnName = $column->getAttribute('name');
                $indexedColumns[] = $columnName;
            }
        }
        
        // Check for missing indexes on common columns
        foreach ($this->configuration['required_indexes'] as $requiredIndex) {
            if (in_array($requiredIndex, $columnNames) && !in_array($requiredIndex, $indexedColumns)) {
                $violations[] = new Violation(
                    'magento-db-missing-index',
                    "Column '{$requiredIndex}' in table '{$tableName}' should be indexed for performance",
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_performance_issues'],
                    Violation::TYPE_DATABASE
                );
            }
        }
        
        return $violations;
    }

    private function analyzeSchemaFile(string $filePath): array
    {
        $violations = [];
        
        // Check for deprecated schema files
        if (str_contains($filePath, 'InstallSchema') || str_contains($filePath, 'UpgradeSchema')) {
            $violations[] = new Violation(
                'magento-db-deprecated-schema-file',
                'InstallSchema/UpgradeSchema files are deprecated. Use db_schema.xml instead.',
                $filePath,
                0,
                0,
                Violation::SEVERITY_WARNING,
                Violation::TYPE_LOGIC
            );
        }
        
        return $violations;
    }

    private function analyzeResourceModel(string $filePath): array
    {
        $violations = [];
        $content = file_get_contents($filePath);
        
        if (!$content) {
            return $violations;
        }
        
        try {
            $ast = $this->parser->parse($content);
            if (!$ast) {
                return $violations;
            }
            
            $visitor = new ResourceModelAnalysisVisitor($filePath, $this->configuration);
            $traverser = new NodeTraverser();
            $traverser->addVisitor($visitor);
            $traverser->traverse($ast);
            
            $violations = array_merge($violations, $visitor->getViolations());
            
        } catch (\Exception $e) {
            $this->logger->error("Failed to parse resource model: {$filePath}", ['error' => $e->getMessage()]);
        }
        
        return $violations;
    }

    private function analyzeCollection(string $filePath): array
    {
        $violations = [];
        $content = file_get_contents($filePath);
        
        if (!$content) {
            return $violations;
        }
        
        try {
            $ast = $this->parser->parse($content);
            if (!$ast) {
                return $violations;
            }
            
            $visitor = new CollectionAnalysisVisitor($filePath, $this->configuration);
            $traverser = new NodeTraverser();
            $traverser->addVisitor($visitor);
            $traverser->traverse($ast);
            
            $violations = array_merge($violations, $visitor->getViolations());
            
        } catch (\Exception $e) {
            $this->logger->error("Failed to parse collection: {$filePath}", ['error' => $e->getMessage()]);
        }
        
        return $violations;
    }

    private function isSchemaFile(string $filePath): bool
    {
        return str_contains($filePath, 'InstallSchema') || 
               str_contains($filePath, 'UpgradeSchema') ||
               str_contains($filePath, 'InstallData') ||
               str_contains($filePath, 'UpgradeData');
    }

    private function isResourceModelFile(string $filePath): bool
    {
        return str_contains($filePath, '/ResourceModel/') && 
               str_ends_with($filePath, '.php') &&
               !str_contains($filePath, '/Collection/');
    }

    private function isCollectionFile(string $filePath): bool
    {
        return str_contains($filePath, '/Collection/') || 
               str_ends_with($filePath, '/Collection.php');
    }

    private function shouldHaveEntityId(string $tableName): bool
    {
        $entityTables = [
            'customer_entity',
            'catalog_product_entity',
            'catalog_category_entity',
            'sales_order',
            'sales_order_item',
            'sales_invoice',
            'sales_shipment',
            'sales_creditmemo',
        ];
        
        foreach ($entityTables as $entityTable) {
            if (str_contains($tableName, $entityTable)) {
                return true;
            }
        }
        
        return false;
    }

    private function shouldAnalyzeFile(string $filePath): bool
    {
        foreach ($this->configuration['exclude_paths'] as $excludePath) {
            if (fnmatch($excludePath, $filePath)) {
                return false;
            }
        }
        
        return true;
    }
}

class ResourceModelAnalysisVisitor extends NodeVisitorAbstract
{
    private string $filePath;
    private array $configuration;
    private array $violations = [];

    public function __construct(string $filePath, array $configuration)
    {
        $this->filePath = $filePath;
        $this->configuration = $configuration;
    }

    public function enterNode(Node $node): void
    {
        if ($node instanceof Node\Expr\MethodCall) {
            $this->analyzeMethodCall($node);
        }
        
        if ($node instanceof Node\Scalar\String_) {
            $this->analyzeStringLiteral($node);
        }
    }

    public function getViolations(): array
    {
        return $this->violations;
    }

    private function analyzeMethodCall(Node\Expr\MethodCall $node): void
    {
        if ($node->name instanceof Node\Identifier) {
            $methodName = $node->name->name;
            
            // Check for deprecated methods
            foreach ($this->configuration['deprecated_methods'] as $deprecated) {
                if (str_contains($methodName, $deprecated)) {
                    $this->violations[] = new Violation(
                        'magento-db-deprecated-method',
                        "Method '{$methodName}' is deprecated",
                        $this->filePath,
                        $node->getStartLine(),
                        0,
                        Violation::SEVERITY_WARNING,
                        Violation::TYPE_LOGIC
                    );
                }
            }
            
            // Check for performance anti-patterns
            if ($methodName === 'load' && $this->isInLoop($node)) {
                $this->violations[] = new Violation(
                    'magento-db-load-in-loop',
                    'Loading entities in loop can cause N+1 performance issues',
                    $this->filePath,
                    $node->getStartLine(),
                    0,
                    $this->configuration['severity_performance_issues'],
                    Violation::TYPE_DATABASE
                );
            }
        }
    }

    private function analyzeStringLiteral(Node\Scalar\String_ $node): void
    {
        $value = $node->value;
        
        // Check for SQL anti-patterns
        foreach ($this->configuration['performance_antipatterns'] as $antipattern) {
            if (str_contains(strtoupper($value), strtoupper($antipattern))) {
                $this->violations[] = new Violation(
                    'magento-db-sql-antipattern',
                    "SQL anti-pattern detected: {$antipattern}",
                    $this->filePath,
                    $node->getStartLine(),
                    0,
                    $this->configuration['severity_performance_issues'],
                    Violation::TYPE_DATABASE
                );
            }
        }
    }

    private function isInLoop(Node $node): bool
    {
        // This is a simplified check - in practice you'd traverse up the AST
        return false; // Placeholder implementation
    }
}

class CollectionAnalysisVisitor extends NodeVisitorAbstract
{
    private string $filePath;
    private array $configuration;
    private array $violations = [];

    public function __construct(string $filePath, array $configuration)
    {
        $this->filePath = $filePath;
        $this->configuration = $configuration;
    }

    public function enterNode(Node $node): void
    {
        if ($node instanceof Node\Expr\MethodCall) {
            $this->analyzeCollectionMethod($node);
        }
    }

    public function getViolations(): array
    {
        return $this->violations;
    }

    private function analyzeCollectionMethod(Node\Expr\MethodCall $node): void
    {
        if ($node->name instanceof Node\Identifier) {
            $methodName = $node->name->name;
            
            // Check for performance issues
            if ($methodName === 'addFieldToFilter' && $this->isSelectAllPattern($node)) {
                $this->violations[] = new Violation(
                    'magento-db-select-all-with-filter',
                    'Using addFieldToFilter with SELECT * can cause performance issues',
                    $this->filePath,
                    $node->getStartLine(),
                    0,
                    $this->configuration['severity_performance_issues'],
                    Violation::TYPE_DATABASE
                );
            }
            
            if ($methodName === 'getSize' && $this->isAfterLoad($node)) {
                $this->violations[] = new Violation(
                    'magento-db-getsize-after-load',
                    'Calling getSize() after loading collection is inefficient',
                    $this->filePath,
                    $node->getStartLine(),
                    0,
                    $this->configuration['severity_performance_issues'],
                    Violation::TYPE_DATABASE
                );
            }
        }
    }

    private function isSelectAllPattern(Node\Expr\MethodCall $node): bool
    {
        // Simplified check - would need more sophisticated AST analysis
        return false;
    }

    private function isAfterLoad(Node\Expr\MethodCall $node): bool
    {
        // Simplified check - would need more sophisticated AST analysis
        return false;
    }
} 
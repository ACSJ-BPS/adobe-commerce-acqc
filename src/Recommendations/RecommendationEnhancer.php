<?php
/**
 * Enhanced Recommendations Mapper
 * 
 * This script provides specific, actionable recommendations for each rule ID
 * instead of generic "optimize code" messages.
 */

declare(strict_types=1);

namespace ACQC\Recommendations;

class RecommendationEnhancer
{
    protected $recommendations = [];
    protected $ruleAliases = [];
    
    public function __construct()
    {
        $this->initializeRuleAliases();
        $this->initializeRecommendations();
    }
    
    /**
     * Map analyzer rule IDs to enhanced rule IDs
     */
    protected function initializeRuleAliases()
    {
        $this->ruleAliases = [
            // Code Quality Mappings
            'ObjectManagerUsage' => 'magento-performance-object-manager-usage',
            'LongMethod' => 'code-health-long-method',
            'MissingPhpDoc' => 'code-health-missing-type-hints',
            'DeadCode' => 'code-health-dead-code',
            'DirectSqlQuery' => 'database-direct-sql',
            'SelectStarUsage' => 'database-select-star',
            'NPlusOneQuery' => 'database-query-n-plus-one',
            'MissingApiAnnotation' => 'api-missing-versioning',
            'DirectSuperGlobalAccess' => 'security-unvalidated-input',
            'SqlInjectionRisk' => 'security-sql-injection',
            'XSSRisk' => 'security-xss-vulnerability',
            'HardcodedPath' => 'code-health-magic-numbers',
            'MissingDependencyInjection' => 'magento-performance-object-manager-usage',
            'LargeArrayOperation' => 'code-health-high-complexity',
            'LargeFile' => 'code-health-large-class',
            'MissingIndexHint' => 'database-missing-index',
            
            // GraphQL Mappings
            'MissingGraphQLAnnotation' => 'graphql-missing-schema',
            'MissingGraphQLSchema' => 'graphql-missing-schema',
            'MissingGraphQLCaching' => 'graphql-missing-caching',
            'InefficientGraphQLResolver' => 'graphql-n-plus-one',
            'MissingGraphQLErrorHandling' => 'graphql-missing-schema',
            'GraphQLNPlusOne' => 'graphql-n-plus-one',
            'MissingInputValidation' => 'security-unvalidated-input',
            'MissingErrorHandling' => 'code-health-error-suppression',
            'InconsistentApiResponse' => 'api-inconsistent-naming',
            'MissingApiVersioning' => 'api-missing-versioning',
            'DirectDbAccessInApi' => 'database-direct-sql',
            
            // Magento-specific API rules
            'magento-api-missing-api-annotation' => 'api-missing-versioning',
            'magento-api-missing-return-type' => 'code-health-missing-return-type',
            'magento-api-missing-method-docs' => 'code-health-missing-type-hints',
            'magento-api-missing-input-validation' => 'security-unvalidated-input',
            'magento-api-no-error-handling' => 'code-health-error-suppression',
            'magento-api-inconsistent-response' => 'api-inconsistent-naming',
            
            // Security Mappings
            'api-no-rate-limiting' => 'api-missing-rate-limiting',
            'csrf-token-missing' => 'security-missing-csrf',
            'unescaped-output' => 'security-xss-vulnerability',
            'sql-injection-risk' => 'security-sql-injection',
            'weak-password' => 'security-weak-password',
            'missing-authentication' => 'security-missing-authentication',
            'missing-authorization' => 'security-insufficient-authorization',
            'sensitive-data-exposed' => 'security-exposed-sensitive-data',
            'insecure-storage' => 'security-insecure-storage',
            'session-fixation' => 'security-session-fixation',
            'weak-hash-algorithm' => 'security-weak-hash',
            'missing-encryption' => 'security-missing-encryption',
            
            // Module Structure Mappings
            'missing-composer-json' => 'module-missing-composer',
            'missing-registration' => 'module-missing-registration',
            'missing-module-xml' => 'module-missing-module-xml',
            'incorrect-namespace' => 'module-incorrect-namespace',
            'missing-di-xml' => 'module-missing-di-xml',
            'circular-dependency' => 'module-circular-dependency',
            'missing-acl' => 'module-missing-acl',
            
            // Testing Mappings
            'low-test-coverage' => 'testing-low-coverage',
            'missing-unit-tests' => 'testing-missing-unit-tests',
            'missing-integration-tests' => 'testing-missing-integration-tests',
            'no-test-isolation' => 'testing-no-test-isolation',
            'deprecated-assertions' => 'testing-deprecated-assertions',
            'slow-tests' => 'testing-slow-tests',
            'missing-fixtures' => 'testing-missing-fixtures',
            'missing-mocks' => 'testing-missing-mocks',
        ];
    }
    
    protected function initializeRecommendations()
    {
        $this->recommendations = [
            // Layout Cache Issues
            'layout-missing-cache-ttl' => [
                'title' => 'Add Cache TTL to Layout Block',
                'action' => 'Add cacheable="true" and ttl attribute to block element',
                'code' => '<block class="..." name="..." cacheable="true" ttl="3600">',
                'values' => "Dynamic content: 3600s (1 hour)\nSemi-static: 21600s (6 hours)\nStatic: 86400s (24 hours)",
                'impact' => 'Reduces server load 30-50%, improves page load 200-500ms',
                'docs' => 'https://devdocs.magento.com/guides/v2.4/extension-dev-guide/cache/partial-caching.html'
            ],
            
            'layout-non-cacheable-block' => [
                'title' => 'Mark Block as Cacheable',
                'action' => 'Add cacheable="false" only when absolutely necessary, or make block cacheable',
                'code' => "<!-- Only for blocks with customer-specific data -->\n<block cacheable=\"false\">\n\n<!-- For most blocks, make them cacheable -->\n<block cacheable=\"true\" ttl=\"3600\">",
                'impact' => 'Prevents full page cache bypass, improves performance 40-60%',
                'docs' => 'https://devdocs.magento.com/guides/v2.4/extension-dev-guide/cache/page-caching/private-content.html'
            ],
            
            // OPcache Issues
            'opcache-max-files-too-low' => [
                'title' => 'Increase OPcache Max Accelerated Files',
                'action' => 'Update opcache.max_accelerated_files in PHP configuration',
                'code' => "opcache.max_accelerated_files=16229\nopcache.memory_consumption=256\nopcache.validate_timestamps=0",
                'file' => '/etc/php.ini or /etc/php.d/10-opcache.ini',
                'command' => 'sudo systemctl restart php-fpm',
                'verify' => 'php -i | grep opcache.max_accelerated_files',
                'impact' => '15-25% faster PHP execution, better memory utilization',
                'docs' => 'https://www.php.net/manual/en/opcache.configuration.php'
            ],
            
            'opcache-interned-strings-low' => [
                'title' => 'Increase OPcache Interned Strings Buffer',
                'action' => 'Increase opcache.interned_strings_buffer for better memory efficiency',
                'code' => 'opcache.interned_strings_buffer=16',
                'file' => '/etc/php.ini',
                'command' => 'sudo systemctl restart php-fpm',
                'impact' => 'Reduces memory usage, faster string operations',
                'docs' => 'https://www.php.net/manual/en/opcache.configuration.php'
            ],
            
            'opcache-interned-strings-too-low' => [
                'title' => 'Increase OPcache Interned Strings Buffer',
                'action' => 'Increase opcache.interned_strings_buffer for better memory efficiency',
                'code' => 'opcache.interned_strings_buffer=16',
                'file' => '/etc/php.ini',
                'command' => 'sudo systemctl restart php-fpm',
                'impact' => 'Reduces memory usage, faster string operations',
                'docs' => 'https://www.php.net/manual/en/opcache.configuration.php'
            ],
            
            // GraphQL Issues
            'graphql-complexity-unlimited' => [
                'title' => 'Add GraphQL Query Complexity Limit',
                'action' => 'Implement query complexity and depth limits to prevent resource exhaustion',
                'code' => '<type name="Magento\GraphQl\Model\Query\ComplexityValidator">
    <arguments>
        <argument name="queryComplexity" xsi:type="number">300</argument>
        <argument name="queryDepth" xsi:type="number">20</argument>
    </arguments>
</type>',
                'file' => 'etc/di.xml',
                'alternative' => "app/etc/env.php:\n'graphql' => ['query_complexity_limit' => 300, 'query_depth_limit' => 20]",
                'impact' => 'Prevents DoS attacks, protects server resources',
                'docs' => 'https://devdocs.magento.com/guides/v2.4/graphql/develop/create-custom-url-resolver.html'
            ],
            
            // Server Issues
            'server-php-fpm-not-running' => [
                'title' => 'CRITICAL: Start PHP-FPM Service',
                'action' => 'PHP-FPM service is not running. Start immediately.',
                'command' => "# Check status\nsudo systemctl status php-fpm\n\n# Start service\nsudo systemctl start php-fpm\n\n# Enable auto-start\nsudo systemctl enable php-fpm",
                'verify' => 'ps aux | grep php-fpm',
                'troubleshoot' => "If won't start:\n1. Check logs: tail -f /var/log/php-fpm/error.log\n2. Test config: php-fpm -t\n3. Check port: netstat -tulpn | grep 9000",
                'impact' => 'CRITICAL: Site is completely down without this',
                'priority' => 'FIX IMMEDIATELY'
            ],
            
            'server-phpfpm-not-running' => [
                'title' => 'CRITICAL: Start PHP-FPM Service',
                'action' => 'PHP-FPM service is not running. Start immediately.',
                'command' => "# Check status\nsudo systemctl status php-fpm\n\n# Start service\nsudo systemctl start php-fpm\n\n# Enable auto-start\nsudo systemctl enable php-fpm",
                'verify' => 'ps aux | grep php-fpm',
                'impact' => 'CRITICAL: Site is completely down without this',
                'priority' => 'FIX IMMEDIATELY'
            ],
            
            'server-mysql-not-running' => [
                'title' => 'CRITICAL: Start MySQL Service',
                'action' => 'MySQL/MariaDB service is not running. Start immediately.',
                'command' => "# Check status\nsudo systemctl status mysql\n\n# Start service\nsudo systemctl start mysql\n\n# Enable auto-start\nsudo systemctl enable mysql",
                'verify' => 'ps aux | grep mysql',
                'impact' => 'CRITICAL: Database unavailable, site completely down',
                'priority' => 'FIX IMMEDIATELY'
            ],
            
            // Search Engine
            'search-engine-not-elasticsearch' => [
                'title' => 'Switch to Elasticsearch for Better Search Performance',
                'action' => 'MySQL search is slow and limited. Configure Elasticsearch for production.',
                'command' => "# Configure Magento\nbin/magento config:set catalog/search/engine elasticsearch7\nbin/magento config:set catalog/search/elasticsearch7_server_hostname localhost\nbin/magento config:set catalog/search/elasticsearch7_server_port 9200\n\n# Reindex\nbin/magento indexer:reindex catalogsearch_fulltext",
                'code' => "'catalog' => ['search' => ['engine' => 'elasticsearch7']]",
                'file' => 'app/etc/env.php',
                'impact' => 'Search speed 10-50x faster, better relevance, faceted search enabled',
                'docs' => 'https://devdocs.magento.com/guides/v2.4/config-guide/elasticsearch/es-overview.html'
            ],
            
            // CDN
            'cdn-not-detected' => [
                'title' => 'Implement CDN for Static Assets',
                'action' => 'Configure CDN to serve static assets for 40-60% faster global load times',
                'steps' => "1. Choose CDN provider (Cloudflare free tier recommended)\n2. Stores → Configuration → Web → Base URLs\n3. Set 'Base URL for Static View Files'\n4. Set 'Base URL for User Media Files'\n5. Deploy static content\n6. Test: View page source, verify assets load from CDN",
                'code' => 'Unsecure: http://cdn.yourdomain.com/static/\nSecure: https://cdn.yourdomain.com/static/',
                'impact' => 'Global page load -40-60%, origin server load -60-80%, bandwidth cost -50-70%',
                'providers' => 'Cloudflare (Free), AWS CloudFront, Fastly, KeyCDN',
                'docs' => 'https://devdocs.magento.com/guides/v2.4/config-guide/cdn/cloud-fastly.html'
            ],
            
            // Magento Performance Issues
            'magento-performance-object-manager-usage' => [
                'title' => 'Avoid Direct ObjectManager Usage',
                'action' => 'Use dependency injection instead of ObjectManager::getInstance()',
                'code' => "// ❌ Bad:\n\$om = \\Magento\\Framework\\App\\ObjectManager::getInstance();\n\$product = \$om->create(Product::class);\n\n// ✅ Good:\npublic function __construct(\n    ProductFactory \$productFactory\n) {\n    \$this->productFactory = \$productFactory;\n}\n\n\$product = \$this->productFactory->create();",
                'impact' => 'Better performance, proper dependency management, easier testing',
                'docs' => 'https://devdocs.magento.com/guides/v2.4/extension-dev-guide/depend-inj.html'
            ],
            
            'magento-performance-large-dataset-loop' => [
                'title' => 'Use Collection Iterator for Large Datasets',
                'action' => 'Replace direct iteration with collection walk or chunking',
                'code' => "// ❌ Bad: Loads all records into memory\n\$collection = \$this->collectionFactory->create();\nforeach (\$collection as \$item) {\n    \$this->processItem(\$item);\n}\n\n// ✅ Good: Process in batches\n\$collection = \$this->collectionFactory->create();\n\$collection->setPageSize(1000);\n\$pages = \$collection->getLastPageNumber();\n\nfor (\$page = 1; \$page <= \$pages; \$page++) {\n    \$collection->setCurPage(\$page);\n    \$collection->load();\n    foreach (\$collection as \$item) {\n        \$this->processItem(\$item);\n    }\n    \$collection->clear();\n}",
                'impact' => 'Reduces memory usage 80-95%, prevents timeouts on large datasets',
                'docs' => 'https://devdocs.magento.com/guides/v2.4/extension-dev-guide/searching-with-repositories.html'
            ],
            
            'magento-performance-db-in-loop' => [
                'title' => 'Avoid Database Calls in Loops (N+1 Problem)',
                'action' => 'Load all required data before loop or use collection with joins',
                'code' => "// ❌ Bad: N+1 queries\nforeach (\$orders as \$order) {\n    \$customer = \$this->customerRepository->getById(\$order->getCustomerId()); // N queries\n}\n\n// ✅ Good: Single query with join\n\$collection = \$this->orderCollectionFactory->create();\n\$collection->join(\n    ['customer' => 'customer_entity'],\n    'main_table.customer_id = customer.entity_id',\n    ['firstname', 'lastname', 'email']\n);\nforeach (\$collection as \$order) {\n    \$customerName = \$order->getFirstname(); // No additional query\n}",
                'impact' => 'Reduces database queries from N+1 to 1, 80-95% faster execution',
                'docs' => 'https://devdocs.magento.com/guides/v2.4/extension-dev-guide/searching-with-repositories.html'
            ],
            
            'magento-performance-session-in-loop' => [
                'title' => 'Avoid Session Access in Loops',
                'action' => 'Get session data once before loop, not inside',
                'code' => "// ❌ Bad: Session read on every iteration\nforeach (\$items as \$item) {\n    \$customerId = \$this->customerSession->getCustomerId(); // Slow\n}\n\n// ✅ Good: Read session once\n\$customerId = \$this->customerSession->getCustomerId();\nforeach (\$items as \$item) {\n    // Use \$customerId\n}",
                'impact' => 'Reduces I/O operations, 50-70% faster loop execution',
                'docs' => 'https://devdocs.magento.com/guides/v2.4/extension-dev-guide/session.html'
            ],
            
            'magento-performance-object-creation-in-loop' => [
                'title' => 'Avoid Object Creation in Loops',
                'action' => 'Create objects outside loop when possible',
                'code' => "// ❌ Bad: Creates new object on every iteration\nforeach (\$items as \$item) {\n    \$helper = \$this->helperFactory->create(); // Expensive\n    \$result = \$helper->process(\$item);\n}\n\n// ✅ Good: Reuse object\n\$helper = \$this->helperFactory->create();\nforeach (\$items as \$item) {\n    \$result = \$helper->process(\$item);\n}",
                'impact' => 'Reduces object creation overhead, 40-60% faster',
                'docs' => 'https://devdocs.magento.com/guides/v2.4/extension-dev-guide/depend-inj.html'
            ],
            
            'magento-performance-expensive-operation-in-loop' => [
                'title' => 'Move Expensive Operations Outside Loop',
                'action' => 'Cache results or perform operations once before loop',
                'code' => "// ❌ Bad: Expensive call on every iteration\nforeach (\$products as \$product) {\n    \$storeConfig = \$this->scopeConfig->getValue('section/group/field'); // Slow\n}\n\n// ✅ Good: Get config once\n\$storeConfig = \$this->scopeConfig->getValue('section/group/field');\nforeach (\$products as \$product) {\n    // Use \$storeConfig\n}",
                'impact' => 'Reduces redundant operations, 60-80% faster',
                'docs' => 'https://devdocs.magento.com/guides/v2.4/config-guide/config/config-php.html'
            ],
            
            'magento-performance-too-many-db-calls' => [
                'title' => 'Reduce Database Calls with Batch Loading',
                'action' => 'Use collections or batch loading instead of individual loads',
                'code' => "// ❌ Bad: Multiple individual loads\nforeach (\$productIds as \$productId) {\n    \$product = \$this->productRepository->getById(\$productId); // N queries\n}\n\n// ✅ Good: Single collection query\n\$collection = \$this->productCollectionFactory->create();\n\$collection->addFieldToFilter('entity_id', ['in' => \$productIds]);\n\$products = \$collection->getItems();",
                'impact' => 'Reduces queries from N to 1, 70-90% faster',
                'docs' => 'https://devdocs.magento.com/guides/v2.4/extension-dev-guide/searching-with-repositories.html'
            ],
            
            'magento-performance-serialization-in-loop' => [
                'title' => 'Avoid Serialization in Loops',
                'action' => 'Serialize/unserialize outside loop or use different data structure',
                'code' => "// ❌ Bad: Serialization on every iteration\nforeach (\$items as \$item) {\n    \$data = json_encode(\$item->getData()); // Expensive\n}\n\n// ✅ Good: Collect first, then serialize\n\$dataArray = [];\nforeach (\$items as \$item) {\n    \$dataArray[] = \$item->getData();\n}\n\$jsonData = json_encode(\$dataArray); // Once",
                'impact' => 'Reduces CPU usage, 50-70% faster',
                'docs' => 'https://www.php.net/manual/en/function.json-encode.php'
            ],
            
            // Additional common issues
            'database-query-n-plus-one' => [
                'title' => 'Fix N+1 Query Problem',
                'action' => 'Loading items in loop causes N+1 queries. Use collection loading or join.',
                'code' => "// Bad:\nforeach (\$orders as \$order) {\n    \$customer = \$order->getCustomer(); // N queries\n}\n\n// Good:\n\$orders->addFieldToFilter('customer_id', ['in' => \$customerIds]);\n\$customers = \$customerRepository->getList(\$searchCriteria);",
                'impact' => 'Reduces database queries from N+1 to 1, 80-95% faster',
                'docs' => 'https://devdocs.magento.com/guides/v2.4/extension-dev-guide/searching-with-repositories.html'
            ],
            
            'cache-disabled' => [
                'title' => 'Enable Magento Cache Types',
                'action' => 'Critical cache types are disabled. Enable for production.',
                'command' => "# Enable all caches\nbin/magento cache:enable\n\n# Or specific caches\nbin/magento cache:enable config layout block_html full_page",
                'verify' => 'bin/magento cache:status',
                'impact' => 'Page load time -60-80%, database queries -70%',
                'docs' => 'https://devdocs.magento.com/guides/v2.4/config-guide/cli/config-cli-subcommands-cache.html'
            ],
            
            'flat-catalog-disabled' => [
                'title' => 'Enable Flat Catalog for Better Performance',
                'action' => 'Flat catalog reduces EAV queries and improves product/category load times',
                'command' => "bin/magento config:set catalog/frontend/flat_catalog_category 1\nbin/magento config:set catalog/frontend/flat_catalog_product 1\nbin/magento indexer:reindex catalog_category_flat catalog_product_flat",
                'impact' => 'Category pages 30-50% faster, product pages 20-40% faster',
                'note' => 'Requires additional disk space, increases indexing time',
                'docs' => 'https://docs.magento.com/user-guide/catalog/catalog-flat.html'
            ],
            
            // ============================================
            // SECURITY RULES (~15 rules)
            // ============================================
            
            'security-xss-vulnerability' => [
                'title' => 'Fix Cross-Site Scripting (XSS) Vulnerability',
                'action' => 'Escape all user-generated output using Magento escaper',
                'code' => "// ❌ Bad: Unescaped output\necho \$customerName;\necho '<div>' . \$userInput . '</div>';\n\n// ✅ Good: Escaped output in template\n<?= \$block->escapeHtml(\$customerName) ?>\n<?= \$block->escapeHtmlAttr(\$attribute) ?>\n<?= \$block->escapeJs(\$jsString) ?>\n<?= \$block->escapeUrl(\$url) ?>",
                'priority' => 'CRITICAL',
                'impact' => 'Prevents XSS attacks, protects customer data and sessions',
                'docs' => 'https://devdocs.magento.com/guides/v2.4/extension-dev-guide/xss-protection.html'
            ],
            
            'security-sql-injection' => [
                'title' => 'Fix SQL Injection Vulnerability',
                'action' => 'Use prepared statements and parameter binding, never concatenate SQL',
                'code' => "// ❌ Bad: SQL injection vulnerable\n\$sql = \"SELECT * FROM table WHERE id = \" . \$userId;\n\$connection->query(\$sql);\n\n// ✅ Good: Prepared statement\n\$connection->query(\n    'SELECT * FROM table WHERE id = ?',\n    [\$userId]\n);\n\n// ✅ Best: Use Magento ORM\n\$collection->addFieldToFilter('customer_id', \$userId);",
                'priority' => 'CRITICAL',
                'impact' => 'Prevents SQL injection, protects database integrity',
                'docs' => 'https://devdocs.magento.com/guides/v2.4/extension-dev-guide/security.html'
            ],
            
            'security-missing-csrf' => [
                'title' => 'Add CSRF Token Validation',
                'action' => 'Add form key validation to prevent CSRF attacks',
                'code' => "// Form (template)\n<input name=\"form_key\" type=\"hidden\" value=\"<?= \$block->escapeHtmlAttr(\$block->getFormKey()) ?>\" />\n\n// Controller validation\npublic function execute() {\n    if (!\$this->formKeyValidator->validate(\$this->getRequest())) {\n        return \$this->resultRedirectFactory->create()->setPath('*/*/');\n    }\n    // Process form\n}",
                'priority' => 'CRITICAL',
                'impact' => 'Prevents CSRF attacks, protects user actions',
                'docs' => 'https://devdocs.magento.com/guides/v2.4/extension-dev-guide/form-key.html'
            ],
            
            'security-unvalidated-input' => [
                'title' => 'Validate and Sanitize User Input',
                'action' => 'Always validate and sanitize user input before processing',
                'code' => "// ❌ Bad: No validation\n\$email = \$this->getRequest()->getParam('email');\n\$this->saveEmail(\$email);\n\n// ✅ Good: Validate input\n\$email = \$this->getRequest()->getParam('email');\nif (!\$this->validator->isValid(\$email)) {\n    throw new \\InvalidArgumentException('Invalid email');\n}\nif (!filter_var(\$email, FILTER_VALIDATE_EMAIL)) {\n    throw new \\InvalidArgumentException('Invalid email format');\n}\n\$this->saveEmail(\$email);",
                'impact' => 'Prevents injection attacks, ensures data integrity',
                'docs' => 'https://devdocs.magento.com/guides/v2.4/extension-dev-guide/validate-request.html'
            ],
            
            'security-weak-password' => [
                'title' => 'Enforce Strong Password Policy',
                'action' => 'Implement password strength requirements',
                'code' => "// Admin: Stores → Configuration → Customers → Customer Configuration → Password Options\n// - Minimum Password Length: 12\n// - Number of Required Character Classes: 3 or 4\n// - Enable password complexity check\n\n// Programmatic validation\nif (strlen(\$password) < 12) {\n    throw new \\Exception('Password must be at least 12 characters');\n}\nif (!preg_match('/[A-Z]/', \$password) || \n    !preg_match('/[a-z]/', \$password) || \n    !preg_match('/[0-9]/', \$password)) {\n    throw new \\Exception('Password must contain uppercase, lowercase, and numbers');\n}",
                'priority' => 'HIGH',
                'impact' => 'Prevents brute force attacks, protects accounts',
                'docs' => 'https://docs.magento.com/user-guide/customers/password-options.html'
            ],
            
            'security-missing-authentication' => [
                'title' => 'Add Authentication Check',
                'action' => 'Require authentication for protected resources',
                'code' => "// Controller\nclass MyController extends \\Magento\\Framework\\App\\Action\\Action\n{\n    public function execute()\n    {\n        if (!\$this->customerSession->isLoggedIn()) {\n            return \$this->resultRedirectFactory->create()\n                ->setPath('customer/account/login');\n        }\n        // Protected action\n    }\n}",
                'priority' => 'CRITICAL',
                'impact' => 'Prevents unauthorized access, protects user data',
                'docs' => 'https://devdocs.magento.com/guides/v2.4/extension-dev-guide/security.html'
            ],
            
            'security-insufficient-authorization' => [
                'title' => 'Add ACL Authorization Check',
                'action' => 'Verify user has permission before allowing action',
                'code' => "// etc/acl.xml\n<acl>\n    <resources>\n        <resource id=\"Magento_Backend::admin\">\n            <resource id=\"Vendor_Module::resource\" title=\"My Resource\"/>\n        </resource>\n    </resources>\n</acl>\n\n// Controller\nprotected function _isAllowed()\n{\n    return \$this->_authorization->isAllowed('Vendor_Module::resource');\n}",
                'priority' => 'CRITICAL',
                'impact' => 'Prevents privilege escalation, enforces access control',
                'docs' => 'https://devdocs.magento.com/guides/v2.4/ext-best-practices/tutorials/create-access-control-list-rule.html'
            ],
            
            'security-exposed-sensitive-data' => [
                'title' => 'Remove or Encrypt Sensitive Data',
                'action' => 'Never log or expose sensitive information',
                'code' => "// ❌ Bad: Logging sensitive data\n\$this->logger->info('Customer password: ' . \$password);\n\$this->logger->debug('CC: ' . \$creditCard);\n\n// ✅ Good: Don't log sensitive data\n\$this->logger->info('Customer logged in');\n\n// ✅ Good: Mask if needed\n\$masked = substr(\$creditCard, 0, 4) . '****' . substr(\$creditCard, -4);\n\$this->logger->info('Payment with card: ' . \$masked);",
                'priority' => 'CRITICAL',
                'impact' => 'Protects PCI compliance, prevents data leaks',
                'docs' => 'https://www.pcisecuritystandards.org/'
            ],
            
            'security-insecure-storage' => [
                'title' => 'Use Encrypted Storage for Sensitive Data',
                'action' => 'Encrypt sensitive configuration values',
                'code' => "// Store encrypted config\nbin/magento config:set --encrypt payment/gateway/api_key \"secret_key\"\n\n// In code - use EncryptorInterface\npublic function __construct(\n    \\Magento\\Framework\\Encryption\\EncryptorInterface \$encryptor\n) {\n    \$this->encryptor = \$encryptor;\n}\n\npublic function saveSecret(\$value) {\n    \$encrypted = \$this->encryptor->encrypt(\$value);\n    \$this->configWriter->save('path/to/config', \$encrypted);\n}",
                'priority' => 'HIGH',
                'impact' => 'Protects API keys, passwords, and sensitive config',
                'docs' => 'https://devdocs.magento.com/guides/v2.4/config-guide/prod/config-reference-sens.html'
            ],
            
            'security-session-fixation' => [
                'title' => 'Prevent Session Fixation',
                'action' => 'Regenerate session ID after login',
                'code' => "// After successful authentication\npublic function login(\$customer)\n{\n    \$this->customerSession->setCustomerDataAsLoggedIn(\$customer);\n    \$this->customerSession->regenerateId(); // Regenerate session ID\n}",
                'priority' => 'HIGH',
                'impact' => 'Prevents session hijacking attacks',
                'docs' => 'https://devdocs.magento.com/guides/v2.4/extension-dev-guide/security.html'
            ],
            
            'security-weak-hash' => [
                'title' => 'Use Strong Hashing Algorithm',
                'action' => 'Use bcrypt or argon2 for password hashing, never MD5 or SHA1',
                'code' => "// ❌ Bad: Weak hashing\n\$hash = md5(\$password); // Crackable\n\$hash = sha1(\$password); // Also weak\n\n// ✅ Good: Use Magento's encryptor\n\$hash = \$this->encryptor->getHash(\$password); // Uses bcrypt/argon2\n\n// ✅ Good: PHP native\n\$hash = password_hash(\$password, PASSWORD_ARGON2ID);",
                'priority' => 'CRITICAL',
                'impact' => 'Prevents rainbow table attacks, protects passwords',
                'docs' => 'https://www.php.net/manual/en/function.password-hash.php'
            ],
            
            'security-missing-encryption' => [
                'title' => 'Encrypt Sensitive Data at Rest',
                'action' => 'Use encryption for sensitive database fields',
                'code' => "// Use Magento encryptor\npublic function __construct(\n    \\Magento\\Framework\\Encryption\\EncryptorInterface \$encryptor\n) {\n    \$this->encryptor = \$encryptor;\n}\n\npublic function saveSensitiveData(\$data)\n{\n    \$encrypted = \$this->encryptor->encrypt(\$data);\n    \$this->resource->save(['sensitive_field' => \$encrypted]);\n}\n\npublic function getSensitiveData()\n{\n    \$encrypted = \$this->resource->load()['sensitive_field'];\n    return \$this->encryptor->decrypt(\$encrypted);\n}",
                'priority' => 'HIGH',
                'impact' => 'Protects data at rest, compliance requirement',
                'docs' => 'https://devdocs.magento.com/guides/v2.4/extension-dev-guide/encryption.html'
            ],
            
            // ============================================
            // CODE QUALITY RULES (~20 rules)
            // ============================================
            
            'code-health-high-complexity' => [
                'title' => 'Reduce Cyclomatic Complexity',
                'action' => 'Break down complex method into smaller, focused methods (target: CC < 10)',
                'code' => "// ❌ Bad: High complexity (CC > 15)\npublic function process(\$data) {\n    if (\$cond1) {\n        if (\$cond2) {\n            if (\$cond3) {\n                // deeply nested logic\n            }\n        }\n    }\n}\n\n// ✅ Good: Low complexity\npublic function process(\$data) {\n    if (!\$this->isValid(\$data)) {\n        return \$this->handleInvalid();\n    }\n    return \$this->processValid(\$data);\n}\n\nprivate function isValid(\$data) {\n    return \$data && \$this->validator->validate(\$data);\n}",
                'impact' => '50% easier to maintain, better testability, fewer bugs',
                'docs' => 'https://en.wikipedia.org/wiki/Cyclomatic_complexity'
            ],
            
            'code-health-long-method' => [
                'title' => 'Break Down Long Method',
                'action' => 'Split methods longer than 50 lines into smaller methods',
                'code' => "// ❌ Bad: 200-line method\npublic function processOrder(\$order) {\n    // 200 lines of code\n}\n\n// ✅ Good: Split into focused methods\npublic function processOrder(\$order) {\n    \$this->validateOrder(\$order);\n    \$this->reserveInventory(\$order);\n    \$this->processPayment(\$order);\n    \$this->sendConfirmation(\$order);\n}\n\nprivate function validateOrder(\$order) { /* 10-20 lines */ }\nprivate function reserveInventory(\$order) { /* 10-20 lines */ }\nprivate function processPayment(\$order) { /* 10-20 lines */ }",
                'impact' => 'Better readability, easier debugging, reusable code',
                'docs' => 'https://refactoring.guru/smells/long-method'
            ],
            
            'code-health-large-class' => [
                'title' => 'Split Large Class',
                'action' => 'Classes > 500 lines should be split by responsibility (SRP)',
                'code' => "// ❌ Bad: God class (1000+ lines)\nclass OrderProcessor {\n    // Order validation\n    // Payment processing  \n    // Inventory management\n    // Email notifications\n    // Shipping\n}\n\n// ✅ Good: Single Responsibility\nclass OrderProcessor {\n    public function __construct(\n        OrderValidator \$validator,\n        PaymentProcessor \$payment,\n        InventoryManager \$inventory,\n        NotificationService \$notifier\n    ) {}\n}",
                'impact' => 'Better maintainability, easier testing, clearer responsibilities',
                'docs' => 'https://en.wikipedia.org/wiki/Single-responsibility_principle'
            ],
            
            'code-health-deep-nesting' => [
                'title' => 'Reduce Nesting Levels',
                'action' => 'Keep nesting below 4 levels, use early returns',
                'code' => "// ❌ Bad: Deep nesting (6 levels)\nif (\$a) {\n    if (\$b) {\n        if (\$c) {\n            if (\$d) {\n                // deeply nested\n            }\n        }\n    }\n}\n\n// ✅ Good: Early returns\nif (!\$a) return;\nif (!\$b) return;\nif (!\$c) return;\nif (!\$d) return;\n// flat logic",
                'impact' => '60% better readability, easier to understand',
                'docs' => 'https://refactoring.guru/replace-nested-conditional-with-guard-clauses'
            ],
            
            'code-health-duplicate-code' => [
                'title' => 'Extract Duplicate Code',
                'action' => 'Extract repeated code into reusable methods',
                'code' => "// ❌ Bad: Duplicated logic\npublic function methodA() {\n    \$this->validate(\$data);\n    \$this->log('Processing');\n    // process A\n}\n\npublic function methodB() {\n    \$this->validate(\$data);\n    \$this->log('Processing');\n    // process B\n}\n\n// ✅ Good: Extracted common logic\nprivate function prepareProcessing(\$data) {\n    \$this->validate(\$data);\n    \$this->log('Processing');\n}\n\npublic function methodA() {\n    \$this->prepareProcessing(\$data);\n    // process A\n}",
                'impact' => 'DRY principle, easier maintenance, fewer bugs',
                'docs' => 'https://refactoring.guru/smells/duplicate-code'
            ],
            
            'code-health-dead-code' => [
                'title' => 'Remove Unused Code',
                'action' => 'Delete unused methods, classes, and variables',
                'code' => "// ❌ Bad: Unused code\nprivate function oldMethod() {\n    // Never called\n}\n\nprivate \$unusedVariable;\n\n// ✅ Good: Clean code\n// Delete unused code\n// Use version control if you need history",
                'impact' => 'Cleaner codebase, less confusion, easier maintenance',
                'docs' => 'https://refactoring.guru/smells/dead-code'
            ],
            
            'code-health-magic-numbers' => [
                'title' => 'Replace Magic Numbers with Constants',
                'action' => 'Use named constants instead of hardcoded numbers',
                'code' => "// ❌ Bad: Magic numbers\nif (\$status == 3) {\n    // What does 3 mean?\n}\n\n// ✅ Good: Named constants\nconst STATUS_PROCESSING = 3;\n\nif (\$status == self::STATUS_PROCESSING) {\n    // Clear meaning\n}",
                'impact' => 'Better readability, easier to maintain',
                'docs' => 'https://refactoring.guru/replace-magic-number-with-symbolic-constant'
            ],
            
            'code-health-long-parameter-list' => [
                'title' => 'Use Parameter Object',
                'action' => 'Methods with 4+ parameters should use data objects',
                'code' => "// ❌ Bad: Too many parameters\npublic function createOrder(\n    \$customerId, \$email, \$firstname, \$lastname,\n    \$street, \$city, \$region, \$postcode, \$country\n) {}\n\n// ✅ Good: Parameter object\npublic function createOrder(\n    OrderData \$orderData\n) {\n    // Access via \$orderData->getCustomerId()\n}",
                'impact' => 'Easier to use, fewer mistakes, extensible',
                'docs' => 'https://refactoring.guru/introduce-parameter-object'
            ],
            
            'code-health-missing-type-hints' => [
                'title' => 'Add Type Hints to Parameters',
                'action' => 'Use strict type hints for all parameters (PHP 8.1+)',
                'code' => "// ❌ Bad: No type hints\npublic function process(\$data) {}\n\n// ✅ Good: Type hints\npublic function process(array \$data): void {}\npublic function getId(): ?int {}\npublic function getName(): string {}",
                'impact' => 'Type safety, fewer runtime errors, better IDE support',
                'docs' => 'https://www.php.net/manual/en/language.types.declarations.php'
            ],
            
            'code-health-missing-return-type' => [
                'title' => 'Add Return Type Declaration',
                'action' => 'Declare return types for all methods',
                'code' => "// ❌ Bad: No return type\npublic function calculate(\$a, \$b) {\n    return \$a + \$b;\n}\n\n// ✅ Good: Return type declared\npublic function calculate(int \$a, int \$b): int {\n    return \$a + \$b;\n}",
                'impact' => 'Type safety, better documentation, IDE autocomplete',
                'docs' => 'https://www.php.net/manual/en/language.types.declarations.php'
            ],
            
            'code-health-deprecated-usage' => [
                'title' => 'Update Deprecated API Usage',
                'action' => 'Replace deprecated Magento APIs with current alternatives',
                'code' => "// ❌ Bad: Deprecated\n\$objectManager = \\Magento\\Framework\\App\\ObjectManager::getInstance();\n\n// ✅ Good: Dependency injection\npublic function __construct(\n    \\Magento\\Catalog\\Api\\ProductRepositoryInterface \$productRepository\n) {\n    \$this->productRepository = \$productRepository;\n}",
                'impact' => 'Future compatibility, avoid breaking changes',
                'docs' => 'https://devdocs.magento.com/guides/v2.4/release-notes/backward-incompatible-changes/index.html'
            ],
            
            'code-health-error-suppression' => [
                'title' => 'Remove Error Suppression',
                'action' => 'Use proper error handling instead of @ operator',
                'code' => "// ❌ Bad: Suppressing errors\n\$result = @file_get_contents(\$file);\n\n// ✅ Good: Proper error handling\ntry {\n    \$result = file_get_contents(\$file);\n} catch (\\Exception \$e) {\n    \$this->logger->error('File read failed: ' . \$e->getMessage());\n    \$result = null;\n}",
                'impact' => 'Better debugging, proper error handling',
                'docs' => 'https://www.php.net/manual/en/language.operators.errorcontrol.php'
            ],
            
            // ============================================
            // API RULES (~10 rules)
            // ============================================
            
            'api-missing-versioning' => [
                'title' => 'Implement API Versioning',
                'action' => 'Version APIs to support backwards compatibility',
                'code' => "// etc/webapi.xml\n<route url=\"/V1/products\" method=\"GET\">\n    <service class=\"Vendor\\Module\\Api\\V1\\ProductInterface\" method=\"getList\"/>\n</route>\n\n<route url=\"/V2/products\" method=\"GET\">\n    <service class=\"Vendor\\Module\\Api\\V2\\ProductInterface\" method=\"getList\"/>\n</route>",
                'impact' => 'Backwards compatibility, easier API evolution',
                'docs' => 'https://devdocs.magento.com/guides/v2.4/extension-dev-guide/service-contracts/service-to-web-service.html'
            ],
            
            'api-inconsistent-naming' => [
                'title' => 'Follow REST Naming Conventions',
                'action' => 'Use standard REST conventions for endpoints',
                'code' => "// ❌ Bad: Inconsistent naming\n/V1/getProductList\n/V1/CreateOrder\n/V1/customer_update\n\n// ✅ Good: RESTful conventions\nGET /V1/products\nPOST /V1/orders\nPUT /V1/customers/:id\nDELETE /V1/products/:id",
                'impact' => 'Better API usability, follows industry standards',
                'docs' => 'https://restfulapi.net/resource-naming/'
            ],
            
            'api-missing-pagination' => [
                'title' => 'Add Pagination Support',
                'action' => 'Implement pagination for list endpoints',
                'code' => "// API Interface\npublic function getList(\n    \\Magento\\Framework\\Api\\SearchCriteriaInterface \$searchCriteria\n): \\Magento\\Framework\\Api\\SearchResultsInterface;\n\n// Usage\nGET /V1/products?searchCriteria[pageSize]=20&searchCriteria[currentPage]=1\n\n// Response\n{\n    \"items\": [...],\n    \"search_criteria\": {...},\n    \"total_count\": 150\n}",
                'impact' => 'Better performance, prevents timeout on large datasets',
                'docs' => 'https://devdocs.magento.com/guides/v2.4/rest/retrieve-filtered-responses.html'
            ],
            
            'api-missing-filtering' => [
                'title' => 'Add Filter/Search Parameters',
                'action' => 'Support filtering and searching via query parameters',
                'code' => "// Support search criteria filters\nGET /V1/products?searchCriteria[filter_groups][0][filters][0][field]=price\n                 &searchCriteria[filter_groups][0][filters][0][value]=100\n                 &searchCriteria[filter_groups][0][filters][0][condition_type]=gt\n\n// Implementation\npublic function getList(\n    \\Magento\\Framework\\Api\\SearchCriteriaInterface \$searchCriteria\n) {\n    \$collection = \$this->collectionFactory->create();\n    foreach (\$searchCriteria->getFilterGroups() as \$filterGroup) {\n        \$this->addFilterGroupToCollection(\$filterGroup, \$collection);\n    }\n}",
                'impact' => 'Better API usability, reduces data transfer',
                'docs' => 'https://devdocs.magento.com/guides/v2.4/rest/retrieve-filtered-responses.html'
            ],
            
            'api-missing-authentication' => [
                'title' => 'Add API Authentication',
                'action' => 'Implement OAuth 2.0 or JWT authentication',
                'code' => "// etc/webapi.xml\n<route url=\"/V1/protected-resource\" method=\"GET\">\n    <service class=\"Vendor\\Module\\Api\\ResourceInterface\" method=\"get\"/>\n    <resources>\n        <resource ref=\"Vendor_Module::resource\" />\n    </resources>\n</route>\n\n// Get admin token\nPOST /V1/integration/admin/token\n{\"username\": \"admin\", \"password\": \"pass123\"}\n\n// Use token\nGET /V1/protected-resource\nAuthorization: Bearer <token>",
                'priority' => 'CRITICAL',
                'impact' => 'Prevents unauthorized access, protects data',
                'docs' => 'https://devdocs.magento.com/guides/v2.4/get-started/authentication/gs-authentication.html'
            ],
            
            'api-missing-rate-limiting' => [
                'title' => 'Implement API Rate Limiting',
                'action' => 'Add rate limiting to prevent abuse',
                'code' => "// Custom rate limiter plugin\nclass RateLimiter\n{\n    private const MAX_REQUESTS = 100;\n    private const TIME_WINDOW = 3600; // 1 hour\n    \n    public function beforeDispatch(\n        \\Magento\\Webapi\\Controller\\Rest \$subject,\n        \\Magento\\Framework\\App\\RequestInterface \$request\n    ) {\n        \$clientId = \$this->getClientId(\$request);\n        \$requests = \$this->cache->load(\"rate_limit_{\$clientId}\");\n        \n        if (\$requests >= self::MAX_REQUESTS) {\n            throw new \\Magento\\Framework\\Webapi\\Exception(\n                __('Rate limit exceeded'),\n                429\n            );\n        }\n    }\n}",
                'priority' => 'HIGH',
                'impact' => 'Prevents DoS attacks, fair resource usage',
                'docs' => 'https://devdocs.magento.com/guides/v2.4/extension-dev-guide/plugins.html'
            ],
            
            'api-missing-cors' => [
                'title' => 'Configure CORS Headers',
                'action' => 'Set proper CORS headers for API access',
                'code' => "// etc/di.xml\n<type name=\"Magento\\Framework\\App\\Response\\HeaderProvider\\XFrameOptions\">\n    <arguments>\n        <argument name=\"xFrameOpt\" xsi:type=\"string\">SAMEORIGIN</argument>\n    </arguments>\n</type>\n\n// Custom CORS plugin\npublic function afterSendResponse(\n    \\Magento\\Framework\\App\\Response\\Http \$subject\n) {\n    \$subject->setHeader('Access-Control-Allow-Origin', 'https://yourdomain.com');\n    \$subject->setHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE');\n    \$subject->setHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization');\n}",
                'impact' => 'Enables cross-origin requests, better security',
                'docs' => 'https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS'
            ],
            
            'api-insecure-http' => [
                'title' => 'Enforce HTTPS for API',
                'action' => 'Require HTTPS for all API endpoints',
                'code' => "// web/index.php or .htaccess\nif (!\$_SERVER['HTTPS']) {\n    header('HTTP/1.1 301 Moved Permanently');\n    header('Location: https://' . \$_SERVER['HTTP_HOST'] . \$_SERVER['REQUEST_URI']);\n    exit;\n}\n\n// .htaccess\nRewriteEngine On\nRewriteCond %{HTTPS} off\nRewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]",
                'priority' => 'CRITICAL',
                'impact' => 'Protects data in transit, prevents MITM attacks',
                'docs' => 'https://devdocs.magento.com/guides/v2.4/config-guide/prod/prod_deploy.html'
            ],
            
            'api-no-caching' => [
                'title' => 'Add Cache Headers to API Response',
                'action' => 'Set appropriate cache headers for GET requests',
                'code' => "// For cacheable GET requests\npublic function getList() {\n    \$response = \$this->responseFactory->create();\n    \$response->setHeader('Cache-Control', 'public, max-age=3600');\n    \$response->setHeader('ETag', md5(json_encode(\$data)));\n    return \$response;\n}\n\n// For non-cacheable endpoints\n\$response->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate');",
                'impact' => 'Reduces server load, faster response times',
                'docs' => 'https://developer.mozilla.org/en-US/docs/Web/HTTP/Caching'
            ],
            
            'api-large-payload' => [
                'title' => 'Implement Field Selection',
                'action' => 'Allow clients to specify which fields to return',
                'code' => "// Support field selection\nGET /V1/products/:id?fields=name,price,sku\n\n// Implementation\npublic function get(\$id, \$fields = null) {\n    \$product = \$this->repository->get(\$id);\n    \n    if (\$fields) {\n        \$fieldsArray = explode(',', \$fields);\n        return \$this->filterFields(\$product, \$fieldsArray);\n    }\n    \n    return \$product;\n}",
                'impact' => 'Reduces payload size 50-80%, faster responses',
                'docs' => 'https://graphql.org/learn/queries/#fields'
            ],
            
            // ============================================
            // GRAPHQL RULES (~8 rules)
            // ============================================
            
            'graphql-missing-schema' => [
                'title' => 'Define GraphQL Schema',
                'action' => 'Create schema.graphqls to define types and queries',
                'code' => "# etc/schema.graphqls\ntype Query {\n    products(\n        filter: ProductFilterInput\n        pageSize: Int = 20\n        currentPage: Int = 1\n    ): Products @resolver(class: \"Vendor\\\\Module\\\\Model\\\\Resolver\\\\Products\") @doc(description: \"Get product list\")\n}\n\ntype Products {\n    items: [ProductInterface]\n    page_info: SearchResultPageInfo\n    total_count: Int\n}\n\ninterface ProductInterface {\n    id: Int\n    sku: String\n    name: String\n    price: Float\n}",
                'impact' => 'Proper API definition, type safety, documentation',
                'docs' => 'https://devdocs.magento.com/guides/v2.4/graphql/develop/create-graphqls-file.html'
            ],
            
            'graphql-inconsistent-naming' => [
                'title' => 'Follow GraphQL Naming Conventions',
                'action' => 'Use camelCase for fields, PascalCase for types',
                'code' => "# ❌ Bad: Inconsistent naming\ntype product_data {\n    ProductID: Int\n    product_name: String\n}\n\n# ✅ Good: Consistent naming\ntype ProductData {\n    productId: Int\n    productName: String\n    createdAt: String\n}",
                'impact' => 'Better API usability, follows GraphQL best practices',
                'docs' => 'https://graphql.org/learn/best-practices/#naming-conventions'
            ],
            
            'graphql-missing-descriptions' => [
                'title' => 'Add Field Descriptions',
                'action' => 'Document all types and fields with @doc directive',
                'code' => "# etc/schema.graphqls\ntype Product @doc(description: \"Represents a product entity\") {\n    id: Int @doc(description: \"Unique product identifier\")\n    sku: String @doc(description: \"Product SKU\")\n    name: String @doc(description: \"Product name\")\n    price: Float @doc(description: \"Product price in base currency\")\n}",
                'impact' => 'Better API documentation, easier for developers',
                'docs' => 'https://devdocs.magento.com/guides/v2.4/graphql/develop/create-graphqls-file.html'
            ],
            
            'graphql-missing-deprecation' => [
                'title' => 'Mark Deprecated Fields',
                'action' => 'Use @deprecated directive for legacy fields',
                'code' => "# etc/schema.graphqls\ntype Product {\n    oldField: String @deprecated(reason: \"Use newField instead\") @doc(description: \"Legacy field\")\n    newField: String @doc(description: \"Replacement field\")\n}",
                'impact' => 'Clear migration path, maintains backwards compatibility',
                'docs' => 'https://graphql.org/learn/best-practices/#deprecation'
            ],
            
            'graphql-n-plus-one' => [
                'title' => 'Fix GraphQL N+1 Query Problem',
                'action' => 'Implement DataLoader pattern to batch database queries',
                'code' => "// ❌ Bad: N+1 queries in resolver\npublic function resolve(Field \$field, \$context, ResolveInfo \$info, array \$value = null) {\n    \$orders = [];\n    foreach (\$value['order_ids'] as \$orderId) {\n        \$orders[] = \$this->orderRepository->get(\$orderId); // N queries!\n    }\n    return \$orders;\n}\n\n// ✅ Good: Batch loading\npublic function resolve(Field \$field, \$context, ResolveInfo \$info, array \$value = null) {\n    \$orderIds = \$value['order_ids'];\n    \$searchCriteria = \$this->searchCriteriaBuilder\n        ->addFilter('entity_id', \$orderIds, 'in')\n        ->create();\n    return \$this->orderRepository->getList(\$searchCriteria)->getItems();\n}",
                'impact' => 'Reduces queries from N+1 to 1, 80-95% faster',
                'docs' => 'https://devdocs.magento.com/guides/v2.4/graphql/develop/resolvers.html'
            ],
            
            'graphql-no-depth-limit' => [
                'title' => 'Add Query Depth Limit',
                'action' => 'Limit query depth to prevent resource exhaustion',
                'code' => "// etc/di.xml\n<type name=\"Magento\\GraphQl\\Model\\Query\\DepthValidator\">\n    <arguments>\n        <argument name=\"maxQueryDepth\" xsi:type=\"number\">20</argument>\n    </arguments>\n</type>",
                'priority' => 'HIGH',
                'impact' => 'Prevents deep query attacks, protects server resources',
                'docs' => 'https://devdocs.magento.com/guides/v2.4/graphql/develop/security.html'
            ],
            
            'graphql-missing-caching' => [
                'title' => 'Implement GraphQL Field Caching',
                'action' => 'Cache resolver results using @cache directive',
                'code' => "# etc/schema.graphqls\ntype Product {\n    id: Int @doc(description: \"Product ID\")\n    description: String @resolver(class: \"Vendor\\\\Module\\\\Model\\\\Resolver\\\\Description\") @cache(cacheIdentity: \"Vendor\\\\Module\\\\Model\\\\Resolver\\\\DescriptionIdentity\")\n}\n\n// DescriptionIdentity.php\nclass DescriptionIdentity implements CacheKeyCalculatorInterface\n{\n    public function calculateCacheKey(array \$resolvedValue): string\n    {\n        return sprintf('product_desc_%s', \$resolvedValue['id']);\n    }\n}",
                'impact' => 'Faster responses, reduced database load',
                'docs' => 'https://devdocs.magento.com/guides/v2.4/graphql/develop/caching.html'
            ],
            
            // ============================================
            // DATABASE RULES (~10 rules)
            // ============================================
            
            'database-missing-index' => [
                'title' => 'Add Database Index',
                'action' => 'Create index on frequently queried columns',
                'code' => "<!-- etc/db_schema.xml -->\n<schema>\n    <table name=\"your_table\" resource=\"default\" engine=\"innodb\">\n        <column name=\"customer_id\" xsi:type=\"int\" nullable=\"false\"/>\n        <index referenceId=\"YOUR_TABLE_CUSTOMER_ID\" indexType=\"btree\">\n            <column name=\"customer_id\"/>\n        </index>\n    </table>\n</schema>\n\n<!-- After adding, run: -->\nbin/magento setup:db-schema:upgrade",
                'impact' => 'Query speed 10-100x faster, eliminates table scans',
                'docs' => 'https://devdocs.magento.com/guides/v2.4/extension-dev-guide/declarative-schema/db-schema.html'
            ],
            
            'database-missing-foreign-key' => [
                'title' => 'Add Foreign Key Constraint',
                'action' => 'Define foreign key relationships for data integrity',
                'code' => "<!-- etc/db_schema.xml -->\n<constraint xsi:type=\"foreign\" \n    referenceId=\"YOUR_TABLE_CUSTOMER_ID_CUSTOMER_ENTITY_ENTITY_ID\"\n    table=\"your_table\" \n    column=\"customer_id\" \n    referenceTable=\"customer_entity\" \n    referenceColumn=\"entity_id\" \n    onDelete=\"CASCADE\"/>",
                'impact' => 'Data integrity, referential consistency, automatic cleanup',
                'docs' => 'https://devdocs.magento.com/guides/v2.4/extension-dev-guide/declarative-schema/db-schema.html'
            ],
            
            'database-wrong-column-type' => [
                'title' => 'Use Appropriate Column Type',
                'action' => 'Choose optimal column types for better performance',
                'code' => "<!-- ❌ Bad: Inefficient types -->\n<column name=\"price\" xsi:type=\"varchar\" length=\"255\"/>\n<column name=\"is_active\" xsi:type=\"varchar\" length=\"10\"/>\n<column name=\"created_at\" xsi:type=\"varchar\" length=\"50\"/>\n\n<!-- ✅ Good: Optimal types -->\n<column name=\"price\" xsi:type=\"decimal\" precision=\"12\" scale=\"4\"/>\n<column name=\"is_active\" xsi:type=\"boolean\"/>\n<column name=\"created_at\" xsi:type=\"timestamp\" default=\"CURRENT_TIMESTAMP\"/>",
                'impact' => 'Better performance, less storage, proper data validation',
                'docs' => 'https://devdocs.magento.com/guides/v2.4/extension-dev-guide/declarative-schema/db-schema.html'
            ],
            
            'database-missing-primary-key' => [
                'title' => 'Add Primary Key',
                'action' => 'Every table must have a primary key',
                'code' => "<!-- etc/db_schema.xml -->\n<table name=\"your_table\">\n    <column name=\"entity_id\" xsi:type=\"int\" identity=\"true\" nullable=\"false\"/>\n    <constraint xsi:type=\"primary\" referenceId=\"PRIMARY\">\n        <column name=\"entity_id\"/>\n    </constraint>\n</table>",
                'priority' => 'HIGH',
                'impact' => 'Data integrity, faster lookups, enables relationships',
                'docs' => 'https://devdocs.magento.com/guides/v2.4/extension-dev-guide/declarative-schema/db-schema.html'
            ],
            
            'database-select-star' => [
                'title' => 'Select Specific Columns Only',
                'action' => 'Avoid SELECT *, specify needed columns',
                'code' => "// ❌ Bad: SELECT *\n\$connection->fetchAll('SELECT * FROM catalog_product_entity');\n\n// ✅ Good: Specific columns\n\$connection->fetchAll(\n    'SELECT entity_id, sku, type_id FROM catalog_product_entity WHERE status = ?',\n    [1]\n);",
                'impact' => 'Reduces data transfer 50-90%, better performance',
                'docs' => 'https://devdocs.magento.com/guides/v2.4/extension-dev-guide/resource-model.html'
            ],
            
            'database-missing-limit' => [
                'title' => 'Add LIMIT to Queries',
                'action' => 'Always use LIMIT for potentially large result sets',
                'code' => "// ❌ Bad: No limit\n\$collection = \$this->collectionFactory->create();\n\$items = \$collection->getItems();\n\n// ✅ Good: With limit\n\$collection = \$this->collectionFactory->create();\n\$collection->setPageSize(100);\n\$collection->setCurPage(1);\n\$items = \$collection->getItems();",
                'impact' => 'Prevents memory exhaustion, faster queries',
                'docs' => 'https://devdocs.magento.com/guides/v2.4/extension-dev-guide/searching-with-repositories.html'
            ],
            
            'database-inefficient-join' => [
                'title' => 'Optimize JOIN Strategy',
                'action' => 'Use appropriate JOIN type and ensure indexes exist',
                'code' => "// ❌ Bad: Unindexed join\n\$collection->getSelect()->join(\n    ['ce' => 'customer_entity'],\n    'main_table.customer_email = ce.email' // Not indexed!\n);\n\n// ✅ Good: Join on indexed column\n\$collection->getSelect()->join(\n    ['ce' => 'customer_entity'],\n    'main_table.customer_id = ce.entity_id' // Primary key!\n);",
                'impact' => 'Faster queries, better use of indexes',
                'docs' => 'https://devdocs.magento.com/guides/v2.4/extension-dev-guide/searching-with-repositories.html'
            ],
            
            'database-table-scan' => [
                'title' => 'Add Index to Prevent Table Scan',
                'action' => 'Queries using WHERE on unindexed columns cause full table scans',
                'code' => "// Query causing table scan:\nSELECT * FROM sales_order WHERE customer_email = 'customer@example.com';\n\n// Solution: Add index\n<!-- etc/db_schema.xml -->\n<index referenceId=\"SALES_ORDER_CUSTOMER_EMAIL\" indexType=\"btree\">\n    <column name=\"customer_email\"/>\n</index>",
                'impact' => 'Query speed 50-500x faster, eliminates table scans',
                'docs' => 'https://dev.mysql.com/doc/refman/8.0/en/optimization-indexes.html'
            ],
            
            'database-direct-sql' => [
                'title' => 'Use Magento ORM Instead of Direct SQL',
                'action' => 'Use collections and repositories instead of raw SQL',
                'code' => "// ❌ Bad: Direct SQL\n\$connection = \$this->resource->getConnection();\n\$sql = \"SELECT * FROM catalog_product_entity WHERE sku = 'ABC123'\";\n\$result = \$connection->fetchAll(\$sql);\n\n// ✅ Good: Use repository\n\$product = \$this->productRepository->get('ABC123');\n\n// ✅ Good: Use collection\n\$collection = \$this->productCollectionFactory->create();\n\$collection->addFieldToFilter('sku', 'ABC123');\n\$products = \$collection->getItems();",
                'impact' => 'Better maintainability, EAV support, cache integration',
                'docs' => 'https://devdocs.magento.com/guides/v2.4/extension-dev-guide/searching-with-repositories.html'
            ],
            
            'database-deprecated-api' => [
                'title' => 'Update Deprecated Database API',
                'action' => 'Replace deprecated Magento database methods',
                'code' => "// ❌ Bad: Deprecated\n\$this->resource->getTableName('core_config_data');\n\$this->resource->getIdFieldName();\n\n// ✅ Good: Current API\n\$this->resource->getTable('core_config_data');\n\$connection->getAutoIncrementField(\$tableName);",
                'impact' => 'Future compatibility, follows best practices',
                'docs' => 'https://devdocs.magento.com/guides/v2.4/extension-dev-guide/resource-model.html'
            ],
            
            // ============================================
            // MODULE STRUCTURE RULES (~8 rules)
            // ============================================
            
            'module-missing-composer' => [
                'title' => 'Add composer.json to Module',
                'action' => 'Create composer.json for proper dependency management',
                'code' => "{\n    \"name\": \"vendor/module-name\",\n    \"description\": \"Module description\",\n    \"type\": \"magento2-module\",\n    \"version\": \"1.0.0\",\n    \"license\": \"proprietary\",\n    \"require\": {\n        \"php\": \"~8.1.0||~8.2.0||~8.3.0\",\n        \"magento/framework\": \"*\"\n    },\n    \"autoload\": {\n        \"psr-4\": {\n            \"Vendor\\\\ModuleName\\\\\": \"\"\n        },\n        \"files\": [\"registration.php\"]\n    }\n}",
                'impact' => 'Proper dependency management, easier installation',
                'docs' => 'https://devdocs.magento.com/guides/v2.4/extension-dev-guide/build/composer-integration.html'
            ],
            
            'module-missing-registration' => [
                'title' => 'Add registration.php',
                'action' => 'Create registration.php to register module with Magento',
                'code' => "<?php\n// registration.php\nuse Magento\\Framework\\Component\\ComponentRegistrar;\n\nComponentRegistrar::register(\n    ComponentRegistrar::MODULE,\n    'Vendor_ModuleName',\n    __DIR__\n);",
                'priority' => 'CRITICAL',
                'impact' => 'Module will not be recognized without this file',
                'docs' => 'https://devdocs.magento.com/guides/v2.4/extension-dev-guide/build/component-registration.html'
            ],
            
            'module-missing-module-xml' => [
                'title' => 'Add module.xml Configuration',
                'action' => 'Create etc/module.xml to define module metadata',
                'code' => "<?xml version=\"1.0\"?>\n<!-- etc/module.xml -->\n<config xmlns:xsi=\"http://www.w3.org/2001/XMLSchema-instance\"\n        xsi:noNamespaceSchemaLocation=\"urn:magento:framework:Module/etc/module.xsd\">\n    <module name=\"Vendor_ModuleName\" setup_version=\"1.0.0\">\n        <sequence>\n            <module name=\"Magento_Catalog\"/>\n            <module name=\"Magento_Customer\"/>\n        </sequence>\n    </module>\n</config>",
                'priority' => 'CRITICAL',
                'impact' => 'Defines module dependencies and load order',
                'docs' => 'https://devdocs.magento.com/guides/v2.4/extension-dev-guide/build/create_component.html'
            ],
            
            'module-incorrect-namespace' => [
                'title' => 'Fix Module Namespace',
                'action' => 'Use proper Vendor_Module namespace format',
                'code' => "// ❌ Bad: Incorrect namespace\nnamespace App\\Code\\MyModule;\nnamespace MyCompany\\MyModule; // Missing separation\n\n// ✅ Good: Proper namespace\nnamespace Vendor\\ModuleName\\Model;\nnamespace Vendor\\ModuleName\\Controller\\Index;",
                'impact' => 'Follows PSR-4, prevents naming conflicts',
                'docs' => 'https://www.php-fig.org/psr/psr-4/'
            ],
            
            'module-missing-di-xml' => [
                'title' => 'Add Dependency Injection Configuration',
                'action' => 'Create etc/di.xml for dependency injection setup',
                'code' => "<?xml version=\"1.0\"?>\n<!-- etc/di.xml -->\n<config xmlns:xsi=\"http://www.w3.org/2001/XMLSchema-instance\"\n        xsi:noNamespaceSchemaLocation=\"urn:magento:framework:ObjectManager/etc/config.xsd\">\n    <!-- Preference -->\n    <preference for=\"Vendor\\ModuleName\\Api\\DataInterface\"\n                type=\"Vendor\\ModuleName\\Model\\Data\"/>\n    \n    <!-- Plugin -->\n    <type name=\"Magento\\Catalog\\Model\\Product\">\n        <plugin name=\"vendor_module_product_plugin\"\n                type=\"Vendor\\ModuleName\\Plugin\\ProductPlugin\"/>\n    </type>\n</config>",
                'impact' => 'Proper dependency management, enables plugins and preferences',
                'docs' => 'https://devdocs.magento.com/guides/v2.4/extension-dev-guide/depend-inj.html'
            ],
            
            'module-circular-dependency' => [
                'title' => 'Remove Circular Module Dependencies',
                'action' => 'Module A depends on B, and B depends on A - break the cycle',
                'code' => "// ❌ Bad: Circular dependency\n// ModuleA/etc/module.xml depends on ModuleB\n// ModuleB/etc/module.xml depends on ModuleA\n\n// ✅ Good: Create shared ModuleC\n// ModuleA depends on ModuleC\n// ModuleB depends on ModuleC\n// ModuleC has shared interfaces/abstractions",
                'impact' => 'Better architecture, easier testing, prevents load issues',
                'docs' => 'https://devdocs.magento.com/guides/v2.4/extension-dev-guide/build/module-file-structure.html'
            ],
            
            'module-missing-acl' => [
                'title' => 'Add ACL Resources',
                'action' => 'Define admin ACL resources in etc/acl.xml',
                'code' => "<?xml version=\"1.0\"?>\n<!-- etc/acl.xml -->\n<config xmlns:xsi=\"http://www.w3.org/2001/XMLSchema-instance\"\n        xsi:noNamespaceSchemaLocation=\"urn:magento:framework:Acl/etc/acl.xsd\">\n    <acl>\n        <resources>\n            <resource id=\"Magento_Backend::admin\">\n                <resource id=\"Vendor_ModuleName::menu\" title=\"Module Menu\" sortOrder=\"50\">\n                    <resource id=\"Vendor_ModuleName::manage\" title=\"Manage Items\" sortOrder=\"10\"/>\n                    <resource id=\"Vendor_ModuleName::config\" title=\"Configuration\" sortOrder=\"20\"/>\n                </resource>\n            </resource>\n        </resources>\n    </acl>\n</config>",
                'impact' => 'Proper admin permissions, security compliance',
                'docs' => 'https://devdocs.magento.com/guides/v2.4/ext-best-practices/tutorials/create-access-control-list-rule.html'
            ],
            
            'module-missing-readme' => [
                'title' => 'Add Module README Documentation',
                'action' => 'Create README.md with installation and usage instructions',
                'code' => "# Module Name\n\n## Description\nBrief description of what the module does.\n\n## Installation\n```bash\ncomposer require vendor/module-name\nbin/magento module:enable Vendor_ModuleName\nbin/magento setup:upgrade\n```\n\n## Configuration\nStores → Configuration → Section → Group\n\n## Usage\nHow to use the module.\n\n## Support\nContact information or link to support.",
                'impact' => 'Better documentation, easier onboarding, professional appearance',
                'docs' => 'https://devdocs.magento.com/marketplace/sellers/module-develop.html'
            ],
            
            // ============================================
            // TESTING RULES (~8 rules)
            // ============================================
            
            'testing-low-coverage' => [
                'title' => 'Increase Test Coverage',
                'action' => 'Add unit tests to reach 80%+ code coverage',
                'code' => "// tests/Unit/Model/ProductTest.php\nnamespace Vendor\\Module\\Test\\Unit\\Model;\n\nuse PHPUnit\\Framework\\TestCase;\nuse Vendor\\Module\\Model\\Product;\n\nclass ProductTest extends TestCase\n{\n    private \$product;\n    \n    protected function setUp(): void\n    {\n        \$this->product = new Product();\n    }\n    \n    public function testGetName()\n    {\n        \$this->product->setName('Test Product');\n        \$this->assertEquals('Test Product', \$this->product->getName());\n    }\n}",
                'command' => 'vendor/bin/phpunit --coverage-html build/coverage',
                'impact' => 'Catch bugs early, easier refactoring, better code quality',
                'docs' => 'https://devdocs.magento.com/guides/v2.4/test/testing.html'
            ],
            
            'testing-missing-unit-tests' => [
                'title' => 'Add Unit Tests',
                'action' => 'Create unit tests for business logic and models',
                'code' => "// tests/Unit/Helper/DataTest.php\nnamespace Vendor\\Module\\Test\\Unit\\Helper;\n\nuse PHPUnit\\Framework\\TestCase;\nuse Vendor\\Module\\Helper\\Data;\n\nclass DataTest extends TestCase\n{\n    public function testCalculateDiscount()\n    {\n        \$helper = \$this->createMock(Data::class);\n        \$helper->method('calculateDiscount')\n              ->willReturn(10.00);\n              \n        \$this->assertEquals(10.00, \$helper->calculateDiscount(100, 10));\n    }\n}",
                'impact' => 'Fast tests, no dependencies, catch logic errors',
                'docs' => 'https://devdocs.magento.com/guides/v2.4/test/unit/unit_test_execution.html'
            ],
            
            'testing-missing-integration-tests' => [
                'title' => 'Add Integration Tests',
                'action' => 'Test interactions between components with database',
                'code' => "// tests/Integration/Model/ProductRepositoryTest.php\nnamespace Vendor\\Module\\Test\\Integration\\Model;\n\nuse Magento\\TestFramework\\Helper\\Bootstrap;\nuse PHPUnit\\Framework\\TestCase;\n\nclass ProductRepositoryTest extends TestCase\n{\n    private \$productRepository;\n    \n    protected function setUp(): void\n    {\n        \$objectManager = Bootstrap::getObjectManager();\n        \$this->productRepository = \$objectManager->get(\n            \\Magento\\Catalog\\Api\\ProductRepositoryInterface::class\n        );\n    }\n    \n    public function testGetProduct()\n    {\n        \$product = \$this->productRepository->get('test-sku');\n        \$this->assertNotNull(\$product);\n    }\n}",
                'impact' => 'Tests real interactions, catches integration bugs',
                'docs' => 'https://devdocs.magento.com/guides/v2.4/test/integration/integration_test_execution.html'
            ],
            
            'testing-no-test-isolation' => [
                'title' => 'Ensure Test Isolation',
                'action' => 'Each test should be independent and not affect others',
                'code' => "// ❌ Bad: Tests share state\nclass BadTest extends TestCase\n{\n    private static \$sharedData;\n    \n    public function testA() {\n        self::\$sharedData = 'value';\n    }\n    \n    public function testB() {\n        // Depends on testA!\n    }\n}\n\n// ✅ Good: Isolated tests\nclass GoodTest extends TestCase\n{\n    protected function setUp(): void\n    {\n        // Fresh state for each test\n        \$this->data = \$this->createTestData();\n    }\n}",
                'impact' => 'Reliable tests, can run in any order, easier debugging',
                'docs' => 'https://phpunit.de/manual/current/en/fixtures.html'
            ],
            
            'testing-deprecated-assertions' => [
                'title' => 'Update Deprecated Test Assertions',
                'action' => 'Replace deprecated PHPUnit assertions with current ones',
                'code' => "// ❌ Bad: Deprecated\n\$this->assertEquals(true, \$result);\n\$this->assertEquals(false, \$result);\n\$this->assertEquals(null, \$result);\n\n// ✅ Good: Current assertions\n\$this->assertTrue(\$result);\n\$this->assertFalse(\$result);\n\$this->assertNull(\$result);",
                'impact' => 'Future compatibility, clearer test intent',
                'docs' => 'https://phpunit.de/announcements.html'
            ],
            
            'testing-slow-tests' => [
                'title' => 'Optimize Slow Tests',
                'action' => 'Mock external dependencies and database calls',
                'code' => "// ❌ Bad: Slow test (real HTTP call)\npublic function testApiCall()\n{\n    \$result = \$this->apiClient->fetchData('https://api.example.com');\n    \$this->assertNotEmpty(\$result);\n}\n\n// ✅ Good: Fast test (mocked)\npublic function testApiCall()\n{\n    \$apiClient = \$this->createMock(ApiClient::class);\n    \$apiClient->method('fetchData')\n              ->willReturn(['data' => 'test']);\n              \n    \$this->assertNotEmpty(\$apiClient->fetchData('url'));\n}",
                'impact' => 'Test suite runs 10-100x faster, better developer experience',
                'docs' => 'https://phpunit.de/manual/current/en/test-doubles.html'
            ],
            
            'testing-missing-fixtures' => [
                'title' => 'Add Test Fixtures',
                'action' => 'Create reusable test data fixtures',
                'code' => "// tests/_files/product_fixture.php\n/** @var \\Magento\\Catalog\\Model\\Product \$product */\n\$product = \\Magento\\TestFramework\\Helper\\Bootstrap::getObjectManager()\n    ->create(\\Magento\\Catalog\\Model\\Product::class);\n\$product->setTypeId('simple')\n    ->setAttributeSetId(4)\n    ->setName('Test Product')\n    ->setSku('test-sku')\n    ->setPrice(10)\n    ->setVisibility(4)\n    ->setStatus(1);\n\$product->save();\n\n// Use in test\n/**\n * @magentoDataFixture product_fixture.php\n */\npublic function testProduct() {\n    // Product is loaded\n}",
                'impact' => 'Reusable test data, consistent test environment',
                'docs' => 'https://devdocs.magento.com/guides/v2.4/test/integration/annotations/magento-data-fixture.html'
            ],
            
            'testing-missing-mocks' => [
                'title' => 'Use Mocks for Dependencies',
                'action' => 'Mock dependencies instead of using real objects',
                'code' => "// ❌ Bad: Real dependencies\npublic function testCalculate()\n{\n    \$calculator = new Calculator(\n        new Logger(),\n        new Database(),\n        new Cache()\n    );\n    \$result = \$calculator->calculate(5, 10);\n}\n\n// ✅ Good: Mocked dependencies\npublic function testCalculate()\n{\n    \$logger = \$this->createMock(Logger::class);\n    \$database = \$this->createMock(Database::class);\n    \$cache = \$this->createMock(Cache::class);\n    \n    \$calculator = new Calculator(\$logger, \$database, \$cache);\n    \$result = \$calculator->calculate(5, 10);\n    \n    \$this->assertEquals(15, \$result);\n}",
                'impact' => 'Faster tests, better isolation, no side effects',
                'docs' => 'https://phpunit.de/manual/current/en/test-doubles.html'
            ],
        ];
    }
    
    public function getRecommendation(string $ruleId, array $context = []): string
    {
        // Check if this rule ID is an alias
        if (isset($this->ruleAliases[$ruleId])) {
            $ruleId = $this->ruleAliases[$ruleId];
        }
        
        if (!isset($this->recommendations[$ruleId])) {
            return 'Optimize code for better performance and efficiency';
        }
        
        $rec = $this->recommendations[$ruleId];
        return $this->formatRecommendation($rec, $context);
    }
    
    protected function formatRecommendation(array $rec, array $context): string
    {
        $formatted = "**{$rec['title']}**\n\n";
        
        if (isset($rec['action'])) {
            $formatted .= "Action: {$rec['action']}\n\n";
        }
        
        if (isset($rec['code'])) {
            $formatted .= "Code:\n{$rec['code']}\n\n";
        }
        
        if (isset($rec['steps'])) {
            $formatted .= "Steps:\n{$rec['steps']}\n\n";
        }
        
        if (isset($rec['command'])) {
            $formatted .= "Commands:\n{$rec['command']}\n\n";
        }
        
        if (isset($rec['file'])) {
            $formatted .= "File: {$rec['file']}\n";
        }
        
        if (isset($rec['verify'])) {
            $formatted .= "Verify: {$rec['verify']}\n";
        }
        
        if (isset($rec['impact'])) {
            $formatted .= "\nImpact: {$rec['impact']}\n";
        }
        
        if (isset($rec['docs'])) {
            $formatted .= "Docs: {$rec['docs']}\n";
        }
        
        if (isset($rec['priority'])) {
            $formatted .= "\n⚠️ PRIORITY: {$rec['priority']}\n";
        }
        
        return $formatted;
    }
    
    public function getAllRuleIds(): array
    {
        return array_keys($this->recommendations);
    }
    
    public function exportToJson(string $filepath): void
    {
        file_put_contents($filepath, json_encode($this->recommendations, JSON_PRETTY_PRINT));
    }
}

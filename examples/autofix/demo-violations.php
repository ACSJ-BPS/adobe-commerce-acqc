<?php

/**
 * Demo: Automated Fix Generation
 * 
 * This script demonstrates the automated fix generation feature by creating
 * sample violations and generating a fix script for them.
 * 
 * Usage:
 *   php examples/autofix/demo-violations.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use M2Quality\Core\AutoFix\FixScriptGenerator;
use M2Quality\Core\AutoFix\Generators\MagentoConfigFixGenerator;
use M2Quality\Core\AutoFix\Generators\DatabaseFixGenerator;
use M2Quality\Core\AutoFix\Generators\CacheFixGenerator;
use M2Quality\Core\Rules\Violation;

echo "🚀 M2-Quality Automated Fix Generation Demo\n";
echo str_repeat('=', 60) . "\n\n";

// Create sample violations
$violations = [
    // Magento Configuration Issues
    (new Violation(
        'config-flat-catalog-disabled',
        'Flat catalog is disabled for products, causing slow category/search pages',
        'app/etc/config.php',
        15,
        0,
        Violation::SEVERITY_WARNING,
        Violation::TYPE_PERFORMANCE
    ))->setCategory('performance'),
    
    (new Violation(
        'config-cache-disabled',
        'Magento caches are disabled, severely impacting performance',
        'app/etc/env.php',
        45,
        0,
        Violation::SEVERITY_CRITICAL,
        Violation::TYPE_PERFORMANCE
    ))->setCategory('performance'),
    
    (new Violation(
        'config-merge-css-disabled',
        'CSS file merging is disabled, causing multiple HTTP requests',
        'app/etc/config.php',
        67,
        0,
        Violation::SEVERITY_WARNING,
        Violation::TYPE_PERFORMANCE
    ))->setCategory('performance'),
    
    (new Violation(
        'config-merge-js-disabled',
        'JavaScript file merging is disabled, causing multiple HTTP requests',
        'app/etc/config.php',
        68,
        0,
        Violation::SEVERITY_WARNING,
        Violation::TYPE_PERFORMANCE
    ))->setCategory('performance'),
    
    // Cache Issues
    (new Violation(
        'cache-fpc-not-enabled',
        'Full Page Cache is not enabled, every page is rendered on request',
        'app/etc/env.php',
        89,
        0,
        Violation::SEVERITY_CRITICAL,
        Violation::TYPE_PERFORMANCE
    ))->setCategory('performance'),
    
    (new Violation(
        'cache-redis-not-configured',
        'Redis is not configured for caching, using slower file-based cache',
        'app/etc/env.php',
        90,
        0,
        Violation::SEVERITY_ERROR,
        Violation::TYPE_PERFORMANCE
    ))->setCategory('performance'),
    
    // Database Issues
    (new Violation(
        'database-missing-index',
        'Missing index on catalog_product_entity.sku column causing slow queries',
        'app/code/Vendor/Module/Model/Product.php',
        45,
        0,
        Violation::SEVERITY_ERROR,
        Violation::TYPE_PERFORMANCE
    ))->setCategory('performance')
        ->setMetadata([
            'table' => 'catalog_product_entity',
            'column' => 'sku',
        ]),
    
    (new Violation(
        'performance-n-plus-one-query',
        'N+1 query detected: loading products in loop without eager loading',
        'app/code/Vendor/Module/Block/ProductList.php',
        78,
        0,
        Violation::SEVERITY_ERROR,
        Violation::TYPE_PERFORMANCE
    ))->setCategory('performance'),
    
    // Unfixable violations (for demonstration)
    (new Violation(
        'code-quality-complex-method',
        'Method complexity is too high (complexity: 25, max: 10)',
        'app/code/Vendor/Module/Helper/Data.php',
        123,
        0,
        Violation::SEVERITY_WARNING,
        Violation::TYPE_MAINTAINABILITY
    ))->setCategory('maintainability'),
];

echo "📋 Sample Violations Created: " . count($violations) . "\n\n";

// Initialize fix generator
$fixGenerator = new FixScriptGenerator();

// Register fix generators
$fixGenerator->registerGenerator('magento_config', new MagentoConfigFixGenerator());
$fixGenerator->registerGenerator('database', new DatabaseFixGenerator());
$fixGenerator->registerGenerator('cache', new CacheFixGenerator());

echo "🔧 Registered Fix Generators:\n";
echo "  • magento_config (15 rules)\n";
echo "  • database (7 rules)\n";
echo "  • cache (7 rules)\n\n";

// Get statistics
$stats = $fixGenerator->getFixableStatistics($violations);

echo "📊 Fixability Analysis:\n";
echo "  Total violations: {$stats['total']}\n";
echo "  Fixable: {$stats['fixable']} (" . round(($stats['fixable']/$stats['total'])*100) . "%)\n";
echo "  Unfixable: {$stats['unfixable']}\n\n";

echo "📈 Fixable by Generator:\n";
foreach ($stats['by_generator'] as $generator => $count) {
    echo "  • {$generator}: {$count} fixes\n";
}
echo "\n";

echo "📂 Fixable by Category:\n";
foreach ($stats['by_category'] as $category => $count) {
    echo "  • {$category}: {$count} violations\n";
}
echo "\n";

// Generate fix script
$scriptPath = __DIR__ . '/demo_fixes_' . date('Ymd_His') . '.sh';
echo "🔨 Generating Fix Script...\n";

try {
    $result = $fixGenerator->generateFixScript(
        $violations,
        $scriptPath,
        [
            'dry_run' => false,
            'magento_root' => '/var/www/magento',
            'require_confirmation' => false,
            'include_rollback' => true,
            'script_path' => $scriptPath
        ]
    );
    
    echo "✅ Fix Script Generated Successfully!\n\n";
    echo "📄 Script Details:\n";
    echo "  Path: {$result['script_path']}\n";
    echo "  Size: " . round(filesize($result['script_path']) / 1024, 2) . " KB\n";
    echo "  Fixable violations: {$result['fixable_count']}\n";
    echo "  Unfixable violations: {$result['unfixable_count']}\n";
    echo "  Commands: " . count($result['commands']) . "\n\n";
    
    echo "🎯 Fix Commands (sorted by priority):\n";
    foreach ($result['commands'] as $i => $command) {
        echo sprintf(
            "  [%d] %s\n",
            $i + 1,
            $command->getSummary()
        );
    }
    echo "\n";
    
    echo "📝 To execute the fixes:\n";
    echo "  chmod +x {$scriptPath}\n";
    echo "  bash {$scriptPath}\n\n";
    
    echo "⚠️  WARNING:\n";
    echo "  • This is a DEMO script for illustration purposes\n";
    echo "  • Review the script before executing on a real system\n";
    echo "  • Test on a development environment first\n";
    echo "  • The script includes safety checks and backups\n\n";
    
    echo "📖 Script Preview (first 50 lines):\n";
    echo str_repeat('-', 60) . "\n";
    $scriptLines = file($scriptPath);
    $previewLines = array_slice($scriptLines, 0, 50);
    echo implode('', $previewLines);
    if (count($scriptLines) > 50) {
        echo "\n... (" . (count($scriptLines) - 50) . " more lines) ...\n";
    }
    echo str_repeat('-', 60) . "\n\n";
    
    echo "✅ Demo Complete!\n";
    echo "Generated script: {$scriptPath}\n\n";
    
} catch (\Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}

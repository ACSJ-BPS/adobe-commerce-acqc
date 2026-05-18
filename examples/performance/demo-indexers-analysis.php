<?php
/**
 * Demo script for IndexersAnalyzer
 * 
 * This demonstrates the indexer analysis capabilities including:
 * - Indexer mode detection
 * - Search engine configuration
 * - Invalid indexer detection
 * - Mview backlog analysis
 * - Flat catalog scheduling
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use M2Quality\Analyzers\Performance\IndexersAnalyzer;
use Psr\Log\NullLogger;

echo "\n";
echo "⚡ M2-Quality Indexers Analysis Demo\n";
echo str_repeat("=", 70) . "\n\n";

// Initialize analyzer
$analyzer = new IndexersAnalyzer([], new NullLogger());

echo "📊 Analyzer Information:\n";
echo "  Name: " . $analyzer->getName() . "\n";
echo "  Version: " . $analyzer->getVersion() . "\n";
echo "  Available: ✅ Yes\n\n";

echo str_repeat("-", 70) . "\n\n";

// Use current directory as Magento root (or specify path)
$magentoRoot = getcwd();

echo "📂 Analyzing: {$magentoRoot}\n\n";

// Run analysis
echo "🔍 Running Indexer Analysis...\n";
echo str_repeat("=", 70) . "\n\n";

try {
    $violations = $analyzer->analyze([$magentoRoot], ['magento_root' => $magentoRoot]);
    
    echo "📊 Analysis Results:\n";
    echo "  Violations found: " . count($violations) . "\n\n";
    
    if (empty($violations)) {
        echo "✅ No indexer issues detected!\n";
        echo "\n";
        echo "Your indexer configuration appears optimal:\n";
        echo "  ✓ All critical indexers using scheduled mode\n";
        echo "  ✓ Search engine properly configured\n";
        echo "  ✓ No invalid indexers\n";
        echo "  ✓ No mview backlog detected\n";
        echo "  ✓ Flat catalog properly configured\n";
    } else {
        echo "⚠️  Issues & Recommendations:\n\n";
        
        foreach ($violations as $i => $violation) {
            $index = $i + 1;
            echo "⚠️ [{$index}] {$violation->getSeverity()}: {$violation->getMessage()}\n\n";
            
            // Show rule ID
            echo "💡 Suggestion:\n";
            switch ($violation->getRuleId()) {
                case 'indexer-update-on-save':
                    echo "   Switch indexers to scheduled mode:\n";
                    echo "   \n";
                    echo "   bin/magento indexer:set-mode schedule [indexer_id]\n";
                    echo "   \n";
                    echo "   Then set up cron to run:\n";
                    echo "   bin/magento cron:run --group index\n";
                    break;
                    
                case 'search-engine-not-elasticsearch':
                    echo "   Configure Elasticsearch/OpenSearch:\n";
                    echo "   \n";
                    echo "   bin/magento config:set catalog/search/engine elasticsearch7\n";
                    echo "   bin/magento indexer:reindex catalogsearch_fulltext\n";
                    break;
                    
                case 'indexer-invalid-status':
                    echo "   Reindex the invalid indexer:\n";
                    echo "   \n";
                    echo "   bin/magento indexer:reindex [indexer_id]\n";
                    echo "   \n";
                    echo "   Or reindex all:\n";
                    echo "   bin/magento indexer:reindex\n";
                    break;
                    
                case 'indexer-outdated-mview':
                    echo "   Run index cron to clear backlog:\n";
                    echo "   \n";
                    echo "   bin/magento cron:run --group index\n";
                    echo "   \n";
                    echo "   Ensure cron is running regularly\n";
                    break;
                    
                case 'flat-catalog-without-schedule':
                    echo "   Either disable flat catalog or use schedule mode:\n";
                    echo "   \n";
                    echo "   Option A (Recommended - Disable):\n";
                    echo "   bin/magento config:set catalog/frontend/flat_catalog_product 0\n";
                    echo "   bin/magento config:set catalog/frontend/flat_catalog_category 0\n";
                    echo "   \n";
                    echo "   Option B (Use Schedule Mode):\n";
                    echo "   bin/magento indexer:set-mode schedule catalog_product_flat\n";
                    echo "   bin/magento indexer:set-mode schedule catalog_category_flat\n";
                    break;
            }
            echo "\n";
            
            // Show metadata if available
            $metadata = $violation->getMetadata();
            if (!empty($metadata)) {
                echo "📊 Details:\n";
                foreach ($metadata as $key => $value) {
                    if (is_array($value)) {
                        $value = implode(', ', $value);
                    }
                    echo "   • " . ucwords(str_replace('_', ' ', $key)) . ": {$value}\n";
                }
                echo "\n";
            }
            
            echo str_repeat("-", 70) . "\n\n";
        }
        
        echo "🔧 Automated Fix Generation:\n";
        echo "  Run with --generate-fix to create executable fix script:\n";
        echo "  php bin/m2-quality analyze . --generate-fix\n\n";
    }
    
} catch (\Exception $e) {
    echo "❌ Error during analysis: " . $e->getMessage() . "\n";
    echo "\nStack trace:\n";
    echo $e->getTraceAsString() . "\n";
}

echo "\n" . str_repeat("=", 70) . "\n";
echo "✅ Demo Complete\n\n";

echo "💡 Tips:\n";
echo "  • Run this from your Magento root directory for best results\n";
echo "  • Ensure bin/magento is executable\n";
echo "  • Use --generate-fix option for automated fixes\n";
echo "  • Monitor indexer status regularly: bin/magento indexer:status\n";
echo "\n";

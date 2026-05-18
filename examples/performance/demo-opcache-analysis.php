<?php

/**
 * Demo: OpCache Analysis
 * 
 * This script demonstrates the OpCache analyzer
 * 
 * Usage:
 *   php examples/performance/demo-opcache-analysis.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use M2Quality\Analyzers\Performance\OpCacheAnalyzer;

echo "⚡ M2-Quality OpCache Analysis Demo\n";
echo str_repeat('=', 70) . "\n\n";

// Initialize analyzer
$analyzer = new OpCacheAnalyzer();

echo "🔍 OpCache Analyzer Information:\n";
echo "  Name: " . $analyzer->getName() . "\n";
echo "  Version: " . $analyzer->getVersion() . "\n";
echo "  Available: " . ($analyzer->isAvailable() ? '✅ Yes' : '❌ No') . "\n";
echo "\n";

if (!$analyzer->isAvailable()) {
    echo "❌ OpCache extension is not available!\n";
    echo "   Install OPcache to use this analyzer.\n";
    exit(1);
}

echo str_repeat('-', 70) . "\n";
echo "📊 Current OpCache Configuration:\n";
echo str_repeat('-', 70) . "\n\n";

$stats = $analyzer->getStatistics();

if (!$stats['enabled']) {
    echo "❌ OpCache is DISABLED\n\n";
    echo "This is CRITICAL for performance. Enable OpCache immediately!\n";
} else {
    echo "✅ OpCache is ENABLED\n\n";
    
    echo "Memory Configuration:\n";
    echo "  Allocated:    {$stats['memory_mb']}MB\n";
    echo "  Used:         " . round(($stats['memory_usage']['used_memory'] ?? 0) / 1024 / 1024, 2) . "MB\n";
    echo "  Free:         " . round(($stats['memory_usage']['free_memory'] ?? 0) / 1024 / 1024, 2) . "MB\n";
    echo "  Wasted:       " . round(($stats['memory_usage']['wasted_memory'] ?? 0) / 1024 / 1024, 2) . "MB\n";
    echo "\n";
    
    echo "File Caching:\n";
    echo "  Max Files:    {$stats['max_files']}\n";
    echo "  Cached:       {$stats['cached_scripts']}\n";
    echo "  Usage:        " . round(($stats['cached_scripts'] / $stats['max_files']) * 100, 1) . "%\n";
    echo "\n";
    
    echo "Hit Rate:\n";
    echo "  Hits:         {$stats['cache_hits']}\n";
    echo "  Misses:       {$stats['cache_misses']}\n";
    echo "  Hit Rate:     " . round($stats['hit_rate'], 2) . "%\n";
    echo "\n";
    
    echo "Interned Strings:\n";
    echo "  Buffer Size:  " . round(($stats['interned_strings']['buffer_size'] ?? 0) / 1024 / 1024, 2) . "MB\n";
    echo "  Used:         " . round(($stats['interned_strings']['used_memory'] ?? 0) / 1024 / 1024, 2) . "MB\n";
    echo "  Strings:      " . ($stats['interned_strings']['number_of_strings'] ?? 0) . "\n";
    echo "\n";
}

echo str_repeat('=', 70) . "\n";
echo "🔍 Running OpCache Analysis...\n";
echo str_repeat('=', 70) . "\n\n";

// Run analysis
$violations = $analyzer->analyze([], [
    'magento_root' => getcwd()
]);

echo "📊 Analysis Results:\n";
echo "  Violations found: " . count($violations) . "\n\n";

if (empty($violations)) {
    echo "✅ No issues found! Your OpCache configuration is optimal.\n\n";
    echo "🏆 Great job! Your OpCache settings are well-configured for Magento.\n";
} else {
    echo "⚠️  Issues & Recommendations:\n\n";
    
    $criticalCount = 0;
    $errorCount = 0;
    $warningCount = 0;
    $infoCount = 0;
    
    foreach ($violations as $i => $violation) {
        $severity = strtoupper($violation->getSeverity());
        $emoji = match($severity) {
            'CRITICAL' => '🔴',
            'ERROR' => '❌',
            'WARNING' => '⚠️',
            'INFO' => 'ℹ️',
            default => '•'
        };
        
        // Count by severity
        match($severity) {
            'CRITICAL' => $criticalCount++,
            'ERROR' => $errorCount++,
            'WARNING' => $warningCount++,
            'INFO' => $infoCount++,
            default => null
        };
        
        echo sprintf(
            "%s [%d] %s: %s\n",
            $emoji,
            $i + 1,
            $severity,
            $violation->getMessage()
        );
        
        if ($violation->getSuggestion()) {
            echo "\n💡 Suggestion:\n";
            $suggestionLines = explode("\n", $violation->getSuggestion());
            foreach ($suggestionLines as $line) {
                echo "   " . $line . "\n";
            }
        }
        
        $metadata = $violation->getMetadata();
        if (!empty($metadata)) {
            echo "\n📊 Details:\n";
            foreach ($metadata as $key => $value) {
                if (!is_array($value)) {
                    echo "   • " . str_replace('_', ' ', ucfirst($key)) . ": {$value}\n";
                }
            }
        }
        
        echo "\n" . str_repeat('-', 70) . "\n\n";
    }
    
    echo "📈 Summary by Severity:\n";
    if ($criticalCount > 0) echo "  🔴 Critical: {$criticalCount}\n";
    if ($errorCount > 0) echo "  ❌ Error: {$errorCount}\n";
    if ($warningCount > 0) echo "  ⚠️  Warning: {$warningCount}\n";
    if ($infoCount > 0) echo "  ℹ️  Info: {$infoCount}\n";
    echo "\n";
    
    // Priority recommendations
    if ($criticalCount > 0 || $errorCount > 0) {
        echo "🚨 IMMEDIATE ACTION REQUIRED:\n";
        echo "  1. Review all CRITICAL and ERROR violations above\n";
        echo "  2. Update php.ini with recommended settings\n";
        echo "  3. Restart PHP-FPM: sudo systemctl restart php-fpm\n";
        echo "  4. Verify changes: php -i | grep opcache\n";
        echo "  5. Run this analysis again to confirm fixes\n";
        echo "\n";
    }
}

echo str_repeat('=', 70) . "\n";
echo "✅ OpCache Analysis Complete!\n";
echo str_repeat('=', 70) . "\n\n";

// Additional tips
echo "💡 Additional OpCache Tips:\n\n";
echo "1. Monitor OpCache Status:\n";
echo "   php -r \"print_r(opcache_get_status());\"\n\n";

echo "2. Clear OpCache After Deployment:\n";
echo "   php -r \"opcache_reset();\"\n";
echo "   # or\n";
echo "   sudo systemctl restart php-fpm\n\n";

echo "3. Check Current Settings:\n";
echo "   php -i | grep opcache\n";
echo "   # or\n";
echo "   php --ri opcache\n\n";

echo "4. Find php.ini Location:\n";
echo "   php --ini\n\n";

echo "5. Recommended Production Settings:\n";
echo "   opcache.enable=1\n";
echo "   opcache.memory_consumption=512\n";
echo "   opcache.interned_strings_buffer=16\n";
echo "   opcache.max_accelerated_files=65536\n";
echo "   opcache.validate_timestamps=0\n";
echo "   opcache.save_comments=1\n";
echo "   opcache.fast_shutdown=1\n\n";

echo "📚 Learn More:\n";
echo "   • OPcache Documentation: https://www.php.net/manual/en/book.opcache.php\n";
echo "   • Magento DevDocs: https://devdocs.magento.com/guides/v2.4/performance-best-practices/software.html#php-settings\n\n";

echo "✅ Demo Complete!\n";

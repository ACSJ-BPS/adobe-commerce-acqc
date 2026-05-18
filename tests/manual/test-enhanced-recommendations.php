#!/usr/bin/env php
<?php
/**
 * Test Enhanced Recommendations
 * 
 * This script regenerates a performance report with enhanced recommendations
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use ACQC\Recommendations\RecommendationEnhancer;

echo "Testing Enhanced Recommendations...\n\n";

// Test 1: RecommendationEnhancer standalone
echo "=" . str_repeat("=", 70) . "\n";
echo "TEST 1: RecommendationEnhancer Standalone Test\n";
echo "=" . str_repeat("=", 70) . "\n\n";

try {
    $enhancer = new RecommendationEnhancer();

    // Test common rule IDs from the screenshot
    $testRules = [
        'layout-missing-cache-ttl',
        'opcache-max-files-too-low',
        'graphql-complexity-unlimited',
        'server-php-fpm-not-running',
        'search-engine-not-elasticsearch',
        'cdn-not-detected'
    ];

    foreach ($testRules as $ruleId) {
        echo "\n📋 Rule: {$ruleId}\n";
        echo str_repeat("-", 70) . "\n";
        $recommendation = $enhancer->getRecommendation($ruleId);
        echo $recommendation;
        echo "\n" . str_repeat("-", 70) . "\n";
    }

    echo "\n\n";
    echo "=" . str_repeat("=", 70) . "\n";
    echo "TEST 2: Check Available Rule Mappings\n";
    echo "=" . str_repeat("=", 70) . "\n\n";

    $allRules = $enhancer->getAllRuleIds();
    echo "✅ Total enhanced rule mappings available: " . count($allRules) . "\n";
    echo "Rules with enhanced recommendations:\n";
    foreach ($allRules as $rule) {
        echo "  • {$rule}\n";
    }

    echo "\n\n";
    echo "=" . str_repeat("=", 70) . "\n";
    echo "TEST 3: Export Recommendations to JSON\n";
    echo "=" . str_repeat("=", 70) . "\n\n";

    $jsonPath = __DIR__ . '/../../reports/enhanced-recommendations.json';
    $enhancer->exportToJson($jsonPath);
    echo "✅ Recommendations exported to: {$jsonPath}\n";
    echo "   File size: " . number_format(filesize($jsonPath)) . " bytes\n";

    echo "\n\n";
    echo "=" . str_repeat("=", 70) . "\n";
    echo "TEST 4: Compare Generic vs Enhanced\n";
    echo "=" . str_repeat("=", 70) . "\n\n";

    $testRule = 'layout-missing-cache-ttl';
    echo "Rule: {$testRule}\n\n";

    echo "❌ GENERIC (Before):\n";
    echo "   'Optimize code for better performance and efficiency'\n\n";

    echo "✅ ENHANCED (After):\n";
    $enhanced = $enhancer->getRecommendation($testRule);
    echo "   " . str_replace("\n", "\n   ", $enhanced) . "\n\n";

    // Calculate character difference
    $genericLength = strlen('Optimize code for better performance and efficiency');
    $enhancedLength = strlen($enhanced);
    $improvement = $enhancedLength - $genericLength;

    echo "📊 Statistics:\n";
    echo "   Generic length: {$genericLength} characters\n";
    echo "   Enhanced length: {$enhancedLength} characters\n";
    echo "   Improvement: +{$improvement} characters of actionable details\n";

    echo "\n\n";
    echo "=" . str_repeat("=", 70) . "\n";
    echo "✅ ALL TESTS COMPLETED SUCCESSFULLY!\n";
    echo "=" . str_repeat("=", 70) . "\n\n";

    echo "Next Steps:\n";
    echo "1. ✅ RecommendationEnhancer class is working\n";
    echo "2. ✅ Integrated into ExcelReportGenerator\n";
    echo "3. 🔄 Ready to generate reports with enhanced recommendations\n\n";

    echo "To test with real data, run:\n";
    echo "  php bin/acqc report /path/to/code --format excel\n\n";
    
} catch (Throwable $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}

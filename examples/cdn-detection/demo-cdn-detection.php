<?php

/**
 * Demo: CDN Detection
 * 
 * This script demonstrates the multi-method CDN detection feature
 * 
 * Usage:
 *   php examples/cdn-detection/demo-cdn-detection.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use M2Quality\Core\Infrastructure\CDNDetector;
use M2Quality\Core\Infrastructure\CDNInfo;
use M2Quality\Analyzers\Infrastructure\CDNAnalyzer;

echo "🌐 M2-Quality CDN Detection Demo\n";
echo str_repeat('=', 70) . "\n\n";

// Initialize detector
$detector = new CDNDetector();

echo "🔍 Available Detection Methods:\n";
$methods = $detector->getAvailableMethods();
foreach ($methods as $method) {
    echo "  ✓ {$method}\n";
}
echo "\n";

// Detect CDN in current directory (for demo purposes)
$magentoRoot = getcwd();

echo "📂 Scanning Directory: {$magentoRoot}\n";
echo "🔎 Running CDN Detection...\n\n";

// Try detection
$cdn = $detector->detect($magentoRoot, [
    'skip_http' => true  // Skip HTTP detection in demo (may not be a web server)
]);

echo str_repeat('-', 70) . "\n";
echo "📊 Detection Result:\n";
echo str_repeat('-', 70) . "\n\n";

if ($cdn->hasCDN()) {
    echo "✅ CDN Detected!\n\n";
    echo "  Type:              {$cdn->getDisplayName()}\n";
    echo "  Version:           " . ($cdn->version ?? 'Unknown') . "\n";
    echo "  Detection Method:  {$cdn->detectionMethod}\n";
    echo "  Confidence:        {$cdn->confidence}\n";
    echo "\n";
    
    echo "📝 Summary: {$cdn->getSummary()}\n\n";
    
    if (!empty($cdn->config)) {
        echo "⚙️  Configuration Details:\n";
        foreach ($cdn->config as $key => $value) {
            if (is_array($value)) {
                echo "  • {$key}: " . json_encode($value) . "\n";
            } else {
                echo "  • {$key}: {$value}\n";
            }
        }
        echo "\n";
    }
    
    // Provide CDN-specific recommendations
    echo "💡 Recommendations:\n";
    switch ($cdn->type) {
        case CDNInfo::TYPE_FASTLY:
            echo "  ✓ Fastly CDN is enterprise-grade and well-suited for Magento\n";
            echo "  ✓ Ensure Fastly_Cdn module is enabled\n";
            echo "  ✓ Configure cache backend to use Fastly adapter\n";
            echo "  ✓ Enable ESI for better cache granularity\n";
            echo "  ✓ Set up Fastly VCL customizations\n";
            echo "  ✓ Configure image optimization via Fastly IO\n";
            break;
            
        case CDNInfo::TYPE_VARNISH:
            echo "  ✓ Varnish is the most popular cache for Magento 2\n";
            echo "  ✓ Generate VCL: bin/magento varnish:vcl:generate\n";
            echo "  ✓ Configure http_cache_hosts in env.php\n";
            echo "  ✓ Enable ESI for block-level caching\n";
            echo "  ✓ Set grace mode for backend failures\n";
            echo "  ✓ Monitor hit rates: varnishstat\n";
            break;
            
        case CDNInfo::TYPE_CLOUDFLARE:
            echo "  ✓ Cloudflare provides good balance of features and cost\n";
            echo "  ✓ Configure page rules for dynamic content\n";
            echo "  ✓ Set up bypass rules for admin/checkout\n";
            echo "  ✓ Enable Cloudflare APO (Automatic Platform Optimization)\n";
            echo "  ✓ Configure cookie-based cache bypass\n";
            echo "  ✓ Use Cloudflare Workers for advanced caching\n";
            break;
    }
    echo "\n";
    
} else {
    echo "❌ No CDN Detected\n\n";
    echo "  Detection Method:  {$cdn->detectionMethod}\n";
    echo "  Confidence:        {$cdn->confidence}\n";
    echo "\n";
    
    echo "💡 Recommendations:\n";
    echo "  Consider implementing a CDN for better performance:\n\n";
    echo "  1. 🚀 Varnish (Free, Most Popular)\n";
    echo "     • Best for: Most Magento installations\n";
    echo "     • Setup time: 1-2 hours\n";
    echo "     • Cost: Free\n";
    echo "     • Performance: Excellent (80-95% hit rate)\n";
    echo "     • Install: apt-get install varnish\n\n";
    
    echo "  2. ⚡ Fastly (Enterprise)\n";
    echo "     • Best for: High-traffic enterprise sites\n";
    echo "     • Setup time: 2-4 hours\n";
    echo "     • Cost: $$$$ (enterprise pricing)\n";
    echo "     • Performance: Excellent (global edge network)\n";
    echo "     • Install: composer require fastly/magento2\n\n";
    
    echo "  3. 🌐 Cloudflare (Good Balance)\n";
    echo "     • Best for: Small to medium sites\n";
    echo "     • Setup time: 1 hour\n";
    echo "     • Cost: $ to $$ (freemium model)\n";
    echo "     • Performance: Good (global CDN)\n";
    echo "     • Setup: DNS-based, no code changes\n\n";
}

// Try all detection methods (for debugging)
echo str_repeat('=', 70) . "\n";
echo "🔬 Detailed Detection Results (All Methods):\n";
echo str_repeat('=', 70) . "\n\n";

$allResults = $detector->detectAll($magentoRoot);

foreach ($allResults as $methodName => $result) {
    echo "Method: {$methodName}\n";
    if ($result) {
        echo "  ✓ Detected: {$result->getDisplayName()}\n";
        echo "    Confidence: {$result->confidence}\n";
        if ($result->version) {
            echo "    Version: {$result->version}\n";
        }
    } else {
        echo "  ✗ Not detected\n";
    }
    echo "\n";
}

echo str_repeat('=', 70) . "\n";
echo "✅ CDN Detection Demo Complete!\n";
echo str_repeat('=', 70) . "\n\n";

// Run CDN analyzer
echo "🔍 Running CDN Analyzer...\n\n";

$analyzer = new CDNAnalyzer();
$violations = $analyzer->analyze([], ['magento_root' => $magentoRoot]);

echo "📊 CDN Analysis Results:\n";
echo "  Violations found: " . count($violations) . "\n\n";

if (!empty($violations)) {
    echo "⚠️  Issues & Recommendations:\n\n";
    foreach ($violations as $i => $violation) {
        echo sprintf(
            "[%d] %s: %s\n",
            $i + 1,
            strtoupper($violation->getSeverity()),
            $violation->getMessage()
        );
        
        if ($violation->getSuggestion()) {
            echo "    💡 Suggestion: " . str_replace("\n", "\n       ", $violation->getSuggestion()) . "\n";
        }
        echo "\n";
    }
}

echo "✅ Demo Complete!\n";

<?php
/**
 * Demo script for HttpProtocolAnalyzer
 * 
 * This demonstrates the HTTP protocol analysis capabilities including:
 * - HSTS header validation
 * - Compression detection
 * - Keep-Alive configuration
 * - Connection pooling
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use M2Quality\Analyzers\Performance\HttpProtocolAnalyzer;
use Psr\Log\NullLogger;

echo "\n";
echo "🌐 M2-Quality HTTP Protocol Analysis Demo\n";
echo str_repeat("=", 70) . "\n\n";

// Initialize analyzer
$analyzer = new HttpProtocolAnalyzer([], new NullLogger());

echo "📊 Analyzer Information:\n";
echo "  Name: " . $analyzer->getName() . "\n";
echo "  Version: " . $analyzer->getVersion() . "\n";
echo "  Available: ✅ Yes\n\n";

echo str_repeat("-", 70) . "\n\n";

// Use current directory as Magento root (or specify path)
$magentoRoot = getcwd();

echo "📂 Analyzing: {$magentoRoot}\n\n";

// Run analysis
echo "🔍 Running HTTP Protocol Analysis...\n";
echo str_repeat("=", 70) . "\n\n";

try {
    $violations = $analyzer->analyze([$magentoRoot], ['magento_root' => $magentoRoot]);
    
    echo "📊 Analysis Results:\n";
    echo "  Violations found: " . count($violations) . "\n\n";
    
    if (empty($violations)) {
        echo "✅ No HTTP protocol issues detected!\n";
        echo "\n";
        echo "Your HTTP configuration appears optimal:\n";
        echo "  ✓ HSTS header properly configured\n";
        echo "  ✓ Compression enabled\n";
        echo "  ✓ Keep-Alive configured\n";
        echo "  ✓ Connection pooling optimized\n";
    } else {
        echo "⚠️  Issues & Recommendations:\n\n";
        
        foreach ($violations as $i => $violation) {
            $index = $i + 1;
            echo "⚠️ [{$index}] {$violation->getSeverity()}: {$violation->getMessage()}\n\n";
            
            // Show rule ID and recommendations
            echo "💡 Suggestion:\n";
            switch ($violation->getRuleId()) {
                case 'missing-hsts-header':
                case 'hsts-max-age-too-low':
                    echo "   Add HSTS header in your web server configuration:\n";
                    echo "   \n";
                    echo "   Nginx:\n";
                    echo "   add_header Strict-Transport-Security \"max-age=31536000; includeSubDomains; preload\" always;\n";
                    echo "   \n";
                    echo "   Apache:\n";
                    echo "   Header always set Strict-Transport-Security \"max-age=31536000; includeSubDomains; preload\"\n";
                    break;
                    
                case 'compression-not-enabled':
                    echo "   Enable compression in your web server:\n";
                    echo "   \n";
                    echo "   Nginx:\n";
                    echo "   gzip on;\n";
                    echo "   gzip_types text/plain text/css application/json application/javascript;\n";
                    echo "   gzip_min_length 1000;\n";
                    echo "   \n";
                    echo "   Apache:\n";
                    echo "   <IfModule mod_deflate.c>\n";
                    echo "     AddOutputFilterByType DEFLATE text/html text/css application/javascript\n";
                    echo "   </IfModule>\n";
                    break;
                    
                case 'keepalive-disabled':
                    echo "   Configure Keep-Alive:\n";
                    echo "   \n";
                    echo "   Nginx:\n";
                    echo "   keepalive_timeout 65;\n";
                    echo "   keepalive_requests 100;\n";
                    echo "   \n";
                    echo "   Apache:\n";
                    echo "   KeepAlive On\n";
                    echo "   MaxKeepAliveRequests 100\n";
                    echo "   KeepAliveTimeout 5\n";
                    break;
                    
                case 'low-php-fpm-max-children':
                    echo "   Increase PHP-FPM max_children:\n";
                    echo "   \n";
                    echo "   Edit /etc/php/8.x/fpm/pool.d/www.conf:\n";
                    echo "   pm.max_children = 50\n";
                    echo "   pm.start_servers = 5\n";
                    echo "   pm.min_spare_servers = 5\n";
                    echo "   pm.max_spare_servers = 35\n";
                    echo "   \n";
                    echo "   Then restart PHP-FPM:\n";
                    echo "   sudo systemctl restart php-fpm\n";
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
        echo "  Run with --generate-fix to create config snippets:\n";
        echo "  php bin/m2-quality analyze . --generate-fix\n\n";
        echo "  This will generate Nginx and Apache configuration\n";
        echo "  snippets that you can copy to your server config.\n\n";
    }
    
} catch (\Exception $e) {
    echo "❌ Error during analysis: " . $e->getMessage() . "\n";
    echo "\nStack trace:\n";
    echo $e->getTraceAsString() . "\n";
}

echo "\n" . str_repeat("=", 70) . "\n";
echo "✅ Demo Complete\n\n";

echo "💡 Tips:\n";
echo "  • This analyzer requires network access to check HTTP headers\n";
echo "  • Ensure your store is accessible via HTTPS\n";
echo "  • Use --generate-fix for Nginx/Apache config snippets\n";
echo "  • Test configuration with: nginx -t (or) apachectl configtest\n";
echo "  • Verify HSTS: curl -I https://your-domain.com | grep -i strict\n";
echo "  • Verify compression: curl -H 'Accept-Encoding: gzip' -I https://your-domain.com\n";
echo "\n";

<?php
declare(strict_types=1);

namespace ACQC\Core\AutoFix\Generators;

use ACQC\Core\AutoFix\FixCommand;
use ACQC\Core\AutoFix\FixGeneratorInterface;
use ACQC\Core\Rules\Violation;

/**
 * Fix generator for HTTP protocol-related issues
 *
 * Generates configuration snippets for:
 * - HSTS header configuration
 * - Compression (Gzip/Brotli) setup
 *
 * Note: These fixes generate configuration files that need to be manually
 * applied to the web server configuration (Nginx/Apache).
 *
 * @package ACQC\Core\AutoFix\Generators
 */
class HttpProtocolFixGenerator implements FixGeneratorInterface
{
    private const FIXABLE_RULES = [
        'missing-hsts-header',
        'hsts-max-age-too-low',
        'compression-not-enabled',
    ];

    public function canFix(Violation $violation): bool
    {
        return in_array($violation->getRuleId(), self::FIXABLE_RULES, true);
    }

    public function generateFix(Violation $violation): ?FixCommand
    {
        return match($violation->getRuleId()) {
            'missing-hsts-header', 'hsts-max-age-too-low' => $this->generateHstsConfig($violation),
            'compression-not-enabled' => $this->generateCompressionConfig($violation),
            default => null,
        };
    }

    public function getFixableRules(): array
    {
        return self::FIXABLE_RULES;
    }

    /**
     * Generate HSTS header configuration snippets
     */
    private function generateHstsConfig(Violation $violation): FixCommand
    {
        $nginxConfig = <<<'NGINX'
# ============================================
# NGINX: Add HSTS Header Configuration
# ============================================
# Add this to your Nginx server block (inside server {...})

# HSTS (HTTP Strict Transport Security)
# max-age: 31536000 = 1 year in seconds
# includeSubDomains: Apply to all subdomains
# preload: Allow inclusion in browsers' HSTS preload list
add_header Strict-Transport-Security "max-age=31536000; includeSubDomains; preload" always;

# Recommended: Also add other security headers
add_header X-Content-Type-Options "nosniff" always;
add_header X-Frame-Options "SAMEORIGIN" always;
add_header X-XSS-Protection "1; mode=block" always;

# After adding, reload Nginx:
# sudo nginx -t && sudo systemctl reload nginx
NGINX;

        $apacheConfig = <<<'APACHE'
# ============================================
# APACHE: Add HSTS Header Configuration
# ============================================
# Add this to your Apache VirtualHost configuration

<IfModule mod_headers.c>
    # HSTS (HTTP Strict Transport Security)
    # max-age: 31536000 = 1 year in seconds
    # includeSubDomains: Apply to all subdomains
    # preload: Allow inclusion in browsers' HSTS preload list
    Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains; preload"
    
    # Recommended: Also add other security headers
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set X-XSS-Protection "1; mode=block"
</IfModule>

# After adding, reload Apache:
# sudo apachectl configtest && sudo systemctl reload apache2
APACHE;

        $filename = '/tmp/acqc_hsts_config_' . date('Ymd_His') . '.txt';

        $command = sprintf(
            "cat > %s << 'HEREDOC_EOF'\n\n%s\n\n%s\nHEREDOC_EOF\n\necho \"\n✅ HSTS configuration generated: %s\n\nNext steps:\n1. Review the configuration file\n2. Choose Nginx or Apache section based on your web server\n3. Copy the relevant configuration to your web server config\n4. Test configuration: nginx -t (or) apachectl configtest\n5. Reload web server\n6. Verify HSTS header: curl -I https://your-domain.com | grep -i strict\"",
            escapeshellarg($filename),
            $nginxConfig,
            $apacheConfig,
            $filename
        );

        return new FixCommand(
            command: $command,
            description: 'Generate HSTS header configuration (Nginx & Apache)',
            safetyChecks: [],
            rollback: null,
            requiresConfirmation: false,
            workingDirectory: null,
            dependencies: [],
            priority: 5
        );
    }

    /**
     * Generate compression configuration snippets
     */
    private function generateCompressionConfig(Violation $violation): FixCommand
    {
        $nginxConfig = <<<'NGINX'
# ============================================
# NGINX: Enable Gzip Compression
# ============================================
# Add this to your Nginx http block (in nginx.conf or separate config)

# Enable Gzip compression
gzip on;

# Compression level (1-9, 6 is good balance)
gzip_comp_level 6;

# Minimum file size to compress (in bytes)
gzip_min_length 1000;

# Disable for old browsers (IE6)
gzip_disable "msie6";

# Enable compression for proxied requests
gzip_proxied any;

# Vary header for caching proxies
gzip_vary on;

# MIME types to compress (in addition to text/html)
gzip_types
    text/plain
    text/css
    text/xml
    text/javascript
    application/json
    application/javascript
    application/xml
    application/xml+rss
    application/x-javascript
    font/truetype
    font/opentype
    image/svg+xml;

# Optional: Brotli compression (requires ngx_brotli module)
# brotli on;
# brotli_comp_level 6;
# brotli_types text/plain text/css application/json application/javascript text/xml application/xml application/xml+rss text/javascript;

# After adding, reload Nginx:
# sudo nginx -t && sudo systemctl reload nginx

# Verify compression:
# curl -H "Accept-Encoding: gzip" -I https://your-domain.com
NGINX;

        $apacheConfig = <<<'APACHE'
# ============================================
# APACHE: Enable Gzip Compression
# ============================================
# Add this to your Apache config (httpd.conf or .htaccess)

<IfModule mod_deflate.c>
    # Enable compression
    SetOutputFilter DEFLATE
    
    # Don't compress images, videos, or already compressed files
    SetEnvIfNoCase Request_URI \.(?:gif|jpe?g|png|zip|gz|rar|pdf|mp4|webm)$ no-gzip
    
    # Compress HTML, CSS, JavaScript, Text, XML
    AddOutputFilterByType DEFLATE text/html
    AddOutputFilterByType DEFLATE text/plain
    AddOutputFilterByType DEFLATE text/css
    AddOutputFilterByType DEFLATE text/xml
    AddOutputFilterByType DEFLATE text/javascript
    AddOutputFilterByType DEFLATE application/javascript
    AddOutputFilterByType DEFLATE application/x-javascript
    AddOutputFilterByType DEFLATE application/json
    AddOutputFilterByType DEFLATE application/xml
    AddOutputFilterByType DEFLATE application/xml+rss
    AddOutputFilterByType DEFLATE application/xhtml+xml
    AddOutputFilterByType DEFLATE font/truetype
    AddOutputFilterByType DEFLATE font/opentype
    AddOutputFilterByType DEFLATE image/svg+xml
    
    # Handle old browsers
    BrowserMatch ^Mozilla/4 gzip-only-text/html
    BrowserMatch ^Mozilla/4\.0[678] no-gzip
    BrowserMatch \bMSIE !no-gzip !gzip-only-text/html
    
    # Ensure proxies cache compressed and uncompressed versions
    Header append Vary Accept-Encoding
</IfModule>

# After adding, reload Apache:
# sudo apachectl configtest && sudo systemctl reload apache2

# Verify compression:
# curl -H "Accept-Encoding: gzip" -I https://your-domain.com | grep -i content-encoding
APACHE;

        $filename = '/tmp/acqc_compression_config_' . date('Ymd_His') . '.txt';

        $command = sprintf(
            "cat > %s << 'HEREDOC_EOF'\n\n%s\n\n%s\nHEREDOC_EOF\n\necho \"\n✅ Compression configuration generated: %s\n\nNext steps:\n1. Review the configuration file\n2. Choose Nginx or Apache section based on your web server\n3. Copy the relevant configuration to your web server config\n4. Test configuration: nginx -t (or) apachectl configtest\n5. Reload web server\n6. Verify compression: curl -H 'Accept-Encoding: gzip' -I https://your-domain.com | grep -i content-encoding\n7. Expected output: 'Content-Encoding: gzip'\n\nNote: For Nginx Brotli support, install ngx_brotli module first\"",
            escapeshellarg($filename),
            $nginxConfig,
            $apacheConfig,
            $filename
        );

        return new FixCommand(
            command: $command,
            description: 'Generate compression configuration (Nginx & Apache)',
            safetyChecks: [],
            rollback: null,
            requiresConfirmation: false,
            workingDirectory: null,
            dependencies: [],
            priority: 7
        );
    }
}

<?php

declare(strict_types=1);

namespace ACQC\Analyzers\Frontend;

use ACQC\Analyzers\AbstractAnalyzer;
use ACQC\Analyzers\AnalyzerException;
use ACQC\Core\Rules\Violation;

class SecurityAnalyzer extends AbstractAnalyzer
{
    private array $securityHeaders = [];
    private array $vulnerabilities = [];
    
    public function getName(): string
    {
        return 'frontend-security-analyzer';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    protected function getDefaultConfiguration(): array
    {
        return [
            'check_xss_vulnerabilities' => true,
            'check_csp_implementation' => true,
            'check_secure_headers' => true,
            'check_input_validation' => true,
            'check_output_encoding' => true,
            'check_third_party_security' => true,
            'check_storage_security' => true,
            'check_communication_security' => true,
            'severity_critical_security' => Violation::SEVERITY_CRITICAL,
            'severity_high_security' => Violation::SEVERITY_ERROR,
            'severity_medium_security' => Violation::SEVERITY_WARNING,
            'severity_low_security' => Violation::SEVERITY_INFO,
            'supported_file_types' => [
                'html' => ['.html', '.htm', '.phtml'],
                'javascript' => ['.js', '.jsx', '.ts', '.tsx'],
                'css' => ['.css', '.scss', '.sass'],
                'config' => ['.json', '.js', '.htaccess', '.conf']
            ],
            'dangerous_html_attributes' => [
                'onclick', 'onload', 'onerror', 'onmouseover', 'onmouseout',
                'onfocus', 'onblur', 'onchange', 'onsubmit', 'onkeydown',
                'onkeyup', 'onkeypress', 'ontouchstart', 'ontouchend'
            ],
            'dangerous_javascript_functions' => [
                'eval', 'Function', 'setTimeout', 'setInterval',
                'execScript', 'document.write', 'document.writeln',
                'innerHTML', 'outerHTML', 'insertAdjacentHTML'
            ],
            'security_headers' => [
                'Content-Security-Policy' => 'required',
                'X-Content-Type-Options' => 'required',
                'X-Frame-Options' => 'required',
                'X-XSS-Protection' => 'recommended',
                'Strict-Transport-Security' => 'required',
                'Referrer-Policy' => 'recommended',
                'Permissions-Policy' => 'recommended'
            ],
            'csp_directives' => [
                'default-src' => 'required',
                'script-src' => 'required',
                'style-src' => 'required',
                'img-src' => 'required',
                'font-src' => 'recommended',
                'connect-src' => 'recommended',
                'frame-src' => 'recommended'
            ],
            'unsafe_csp_values' => [
                "'unsafe-eval'",
                "'unsafe-inline'",
                "data:",
                "*"
            ],
            'trusted_domains' => [
                'googleapis.com',
                'gstatic.com',
                'jquery.com',
                'cdnjs.cloudflare.com',
                'unpkg.com'
            ],
            'sensitive_data_patterns' => [
                'password',
                'token',
                'secret',
                'key',
                'api_key',
                'private',
                'credential'
            ],
            'exclude_paths' => [
                '*/node_modules/*',
                '*/vendor/*',
                '*/test/*',
                '*/tests/*'
            ],
        ];
    }


    public function __construct(array $configuration = [], ?\Psr\Log\LoggerInterface $logger = null)
    {
        parent::__construct($configuration, $logger);
        $this->supportedFileExtensions = ['html', 'htm', 'js', 'jsx', 'ts', 'tsx', 'css', 'scss', 'json', 'htaccess', 'conf'];
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
        $this->logger->info('Starting frontend security analysis');
        
        $violations = [];
        
        // Categorize files by type
        $categorizedFiles = $this->categorizeFiles($files);
        
        // Analyze HTML files for security issues
        $violations = array_merge($violations, $this->analyzeHtmlSecurity($categorizedFiles['html'] ?? []));
        
        // Analyze JavaScript security
        $violations = array_merge($violations, $this->analyzeJavaScriptSecurity($categorizedFiles['javascript'] ?? []));
        
        // Analyze CSS security
        $violations = array_merge($violations, $this->analyzeCssSecurity($categorizedFiles['css'] ?? []));
        
        // Analyze configuration security
        $violations = array_merge($violations, $this->analyzeConfigSecurity($categorizedFiles['config'] ?? []));
        
        $this->logger->info('Found ' . count($violations) . ' frontend security violations');
        
        return $violations;
    }

    protected function parseOutput(string $output, array $files): array
    {
        return [];
    }

    private function categorizeFiles(array $files): array
    {
        $categorized = [];
        
        foreach ($files as $file) {
            if (!$this->shouldAnalyzeFile($file)) {
                continue;
            }
            
            $extension = $this->getFileExtension($file);
            
            foreach ($this->configuration['supported_file_types'] as $type => $extensions) {
                if (in_array($extension, $extensions)) {
                    $categorized[$type][] = $file;
                    break;
                }
            }
        }
        
        return $categorized;
    }

    private function analyzeHtmlSecurity(array $htmlFiles): array
    {
        $violations = [];
        
        foreach ($htmlFiles as $file) {
            $violations = array_merge($violations, $this->analyzeHtmlFile($file));
        }
        
        return $violations;
    }

    private function analyzeHtmlFile(string $filePath): array
    {
        $violations = [];
        $content = file_get_contents($filePath);
        
        if (!$content) {
            return $violations;
        }
        
        $dom = $this->createDOMDocument($content);
        if (!$dom) {
            return $violations;
        }
        
        $xpath = new \DOMXPath($dom);
        
        // Check for XSS vulnerabilities
        $violations = array_merge($violations, $this->checkXSSVulnerabilities($xpath, $filePath));
        
        // Check for CSP implementation
        $violations = array_merge($violations, $this->checkCSPImplementation($xpath, $filePath));
        
        // Check for secure headers
        $violations = array_merge($violations, $this->checkSecurityHeaders($xpath, $filePath));
        
        // Check for unsafe HTML patterns
        $violations = array_merge($violations, $this->checkUnsafeHtmlPatterns($xpath, $filePath));
        
        // Check for third-party security
        $violations = array_merge($violations, $this->checkThirdPartySecurity($xpath, $filePath));
        
        return $violations;
    }

    private function checkXSSVulnerabilities(\DOMXPath $xpath, string $filePath): array
    {
        $violations = [];
        
        // Check for dangerous event handlers
        foreach ($this->configuration['dangerous_html_attributes'] as $attribute) {
            $elements = $xpath->query("//*[@{$attribute}]");
            
            foreach ($elements as $element) {
                $value = $element->getAttribute($attribute);
                
                // Check for user input in event handlers
                if (str_contains($value, '$') || str_contains($value, 'user') || str_contains($value, 'input')) {
                    $violations[] = new Violation(
                        'frontend-security-xss-event-handler',
                        "Potential XSS in event handler '{$attribute}': {$value}",
                        $filePath,
                        0,
                        0,
                        $this->configuration['severity_critical_security'],
                        Violation::TYPE_SECURITY
                    );
                }
                
                // Check for eval in event handlers
                if (str_contains($value, 'eval(')) {
                    $violations[] = new Violation(
                        'frontend-security-eval-in-handler',
                        "Dangerous eval() in event handler '{$attribute}'",
                        $filePath,
                        0,
                        0,
                        $this->configuration['severity_critical_security'],
                        Violation::TYPE_SECURITY
                    );
                }
            }
        }
        
        // Check for unsafe innerHTML usage patterns
        $scriptTags = $xpath->query('//script[not(@src)]');
        
        foreach ($scriptTags as $script) {
            $content = $script->textContent;
            
            if (str_contains($content, 'innerHTML') && !str_contains($content, 'textContent')) {
                $violations[] = new Violation(
                    'frontend-security-innerHTML-usage',
                    'innerHTML usage without proper sanitization detected',
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_high_security'],
                    Violation::TYPE_SECURITY
                );
            }
        }
        
        // Check for unescaped user data in templates
        if (preg_match_all('/\{\{[^}]*\$[^}]*\}\}/', $content, $matches)) {
            foreach ($matches[0] as $match) {
                if (!str_contains($match, 'escape') && !str_contains($match, 'safe')) {
                    $violations[] = new Violation(
                        'frontend-security-unescaped-template-data',
                        "Potentially unescaped template data: {$match}",
                        $filePath,
                        0,
                        0,
                        $this->configuration['severity_high_security'],
                        Violation::TYPE_SECURITY
                    );
                }
            }
        }
        
        return $violations;
    }

    private function checkCSPImplementation(\DOMXPath $xpath, string $filePath): array
    {
        $violations = [];
        
        // Check for CSP meta tag
        $cspMeta = $xpath->query('//meta[@http-equiv="Content-Security-Policy"]');
        
        if ($cspMeta->length === 0) {
            $violations[] = new Violation(
                'frontend-security-missing-csp',
                'Missing Content Security Policy (CSP) implementation',
                $filePath,
                0,
                0,
                $this->configuration['severity_high_security'],
                Violation::TYPE_SECURITY
            );
            return $violations;
        }
        
        // Analyze CSP content
        $cspContent = $cspMeta->item(0)->getAttribute('content');
        $violations = array_merge($violations, $this->analyzeCSPContent($cspContent, $filePath));
        
        return $violations;
    }

    private function analyzeCSPContent(string $csp, string $filePath): array
    {
        $violations = [];
        
        // Check for required directives
        foreach ($this->configuration['csp_directives'] as $directive => $requirement) {
            if ($requirement === 'required' && !str_contains($csp, $directive)) {
                $violations[] = new Violation(
                    'frontend-security-csp-missing-directive',
                    "CSP missing required directive: {$directive}",
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_high_security'],
                    Violation::TYPE_SECURITY
                );
            }
        }
        
        // Check for unsafe CSP values
        foreach ($this->configuration['unsafe_csp_values'] as $unsafeValue) {
            if (str_contains($csp, $unsafeValue)) {
                $violations[] = new Violation(
                    'frontend-security-csp-unsafe-value',
                    "CSP contains unsafe value: {$unsafeValue}",
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_medium_security'],
                    Violation::TYPE_SECURITY
                );
            }
        }
        
        // Check for overly permissive policies
        if (str_contains($csp, "default-src *") || str_contains($csp, "script-src *")) {
            $violations[] = new Violation(
                'frontend-security-csp-too-permissive',
                'CSP policy is too permissive (allows all sources)',
                $filePath,
                0,
                0,
                $this->configuration['severity_medium_security'],
                Violation::TYPE_SECURITY
            );
        }
        
        return $violations;
    }

    private function checkSecurityHeaders(\DOMXPath $xpath, string $filePath): array
    {
        $violations = [];
        
        // Check for security-related meta tags
        $metaTags = $xpath->query('//meta[@http-equiv]');
        $foundHeaders = [];
        
        foreach ($metaTags as $meta) {
            $httpEquiv = $meta->getAttribute('http-equiv');
            $foundHeaders[] = $httpEquiv;
        }
        
        // Check for missing security headers
        foreach ($this->configuration['security_headers'] as $header => $requirement) {
            if ($requirement === 'required' && !in_array($header, $foundHeaders)) {
                $violations[] = new Violation(
                    'frontend-security-missing-header',
                    "Missing security header: {$header}",
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_high_security'],
                    Violation::TYPE_SECURITY
                );
            }
        }
        
        // Check X-Frame-Options implementation
        $frameOptions = $xpath->query('//meta[@http-equiv="X-Frame-Options"]');
        if ($frameOptions->length > 0) {
            $content = $frameOptions->item(0)->getAttribute('content');
            if (!in_array(strtoupper($content), ['DENY', 'SAMEORIGIN'])) {
                $violations[] = new Violation(
                    'frontend-security-weak-frame-options',
                    "Weak X-Frame-Options value: {$content}",
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_medium_security'],
                    Violation::TYPE_SECURITY
                );
            }
        }
        
        return $violations;
    }

    private function checkUnsafeHtmlPatterns(\DOMXPath $xpath, string $filePath): array
    {
        $violations = [];
        
        // Check for target="_blank" without rel="noopener"
        $externalLinks = $xpath->query('//a[@target="_blank"]');
        
        foreach ($externalLinks as $link) {
            $rel = $link->getAttribute('rel');
            $href = $link->getAttribute('href');
            
            if (!str_contains($rel, 'noopener') || !str_contains($rel, 'noreferrer')) {
                $violations[] = new Violation(
                    'frontend-security-target-blank-vulnerability',
                    "Link with target=\"_blank\" should include rel=\"noopener noreferrer\": {$href}",
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_medium_security'],
                    Violation::TYPE_SECURITY
                );
            }
        }
        
        // Check for forms without CSRF protection
        $forms = $xpath->query('//form[@method="post" or @method="POST"]');
        
        foreach ($forms as $form) {
            $csrfFields = $xpath->query('.//input[@name="csrf_token" or @name="_token" or contains(@name, "csrf")]', $form);
            
            if ($csrfFields->length === 0) {
                $action = $form->getAttribute('action') ?: 'current page';
                $violations[] = new Violation(
                    'frontend-security-missing-csrf-protection',
                    "Form may be missing CSRF protection: {$action}",
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_high_security'],
                    Violation::TYPE_SECURITY
                );
            }
        }
        
        // Check for password fields without autocomplete="off"
        $passwordFields = $xpath->query('//input[@type="password"]');
        
        foreach ($passwordFields as $field) {
            $autocomplete = $field->getAttribute('autocomplete');
            
            if (empty($autocomplete)) {
                $violations[] = new Violation(
                    'frontend-security-password-autocomplete',
                    'Password field should specify autocomplete attribute for security',
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_low_security'],
                    Violation::TYPE_SECURITY
                );
            }
        }
        
        return $violations;
    }

    private function checkThirdPartySecurity(\DOMXPath $xpath, string $filePath): array
    {
        $violations = [];
        
        // Check external script sources
        $externalScripts = $xpath->query('//script[@src[starts-with(., "http")]]');
        
        foreach ($externalScripts as $script) {
            $src = $script->getAttribute('src');
            $integrity = $script->getAttribute('integrity');
            $crossorigin = $script->getAttribute('crossorigin');
            
            // Check for missing SRI (Subresource Integrity)
            if (empty($integrity)) {
                $violations[] = new Violation(
                    'frontend-security-missing-sri',
                    "External script missing integrity attribute: {$src}",
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_medium_security'],
                    Violation::TYPE_SECURITY
                );
            }
            
            // Check for untrusted domains
            $domain = parse_url($src, PHP_URL_HOST);
            if (!$this->isTrustedDomain($domain)) {
                $violations[] = new Violation(
                    'frontend-security-untrusted-script-domain',
                    "Script from potentially untrusted domain: {$domain}",
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_medium_security'],
                    Violation::TYPE_SECURITY
                );
            }
        }
        
        // Check external stylesheets
        $externalStyles = $xpath->query('//link[@rel="stylesheet"][@href[starts-with(., "http")]]');
        
        foreach ($externalStyles as $style) {
            $href = $style->getAttribute('href');
            $integrity = $style->getAttribute('integrity');
            
            if (empty($integrity)) {
                $violations[] = new Violation(
                    'frontend-security-stylesheet-missing-sri',
                    "External stylesheet missing integrity attribute: {$href}",
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_low_security'],
                    Violation::TYPE_SECURITY
                );
            }
        }
        
        return $violations;
    }

    private function analyzeJavaScriptSecurity(array $jsFiles): array
    {
        $violations = [];
        
        foreach ($jsFiles as $file) {
            $violations = array_merge($violations, $this->analyzeJavaScriptFile($file));
        }
        
        return $violations;
    }

    private function analyzeJavaScriptFile(string $filePath): array
    {
        $violations = [];
        $content = file_get_contents($filePath);
        
        if (!$content) {
            return $violations;
        }
        
        // Check for dangerous JavaScript functions
        foreach ($this->configuration['dangerous_javascript_functions'] as $function) {
            if (preg_match('/\b' . preg_quote($function) . '\s*\(/', $content)) {
                $violations[] = new Violation(
                    'frontend-security-dangerous-js-function',
                    "Dangerous JavaScript function detected: {$function}",
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_high_security'],
                    Violation::TYPE_SECURITY
                );
            }
        }
        
        // Check for hardcoded sensitive data
        foreach ($this->configuration['sensitive_data_patterns'] as $pattern) {
            if (preg_match('/' . $pattern . '\s*[:=]\s*["\'][^"\']+["\']/', $content, $matches)) {
                $violations[] = new Violation(
                    'frontend-security-hardcoded-sensitive-data',
                    "Potential hardcoded sensitive data: {$matches[0]}",
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_critical_security'],
                    Violation::TYPE_SECURITY
                );
            }
        }
        
        // Check for console.log in production code
        if (preg_match('/console\.(log|debug|info|warn|error)/', $content)) {
            $violations[] = new Violation(
                'frontend-security-console-statements',
                'Console statements may leak sensitive information in production',
                $filePath,
                0,
                0,
                $this->configuration['severity_low_security'],
                Violation::TYPE_SECURITY
            );
        }
        
        // Check for localStorage/sessionStorage usage with sensitive data
        if (preg_match('/(localStorage|sessionStorage)\.(setItem|getItem)/', $content)) {
            foreach ($this->configuration['sensitive_data_patterns'] as $pattern) {
                if (str_contains($content, $pattern)) {
                    $violations[] = new Violation(
                        'frontend-security-sensitive-data-storage',
                        'Storing potentially sensitive data in browser storage',
                        $filePath,
                        0,
                        0,
                        $this->configuration['severity_medium_security'],
                        Violation::TYPE_SECURITY
                    );
                    break;
                }
            }
        }
        
        // Check for unsafe jQuery usage
        if (str_contains($content, 'jQuery') || str_contains($content, '$')) {
            if (preg_match('/\$\(["\'][^"\']*\+[^"\']*["\']/', $content)) {
                $violations[] = new Violation(
                    'frontend-security-jquery-injection',
                    'Potential jQuery injection vulnerability through string concatenation',
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_high_security'],
                    Violation::TYPE_SECURITY
                );
            }
        }
        
        // Check for postMessage usage without origin validation
        if (str_contains($content, 'postMessage')) {
            if (!str_contains($content, 'origin') || !str_contains($content, 'event.origin')) {
                $violations[] = new Violation(
                    'frontend-security-postmessage-no-origin-check',
                    'postMessage usage without proper origin validation',
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_high_security'],
                    Violation::TYPE_SECURITY
                );
            }
        }
        
        return $violations;
    }

    private function analyzeCssSecurity(array $cssFiles): array
    {
        $violations = [];
        
        foreach ($cssFiles as $file) {
            $violations = array_merge($violations, $this->analyzeCssFile($file));
        }
        
        return $violations;
    }

    private function analyzeCssFile(string $filePath): array
    {
        $violations = [];
        $content = file_get_contents($filePath);
        
        if (!$content) {
            return $violations;
        }
        
        // Check for expression() usage (IE-specific vulnerability)
        if (str_contains($content, 'expression(')) {
            $violations[] = new Violation(
                'frontend-security-css-expression',
                'CSS expression() usage can lead to code injection vulnerabilities',
                $filePath,
                0,
                0,
                $this->configuration['severity_high_security'],
                Violation::TYPE_SECURITY
            );
        }
        
        // Check for javascript: URLs in CSS
        if (preg_match('/url\s*\(\s*["\']?javascript:/', $content)) {
            $violations[] = new Violation(
                'frontend-security-css-javascript-url',
                'JavaScript URLs in CSS can lead to code execution',
                $filePath,
                0,
                0,
                $this->configuration['severity_high_security'],
                Violation::TYPE_SECURITY
            );
        }
        
        // Check for data URLs with potential scripts
        if (preg_match('/url\s*\(\s*["\']?data:[^)]*script/', $content)) {
            $violations[] = new Violation(
                'frontend-security-css-data-url-script',
                'Data URL containing script content in CSS',
                $filePath,
                0,
                0,
                $this->configuration['severity_medium_security'],
                Violation::TYPE_SECURITY
            );
        }
        
        return $violations;
    }

    private function analyzeConfigSecurity(array $configFiles): array
    {
        $violations = [];
        
        foreach ($configFiles as $file) {
            $violations = array_merge($violations, $this->analyzeConfigFile($file));
        }
        
        return $violations;
    }

    private function analyzeConfigFile(string $filePath): array
    {
        $violations = [];
        $content = file_get_contents($filePath);
        
        if (!$content) {
            return $violations;
        }
        
        $fileName = basename($filePath);
        
        // Analyze .htaccess files
        if ($fileName === '.htaccess') {
            $violations = array_merge($violations, $this->analyzeHtaccessSecurity($content, $filePath));
        }
        
        // Analyze package.json for security dependencies
        if ($fileName === 'package.json') {
            $violations = array_merge($violations, $this->analyzePackageJsonSecurity($content, $filePath));
        }
        
        // Check for exposed configuration files
        if (in_array($fileName, ['.env', 'config.js', 'config.json'])) {
            foreach ($this->configuration['sensitive_data_patterns'] as $pattern) {
                if (preg_match('/' . $pattern . '/i', $content)) {
                    $violations[] = new Violation(
                        'frontend-security-exposed-config',
                        "Configuration file may contain sensitive data: {$fileName}",
                        $filePath,
                        0,
                        0,
                        $this->configuration['severity_medium_security'],
                        Violation::TYPE_SECURITY
                    );
                    break;
                }
            }
        }
        
        return $violations;
    }

    private function analyzeHtaccessSecurity(string $content, string $filePath): array
    {
        $violations = [];
        
        // Check for security headers in .htaccess
        $securityHeaders = [
            'X-Content-Type-Options' => 'Header always set X-Content-Type-Options nosniff',
            'X-Frame-Options' => 'Header always set X-Frame-Options',
            'X-XSS-Protection' => 'Header always set X-XSS-Protection'
        ];
        
        foreach ($securityHeaders as $header => $directive) {
            if (!str_contains($content, $header)) {
                $violations[] = new Violation(
                    'frontend-security-htaccess-missing-header',
                    "Missing security header in .htaccess: {$header}",
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_medium_security'],
                    Violation::TYPE_SECURITY
                );
            }
        }
        
        // Check for directory listing prevention
        if (!str_contains($content, 'Options -Indexes')) {
            $violations[] = new Violation(
                'frontend-security-htaccess-directory-listing',
                '.htaccess should prevent directory listing with "Options -Indexes"',
                $filePath,
                0,
                0,
                $this->configuration['severity_medium_security'],
                Violation::TYPE_SECURITY
            );
        }
        
        return $violations;
    }

    private function analyzePackageJsonSecurity(string $content, string $filePath): array
    {
        $violations = [];
        
        $package = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $violations;
        }
        
        // Check for security-related dependencies
        $securityPackages = [
            'helmet',
            'cors',
            'express-rate-limit',
            'csurf',
            'express-validator'
        ];
        
        $dependencies = array_merge(
            $package['dependencies'] ?? [],
            $package['devDependencies'] ?? []
        );
        
        $hasSecurityPackages = false;
        foreach ($securityPackages as $securityPackage) {
            if (isset($dependencies[$securityPackage])) {
                $hasSecurityPackages = true;
                break;
            }
        }
        
        if (!$hasSecurityPackages && isset($package['dependencies']['express'])) {
            $violations[] = new Violation(
                'frontend-security-missing-security-middleware',
                'Express app should include security middleware (helmet, cors, etc.)',
                $filePath,
                0,
                0,
                $this->configuration['severity_medium_security'],
                Violation::TYPE_SECURITY
            );
        }
        
        return $violations;
    }

    private function isTrustedDomain(string $domain): bool
    {
        if (empty($domain)) {
            return false;
        }
        
        foreach ($this->configuration['trusted_domains'] as $trustedDomain) {
            if (str_ends_with($domain, $trustedDomain)) {
                return true;
            }
        }
        
        return false;
    }

    private function createDOMDocument(string $content): ?\DOMDocument
    {
        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = false;
        
        if (!str_contains($content, '<html') && !str_contains($content, '<!DOCTYPE')) {
            $content = '<!DOCTYPE html><html><head></head><body>' . $content . '</body></html>';
        }
        
        $previousUseErrors = libxml_use_internal_errors(true);
        $success = @$dom->loadHTML($content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_use_internal_errors($previousUseErrors);
        
        return $success ? $dom : null;
    }

    private function getFileExtension(string $filePath): string
    {
        $pathInfo = pathinfo($filePath);
        return '.' . ($pathInfo['extension'] ?? '');
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
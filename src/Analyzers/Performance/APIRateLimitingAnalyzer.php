<?php

declare(strict_types=1);

namespace ACQC\Analyzers\Performance;

use ACQC\Analyzers\AbstractAnalyzer;
use ACQC\Core\Rules\Violation;

/**
 * Analyzes REST/GraphQL API rate limiting configuration
 * 
 * Ensures APIs are protected from abuse and DDoS attacks
 * through proper rate limiting implementation.
 */
class APIRateLimitingAnalyzer extends AbstractAnalyzer
{
    private const RULE_NO_RATE_LIMITING = 'api-no-rate-limiting';
    private const RULE_LIMITS_TOO_HIGH = 'api-limits-too-high';
    private const RULE_NO_AUTH_REQUIRED = 'api-no-auth-required';
    private const RULE_GRAPHQL_COMPLEXITY_UNLIMITED = 'graphql-complexity-unlimited';
    
    // Recommended limits
    private const RECOMMENDED_ANONYMOUS_RPM = 100; // requests per minute
    private const RECOMMENDED_AUTHENTICATED_RPM = 600;
    private const RECOMMENDED_GRAPHQL_COMPLEXITY = 300;
    
    public function getName(): string
    {
        return 'api-rate-limiting';
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
            'check_rest_api' => true,
            'check_graphql_api' => true,
            'check_auth_requirements' => true,
            'check_complexity_limits' => true,
        ];
    }
    
    protected function executeAnalysis(array $files, array $options = []): array
    {
        return [];
    }
    
    protected function parseOutput(string $output, array $files): array
    {
        return [];
    }
    
    public function analyze(array $files, array $options = []): array
    {
        $violations = [];
        $magentoRoot = $options['magento_root'] ?? $this->findMagentoRoot($files);
        
        if (!$magentoRoot) {
            return $violations;
        }
        
        // Check REST API configuration
        if ($this->configuration['check_rest_api']) {
            $restViolations = $this->checkRESTConfiguration($magentoRoot);
            $violations = array_merge($violations, $restViolations);
        }
        
        // Check GraphQL configuration
        if ($this->configuration['check_graphql_api']) {
            $graphqlViolations = $this->checkGraphQLConfiguration($magentoRoot);
            $violations = array_merge($violations, $graphqlViolations);
        }
        
        return $violations;
    }
    
    private function checkRESTConfiguration(string $magentoRoot): array
    {
        $violations = [];
        $envFile = $magentoRoot . '/app/etc/env.php';
        
        if (!file_exists($envFile)) {
            return $violations;
        }
        
        $env = include $envFile;
        
        // Check if rate limiting is configured
        $rateLimitConfigured = isset($env['api']['rate_limit']) || 
                              isset($env['webapi']['rate_limit']);
        
        if (!$rateLimitConfigured) {
            $violation = new Violation(
                self::RULE_NO_RATE_LIMITING,
                'REST API rate limiting is not configured. APIs without rate limiting are vulnerable to abuse and DDoS attacks. ' .
                'Implement rate limiting to protect backend resources.',
                $envFile,
                0,
                0,
                Violation::SEVERITY_ERROR,
                Violation::TYPE_SECURITY
            );
            
            $violation->setCategory('security');
            $violation->setMetadata([
                'impact' => 'high',
                'vulnerability_type' => 'dos',
            ]);
            
            $violation->setSuggestion(
                "Implement API rate limiting:\n\n" .
                "1. Configure in app/etc/env.php:\n\n" .
                "'webapi' => [\n" .
                "    'rate_limit' => [\n" .
                "        'enabled' => true,\n" .
                "        'anonymous' => " . self::RECOMMENDED_ANONYMOUS_RPM . ",  // requests/minute\n" .
                "        'authenticated' => " . self::RECOMMENDED_AUTHENTICATED_RPM . ",\n" .
                "    ]\n" .
                "],\n\n" .
                "2. Use Nginx rate limiting:\n" .
                "   limit_req_zone \$binary_remote_addr zone=api:10m rate=10r/s;\n" .
                "   location /rest {\n" .
                "       limit_req zone=api burst=20 nodelay;\n" .
                "   }\n\n" .
                "3. Use Cloudflare/CDN rate limiting\n\n" .
                "4. Monitor API usage:\n" .
                "   tail -f /var/log/nginx/access.log | grep '/rest/'"
            );
            
            $violations[] = $violation;
        }
        
        return $violations;
    }
    
    private function checkGraphQLConfiguration(string $magentoRoot): array
    {
        $violations = [];
        $envFile = $magentoRoot . '/app/etc/env.php';
        
        if (!file_exists($envFile)) {
            return $violations;
        }
        
        $env = include $envFile;
        
        // Check GraphQL complexity limits
        $complexityConfigured = isset($env['graphql']['complexity_limit']) ||
                               isset($env['graphql']['max_complexity']);
        
        if (!$complexityConfigured) {
            $violation = new Violation(
                self::RULE_GRAPHQL_COMPLEXITY_UNLIMITED,
                'GraphQL query complexity is unlimited. Complex queries can cause severe performance degradation or DoS. ' .
                'Set complexity limits to prevent expensive queries.',
                $envFile,
                0,
                0,
                Violation::SEVERITY_WARNING,
                Violation::TYPE_PERFORMANCE
            );
            
            $violation->setCategory('performance');
            $violation->setSuggestion(
                "Configure GraphQL complexity limits:\n\n" .
                "In app/etc/env.php:\n\n" .
                "'graphql' => [\n" .
                "    'complexity_limit' => " . self::RECOMMENDED_GRAPHQL_COMPLEXITY . ",\n" .
                "    'depth_limit' => 20,\n" .
                "],\n\n" .
                "Or in di.xml:\n" .
                "<type name=\"Magento\\\\GraphQl\\\\Model\\\\Query\\\\ContextFactory\">\n" .
                "    <arguments>\n" .
                "        <argument name=\"maxQueryComplexity\" xsi:type=\"number\">" . self::RECOMMENDED_GRAPHQL_COMPLEXITY . "</argument>\n" .
                "        <argument name=\"maxQueryDepth\" xsi:type=\"number\">20</argument>\n" .
                "    </arguments>\n" .
                "</type>\n\n" .
                "Restart after changes:\n" .
                "  bin/magento cache:flush"
            );
            
            $violations[] = $violation;
        }
        
        return $violations;
    }
    
    private function findMagentoRoot(array $files): ?string
    {
        if (empty($files)) {
            return getcwd();
        }
        
        $firstFile = reset($files);
        $dir = is_dir($firstFile) ? $firstFile : dirname($firstFile);
        
        $maxDepth = 10;
        for ($i = 0; $i < $maxDepth; $i++) {
            if (file_exists($dir . '/bin/magento')) {
                return $dir;
            }
            $parent = dirname($dir);
            if ($parent === $dir) break;
            $dir = $parent;
        }
        
        return null;
    }
}

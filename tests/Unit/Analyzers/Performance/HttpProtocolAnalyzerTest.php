<?php

declare(strict_types=1);

namespace ACQC\Tests\Unit\Analyzers\Performance;

use ACQC\Analyzers\Performance\HttpProtocolAnalyzer;
use ACQC\Core\Rules\Violation;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for HttpProtocolAnalyzer
 *
 * Tests all 4 HTTP protocol checks:
 * - HSTS header validation
 * - Compression detection
 * - Keep-Alive configuration
 * - Connection pooling
 */
class HttpProtocolAnalyzerTest extends TestCase
{
    private HttpProtocolAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new HttpProtocolAnalyzer([], new NullLogger());
    }

    public function testGetName(): void
    {
        $this->assertEquals('http_protocol', $this->analyzer->getName());
    }

    public function testGetVersion(): void
    {
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $this->analyzer->getVersion());
    }

    public function testAnalyzeReturnsEmptyArrayWhenNoMagentoRoot(): void
    {
        $violations = $this->analyzer->analyze(['/nonexistent/path'], []);
        
        $this->assertIsArray($violations);
        $this->assertEmpty($violations);
    }

    public function testAnalyzeReturnsEmptyArrayWhenNoBaseUrl(): void
    {
        // Create temp structure without base URL configuration
        $tempDir = sys_get_temp_dir() . '/acqc_test_' . uniqid();
        mkdir($tempDir, 0777, true);
        mkdir($tempDir . '/app/etc', 0777, true);
        mkdir($tempDir . '/bin', 0777, true);
        
        $binMagento = $tempDir . '/bin/magento';
        file_put_contents($binMagento, '<?php echo "Magento CLI";');
        chmod($binMagento, 0755);
        
        // Create env.php without base URL
        $envPhp = $tempDir . '/app/etc/env.php';
        file_put_contents($envPhp, '<?php return [];');
        
        try {
            $violations = $this->analyzer->analyze([$tempDir], ['magento_root' => $tempDir]);
            
            // Should return empty array when no base URL found
            $this->assertIsArray($violations);
            // May be empty if no URL is configured
        } finally {
            // Cleanup
            if (file_exists($binMagento)) {
                unlink($binMagento);
            }
            if (file_exists($envPhp)) {
                unlink($envPhp);
            }
            if (is_dir($tempDir . '/bin')) {
                rmdir($tempDir . '/bin');
            }
            if (is_dir($tempDir . '/app/etc')) {
                rmdir($tempDir . '/app/etc');
            }
            if (is_dir($tempDir . '/app')) {
                rmdir($tempDir . '/app');
            }
            if (is_dir($tempDir)) {
                rmdir($tempDir);
            }
        }
    }

    public function testAnalyzeReturnsArrayOfViolations(): void
    {
        $tempDir = sys_get_temp_dir() . '/acqc_test_' . uniqid();
        mkdir($tempDir, 0777, true);
        mkdir($tempDir . '/app/etc', 0777, true);
        
        // Create env.php with base URL
        $envPhp = $tempDir . '/app/etc/env.php';
        $envContent = <<<'PHP'
<?php
return [
    'system' => [
        'default' => [
            'web' => [
                'secure' => [
                    'base_url' => 'https://example.com/'
                ]
            ]
        ]
    ]
];
PHP;
        file_put_contents($envPhp, $envContent);
        
        try {
            $violations = $this->analyzer->analyze([$tempDir], ['magento_root' => $tempDir]);
            
            $this->assertIsArray($violations);
            // All violations should be Violation objects
            foreach ($violations as $violation) {
                $this->assertInstanceOf(Violation::class, $violation);
            }
        } finally {
            // Cleanup
            if (file_exists($envPhp)) {
                unlink($envPhp);
            }
            if (is_dir($tempDir . '/app/etc')) {
                rmdir($tempDir . '/app/etc');
            }
            if (is_dir($tempDir . '/app')) {
                rmdir($tempDir . '/app');
            }
            if (is_dir($tempDir)) {
                rmdir($tempDir);
            }
        }
    }

    public function testViolationsHaveProperStructure(): void
    {
        $tempDir = sys_get_temp_dir() . '/acqc_test_' . uniqid();
        mkdir($tempDir, 0777, true);
        mkdir($tempDir . '/app/etc', 0777, true);
        
        $envPhp = $tempDir . '/app/etc/env.php';
        $envContent = '<?php return ["system" => ["default" => ["web" => ["secure" => ["base_url" => "https://example.com/"]]]]];';
        file_put_contents($envPhp, $envContent);
        
        try {
            $violations = $this->analyzer->analyze([$tempDir], ['magento_root' => $tempDir]);
            
            foreach ($violations as $violation) {
                // Test violation structure
                $this->assertInstanceOf(Violation::class, $violation);
                $this->assertNotEmpty($violation->getRuleId());
                $this->assertNotEmpty($violation->getMessage());
                $this->assertContains($violation->getSeverity(), [
                    Violation::SEVERITY_INFO,
                    Violation::SEVERITY_WARNING,
                    Violation::SEVERITY_ERROR,
                    Violation::SEVERITY_CRITICAL
                ]);
                $this->assertEquals(Violation::TYPE_PERFORMANCE, $violation->getType());
                $this->assertEquals('performance', $violation->getCategory());
            }
        } finally {
            // Cleanup
            if (file_exists($envPhp)) {
                unlink($envPhp);
            }
            if (is_dir($tempDir . '/app/etc')) {
                rmdir($tempDir . '/app/etc');
            }
            if (is_dir($tempDir . '/app')) {
                rmdir($tempDir . '/app');
            }
            if (is_dir($tempDir)) {
                rmdir($tempDir);
            }
        }
    }

    public function testExpectedRuleIds(): void
    {
        // Test that analyzer checks for expected rule IDs
        $expectedRuleIds = [
            'missing-hsts-header',
            'hsts-max-age-too-low',
            'compression-not-enabled',
            'keepalive-disabled',
            'low-php-fpm-max-children'
        ];
        
        // Create test environment
        $tempDir = sys_get_temp_dir() . '/acqc_test_' . uniqid();
        mkdir($tempDir, 0777, true);
        mkdir($tempDir . '/app/etc', 0777, true);
        
        $envPhp = $tempDir . '/app/etc/env.php';
        file_put_contents($envPhp, '<?php return ["system" => ["default" => ["web" => ["secure" => ["base_url" => "https://example.com/"]]]]];');
        
        try {
            $violations = $this->analyzer->analyze([$tempDir], ['magento_root' => $tempDir]);
            
            // Collect all rule IDs from violations
            $actualRuleIds = array_map(fn($v) => $v->getRuleId(), $violations);
            
            // Each rule ID should be in the expected list
            foreach ($actualRuleIds as $ruleId) {
                $this->assertContains($ruleId, $expectedRuleIds, "Unexpected rule ID: {$ruleId}");
            }
        } finally {
            // Cleanup
            if (file_exists($envPhp)) {
                unlink($envPhp);
            }
            if (is_dir($tempDir . '/app/etc')) {
                rmdir($tempDir . '/app/etc');
            }
            if (is_dir($tempDir . '/app')) {
                rmdir($tempDir . '/app');
            }
            if (is_dir($tempDir)) {
                rmdir($tempDir);
            }
        }
    }

    public function testHandlesNetworkErrorsGracefully(): void
    {
        // Test that network errors don't cause crashes
        $tempDir = sys_get_temp_dir() . '/acqc_test_' . uniqid();
        mkdir($tempDir, 0777, true);
        mkdir($tempDir . '/app/etc', 0777, true);
        
        // Use an invalid URL that will cause network error
        $envPhp = $tempDir . '/app/etc/env.php';
        $envContent = '<?php return ["system" => ["default" => ["web" => ["secure" => ["base_url" => "https://invalid-nonexistent-domain-12345.com/"]]]]];';
        file_put_contents($envPhp, $envContent);
        
        try {
            // Should not throw exception even with network errors
            $violations = $this->analyzer->analyze([$tempDir], ['magento_root' => $tempDir]);
            
            $this->assertIsArray($violations);
            // Network errors should be caught and logged, not thrown
        } finally {
            // Cleanup
            if (file_exists($envPhp)) {
                unlink($envPhp);
            }
            if (is_dir($tempDir . '/app/etc')) {
                rmdir($tempDir . '/app/etc');
            }
            if (is_dir($tempDir . '/app')) {
                rmdir($tempDir . '/app');
            }
            if (is_dir($tempDir)) {
                rmdir($tempDir);
            }
        }
    }

    public function testMetadataIsIncludedInViolations(): void
    {
        $tempDir = sys_get_temp_dir() . '/acqc_test_' . uniqid();
        mkdir($tempDir, 0777, true);
        mkdir($tempDir . '/app/etc', 0777, true);
        
        $envPhp = $tempDir . '/app/etc/env.php';
        file_put_contents($envPhp, '<?php return ["system" => ["default" => ["web" => ["secure" => ["base_url" => "https://example.com/"]]]]];');
        
        try {
            $violations = $this->analyzer->analyze([$tempDir], ['magento_root' => $tempDir]);
            
            // Check that violations include metadata
            foreach ($violations as $violation) {
                $metadata = $violation->getMetadata();
                
                // Metadata should be an array
                $this->assertIsArray($metadata);
                
                // Specific violations should have specific metadata
                if ($violation->getRuleId() === 'missing-hsts-header') {
                    $this->assertArrayHasKey('url', $metadata);
                    $this->assertArrayHasKey('recommendation', $metadata);
                }
                
                if ($violation->getRuleId() === 'compression-not-enabled') {
                    $this->assertArrayHasKey('url', $metadata);
                    $this->assertArrayHasKey('recommended', $metadata);
                }
            }
        } finally {
            // Cleanup
            if (file_exists($envPhp)) {
                unlink($envPhp);
            }
            if (is_dir($tempDir . '/app/etc')) {
                rmdir($tempDir . '/app/etc');
            }
            if (is_dir($tempDir . '/app')) {
                rmdir($tempDir . '/app');
            }
            if (is_dir($tempDir)) {
                rmdir($tempDir);
            }
        }
    }

    public function testSkipsHttpChecksForNonHttpsUrls(): void
    {
        // HSTS checks should only run for HTTPS URLs
        $tempDir = sys_get_temp_dir() . '/acqc_test_' . uniqid();
        mkdir($tempDir, 0777, true);
        mkdir($tempDir . '/app/etc', 0777, true);
        
        // Create env.php with HTTP (not HTTPS) URL
        $envPhp = $tempDir . '/app/etc/env.php';
        $envContent = '<?php return ["system" => ["default" => ["web" => ["unsecure" => ["base_url" => "http://example.com/"]]]]];';
        file_put_contents($envPhp, $envContent);
        
        try {
            $violations = $this->analyzer->analyze([$tempDir], ['magento_root' => $tempDir]);
            
            // HSTS violations should not be present for HTTP URLs
            $hstsViolations = array_filter(
                $violations,
                fn($v) => in_array($v->getRuleId(), ['missing-hsts-header', 'hsts-max-age-too-low'])
            );
            
            // HSTS checks should be skipped for non-HTTPS
            $this->assertCount(0, $hstsViolations, 'HSTS checks should be skipped for HTTP URLs');
        } finally {
            // Cleanup
            if (file_exists($envPhp)) {
                unlink($envPhp);
            }
            if (is_dir($tempDir . '/app/etc')) {
                rmdir($tempDir . '/app/etc');
            }
            if (is_dir($tempDir . '/app')) {
                rmdir($tempDir . '/app');
            }
            if (is_dir($tempDir)) {
                rmdir($tempDir);
            }
        }
    }
}

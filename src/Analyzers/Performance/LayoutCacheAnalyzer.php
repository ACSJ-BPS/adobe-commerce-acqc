<?php

declare(strict_types=1);

namespace ACQC\Analyzers\Performance;

use ACQC\Analyzers\AbstractAnalyzer;
use ACQC\Core\Rules\Violation;

/**
 * Analyzes layout XML files for cacheable="false" blocks
 * 
 * Non-cacheable blocks severely impact Full Page Cache effectiveness.
 * This analyzer identifies blocks that disable caching and provides
 * recommendations for making them cacheable.
 */
class LayoutCacheAnalyzer extends AbstractAnalyzer
{
    private const RULE_NON_CACHEABLE_BLOCK = 'layout-non-cacheable-block';
    private const RULE_MISSING_TTL = 'layout-missing-cache-ttl';
    private const RULE_PRIVATE_CONTENT_CANDIDATE = 'layout-private-content-candidate';
    
    public function getName(): string
    {
        return 'layout-cache';
    }
    
    public function getVersion(): string
    {
        return '1.0.0';
    }
    
    public function isAvailable(): bool
    {
        return true; // Always available, analyzes XML files
    }
    
    protected function getDefaultConfiguration(): array
    {
        return [
            'check_non_cacheable' => true,
            'check_missing_ttl' => true,
            'check_private_content' => true,
            'severity_non_cacheable' => Violation::SEVERITY_ERROR,
            'severity_missing_ttl' => Violation::SEVERITY_WARNING,
            'common_private_blocks' => [
                'customer-name',
                'customer-welcome',
                'cart-sidebar',
                'wishlist-sidebar',
                'compare-products',
            ],
        ];
    }
    
    protected function executeAnalysis(array $files, array $options = []): array
    {
        $layoutFiles = $this->filterLayoutFiles($files);
        
        if (empty($layoutFiles)) {
            return [];
        }
        
        $results = [];
        
        foreach ($layoutFiles as $file) {
            $results[$file] = $this->analyzeLayoutFile($file);
        }
        
        return $results;
    }
    
    protected function parseOutput(string $output, array $files): array
    {
        // Not used - we analyze directly
        return [];
    }
    
    /**
     * Main analysis method
     */
    public function analyze(array $files, array $options = []): array
    {
        $violations = [];
        
        $layoutFiles = $this->filterLayoutFiles($files);
        
        foreach ($layoutFiles as $file) {
            $violations = array_merge(
                $violations,
                $this->analyzeLayoutFile($file)
            );
        }
        
        return $violations;
    }
    
    private function filterLayoutFiles(array $files): array
    {
        return array_filter($files, function($file) {
            // Layout files are in view/*/layout/ directories
            return preg_match('#/view/[^/]+/layout/.*\.xml$#', $file) && file_exists($file);
        });
    }
    
    private function analyzeLayoutFile(string $file): array
    {
        $violations = [];
        
        if (!file_exists($file)) {
            return $violations;
        }
        
        $content = file_get_contents($file);
        
        if ($content === false) {
            return $violations;
        }
        
        // Try to parse XML
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($content);
        
        if ($xml === false) {
            // Invalid XML, skip
            libxml_clear_errors();
            return $violations;
        }
        
        // Check for non-cacheable blocks
        if ($this->configuration['check_non_cacheable']) {
            $violations = array_merge(
                $violations,
                $this->checkNonCacheableBlocks($xml, $file, $content)
            );
        }
        
        // Check for missing cache TTL
        if ($this->configuration['check_missing_ttl']) {
            $violations = array_merge(
                $violations,
                $this->checkMissingCacheTTL($xml, $file)
            );
        }
        
        // Check for private content candidates
        if ($this->configuration['check_private_content']) {
            $violations = array_merge(
                $violations,
                $this->checkPrivateContentCandidates($xml, $file)
            );
        }
        
        return $violations;
    }
    
    private function checkNonCacheableBlocks($xml, string $file, string $content): array
    {
        $violations = [];
        
        // Find all blocks with cacheable="false"
        $blocks = $xml->xpath('//block[@cacheable="false"]');
        
        if (empty($blocks)) {
            return $violations;
        }
        
        foreach ($blocks as $block) {
            $blockName = (string)($block['name'] ?? 'unknown');
            $blockClass = (string)($block['class'] ?? 'unknown');
            
            // Find line number
            $lineNumber = $this->findLineNumber($content, $blockName);
            
            $violation = new Violation(
                self::RULE_NON_CACHEABLE_BLOCK,
                sprintf(
                    'Block "%s" has cacheable="false" which disables Full Page Cache for the entire page. ' .
                    'This severely impacts performance. Consider: (1) Making block cacheable, ' .
                    '(2) Using private content (UI components), (3) Using ESI/Ajax to load dynamic parts.',
                    $blockName
                ),
                $file,
                $lineNumber,
                0,
                $this->configuration['severity_non_cacheable'],
                Violation::TYPE_PERFORMANCE
            );
            
            $violation->setCategory('performance');
            $violation->setMetadata([
                'block_name' => $blockName,
                'block_class' => $blockClass,
                'impact' => 'high',
                'affects_fpc' => true,
            ]);
            
            $violation->setSuggestion(
                "Solutions to make this block cacheable:\n\n" .
                "1. Use Private Content (Recommended):\n" .
                "   - Move dynamic data to customer-data sections\n" .
                "   - Use knockout.js to render client-side\n" .
                "   - Block becomes cacheable, data loads via AJAX\n\n" .
                "2. Use ESI (Edge Side Includes):\n" .
                "   - Available with Varnish/Fastly\n" .
                "   - Cache page with holes for dynamic blocks\n\n" .
                "3. Remove dynamic content:\n" .
                "   - If possible, make content truly static\n" .
                "   - Consider if personalization is necessary\n\n" .
                "4. Use AJAX:\n" .
                "   - Load dynamic content after page load\n" .
                "   - Requires custom JavaScript\n\n" .
                "Example: Convert customer name block to private content:\n" .
                "In layout XML:\n" .
                "  <block name=\"customer.name\" cacheable=\"true\">\n" .
                "    <arguments>\n" .
                "      <argument name=\"jsLayout\">\n" .
                "        <item name=\"components\">\n" .
                "          <item name=\"customer\" xsi:type=\"array\">\n" .
                "            <item name=\"component\">Vendor_Module/js/customer-data</item>\n" .
                "          </item>\n" .
                "        </item>\n" .
                "      </argument>\n" .
                "    </arguments>\n" .
                "  </block>\n\n" .
                "In requirejs-config.js:\n" .
                "  var config = {\n" .
                "    deps: ['Vendor_Module/js/customer-data']\n" .
                "  };\n\n" .
                "In js/customer-data.js:\n" .
                "  define(['jquery', 'Magento_Customer/js/customer-data'], function($, customerData) {\n" .
                "    var customer = customerData.get('customer');\n" .
                "    customer.subscribe(function(data) {\n" .
                "      $('.customer-name').text(data.firstname);\n" .
                "    });\n" .
                "  });"
            );
            
            $violations[] = $violation;
        }
        
        return $violations;
    }
    
    private function checkMissingCacheTTL($xml, string $file): array
    {
        $violations = [];
        
        // Find cacheable blocks without TTL
        $cacheableBlocks = $xml->xpath('//block[@cacheable="true" or not(@cacheable)]');
        
        foreach ($cacheableBlocks as $block) {
            // Check if block has cache_lifetime argument
            $hasLifetime = !empty($block->xpath('.//argument[@name="cache_lifetime"]'));
            
            if (!$hasLifetime) {
                $blockName = (string)($block['name'] ?? 'unknown');
                
                // Only warn for custom blocks (not Magento core)
                if (!$this->isCoreBlock($blockName)) {
                    $violation = new Violation(
                        self::RULE_MISSING_TTL,
                        sprintf(
                            'Cacheable block "%s" does not specify cache_lifetime. ' .
                            'Without explicit TTL, block uses default cache lifetime which may not be optimal.',
                            $blockName
                        ),
                        $file,
                        0,
                        0,
                        $this->configuration['severity_missing_ttl'],
                        Violation::TYPE_PERFORMANCE
                    );
                    
                    $violation->setCategory('performance');
                    $violation->setSuggestion(
                        "Add cache_lifetime argument to block:\n\n" .
                        "<block name=\"{$blockName}\">\n" .
                        "  <arguments>\n" .
                        "    <argument name=\"cache_lifetime\" xsi:type=\"number\">3600</argument>\n" .
                        "  </arguments>\n" .
                        "</block>\n\n" .
                        "Common TTL values:\n" .
                        "- 3600 (1 hour) - Frequently changing content\n" .
                        "- 86400 (24 hours) - Daily updates\n" .
                        "- 604800 (1 week) - Rarely changing content\n" .
                        "- null - Cache indefinitely (only for static content)"
                    );
                    
                    $violations[] = $violation;
                }
            }
        }
        
        return $violations;
    }
    
    private function checkPrivateContentCandidates($xml, string $file): array
    {
        $violations = [];
        
        // Check for blocks with names suggesting customer-specific content
        $blocks = $xml->xpath('//block');
        
        foreach ($blocks as $block) {
            $blockName = (string)($block['name'] ?? '');
            $isCacheable = !isset($block['cacheable']) || (string)$block['cacheable'] !== 'false';
            
            // Check if block name suggests private content
            foreach ($this->configuration['common_private_blocks'] as $privatePattern) {
                if (stripos($blockName, $privatePattern) !== false && $isCacheable) {
                    $violation = new Violation(
                        self::RULE_PRIVATE_CONTENT_CANDIDATE,
                        sprintf(
                            'Block "%s" appears to contain customer-specific content but is marked cacheable. ' .
                            'Verify this block does not display personalized data, or use private content sections.',
                            $blockName
                        ),
                        $file,
                        0,
                        0,
                        Violation::SEVERITY_INFO,
                        Violation::TYPE_PERFORMANCE
                    );
                    
                    $violation->setCategory('performance');
                    $violation->setSuggestion(
                        "If this block displays customer-specific data:\n\n" .
                        "1. Use Magento's customer-data sections\n" .
                        "2. Load data via JavaScript after page load\n" .
                        "3. Keep block cacheable for FPC\n\n" .
                        "If block is truly static:\n" .
                        "1. Ignore this warning\n" .
                        "2. Block name may be misleading"
                    );
                    
                    $violations[] = $violation;
                    break; // Only report once per block
                }
            }
        }
        
        return $violations;
    }
    
    private function findLineNumber(string $content, string $blockName): int
    {
        $lines = explode("\n", $content);
        
        foreach ($lines as $lineNum => $line) {
            if (strpos($line, "name=\"{$blockName}\"") !== false) {
                return $lineNum + 1; // 1-indexed
            }
        }
        
        return 0;
    }
    
    private function isCoreBlock(string $blockName): bool
    {
        // Check if block name suggests it's from Magento core
        $corePatterns = [
            'catalog.',
            'product.',
            'category.',
            'checkout.',
            'cart.',
            'customer.account',
            'cms.',
        ];
        
        foreach ($corePatterns as $pattern) {
            if (strpos($blockName, $pattern) === 0) {
                return true;
            }
        }
        
        return false;
    }
}

<?php

declare(strict_types=1);

namespace ACQC\Core\AutoFix\Generators;

use ACQC\Core\AutoFix\FixCommand;
use ACQC\Core\AutoFix\FixGeneratorInterface;
use ACQC\Core\Rules\Violation;

/**
 * Fix generator for Magento configuration issues
 * 
 * Generates fix commands for common Magento configuration problems like:
 * - Disabled flat catalog
 * - Disabled caches
 * - Disabled CSS/JS merging and minification
 * - Suboptimal production settings
 */
class MagentoConfigFixGenerator implements FixGeneratorInterface
{
    private const FIXABLE_RULES = [
        'config-flat-catalog-disabled',
        'config-flat-catalog-product-disabled',
        'config-flat-catalog-category-disabled',
        'config-cache-disabled',
        'config-merge-css-disabled',
        'config-merge-js-disabled',
        'config-minify-html-disabled',
        'config-minify-css-disabled',
        'config-minify-js-disabled',
        'config-production-mode-disabled',
        'config-static-sign-disabled',
        'config-di-compile-missing',
        'performance-flat-catalog-disabled',
        'performance-merge-css-disabled',
        'performance-merge-js-disabled',
    ];
    
    public function getName(): string
    {
        return 'magento_config';
    }
    
    public function getSupportedRules(): array
    {
        return self::FIXABLE_RULES;
    }
    
    public function supports(Violation $violation): bool
    {
        return in_array($violation->getRuleId(), self::FIXABLE_RULES, true);
    }
    
    public function generate(Violation $violation): ?FixCommand
    {
        return match($violation->getRuleId()) {
            'config-flat-catalog-disabled',
            'performance-flat-catalog-disabled' => $this->fixFlatCatalog(),
            
            'config-flat-catalog-product-disabled' => $this->fixFlatCatalogProduct(),
            'config-flat-catalog-category-disabled' => $this->fixFlatCatalogCategory(),
            
            'config-cache-disabled' => $this->fixCacheConfig(),
            
            'config-merge-css-disabled',
            'performance-merge-css-disabled' => $this->fixCssMerging(),
            
            'config-merge-js-disabled',
            'performance-merge-js-disabled' => $this->fixJsMerging(),
            
            'config-minify-html-disabled' => $this->fixHtmlMinification(),
            'config-minify-css-disabled' => $this->fixCssMinification(),
            'config-minify-js-disabled' => $this->fixJsMinification(),
            
            'config-production-mode-disabled' => $this->fixProductionMode(),
            'config-static-sign-disabled' => $this->fixStaticContentSigning(),
            'config-di-compile-missing' => $this->fixDiCompile(),
            
            default => null
        };
    }
    
    private function fixFlatCatalog(): FixCommand
    {
        return new FixCommand(
            command: 'bin/magento config:set catalog/frontend/flat_catalog_product 1 && ' .
                    'bin/magento config:set catalog/frontend/flat_catalog_category 1 && ' .
                    'bin/magento indexer:reindex catalog_product_flat catalog_category_flat && ' .
                    'bin/magento cache:flush',
            description: 'Enable flat catalog for products and categories',
            safetyChecks: [
                [
                    'condition' => '[ -f "bin/magento" ]',
                    'message' => 'Magento CLI not found'
                ],
                [
                    'condition' => '[ -w "app/etc/env.php" ] || [ -w "app/etc" ]',
                    'message' => 'app/etc/env.php is not writable'
                ],
            ],
            rollback: 'bin/magento config:set catalog/frontend/flat_catalog_product 0 && ' .
                     'bin/magento config:set catalog/frontend/flat_catalog_category 0',
            requiresConfirmation: false,
            priority: 8
        );
    }
    
    private function fixFlatCatalogProduct(): FixCommand
    {
        return new FixCommand(
            command: 'bin/magento config:set catalog/frontend/flat_catalog_product 1 && ' .
                    'bin/magento indexer:reindex catalog_product_flat && ' .
                    'bin/magento cache:flush',
            description: 'Enable flat catalog for products',
            safetyChecks: [
                [
                    'condition' => '[ -f "bin/magento" ]',
                    'message' => 'Magento CLI not found'
                ],
            ],
            rollback: 'bin/magento config:set catalog/frontend/flat_catalog_product 0',
            priority: 7
        );
    }
    
    private function fixFlatCatalogCategory(): FixCommand
    {
        return new FixCommand(
            command: 'bin/magento config:set catalog/frontend/flat_catalog_category 1 && ' .
                    'bin/magento indexer:reindex catalog_category_flat && ' .
                    'bin/magento cache:flush',
            description: 'Enable flat catalog for categories',
            safetyChecks: [
                [
                    'condition' => '[ -f "bin/magento" ]',
                    'message' => 'Magento CLI not found'
                ],
            ],
            rollback: 'bin/magento config:set catalog/frontend/flat_catalog_category 0',
            priority: 7
        );
    }
    
    private function fixCacheConfig(): FixCommand
    {
        return new FixCommand(
            command: 'bin/magento cache:enable && bin/magento cache:flush',
            description: 'Enable all Magento caches',
            safetyChecks: [
                [
                    'condition' => '[ -f "bin/magento" ]',
                    'message' => 'Magento CLI not found'
                ],
            ],
            priority: 10  // Highest priority - cache is critical
        );
    }
    
    private function fixCssMerging(): FixCommand
    {
        return new FixCommand(
            command: 'bin/magento config:set dev/css/merge_css_files 1 && ' .
                    'bin/magento cache:flush config full_page',
            description: 'Enable CSS file merging',
            safetyChecks: [
                [
                    'condition' => '[ -f "bin/magento" ]',
                    'message' => 'Magento CLI not found'
                ],
            ],
            rollback: 'bin/magento config:set dev/css/merge_css_files 0',
            priority: 6
        );
    }
    
    private function fixJsMerging(): FixCommand
    {
        return new FixCommand(
            command: 'bin/magento config:set dev/js/merge_files 1 && ' .
                    'bin/magento cache:flush config full_page',
            description: 'Enable JavaScript file merging',
            safetyChecks: [
                [
                    'condition' => '[ -f "bin/magento" ]',
                    'message' => 'Magento CLI not found'
                ],
            ],
            rollback: 'bin/magento config:set dev/js/merge_files 0',
            priority: 6
        );
    }
    
    private function fixHtmlMinification(): FixCommand
    {
        return new FixCommand(
            command: 'bin/magento config:set dev/template/minify_html 1 && ' .
                    'bin/magento cache:flush config full_page',
            description: 'Enable HTML minification',
            safetyChecks: [
                [
                    'condition' => '[ -f "bin/magento" ]',
                    'message' => 'Magento CLI not found'
                ],
            ],
            rollback: 'bin/magento config:set dev/template/minify_html 0',
            priority: 5
        );
    }
    
    private function fixCssMinification(): FixCommand
    {
        return new FixCommand(
            command: 'bin/magento config:set dev/css/minify_files 1 && ' .
                    'bin/magento cache:flush config full_page',
            description: 'Enable CSS minification',
            safetyChecks: [
                [
                    'condition' => '[ -f "bin/magento" ]',
                    'message' => 'Magento CLI not found'
                ],
            ],
            rollback: 'bin/magento config:set dev/css/minify_files 0',
            priority: 5
        );
    }
    
    private function fixJsMinification(): FixCommand
    {
        return new FixCommand(
            command: 'bin/magento config:set dev/js/minify_files 1 && ' .
                    'bin/magento cache:flush config full_page',
            description: 'Enable JavaScript minification',
            safetyChecks: [
                [
                    'condition' => '[ -f "bin/magento" ]',
                    'message' => 'Magento CLI not found'
                ],
            ],
            rollback: 'bin/magento config:set dev/js/minify_files 0',
            priority: 5
        );
    }
    
    private function fixProductionMode(): FixCommand
    {
        return new FixCommand(
            command: 'bin/magento deploy:mode:set production --skip-compilation',
            description: 'Enable production mode (without compilation, run separately)',
            safetyChecks: [
                [
                    'condition' => '[ -f "bin/magento" ]',
                    'message' => 'Magento CLI not found'
                ],
                [
                    'condition' => '[ "$(bin/magento deploy:mode:show)" != "production" ]',
                    'message' => 'Already in production mode'
                ],
            ],
            rollback: 'bin/magento deploy:mode:set developer',
            requiresConfirmation: true,  // Mode change requires confirmation
            priority: 9
        );
    }
    
    private function fixStaticContentSigning(): FixCommand
    {
        return new FixCommand(
            command: 'bin/magento config:set dev/static/sign 1 && ' .
                    'bin/magento cache:flush config',
            description: 'Enable static content signing',
            safetyChecks: [
                [
                    'condition' => '[ -f "bin/magento" ]',
                    'message' => 'Magento CLI not found'
                ],
            ],
            rollback: 'bin/magento config:set dev/static/sign 0',
            priority: 4
        );
    }
    
    private function fixDiCompile(): FixCommand
    {
        return new FixCommand(
            command: 'bin/magento setup:di:compile',
            description: 'Run dependency injection compilation',
            safetyChecks: [
                [
                    'condition' => '[ -f "bin/magento" ]',
                    'message' => 'Magento CLI not found'
                ],
                [
                    'condition' => '[ -w "generated" ] || mkdir -p generated',
                    'message' => 'generated/ directory not writable'
                ],
            ],
            requiresConfirmation: true,  // DI compile can take time
            priority: 8
        );
    }
}

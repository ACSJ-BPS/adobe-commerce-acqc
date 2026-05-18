<?php

declare(strict_types=1);

namespace ACQC\Analyzers\Frontend;

use ACQC\Analyzers\AbstractAnalyzer;
use ACQC\Analyzers\AnalyzerException;
use ACQC\Core\Rules\Violation;

class AccessibilityAnalyzer extends AbstractAnalyzer
{
    private array $ariaRoles = [];
    private array $wcagViolations = [];
    
    public function getName(): string
    {
        return 'accessibility-analyzer';
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
            'check_wcag_compliance' => true,
            'check_aria_patterns' => true,
            'check_semantic_html' => true,
            'check_keyboard_navigation' => true,
            'check_color_contrast' => true,
            'check_focus_management' => true,
            'check_screen_reader_support' => true,
            'check_form_accessibility' => true,
            'severity_critical_a11y' => Violation::SEVERITY_ERROR,
            'severity_important_a11y' => Violation::SEVERITY_WARNING,
            'severity_recommended_a11y' => Violation::SEVERITY_INFO,
            'wcag_level' => 'AA', // A, AA, or AAA
            'supported_file_types' => ['.html', '.htm', '.php', '.phtml', '.jsx', '.tsx'],
            'required_alt_elements' => ['img', 'area', 'input[type="image"]'],
            'interactive_elements' => [
                'a', 'button', 'input', 'select', 'textarea', 'details', 'summary'
            ],
            'heading_elements' => ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'],
            'landmark_roles' => [
                'banner', 'navigation', 'main', 'complementary', 
                'contentinfo', 'region', 'search', 'form'
            ],
            'valid_aria_roles' => [
                'alert', 'alertdialog', 'application', 'article', 'banner',
                'button', 'cell', 'checkbox', 'columnheader', 'combobox',
                'complementary', 'contentinfo', 'definition', 'dialog',
                'directory', 'document', 'feed', 'figure', 'form', 'grid',
                'gridcell', 'group', 'heading', 'img', 'link', 'list',
                'listbox', 'listitem', 'log', 'main', 'marquee', 'math',
                'menu', 'menubar', 'menuitem', 'menuitemcheckbox',
                'menuitemradio', 'navigation', 'none', 'note', 'option',
                'presentation', 'progressbar', 'radio', 'radiogroup',
                'region', 'row', 'rowgroup', 'rowheader', 'scrollbar',
                'search', 'searchbox', 'separator', 'slider', 'spinbutton',
                'status', 'switch', 'tab', 'table', 'tablist', 'tabpanel',
                'term', 'textbox', 'timer', 'toolbar', 'tooltip', 'tree',
                'treegrid', 'treeitem'
            ],
            'color_contrast_ratios' => [
                'normal_aa' => 4.5,
                'large_aa' => 3.0,
                'normal_aaa' => 7.0,
                'large_aaa' => 4.5
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
        $this->supportedFileExtensions = ['html', 'htm', 'php', 'phtml', 'jsx', 'tsx'];
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
        $this->logger->info('Starting accessibility analysis');
        
        $violations = [];
        
        foreach ($files as $file) {
            if (!$this->shouldAnalyzeFile($file) || !$this->isAccessibilityRelevantFile($file)) {
                continue;
            }
            
            $violations = array_merge($violations, $this->analyzeAccessibilityFile($file));
        }
        
        $this->logger->info('Found ' . count($violations) . ' accessibility violations');
        
        return $violations;
    }

    protected function parseOutput(string $output, array $files): array
    {
        return [];
    }

    private function isAccessibilityRelevantFile(string $filePath): bool
    {
        $extension = $this->getFileExtension($filePath);
        return in_array($extension, $this->configuration['supported_file_types']);
    }

    private function analyzeAccessibilityFile(string $filePath): array
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
        
        // WCAG compliance checks
        $violations = array_merge($violations, $this->checkWCAGCompliance($xpath, $filePath));
        
        // ARIA patterns and roles
        $violations = array_merge($violations, $this->checkARIAPatterns($xpath, $filePath));
        
        // Semantic HTML structure
        $violations = array_merge($violations, $this->checkSemanticHTML($xpath, $filePath));
        
        // Keyboard navigation support
        $violations = array_merge($violations, $this->checkKeyboardNavigation($xpath, $filePath));
        
        // Form accessibility
        $violations = array_merge($violations, $this->checkFormAccessibility($xpath, $filePath));
        
        // Focus management
        $violations = array_merge($violations, $this->checkFocusManagement($xpath, $filePath));
        
        // Screen reader support
        $violations = array_merge($violations, $this->checkScreenReaderSupport($xpath, $filePath));
        
        return $violations;
    }

    private function checkWCAGCompliance(\DOMXPath $xpath, string $filePath): array
    {
        $violations = [];
        
        // WCAG 1.1.1 - Non-text Content
        $violations = array_merge($violations, $this->checkNonTextContent($xpath, $filePath));
        
        // WCAG 1.3.1 - Info and Relationships
        $violations = array_merge($violations, $this->checkInfoAndRelationships($xpath, $filePath));
        
        // WCAG 1.4.3 - Contrast (Minimum)
        $violations = array_merge($violations, $this->checkColorContrast($xpath, $filePath));
        
        // WCAG 2.1.1 - Keyboard
        $violations = array_merge($violations, $this->checkKeyboardAccess($xpath, $filePath));
        
        // WCAG 2.4.1 - Bypass Blocks
        $violations = array_merge($violations, $this->checkBypassBlocks($xpath, $filePath));
        
        // WCAG 2.4.2 - Page Titled
        $violations = array_merge($violations, $this->checkPageTitle($xpath, $filePath));
        
        // WCAG 2.4.6 - Headings and Labels
        $violations = array_merge($violations, $this->checkHeadingsAndLabels($xpath, $filePath));
        
        // WCAG 3.1.1 - Language of Page
        $violations = array_merge($violations, $this->checkLanguageOfPage($xpath, $filePath));
        
        // WCAG 4.1.2 - Name, Role, Value
        $violations = array_merge($violations, $this->checkNameRoleValue($xpath, $filePath));
        
        return $violations;
    }

    private function checkNonTextContent(\DOMXPath $xpath, string $filePath): array
    {
        $violations = [];
        
        // Check images for alt text
        $images = $xpath->query('//img');
        foreach ($images as $image) {
            $alt = $image->getAttribute('alt');
            $src = $image->getAttribute('src');
            $role = $image->getAttribute('role');
            
            if ($role === 'presentation' || $role === 'none') {
                if (!empty($alt)) {
                    $violations[] = new Violation(
                        'a11y-decorative-image-with-alt',
                        "Decorative image should have empty alt text: {$src}",
                        $filePath,
                        0,
                        0,
                        $this->configuration['severity_important_a11y'],
                        Violation::TYPE_ACCESSIBILITY
                    );
                }
            } elseif (!$image->hasAttribute('alt')) {
                $violations[] = new Violation(
                    'a11y-missing-alt-text',
                    "Image missing alt text: {$src}",
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_critical_a11y'],
                    Violation::TYPE_ACCESSIBILITY
                );
            } elseif (empty($alt) && $role !== 'presentation' && $role !== 'none') {
                $violations[] = new Violation(
                    'a11y-empty-alt-text',
                    "Non-decorative image has empty alt text: {$src}",
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_critical_a11y'],
                    Violation::TYPE_ACCESSIBILITY
                );
            }
        }
        
        // Check area elements in image maps
        $areas = $xpath->query('//area');
        foreach ($areas as $area) {
            if (!$area->hasAttribute('alt')) {
                $href = $area->getAttribute('href');
                $violations[] = new Violation(
                    'a11y-area-missing-alt',
                    "Area element missing alt text: {$href}",
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_critical_a11y'],
                    Violation::TYPE_ACCESSIBILITY
                );
            }
        }
        
        // Check input[type="image"] for alt text
        $imageInputs = $xpath->query('//input[@type="image"]');
        foreach ($imageInputs as $input) {
            if (!$input->hasAttribute('alt')) {
                $src = $input->getAttribute('src');
                $violations[] = new Violation(
                    'a11y-input-image-missing-alt',
                    "Input image missing alt text: {$src}",
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_critical_a11y'],
                    Violation::TYPE_ACCESSIBILITY
                );
            }
        }
        
        return $violations;
    }

    private function checkInfoAndRelationships(\DOMXPath $xpath, string $filePath): array
    {
        $violations = [];
        
        // Check for proper heading hierarchy
        $headings = $xpath->query('//h1 | //h2 | //h3 | //h4 | //h5 | //h6');
        $previousLevel = 0;
        
        foreach ($headings as $heading) {
            $level = intval(substr($heading->nodeName, 1));
            
            if ($previousLevel > 0 && $level > $previousLevel + 1) {
                $violations[] = new Violation(
                    'a11y-heading-skip-level',
                    "Heading hierarchy skips from h{$previousLevel} to h{$level}",
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_important_a11y'],
                    Violation::TYPE_ACCESSIBILITY
                );
            }
            
            if (empty(trim($heading->textContent))) {
                $violations[] = new Violation(
                    'a11y-empty-heading',
                    "Empty heading element: {$heading->nodeName}",
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_critical_a11y'],
                    Violation::TYPE_ACCESSIBILITY
                );
            }
            
            $previousLevel = $level;
        }
        
        // Check for proper list structure
        $lists = $xpath->query('//ul | //ol');
        foreach ($lists as $list) {
            $listItems = $xpath->query('./li', $list);
            if ($listItems->length === 0) {
                $violations[] = new Violation(
                    'a11y-empty-list',
                    "List element has no list items: {$list->nodeName}",
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_important_a11y'],
                    Violation::TYPE_ACCESSIBILITY
                );
            }
        }
        
        // Check for proper table structure
        $tables = $xpath->query('//table');
        foreach ($tables as $table) {
            $caption = $xpath->query('./caption', $table);
            $headers = $xpath->query('.//th', $table);
            
            if ($caption->length === 0 && !$table->hasAttribute('aria-label') && !$table->hasAttribute('aria-labelledby')) {
                $violations[] = new Violation(
                    'a11y-table-missing-caption',
                    'Table missing caption or accessible name',
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_important_a11y'],
                    Violation::TYPE_ACCESSIBILITY
                );
            }
            
            if ($headers->length === 0) {
                $violations[] = new Violation(
                    'a11y-table-missing-headers',
                    'Table missing header cells (th elements)',
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_critical_a11y'],
                    Violation::TYPE_ACCESSIBILITY
                );
            }
        }
        
        return $violations;
    }

    private function checkColorContrast(\DOMXPath $xpath, string $filePath): array
    {
        $violations = [];
        
        // Basic color contrast checks (would need more sophisticated analysis for full compliance)
        $elementsWithStyle = $xpath->query('//*[@style]');
        
        foreach ($elementsWithStyle as $element) {
            $style = $element->getAttribute('style');
            
            // Check for potential contrast issues
            if (preg_match('/color:\s*#?([a-fA-F0-9]{3,6})/', $style, $colorMatch) &&
                preg_match('/background-color:\s*#?([a-fA-F0-9]{3,6})/', $style, $bgMatch)) {
                
                // This is a simplified check - real implementation would calculate actual contrast ratios
                $color = $colorMatch[1];
                $background = $bgMatch[1];
                
                if (strtolower($color) === strtolower($background)) {
                    $violations[] = new Violation(
                        'a11y-no-color-contrast',
                        'Text and background colors are identical',
                        $filePath,
                        0,
                        0,
                        $this->configuration['severity_critical_a11y'],
                        Violation::TYPE_ACCESSIBILITY
                    );
                }
            }
            
            // Check for color-only information
            if (str_contains($style, 'color:red') || str_contains($style, 'color:green')) {
                $violations[] = new Violation(
                    'a11y-color-only-information',
                    'Avoid using color alone to convey information',
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_recommended_a11y'],
                    Violation::TYPE_ACCESSIBILITY
                );
            }
        }
        
        return $violations;
    }

    private function checkKeyboardAccess(\DOMXPath $xpath, string $filePath): array
    {
        $violations = [];
        
        // Check interactive elements for keyboard accessibility
        foreach ($this->configuration['interactive_elements'] as $element) {
            $elements = $xpath->query("//{$element}");
            
            foreach ($elements as $el) {
                $tabindex = $el->getAttribute('tabindex');
                
                // Check for positive tabindex values (anti-pattern)
                if (!empty($tabindex) && intval($tabindex) > 0) {
                    $violations[] = new Violation(
                        'a11y-positive-tabindex',
                        "Avoid positive tabindex values: {$element}",
                        $filePath,
                        0,
                        0,
                        $this->configuration['severity_important_a11y'],
                        Violation::TYPE_ACCESSIBILITY
                    );
                }
                
                // Check for click handlers on non-interactive elements
                if ($element === 'div' || $element === 'span') {
                    $onclick = $el->getAttribute('onclick');
                    if (!empty($onclick) && empty($tabindex) && $el->getAttribute('role') !== 'button') {
                        $violations[] = new Violation(
                            'a11y-click-handler-missing-keyboard',
                            "Element with click handler should be keyboard accessible: {$element}",
                            $filePath,
                            0,
                            0,
                            $this->configuration['severity_critical_a11y'],
                            Violation::TYPE_ACCESSIBILITY
                        );
                    }
                }
            }
        }
        
        // Check for keyboard traps
        $tabIndexMinusOne = $xpath->query('//*[@tabindex="-1"]');
        if ($tabIndexMinusOne->length > 5) { // Heuristic for potential keyboard trap
            $violations[] = new Violation(
                'a11y-potential-keyboard-trap',
                'High number of tabindex="-1" elements may create keyboard traps',
                $filePath,
                0,
                0,
                $this->configuration['severity_recommended_a11y'],
                Violation::TYPE_ACCESSIBILITY
            );
        }
        
        return $violations;
    }

    private function checkBypassBlocks(\DOMXPath $xpath, string $filePath): array
    {
        $violations = [];
        
        // Check for skip links
        $skipLinks = $xpath->query('//a[contains(@href, "#") and (contains(text(), "skip") or contains(@class, "skip"))]');
        
        if ($skipLinks->length === 0) {
            $violations[] = new Violation(
                'a11y-missing-skip-link',
                'Page should include skip links for keyboard navigation',
                $filePath,
                0,
                0,
                $this->configuration['severity_important_a11y'],
                Violation::TYPE_ACCESSIBILITY
            );
        }
        
        // Check for proper landmark structure
        $landmarks = $xpath->query('//*[@role="main" or @role="navigation" or @role="banner" or @role="contentinfo"] | //main | //nav | //header | //footer');
        
        if ($landmarks->length === 0) {
            $violations[] = new Violation(
                'a11y-missing-landmarks',
                'Page should include ARIA landmarks or semantic HTML5 elements',
                $filePath,
                0,
                0,
                $this->configuration['severity_important_a11y'],
                Violation::TYPE_ACCESSIBILITY
            );
        }
        
        return $violations;
    }

    private function checkPageTitle(\DOMXPath $xpath, string $filePath): array
    {
        $violations = [];
        
        $titles = $xpath->query('//title');
        
        if ($titles->length === 0) {
            $violations[] = new Violation(
                'a11y-missing-page-title',
                'Page missing title element',
                $filePath,
                0,
                0,
                $this->configuration['severity_critical_a11y'],
                Violation::TYPE_ACCESSIBILITY
            );
        } else {
            $title = trim($titles->item(0)->textContent);
            
            if (empty($title)) {
                $violations[] = new Violation(
                    'a11y-empty-page-title',
                    'Page title is empty',
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_critical_a11y'],
                    Violation::TYPE_ACCESSIBILITY
                );
            } elseif (strlen($title) < 10) {
                $violations[] = new Violation(
                    'a11y-page-title-too-short',
                    'Page title may be too short to be descriptive',
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_recommended_a11y'],
                    Violation::TYPE_ACCESSIBILITY
                );
            }
        }
        
        return $violations;
    }

    private function checkHeadingsAndLabels(\DOMXPath $xpath, string $filePath): array
    {
        $violations = [];
        
        // Check that headings describe topic or purpose
        $headings = $xpath->query('//h1 | //h2 | //h3 | //h4 | //h5 | //h6');
        
        foreach ($headings as $heading) {
            $text = trim($heading->textContent);
            
            if (strlen($text) < 3) {
                $violations[] = new Violation(
                    'a11y-heading-too-short',
                    "Heading text may be too short to be descriptive: {$heading->nodeName}",
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_recommended_a11y'],
                    Violation::TYPE_ACCESSIBILITY
                );
            }
        }
        
        // Check for descriptive button text
        $buttons = $xpath->query('//button');
        foreach ($buttons as $button) {
            $text = trim($button->textContent);
            $ariaLabel = $button->getAttribute('aria-label');
            $ariaLabelledby = $button->getAttribute('aria-labelledby');
            
            if (empty($text) && empty($ariaLabel) && empty($ariaLabelledby)) {
                $violations[] = new Violation(
                    'a11y-button-missing-accessible-name',
                    'Button missing accessible name',
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_critical_a11y'],
                    Violation::TYPE_ACCESSIBILITY
                );
            } elseif (in_array(strtolower($text), ['click', 'button', 'link', 'here'])) {
                $violations[] = new Violation(
                    'a11y-button-non-descriptive-text',
                    "Button text should be descriptive: '{$text}'",
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_important_a11y'],
                    Violation::TYPE_ACCESSIBILITY
                );
            }
        }
        
        return $violations;
    }

    private function checkLanguageOfPage(\DOMXPath $xpath, string $filePath): array
    {
        $violations = [];
        
        $htmlElements = $xpath->query('//html');
        
        if ($htmlElements->length > 0) {
            $html = $htmlElements->item(0);
            $lang = $html->getAttribute('lang');
            
            if (empty($lang)) {
                $violations[] = new Violation(
                    'a11y-missing-lang-attribute',
                    'HTML element missing lang attribute',
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_critical_a11y'],
                    Violation::TYPE_ACCESSIBILITY
                );
            } elseif (strlen($lang) < 2 || !preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $lang)) {
                $violations[] = new Violation(
                    'a11y-invalid-lang-attribute',
                    "Invalid lang attribute value: {$lang}",
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_important_a11y'],
                    Violation::TYPE_ACCESSIBILITY
                );
            }
        }
        
        return $violations;
    }

    private function checkNameRoleValue(\DOMXPath $xpath, string $filePath): array
    {
        $violations = [];
        
        // Check ARIA roles are valid
        $elementsWithRole = $xpath->query('//*[@role]');
        
        foreach ($elementsWithRole as $element) {
            $role = $element->getAttribute('role');
            $roles = array_map('trim', explode(' ', $role));
            
            foreach ($roles as $singleRole) {
                if (!in_array($singleRole, $this->configuration['valid_aria_roles'])) {
                    $violations[] = new Violation(
                        'a11y-invalid-aria-role',
                        "Invalid ARIA role: {$singleRole}",
                        $filePath,
                        0,
                        0,
                        $this->configuration['severity_critical_a11y'],
                        Violation::TYPE_ACCESSIBILITY
                    );
                }
            }
        }
        
        // Check for proper ARIA attributes
        $violations = array_merge($violations, $this->checkARIAAttributes($xpath, $filePath));
        
        return $violations;
    }

    private function checkARIAPatterns(\DOMXPath $xpath, string $filePath): array
    {
        $violations = [];
        
        // Check for ARIA attributes without corresponding roles
        $violations = array_merge($violations, $this->checkARIAAttributes($xpath, $filePath));
        
        // Check for proper ARIA relationships
        $violations = array_merge($violations, $this->checkARIARelationships($xpath, $filePath));
        
        // Check for ARIA state management
        $violations = array_merge($violations, $this->checkARIAStates($xpath, $filePath));
        
        return $violations;
    }

    private function checkARIAAttributes(\DOMXPath $xpath, string $filePath): array
    {
        $violations = [];
        
        // Check aria-labelledby references
        $elementsWithLabelledby = $xpath->query('//*[@aria-labelledby]');
        
        foreach ($elementsWithLabelledby as $element) {
            $labelledby = $element->getAttribute('aria-labelledby');
            $ids = array_map('trim', explode(' ', $labelledby));
            
            foreach ($ids as $id) {
                $referencedElement = $xpath->query("//*[@id='{$id}']");
                if ($referencedElement->length === 0) {
                    $violations[] = new Violation(
                        'a11y-aria-labelledby-missing-target',
                        "aria-labelledby references non-existent ID: {$id}",
                        $filePath,
                        0,
                        0,
                        $this->configuration['severity_critical_a11y'],
                        Violation::TYPE_ACCESSIBILITY
                    );
                }
            }
        }
        
        // Check aria-describedby references
        $elementsWithDescribedby = $xpath->query('//*[@aria-describedby]');
        
        foreach ($elementsWithDescribedby as $element) {
            $describedby = $element->getAttribute('aria-describedby');
            $ids = array_map('trim', explode(' ', $describedby));
            
            foreach ($ids as $id) {
                $referencedElement = $xpath->query("//*[@id='{$id}']");
                if ($referencedElement->length === 0) {
                    $violations[] = new Violation(
                        'a11y-aria-describedby-missing-target',
                        "aria-describedby references non-existent ID: {$id}",
                        $filePath,
                        0,
                        0,
                        $this->configuration['severity_critical_a11y'],
                        Violation::TYPE_ACCESSIBILITY
                    );
                }
            }
        }
        
        return $violations;
    }

    private function checkARIARelationships(\DOMXPath $xpath, string $filePath): array
    {
        $violations = [];
        
        // Check for proper parent-child relationships
        $listboxes = $xpath->query('//*[@role="listbox"]');
        foreach ($listboxes as $listbox) {
            $options = $xpath->query('.//*[@role="option"]', $listbox);
            if ($options->length === 0) {
                $violations[] = new Violation(
                    'a11y-listbox-missing-options',
                    'Listbox role requires option children',
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_critical_a11y'],
                    Violation::TYPE_ACCESSIBILITY
                );
            }
        }
        
        // Check for proper tab relationships
        $tablists = $xpath->query('//*[@role="tablist"]');
        foreach ($tablists as $tablist) {
            $tabs = $xpath->query('.//*[@role="tab"]', $tablist);
            if ($tabs->length === 0) {
                $violations[] = new Violation(
                    'a11y-tablist-missing-tabs',
                    'Tablist role requires tab children',
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_critical_a11y'],
                    Violation::TYPE_ACCESSIBILITY
                );
            }
        }
        
        return $violations;
    }

    private function checkARIAStates(\DOMXPath $xpath, string $filePath): array
    {
        $violations = [];
        
        // Check for proper button states
        $buttons = $xpath->query('//button[@aria-pressed] | //*[@role="button"][@aria-pressed]');
        foreach ($buttons as $button) {
            $pressed = $button->getAttribute('aria-pressed');
            if (!in_array($pressed, ['true', 'false', 'mixed'])) {
                $violations[] = new Violation(
                    'a11y-invalid-aria-pressed',
                    "Invalid aria-pressed value: {$pressed}",
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_critical_a11y'],
                    Violation::TYPE_ACCESSIBILITY
                );
            }
        }
        
        // Check for proper expanded states
        $expandableElements = $xpath->query('//*[@aria-expanded]');
        foreach ($expandableElements as $element) {
            $expanded = $element->getAttribute('aria-expanded');
            if (!in_array($expanded, ['true', 'false'])) {
                $violations[] = new Violation(
                    'a11y-invalid-aria-expanded',
                    "Invalid aria-expanded value: {$expanded}",
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_critical_a11y'],
                    Violation::TYPE_ACCESSIBILITY
                );
            }
        }
        
        return $violations;
    }

    private function checkSemanticHTML(\DOMXPath $xpath, string $filePath): array
    {
        $violations = [];
        
        // Check for proper use of semantic elements
        $mainElements = $xpath->query('//main');
        if ($mainElements->length === 0) {
            $violations[] = new Violation(
                'a11y-missing-main-element',
                'Page should include a main element for primary content',
                $filePath,
                0,
                0,
                $this->configuration['severity_important_a11y'],
                Violation::TYPE_ACCESSIBILITY
            );
        } elseif ($mainElements->length > 1) {
            $violations[] = new Violation(
                'a11y-multiple-main-elements',
                'Page should have only one main element',
                $filePath,
                0,
                0,
                $this->configuration['severity_critical_a11y'],
                Violation::TYPE_ACCESSIBILITY
            );
        }
        
        // Check for div soup - too many nested divs
        $deepDivs = $xpath->query('//div//div//div//div//div');
        if ($deepDivs->length > 0) {
            $violations[] = new Violation(
                'a11y-excessive-div-nesting',
                'Excessive div nesting detected. Consider using semantic HTML elements.',
                $filePath,
                0,
                0,
                $this->configuration['severity_recommended_a11y'],
                Violation::TYPE_ACCESSIBILITY
            );
        }
        
        return $violations;
    }

    private function checkKeyboardNavigation(\DOMXPath $xpath, string $filePath): array
    {
        $violations = [];
        
        // Check for focus indicators
        $focusableElements = $xpath->query('//a | //button | //input | //select | //textarea | //*[@tabindex]');
        $elementsWithFocusStyle = $xpath->query('//*[contains(@style, ":focus") or contains(@class, "focus")]');
        
        if ($focusableElements->length > 5 && $elementsWithFocusStyle->length === 0) {
            $violations[] = new Violation(
                'a11y-missing-focus-indicators',
                'Interactive elements should have visible focus indicators',
                $filePath,
                0,
                0,
                $this->configuration['severity_important_a11y'],
                Violation::TYPE_ACCESSIBILITY
            );
        }
        
        return $violations;
    }

    private function checkFormAccessibility(\DOMXPath $xpath, string $filePath): array
    {
        $violations = [];
        
        // Check form inputs for labels
        $inputs = $xpath->query('//input[@type!="hidden"] | //select | //textarea');
        
        foreach ($inputs as $input) {
            $id = $input->getAttribute('id');
            $ariaLabel = $input->getAttribute('aria-label');
            $ariaLabelledby = $input->getAttribute('aria-labelledby');
            $title = $input->getAttribute('title');
            
            $hasLabel = false;
            
            if (!empty($id) && preg_match('/^[a-zA-Z0-9_-]+$/', $id)) {
                // Only query if ID contains safe characters
                try {
                    $labels = $xpath->query("//label[@for='{$id}']");
                    $hasLabel = $labels && $labels->length > 0;
                } catch (\Exception $e) {
                    // Invalid XPath, skip
                    $hasLabel = false;
                }
            }
            
            if (!$hasLabel && empty($ariaLabel) && empty($ariaLabelledby) && empty($title)) {
                $name = $input->getAttribute('name') ?: $input->getAttribute('type') ?: 'input';
                $violations[] = new Violation(
                    'a11y-form-input-missing-label',
                    "Form input missing accessible label: {$name}",
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_critical_a11y'],
                    Violation::TYPE_ACCESSIBILITY
                );
            }
        }
        
        // Check for fieldset/legend in form groups
        $forms = $xpath->query('//form');
        foreach ($forms as $form) {
            $radioGroups = $xpath->query('.//input[@type="radio"]', $form);
            $checkboxGroups = $xpath->query('.//input[@type="checkbox"]', $form);
            
            if ($radioGroups->length > 1 || $checkboxGroups->length > 1) {
                $fieldsets = $xpath->query('.//fieldset', $form);
                if ($fieldsets->length === 0) {
                    $violations[] = new Violation(
                        'a11y-form-missing-fieldset',
                        'Form with grouped inputs should use fieldset/legend',
                        $filePath,
                        0,
                        0,
                        $this->configuration['severity_important_a11y'],
                        Violation::TYPE_ACCESSIBILITY
                    );
                }
            }
        }
        
        return $violations;
    }

    private function checkFocusManagement(\DOMXPath $xpath, string $filePath): array
    {
        $violations = [];
        
        // Check for autofocus (can be problematic)
        $autofocusElements = $xpath->query('//*[@autofocus]');
        
        if ($autofocusElements->length > 1) {
            $violations[] = new Violation(
                'a11y-multiple-autofocus',
                'Multiple elements with autofocus can cause confusion',
                $filePath,
                0,
                0,
                $this->configuration['severity_important_a11y'],
                Violation::TYPE_ACCESSIBILITY
            );
        }
        
        return $violations;
    }

    private function checkScreenReaderSupport(\DOMXPath $xpath, string $filePath): array
    {
        $violations = [];
        
        // Check for screen reader only content
        $srOnlyElements = $xpath->query('//*[contains(@class, "sr-only") or contains(@class, "screen-reader")]');
        
        // Check for live regions
        $liveRegions = $xpath->query('//*[@aria-live]');
        $statusElements = $xpath->query('//*[@role="status" or @role="alert"]');
        
        if ($liveRegions->length === 0 && $statusElements->length === 0) {
            // This is just a recommendation, not a violation
            $violations[] = new Violation(
                'a11y-consider-live-regions',
                'Consider using ARIA live regions for dynamic content updates',
                $filePath,
                0,
                0,
                $this->configuration['severity_recommended_a11y'],
                Violation::TYPE_ACCESSIBILITY
            );
        }
        
        return $violations;
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
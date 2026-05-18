<?php

declare(strict_types=1);

namespace ACQC\Analyzers\Frontend;

use ACQC\Analyzers\AbstractAnalyzer;
use ACQC\Analyzers\AnalyzerException;
use ACQC\Core\Rules\Violation;

class SEOAnalyzer extends AbstractAnalyzer
{
    private array $metaTags = [];
    private array $structuredData = [];
    
    public function getName(): string
    {
        return 'seo-analyzer';
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
            'check_meta_tags' => true,
            'check_structured_data' => true,
            'check_heading_structure' => true,
            'check_image_optimization' => true,
            'check_link_optimization' => true,
            'check_content_quality' => true,
            'check_performance_seo' => true,
            'check_mobile_seo' => true,
            'severity_critical_seo' => Violation::SEVERITY_ERROR,
            'severity_important_seo' => Violation::SEVERITY_WARNING,
            'severity_recommended_seo' => Violation::SEVERITY_INFO,
            'required_meta_tags' => [
                'title',
                'description',
                'viewport',
                'charset'
            ],
            'recommended_meta_tags' => [
                'og:title',
                'og:description',
                'og:image',
                'og:url',
                'og:type',
                'twitter:card',
                'twitter:title',
                'twitter:description',
                'twitter:image',
                'canonical'
            ],
            'max_title_length' => 60,
            'max_description_length' => 160,
            'min_description_length' => 120,
            'max_h1_count' => 1,
            'max_heading_levels' => 6,
            'max_alt_text_length' => 125,
            'supported_file_types' => ['.html', '.htm', '.php', '.phtml', '.jsx', '.tsx'],
            'structured_data_types' => [
                'Organization',
                'Product',
                'Article',
                'BreadcrumbList',
                'WebSite',
                'WebPage',
                'LocalBusiness'
            ],
            'image_formats' => [
                'webp' => 'recommended',
                'avif' => 'modern',
                'jpg' => 'standard',
                'jpeg' => 'standard',
                'png' => 'standard',
                'gif' => 'legacy',
                'svg' => 'vector'
            ],
            'exclude_paths' => [
                '*/node_modules/*',
                '*/vendor/*',
                '*/dist/*',
                '*/build/*',
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
        $this->logger->info('Starting SEO analysis');
        
        $violations = [];
        
        foreach ($files as $file) {
            if (!$this->shouldAnalyzeFile($file)) {
                continue;
            }
            
            if ($this->isSEORelevantFile($file)) {
                $violations = array_merge($violations, $this->analyzeSEOFile($file));
            }
        }
        
        $this->logger->info('Found ' . count($violations) . ' SEO violations');
        
        return $violations;
    }

    protected function parseOutput(string $output, array $files): array
    {
        return [];
    }

    private function isSEORelevantFile(string $filePath): bool
    {
        $extension = $this->getFileExtension($filePath);
        return in_array($extension, $this->configuration['supported_file_types']);
    }

    private function analyzeSEOFile(string $filePath): array
    {
        $violations = [];
        $content = file_get_contents($filePath);
        
        if (!$content) {
            return $violations;
        }
        
        // Parse HTML/template content
        $dom = $this->createDOMDocument($content);
        if (!$dom) {
            return $violations;
        }
        
        // Meta tags analysis
        $violations = array_merge($violations, $this->analyzeMetaTags($dom, $filePath));
        
        // Heading structure analysis
        $violations = array_merge($violations, $this->analyzeHeadingStructure($dom, $filePath));
        
        // Image optimization analysis
        $violations = array_merge($violations, $this->analyzeImageOptimization($dom, $filePath));
        
        // Link optimization analysis
        $violations = array_merge($violations, $this->analyzeLinkOptimization($dom, $filePath));
        
        // Structured data analysis
        $violations = array_merge($violations, $this->analyzeStructuredData($dom, $filePath));
        
        // Content quality analysis
        $violations = array_merge($violations, $this->analyzeContentQuality($dom, $filePath));
        
        // Mobile SEO analysis
        $violations = array_merge($violations, $this->analyzeMobileSEO($dom, $filePath));
        
        return $violations;
    }

    private function createDOMDocument(string $content): ?\DOMDocument
    {
        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = false;
        
        // Handle template files and partial HTML
        if (!str_contains($content, '<html') && !str_contains($content, '<!DOCTYPE')) {
            $content = '<!DOCTYPE html><html><head></head><body>' . $content . '</body></html>';
        }
        
        // Suppress warnings for malformed HTML
        $previousUseErrors = libxml_use_internal_errors(true);
        $success = @$dom->loadHTML($content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_use_internal_errors($previousUseErrors);
        
        return $success ? $dom : null;
    }

    private function analyzeMetaTags(\DOMDocument $dom, string $filePath): array
    {
        $violations = [];
        $xpath = new \DOMXPath($dom);
        
        // Check for required meta tags
        foreach ($this->configuration['required_meta_tags'] as $tagName) {
            $found = false;
            
            switch ($tagName) {
                case 'title':
                    $titles = $xpath->query('//title');
                    if ($titles->length === 0) {
                        $violations[] = new Violation(
                            'seo-missing-title',
                            'Missing title tag',
                            $filePath,
                            0,
                            0,
                            $this->configuration['severity_critical_seo'],
                            Violation::TYPE_SEO
                        );
                    } else {
                        $title = trim($titles->item(0)->textContent);
                        if (empty($title)) {
                            $violations[] = new Violation(
                                'seo-empty-title',
                                'Title tag is empty',
                                $filePath,
                                0,
                                0,
                                $this->configuration['severity_critical_seo'],
                                Violation::TYPE_SEO
                            );
                        } elseif (strlen($title) > $this->configuration['max_title_length']) {
                            $violations[] = new Violation(
                                'seo-title-too-long',
                                "Title length ({$title}) exceeds recommended maximum of {$this->configuration['max_title_length']} characters",
                                $filePath,
                                0,
                                0,
                                $this->configuration['severity_important_seo'],
                                Violation::TYPE_SEO
                            );
                        }
                    }
                    break;
                    
                case 'description':
                    $descriptions = $xpath->query('//meta[@name="description"]');
                    if ($descriptions->length === 0) {
                        $violations[] = new Violation(
                            'seo-missing-description',
                            'Missing meta description',
                            $filePath,
                            0,
                            0,
                            $this->configuration['severity_critical_seo'],
                            Violation::TYPE_SEO
                        );
                    } else {
                        $description = trim($descriptions->item(0)->getAttribute('content'));
                        if (empty($description)) {
                            $violations[] = new Violation(
                                'seo-empty-description',
                                'Meta description is empty',
                                $filePath,
                                0,
                                0,
                                $this->configuration['severity_critical_seo'],
                                Violation::TYPE_SEO
                            );
                        } else {
                            $descLength = strlen($description);
                            if ($descLength > $this->configuration['max_description_length']) {
                                $violations[] = new Violation(
                                    'seo-description-too-long',
                                    "Meta description length ({$descLength}) exceeds recommended maximum of {$this->configuration['max_description_length']} characters",
                                    $filePath,
                                    0,
                                    0,
                                    $this->configuration['severity_important_seo'],
                                    Violation::TYPE_SEO
                                );
                            } elseif ($descLength < $this->configuration['min_description_length']) {
                                $violations[] = new Violation(
                                    'seo-description-too-short',
                                    "Meta description length ({$descLength}) is below recommended minimum of {$this->configuration['min_description_length']} characters",
                                    $filePath,
                                    0,
                                    0,
                                    $this->configuration['severity_recommended_seo'],
                                    Violation::TYPE_SEO
                                );
                            }
                        }
                    }
                    break;
                    
                case 'viewport':
                    $viewports = $xpath->query('//meta[@name="viewport"]');
                    if ($viewports->length === 0) {
                        $violations[] = new Violation(
                            'seo-missing-viewport',
                            'Missing viewport meta tag for mobile optimization',
                            $filePath,
                            0,
                            0,
                            $this->configuration['severity_important_seo'],
                            Violation::TYPE_SEO
                        );
                    }
                    break;
                    
                case 'charset':
                    $charsets = $xpath->query('//meta[@charset]');
                    if ($charsets->length === 0) {
                        $violations[] = new Violation(
                            'seo-missing-charset',
                            'Missing charset declaration',
                            $filePath,
                            0,
                            0,
                            $this->configuration['severity_important_seo'],
                            Violation::TYPE_SEO
                        );
                    }
                    break;
            }
        }
        
        // Check for recommended meta tags (Open Graph, Twitter Cards)
        foreach ($this->configuration['recommended_meta_tags'] as $tagName) {
            $found = false;
            
            if (str_starts_with($tagName, 'og:')) {
                $ogTags = $xpath->query("//meta[@property='{$tagName}']");
                $found = $ogTags->length > 0;
            } elseif (str_starts_with($tagName, 'twitter:')) {
                $twitterTags = $xpath->query("//meta[@name='{$tagName}']");
                $found = $twitterTags->length > 0;
            } elseif ($tagName === 'canonical') {
                $canonicalTags = $xpath->query('//link[@rel="canonical"]');
                $found = $canonicalTags->length > 0;
            }
            
            if (!$found) {
                $violations[] = new Violation(
                    'seo-missing-recommended-meta',
                    "Missing recommended meta tag: {$tagName}",
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_recommended_seo'],
                    Violation::TYPE_SEO
                );
            }
        }
        
        return $violations;
    }

    private function analyzeHeadingStructure(\DOMDocument $dom, string $filePath): array
    {
        $violations = [];
        $xpath = new \DOMXPath($dom);
        
        // Check H1 tags
        $h1Tags = $xpath->query('//h1');
        if ($h1Tags->length === 0) {
            $violations[] = new Violation(
                'seo-missing-h1',
                'Missing H1 tag - important for SEO structure',
                $filePath,
                0,
                0,
                $this->configuration['severity_important_seo'],
                Violation::TYPE_SEO
            );
        } elseif ($h1Tags->length > $this->configuration['max_h1_count']) {
            $violations[] = new Violation(
                'seo-multiple-h1',
                "Multiple H1 tags found ({$h1Tags->length}). Use only one H1 per page.",
                $filePath,
                0,
                0,
                $this->configuration['severity_important_seo'],
                Violation::TYPE_SEO
            );
        }
        
        // Check heading hierarchy
        $headingLevels = [];
        for ($i = 1; $i <= 6; $i++) {
            $headings = $xpath->query("//h{$i}");
            if ($headings->length > 0) {
                $headingLevels[$i] = $headings->length;
            }
        }
        
        // Check for skipped heading levels
        $previousLevel = 0;
        foreach ($headingLevels as $level => $count) {
            if ($previousLevel > 0 && $level > $previousLevel + 1) {
                $violations[] = new Violation(
                    'seo-skipped-heading-level',
                    "Heading hierarchy skips from H{$previousLevel} to H{$level}. Use sequential heading levels.",
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_recommended_seo'],
                    Violation::TYPE_SEO
                );
            }
            $previousLevel = $level;
        }
        
        // Check for empty headings
        for ($i = 1; $i <= 6; $i++) {
            $headings = $xpath->query("//h{$i}");
            foreach ($headings as $heading) {
                if (empty(trim($heading->textContent))) {
                    $violations[] = new Violation(
                        'seo-empty-heading',
                        "Empty H{$i} tag found",
                        $filePath,
                        0,
                        0,
                        $this->configuration['severity_important_seo'],
                        Violation::TYPE_SEO
                    );
                }
            }
        }
        
        return $violations;
    }

    private function analyzeImageOptimization(\DOMDocument $dom, string $filePath): array
    {
        $violations = [];
        $xpath = new \DOMXPath($dom);
        
        $images = $xpath->query('//img');
        
        foreach ($images as $image) {
            $src = $image->getAttribute('src');
            $alt = $image->getAttribute('alt');
            
            // Check for missing alt text
            if (empty($alt)) {
                $violations[] = new Violation(
                    'seo-missing-alt-text',
                    "Image missing alt text: {$src}",
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_important_seo'],
                    Violation::TYPE_SEO
                );
            } elseif (strlen($alt) > $this->configuration['max_alt_text_length']) {
                $violations[] = new Violation(
                    'seo-alt-text-too-long',
                    "Alt text too long ({$alt}): {$src}",
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_recommended_seo'],
                    Violation::TYPE_SEO
                );
            }
            
            // Check image format optimization
            if (!empty($src)) {
                $extension = strtolower(pathinfo($src, PATHINFO_EXTENSION));
                if (isset($this->configuration['image_formats'][$extension])) {
                    $format = $this->configuration['image_formats'][$extension];
                    if ($format === 'legacy') {
                        $violations[] = new Violation(
                            'seo-legacy-image-format',
                            "Consider modern image format for better performance: {$src}",
                            $filePath,
                            0,
                            0,
                            $this->configuration['severity_recommended_seo'],
                            Violation::TYPE_PERFORMANCE
                        );
                    }
                }
            }
            
            // Check for missing width/height attributes
            if (!$image->hasAttribute('width') || !$image->hasAttribute('height')) {
                $violations[] = new Violation(
                    'seo-missing-image-dimensions',
                    "Image missing width/height attributes for layout stability: {$src}",
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_recommended_seo'],
                    Violation::TYPE_PERFORMANCE
                );
            }
            
            // Check for loading="lazy" on non-critical images
            if (!$image->hasAttribute('loading')) {
                $violations[] = new Violation(
                    'seo-missing-lazy-loading',
                    "Consider adding loading=\"lazy\" for performance: {$src}",
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_recommended_seo'],
                    Violation::TYPE_PERFORMANCE
                );
            }
        }
        
        return $violations;
    }

    private function analyzeLinkOptimization(\DOMDocument $dom, string $filePath): array
    {
        $violations = [];
        $xpath = new \DOMXPath($dom);
        
        // Check external links
        $externalLinks = $xpath->query('//a[starts-with(@href, "http")]');
        
        foreach ($externalLinks as $link) {
            $href = $link->getAttribute('href');
            $rel = $link->getAttribute('rel');
            $target = $link->getAttribute('target');
            
            // Check for missing rel="noopener" on target="_blank" links
            if ($target === '_blank' && !str_contains($rel, 'noopener')) {
                $violations[] = new Violation(
                    'seo-missing-noopener',
                    "External link with target=\"_blank\" should include rel=\"noopener\": {$href}",
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_important_seo'],
                    Violation::TYPE_SECURITY
                );
            }
            
            // Check for missing rel="nofollow" on untrusted external links
            if (!str_contains($rel, 'nofollow') && !$this->isTrustedDomain($href)) {
                $violations[] = new Violation(
                    'seo-consider-nofollow',
                    "Consider adding rel=\"nofollow\" to untrusted external link: {$href}",
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_recommended_seo'],
                    Violation::TYPE_SEO
                );
            }
        }
        
        // Check for empty link text
        $allLinks = $xpath->query('//a[@href]');
        foreach ($allLinks as $link) {
            $linkText = trim($link->textContent);
            $href = $link->getAttribute('href');
            
            if (empty($linkText) && !$xpath->query('.//img', $link)->length) {
                $violations[] = new Violation(
                    'seo-empty-link-text',
                    "Link has no descriptive text: {$href}",
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_important_seo'],
                    Violation::TYPE_SEO
                );
            } elseif (in_array(strtolower($linkText), ['click here', 'read more', 'link', 'here'])) {
                $violations[] = new Violation(
                    'seo-poor-link-text',
                    "Use descriptive link text instead of \"{$linkText}\": {$href}",
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_recommended_seo'],
                    Violation::TYPE_SEO
                );
            }
        }
        
        return $violations;
    }

    private function analyzeStructuredData(\DOMDocument $dom, string $filePath): array
    {
        $violations = [];
        $xpath = new \DOMXPath($dom);
        
        // Check for JSON-LD structured data
        $jsonLdScripts = $xpath->query('//script[@type="application/ld+json"]');
        
        if ($jsonLdScripts->length === 0) {
            $violations[] = new Violation(
                'seo-missing-structured-data',
                'Consider adding structured data (JSON-LD) for better search visibility',
                $filePath,
                0,
                0,
                $this->configuration['severity_recommended_seo'],
                Violation::TYPE_SEO
            );
        } else {
            foreach ($jsonLdScripts as $script) {
                $jsonContent = trim($script->textContent);
                if (!empty($jsonContent)) {
                    $structuredData = json_decode($jsonContent, true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        $violations[] = new Violation(
                            'seo-invalid-structured-data',
                            'Invalid JSON-LD structured data: ' . json_last_error_msg(),
                            $filePath,
                            0,
                            0,
                            $this->configuration['severity_important_seo'],
                            Violation::TYPE_SEO
                        );
                    } else {
                        // Validate common structured data requirements
                        $violations = array_merge($violations, $this->validateStructuredData($structuredData, $filePath));
                    }
                }
            }
        }
        
        return $violations;
    }

    private function validateStructuredData(array $data, string $filePath): array
    {
        $violations = [];
        
        if (isset($data['@type'])) {
            $type = $data['@type'];
            
            // Validate common structured data types
            switch ($type) {
                case 'Product':
                    $requiredFields = ['name', 'image', 'description'];
                    foreach ($requiredFields as $field) {
                        if (!isset($data[$field])) {
                            $violations[] = new Violation(
                                'seo-product-structured-data-incomplete',
                                "Product structured data missing required field: {$field}",
                                $filePath,
                                0,
                                0,
                                $this->configuration['severity_recommended_seo'],
                                Violation::TYPE_SEO
                            );
                        }
                    }
                    break;
                    
                case 'Organization':
                    if (!isset($data['name']) || !isset($data['url'])) {
                        $violations[] = new Violation(
                            'seo-organization-structured-data-incomplete',
                            'Organization structured data should include name and url',
                            $filePath,
                            0,
                            0,
                            $this->configuration['severity_recommended_seo'],
                            Violation::TYPE_SEO
                        );
                    }
                    break;
            }
        }
        
        return $violations;
    }

    private function analyzeContentQuality(\DOMDocument $dom, string $filePath): array
    {
        $violations = [];
        $xpath = new \DOMXPath($dom);
        
        // Check content length
        $bodyContent = $xpath->query('//body')->item(0);
        if ($bodyContent) {
            $textContent = trim(strip_tags($bodyContent->textContent));
            $wordCount = str_word_count($textContent);
            
            if ($wordCount < 300) {
                $violations[] = new Violation(
                    'seo-thin-content',
                    "Page content is thin ({$wordCount} words). Consider adding more valuable content.",
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_recommended_seo'],
                    Violation::TYPE_SEO
                );
            }
        }
        
        // Check for duplicate content indicators
        $paragraphs = $xpath->query('//p');
        $paragraphTexts = [];
        foreach ($paragraphs as $paragraph) {
            $text = trim($paragraph->textContent);
            if (!empty($text) && strlen($text) > 50) {
                if (in_array($text, $paragraphTexts)) {
                    $violations[] = new Violation(
                        'seo-duplicate-content',
                        'Duplicate paragraph content detected',
                        $filePath,
                        0,
                        0,
                        $this->configuration['severity_recommended_seo'],
                        Violation::TYPE_SEO
                    );
                }
                $paragraphTexts[] = $text;
            }
        }
        
        return $violations;
    }

    private function analyzeMobileSEO(\DOMDocument $dom, string $filePath): array
    {
        $violations = [];
        $xpath = new \DOMXPath($dom);
        
        // Check for mobile viewport
        $viewports = $xpath->query('//meta[@name="viewport"]');
        if ($viewports->length > 0) {
            $content = $viewports->item(0)->getAttribute('content');
            if (!str_contains($content, 'width=device-width')) {
                $violations[] = new Violation(
                    'seo-mobile-viewport-incorrect',
                    'Viewport should include width=device-width for mobile optimization',
                    $filePath,
                    0,
                    0,
                    $this->configuration['severity_important_seo'],
                    Violation::TYPE_SEO
                );
            }
        }
        
        // Check for mobile-friendly font sizes
        $styles = $xpath->query('//*[@style]');
        foreach ($styles as $element) {
            $style = $element->getAttribute('style');
            if (preg_match('/font-size:\s*(\d+)px/', $style, $matches)) {
                $fontSize = intval($matches[1]);
                if ($fontSize < 16) {
                    $violations[] = new Violation(
                        'seo-mobile-font-size-small',
                        "Font size {$fontSize}px may be too small for mobile devices",
                        $filePath,
                        0,
                        0,
                        $this->configuration['severity_recommended_seo'],
                        Violation::TYPE_SEO
                    );
                }
            }
        }
        
        return $violations;
    }

    private function isTrustedDomain(string $url): bool
    {
        $trustedDomains = [
            'google.com',
            'facebook.com',
            'twitter.com',
            'linkedin.com',
            'youtube.com',
            'github.com',
            'stackoverflow.com',
            'adobe.com',
            'magento.com'
        ];
        
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) {
            return false;
        }
        
        foreach ($trustedDomains as $trustedDomain) {
            if (str_ends_with($host, $trustedDomain)) {
                return true;
            }
        }
        
        return false;
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
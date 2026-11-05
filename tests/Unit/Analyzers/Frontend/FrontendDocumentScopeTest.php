<?php

declare(strict_types=1);

namespace ACQC\Tests\Unit\Analyzers\Frontend;

use ACQC\Analyzers\Frontend\AccessibilityAnalyzer;
use ACQC\Analyzers\Frontend\SEOAnalyzer;
use PHPUnit\Framework\TestCase;

final class FrontendDocumentScopeTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/acqc_fe_scope_' . uniqid('', true);
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tempDir);
    }

    public function testPurePhpClassProducesZeroSeoAndAccessibilityFindings(): void
    {
        $phpFile = $this->tempDir . '/SampleService.php';
        file_put_contents($phpFile, <<<'PHP'
<?php
declare(strict_types=1);
namespace Vendor\Module\Model;

final class SampleService
{
    public function calculate(int $a, int $b): bool
    {
        return $a < $b && $b > 0;
    }
}
PHP);

        $seo = new SEOAnalyzer();
        $a11y = new AccessibilityAnalyzer();

        $this->assertSame([], $seo->analyze([$phpFile]));
        $this->assertSame([], $a11y->analyze([$phpFile]));
    }

    public function testPhpFragmentSkipsPageLevelChecksButKeepsElementLevelChecks(): void
    {
        $fragmentFile = $this->tempDir . '/MagentoStatusColumn.php';
        file_put_contents($fragmentFile, <<<'PHP'
<?php
namespace Vendor\Module\Block;

class MagentoStatusColumn
{
    public function renderHtml(): string
    {
        return '<div class="admin-field"><input type="text" name="status_filter" /><img src="icon.png" /></div>';
    }
}
PHP);

        $seo = new SEOAnalyzer();
        $a11y = new AccessibilityAnalyzer();

        $seoRuleIds = array_map(static fn($v) => $v->getRuleId(), $seo->analyze([$fragmentFile]));
        $a11yRuleIds = array_map(static fn($v) => $v->getRuleId(), $a11y->analyze([$fragmentFile]));

        // Page-level checks must NOT fire on a fragment
        $this->assertNotContains('seo-missing-title', $seoRuleIds);
        $this->assertNotContains('seo-missing-description', $seoRuleIds);
        $this->assertNotContains('seo-missing-h1', $seoRuleIds);
        $this->assertNotContains('seo-thin-content', $seoRuleIds);
        $this->assertNotContains('a11y-missing-page-title', $a11yRuleIds);
        $this->assertNotContains('a11y-missing-lang-attribute', $a11yRuleIds);
        $this->assertNotContains('a11y-missing-main-element', $a11yRuleIds);
        $this->assertNotContains('a11y-missing-landmarks', $a11yRuleIds);

        // Element-level checks MUST still fire on the fragment
        $this->assertContains('seo-missing-alt-text', $seoRuleIds);
        $this->assertContains('a11y-form-input-missing-label', $a11yRuleIds);
        $this->assertContains('a11y-missing-alt-text', $a11yRuleIds);
    }

    public function testFullHtmlDocumentRunsPageLevelChecks(): void
    {
        $htmlFile = $this->tempDir . '/page.html';
        file_put_contents($htmlFile, '<!DOCTYPE html><html><head></head><body><p>Hello</p></body></html>');

        $seo = new SEOAnalyzer();
        $a11y = new AccessibilityAnalyzer();

        $seoRuleIds = array_map(static fn($v) => $v->getRuleId(), $seo->analyze([$htmlFile]));
        $a11yRuleIds = array_map(static fn($v) => $v->getRuleId(), $a11y->analyze([$htmlFile]));

        $this->assertContains('seo-missing-title', $seoRuleIds);
        $this->assertContains('seo-missing-description', $seoRuleIds);
        $this->assertContains('seo-missing-h1', $seoRuleIds);
        $this->assertContains('a11y-missing-page-title', $a11yRuleIds);
        $this->assertContains('a11y-missing-lang-attribute', $a11yRuleIds);
        $this->assertContains('a11y-missing-main-element', $a11yRuleIds);
    }
}

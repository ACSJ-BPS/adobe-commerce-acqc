<?php

declare(strict_types=1);

namespace ACQC\Tests\Unit\Core\Profiles;

use ACQC\Core\Profiles\ProfileConfig;
use ACQC\Core\Profiles\ProfileResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProfileResolver::class)]
#[CoversClass(ProfileConfig::class)]
#[Group('unit')]
class ProfileResolverTest extends TestCase
{
    private string $acqcRoot;
    private string $tmpProject;
    private ProfileResolver $resolver;

    protected function setUp(): void
    {
        // Use the real project root so built-in profiles are found
        $this->acqcRoot   = dirname(__DIR__, 4); // tests/Unit/Core/Profiles → project root
        $this->tmpProject = sys_get_temp_dir() . '/acqc_profile_test_' . uniqid();
        mkdir($this->tmpProject, 0755, true);

        $this->resolver = new ProfileResolver($this->acqcRoot, $this->tmpProject);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpProject);
    }

    // ── built-in profiles ─────────────────────────────────────────────────────

    public function testLoadsBuiltInDeveloperProfile(): void
    {
        $config = $this->resolver->resolve('developer');

        $this->assertInstanceOf(ProfileConfig::class, $config);
        $this->assertSame('developer', $config->name);
    }

    public function testDeveloperProfileHasExpectedDefaults(): void
    {
        $config = $this->resolver->resolve('developer');

        $this->assertContains(1, $config->tiers);
        $this->assertContains(2, $config->tiers);
        $this->assertContains(3, $config->tiers);
        $this->assertSame('console', $config->format);
        $this->assertFalse($config->hideFilePaths);
        $this->assertContains('error', $config->failOn);
    }

    public function testLoadsBuiltInCiProfile(): void
    {
        $config = $this->resolver->resolve('ci');

        $this->assertSame('ci', $config->name);
        $this->assertFalse($config->personalRules);
        $this->assertContains('error', $config->failOn);
    }

    public function testLoadsBuiltInExecutiveProfile(): void
    {
        $config = $this->resolver->resolve('executive');

        $this->assertTrue($config->hideFilePaths);
        $this->assertTrue($config->hideLineNumbers);
        $this->assertTrue($config->hideRuleIds);
        $this->assertEmpty($config->failOn);
    }

    // ── name normalization ────────────────────────────────────────────────────

    public function testTechLeadWithHyphenResolves(): void
    {
        $config = $this->resolver->resolve('tech-lead');
        $this->assertSame('tech-lead', $config->name);
    }

    public function testTechLeadWithUnderscoreResolvesSameProfile(): void
    {
        $hyphen     = $this->resolver->resolve('tech-lead');
        $underscore = $this->resolver->resolve('tech_lead');

        $this->assertSame($hyphen->name, $underscore->name);
        $this->assertSame($hyphen->format, $underscore->format);
    }

    // ── project-level override ────────────────────────────────────────────────

    public function testProjectOverrideWinsOverBuiltIn(): void
    {
        // Create a project-level developer.yaml that overrides the built-in
        mkdir($this->tmpProject . '/.acqc/profiles', 0755, true);
        file_put_contents(
            $this->tmpProject . '/.acqc/profiles/developer.yaml',
            "name: developer\ndescription: project override\ntiers: [1]\nmin_severity: error\n" .
            "format: json\nfail_on: []\npersonal_rules: false\nhide_file_paths: true\n" .
            "hide_line_numbers: false\nhide_rule_ids: false\ntier2_as_warnings: false\nseverity_labels: {}\n"
        );

        $config = $this->resolver->resolve('developer');

        $this->assertSame('project override', $config->description);
        $this->assertTrue($config->hideFilePaths, 'Project override must apply hideFilePaths=true');
    }

    // ── unknown profile ───────────────────────────────────────────────────────

    public function testUnknownProfileThrowsInvalidArgumentException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/nonexistent-profile/');

        $this->resolver->resolve('nonexistent-profile');
    }

    public function testExceptionMessageListsAvailableProfiles(): void
    {
        try {
            $this->resolver->resolve('does-not-exist');
            $this->fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('developer', $e->getMessage());
        }
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}

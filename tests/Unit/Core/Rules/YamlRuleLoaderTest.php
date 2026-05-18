<?php

declare(strict_types=1);

namespace ACQC\Tests\Unit\Core\Rules;

use ACQC\Core\Rules\RuleDefinition;
use ACQC\Core\Rules\RuleValidator;
use ACQC\Core\Rules\YamlRuleLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Yaml\Yaml;

#[CoversClass(YamlRuleLoader::class)]
#[CoversClass(RuleDefinition::class)]
#[Group('unit')]
class YamlRuleLoaderTest extends TestCase
{
    private string $tmpRoot;
    private string $acqcRoot;
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->tmpRoot     = sys_get_temp_dir() . '/acqc_loader_test_' . uniqid();
        $this->acqcRoot    = $this->tmpRoot . '/acqc';
        $this->projectRoot = $this->tmpRoot . '/project';

        mkdir($this->acqcRoot . '/config/rules/global/php', 0755, true);
        mkdir($this->acqcRoot . '/config/rules/adobe-commerce/di', 0755, true);
        mkdir($this->projectRoot . '/.acqc', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpRoot);
    }

    // ── layer 1 loads ──────────────────────────────────────────────────────────

    public function testLoadsLayer1GlobalRules(): void
    {
        $this->writeRule(
            $this->acqcRoot . '/config/rules/global/php/gl-php-001.yaml',
            $this->makeRule('GL_PHP_001', 'developer')
        );

        $rules = $this->makeLoader()->load('developer');
        $this->assertArrayHasKey('GL_PHP_001', $rules);
        $this->assertInstanceOf(RuleDefinition::class, $rules['GL_PHP_001']);
    }

    public function testLoadsLayer2AdobeCommerceRules(): void
    {
        $this->writeRule(
            $this->acqcRoot . '/config/rules/adobe-commerce/di/ac-di-001.yaml',
            $this->makeRule('AC_DI_001', 'developer')
        );

        $rules = $this->makeLoader()->load('developer');
        $this->assertArrayHasKey('AC_DI_001', $rules);
    }

    // ── layer 3 override ───────────────────────────────────────────────────────

    public function testLayer3TeamOverridesLayer1OnSameId(): void
    {
        $base = $this->makeRule('GL_PHP_001', 'developer');
        $this->writeRule($this->acqcRoot . '/config/rules/global/php/gl-php-001.yaml', $base);

        mkdir($this->projectRoot . '/.acqc/rules/team', 0755, true);
        $override             = $this->makeRule('GL_PHP_001', 'developer');
        $override['severity'] = 'error';
        $override['title']    = 'Team override title';
        $this->writeRule($this->projectRoot . '/.acqc/rules/team/gl-php-001.yaml', $override);

        $rules = $this->makeLoader()->load('developer');

        $this->assertArrayHasKey('GL_PHP_001', $rules);
        $this->assertSame('error', $rules['GL_PHP_001']->severity, 'Team layer must win');
    }

    // ── project suppressions ───────────────────────────────────────────────────

    public function testSuppressedRuleHasSuppressionFieldSet(): void
    {
        $this->writeRule(
            $this->acqcRoot . '/config/rules/global/php/gl-php-001.yaml',
            $this->makeRule('GL_PHP_001', 'developer')
        );

        file_put_contents(
            $this->projectRoot . '/.acqc/project.yaml',
            Yaml::dump([
                'suppressions' => [[
                    'rule_id'       => 'GL_PHP_001',
                    'reason'        => 'Legacy code — tracked in JIRA-123',
                    'suppressed_by' => 'dev@example.com',
                    'suppressed_at' => '2025-01-01',
                ]],
            ])
        );

        $rules = $this->makeLoader()->load('developer');

        $this->assertArrayHasKey('GL_PHP_001', $rules);
        $rule = $rules['GL_PHP_001'];
        $this->assertNotNull($rule->suppression);
        $this->assertSame('Legacy code — tracked in JIRA-123', $rule->suppression['reason']);
        $this->assertFalse($rule->isActive());
    }

    // ── profile filtering ──────────────────────────────────────────────────────

    public function testProfileFilterRemovesRulesForOtherRoles(): void
    {
        $rule          = $this->makeRule('GL_PHP_001', 'tech_lead');
        $rule['roles'] = ['tech_lead'];
        $this->writeRule($this->acqcRoot . '/config/rules/global/php/gl-php-001.yaml', $rule);

        $rules = $this->makeLoader()->load('developer');
        $this->assertArrayNotHasKey('GL_PHP_001', $rules);
    }

    public function testProfileFilterKeepsMatchingRoles(): void
    {
        $rule          = $this->makeRule('GL_PHP_001', 'developer');
        $rule['roles'] = ['developer', 'ci'];
        $this->writeRule($this->acqcRoot . '/config/rules/global/php/gl-php-001.yaml', $rule);

        $this->assertArrayHasKey('GL_PHP_001', $this->makeLoader()->load('developer'));
        $this->assertArrayHasKey('GL_PHP_001', $this->makeLoader()->load('ci'));
    }

    // ── invalid YAML silently skipped ──────────────────────────────────────────

    public function testInvalidYamlFileIsSkipped(): void
    {
        file_put_contents(
            $this->acqcRoot . '/config/rules/global/php/broken.yaml',
            "id: [unclosed bracket\n   bad: yaml:"
        );
        $this->writeRule(
            $this->acqcRoot . '/config/rules/global/php/gl-php-001.yaml',
            $this->makeRule('GL_PHP_001', 'developer')
        );

        $rules = $this->makeLoader()->load('developer');
        $this->assertArrayHasKey('GL_PHP_001', $rules);
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    private function makeLoader(): YamlRuleLoader
    {
        return new YamlRuleLoader(
            new RuleValidator(),
            $this->projectRoot,
            $this->acqcRoot,
            new ArrayAdapter(),
        );
    }

    private function makeRule(string $id, string $role): array
    {
        return [
            'schema_version' => '1.0',
            'id'             => $id,
            'name'           => strtolower(str_replace('_', '-', $id)),
            'title'          => "Rule {$id}",
            'description'    => "Description for {$id}",
            'category'       => 'php_best_practices',
            'severity'       => 'warning',
            'tier'           => 1,
            'roles'          => [$role],
            'platforms'      => ['cli', 'ci'],
            'introduced_in'  => '1.0.0',
            'globs'          => ['**/*.php'],
            'check'          => ['type' => 'regex', 'pattern' => 'catch\s*\(Exception'],
            'examples'       => ['bad' => 'catch (Exception $e) {}', 'good' => 'catch (\\Throwable $e) {}'],
            'recommendation' => 'Use \\Throwable.',
        ];
    }

    private function writeRule(string $path, array $data): void
    {
        file_put_contents($path, Yaml::dump($data, 4, 2));
    }

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

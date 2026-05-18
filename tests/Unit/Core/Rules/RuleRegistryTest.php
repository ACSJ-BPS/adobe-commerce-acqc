<?php

declare(strict_types=1);

namespace ACQC\Tests\Unit\Core\Rules;

use ACQC\Core\Rules\RuleDefinition;
use ACQC\Core\Rules\RuleRegistry;
use ACQC\Core\Rules\RuleValidator;
use ACQC\Core\Rules\YamlRuleLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Yaml\Yaml;

#[CoversClass(RuleRegistry::class)]
#[CoversClass(YamlRuleLoader::class)]
#[Group('unit')]
class RuleRegistryTest extends TestCase
{
    private string $tmpRoot;
    private string $acqcRoot;
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->tmpRoot     = sys_get_temp_dir() . '/acqc_registry_test_' . uniqid();
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

    // ── forProfile ────────────────────────────────────────────────────────────

    public function testForProfileReturnsRuleDefinitions(): void
    {
        $this->seedRule($this->acqcRoot . '/config/rules/global/php/gl-php-001.yaml', 'GL_PHP_001');

        $rules = $this->makeRegistry()->forProfile('developer');

        $this->assertArrayHasKey('GL_PHP_001', $rules);
        $this->assertInstanceOf(RuleDefinition::class, $rules['GL_PHP_001']);
    }

    public function testForProfileCachesResultBetweenCalls(): void
    {
        $this->seedRule($this->acqcRoot . '/config/rules/global/php/gl-php-001.yaml', 'GL_PHP_001');

        $registry = $this->makeRegistry();
        $first    = $registry->forProfile('developer');
        $second   = $registry->forProfile('developer');

        $this->assertSame($first, $second);
    }

    // ── get ───────────────────────────────────────────────────────────────────

    public function testGetReturnsNullForUnknownId(): void
    {
        $this->assertNull($this->makeRegistry()->get('UNKNOWN_ID_999'));
    }

    public function testGetReturnsByIdWhenPresent(): void
    {
        $this->seedRule($this->acqcRoot . '/config/rules/global/php/gl-php-001.yaml', 'GL_PHP_001');

        $rule = $this->makeRegistry()->get('GL_PHP_001');

        $this->assertNotNull($rule);
        $this->assertSame('GL_PHP_001', $rule->id);
    }

    // ── forFile ───────────────────────────────────────────────────────────────

    public function testForFileReturnsOnlyMatchingGlobRules(): void
    {
        $phpRule          = $this->makeRuleData('GL_PHP_001');
        $phpRule['globs'] = ['**/*.php'];
        file_put_contents(
            $this->acqcRoot . '/config/rules/global/php/gl-php-001.yaml',
            Yaml::dump($phpRule, 4, 2)
        );

        $xmlRule          = $this->makeRuleData('AC_CRON_001');
        $xmlRule['globs'] = ['**/crontab.xml'];
        file_put_contents(
            $this->acqcRoot . '/config/rules/adobe-commerce/di/ac-cron-001.yaml',
            Yaml::dump($xmlRule, 4, 2)
        );

        $registry = $this->makeRegistry();

        $phpRules = $registry->forFile('app/code/Vendor/Module/Plugin.php');
        $this->assertArrayHasKey('GL_PHP_001', $phpRules);
        $this->assertArrayNotHasKey('AC_CRON_001', $phpRules);

        $xmlRules = $registry->forFile('app/code/Vendor/Module/etc/crontab.xml');
        $this->assertArrayHasKey('AC_CRON_001', $xmlRules);
        $this->assertArrayNotHasKey('GL_PHP_001', $xmlRules);
    }

    public function testForFileWithNoMatchingGlobsReturnsEmpty(): void
    {
        $rule          = $this->makeRuleData('GL_PHP_001');
        $rule['globs'] = ['**/*.php'];
        file_put_contents(
            $this->acqcRoot . '/config/rules/global/php/gl-php-001.yaml',
            Yaml::dump($rule, 4, 2)
        );

        $rules = $this->makeRegistry()->forFile('app/code/Vendor/Module/etc/di.xml');
        $this->assertEmpty($rules);
    }

    // ── flush ────────────────────────────────────────────────────────────────

    public function testFlushClearsInMemoryCache(): void
    {
        $this->seedRule($this->acqcRoot . '/config/rules/global/php/gl-php-001.yaml', 'GL_PHP_001');

        $registry = $this->makeRegistry();
        $before   = $registry->forProfile('developer');
        $registry->flush();
        $after    = $registry->forProfile('developer');

        $this->assertNotSame($before, $after);
        $this->assertArrayHasKey('GL_PHP_001', $after);
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    private function makeRegistry(): RuleRegistry
    {
        return new RuleRegistry(new YamlRuleLoader(
            new RuleValidator(),
            $this->projectRoot,
            $this->acqcRoot,
            new ArrayAdapter(),
        ));
    }

    private function makeRuleData(string $id): array
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
            'roles'          => ['developer', 'ci'],
            'platforms'      => ['cli', 'ci'],
            'introduced_in'  => '1.0.0',
            'globs'          => ['**/*.php'],
            'check'          => ['type' => 'regex', 'pattern' => 'test_pattern'],
            'examples'       => ['bad' => 'bad', 'good' => 'good'],
            'recommendation' => 'Fix it.',
        ];
    }

    private function seedRule(string $path, string $id): void
    {
        file_put_contents($path, Yaml::dump($this->makeRuleData($id), 4, 2));
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

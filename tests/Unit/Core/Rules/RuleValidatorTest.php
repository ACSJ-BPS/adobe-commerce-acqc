<?php

declare(strict_types=1);

namespace ACQC\Tests\Unit\Core\Rules;

use ACQC\Core\Rules\RuleDefinition;
use ACQC\Core\Rules\RuleValidationException;
use ACQC\Core\Rules\RuleValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(RuleValidator::class)]
#[CoversClass(RuleDefinition::class)]
#[CoversClass(RuleValidationException::class)]
#[Group('unit')]
class RuleValidatorTest extends TestCase
{
    private RuleValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new RuleValidator();
    }

    // ── happy path ─────────────────────────────────────────────────────────────

    public function testValidTier1RulePasses(): void
    {
        $rule = $this->validator->validate($this->validTier1Rule(), '/path/test.yaml');

        $this->assertInstanceOf(RuleDefinition::class, $rule);
        $this->assertSame('AC_DI_001', $rule->id);
        $this->assertSame('critical', $rule->severity);
        $this->assertSame(1, $rule->tier);
        $this->assertSame('/path/test.yaml', $rule->layerPath);
    }

    public function testValidTier3RulePassesWithoutGlobs(): void
    {
        $data = $this->validTier1Rule();
        $data['tier']  = 3;
        $data['check'] = ['type' => 'guidance'];
        unset($data['globs'], $data['examples'], $data['recommendation']);

        $rule = $this->validator->validate($data, '/path/test.yaml');
        $this->assertSame(3, $rule->tier);
    }

    // ── missing required fields ────────────────────────────────────────────────

    public function testMissingTitleThrows(): void
    {
        $data = $this->validTier1Rule();
        unset($data['title']);

        $this->expectException(RuleValidationException::class);
        $this->expectExceptionMessageMatches("/Missing required field: 'title'/");
        $this->validator->validate($data, '/path/test.yaml');
    }

    public function testMissingDescriptionThrows(): void
    {
        $data = $this->validTier1Rule();
        unset($data['description']);

        $this->expectException(RuleValidationException::class);
        $this->validator->validate($data, '/path/test.yaml');
    }

    public function testMultipleMissingFieldsReportedTogether(): void
    {
        $data = $this->validTier1Rule();
        unset($data['title'], $data['description'], $data['category']);

        try {
            $this->validator->validate($data, '/test.yaml');
            $this->fail('Expected RuleValidationException');
        } catch (RuleValidationException $e) {
            $this->assertStringContainsString('title', $e->getMessage());
            $this->assertStringContainsString('description', $e->getMessage());
            $this->assertStringContainsString('category', $e->getMessage());
        }
    }

    // ── severity validation ────────────────────────────────────────────────────

    public function testInvalidSeverityThrows(): void
    {
        $data             = $this->validTier1Rule();
        $data['severity'] = 'fatal';

        $this->expectException(RuleValidationException::class);
        $this->expectExceptionMessageMatches("/Invalid severity 'fatal'/");
        $this->validator->validate($data, '/test.yaml');
    }

    public function testAllValidSeveritiesPass(): void
    {
        foreach (['blocker', 'critical', 'error', 'warning', 'info', 'minor'] as $sev) {
            $data             = $this->validTier1Rule();
            $data['severity'] = $sev;
            $rule             = $this->validator->validate($data, '/test.yaml');
            $this->assertSame($sev, $rule->severity);
        }
    }

    // ── ID format ─────────────────────────────────────────────────────────────

    public function testInvalidIdFormatThrows(): void
    {
        $data       = $this->validTier1Rule();
        $data['id'] = 'invalid-id';

        $this->expectException(RuleValidationException::class);
        $this->expectExceptionMessageMatches("/Invalid ID format/");
        $this->validator->validate($data, '/test.yaml');
    }

    public function testValidIdFormatsPass(): void
    {
        foreach (['AC_DI_001', 'GL_PHP_001', 'AC_CRON_001', 'AC_SEC_004', 'AC_DEP_001'] as $id) {
            $data       = $this->validTier1Rule();
            $data['id'] = $id;
            $rule       = $this->validator->validate($data, '/test.yaml');
            $this->assertSame($id, $rule->id);
        }
    }

    // ── tier validation ────────────────────────────────────────────────────────

    public function testInvalidTierThrows(): void
    {
        $data         = $this->validTier1Rule();
        $data['tier'] = 5;

        $this->expectException(RuleValidationException::class);
        $this->validator->validate($data, '/test.yaml');
    }

    public function testTier2MissingGlobsThrows(): void
    {
        $data         = $this->validTier1Rule();
        $data['tier'] = 2;
        unset($data['globs']);

        $this->expectException(RuleValidationException::class);
        $this->expectExceptionMessageMatches("/Tier 2 rule missing required field: 'globs'/");
        $this->validator->validate($data, '/test.yaml');
    }

    public function testTier2MissingCheckThrows(): void
    {
        $data         = $this->validTier1Rule();
        $data['tier'] = 2;
        unset($data['check']);

        $this->expectException(RuleValidationException::class);
        $this->validator->validate($data, '/test.yaml');
    }

    // ── check.type validation ──────────────────────────────────────────────────

    public function testInvalidCheckTypeThrows(): void
    {
        $data          = $this->validTier1Rule();
        $data['check'] = ['type' => 'magic'];

        $this->expectException(RuleValidationException::class);
        $this->expectExceptionMessageMatches("/Invalid check.type 'magic'/");
        $this->validator->validate($data, '/test.yaml');
    }

    // ── roles/platforms validation ─────────────────────────────────────────────

    public function testInvalidRoleThrows(): void
    {
        $data          = $this->validTier1Rule();
        $data['roles'] = ['developer', 'cto'];

        $this->expectException(RuleValidationException::class);
        $this->expectExceptionMessageMatches("/Invalid role 'cto'/");
        $this->validator->validate($data, '/test.yaml');
    }

    public function testInvalidPlatformThrows(): void
    {
        $data              = $this->validTier1Rule();
        $data['platforms'] = ['cli', 'slack'];

        $this->expectException(RuleValidationException::class);
        $this->validator->validate($data, '/test.yaml');
    }

    // ── exception includes file path ───────────────────────────────────────────

    public function testExceptionMessageContainsFilePath(): void
    {
        $data = $this->validTier1Rule();
        unset($data['title']);

        try {
            $this->validator->validate($data, '/rules/my-rule.yaml');
            $this->fail('Expected exception');
        } catch (RuleValidationException $e) {
            $this->assertStringContainsString('/rules/my-rule.yaml', $e->getMessage());
        }
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    private function validTier1Rule(): array
    {
        return [
            'schema_version' => '1.0',
            'id'             => 'AC_DI_001',
            'name'           => 'ac-di-001-object-manager',
            'title'          => 'Direct ObjectManager Usage',
            'description'    => 'Do not use ObjectManager directly.',
            'category'       => 'dependency_injection',
            'severity'       => 'critical',
            'tier'           => 1,
            'roles'          => ['developer', 'ci'],
            'platforms'      => ['cli', 'ci'],
            'introduced_in'  => '1.0.0',
            'globs'          => ['**/*.php'],
            'check'          => ['type' => 'regex', 'pattern' => 'ObjectManager::getInstance'],
            'examples'       => ['bad' => '$om = ObjectManager::getInstance();', 'good' => '// use DI'],
            'recommendation' => 'Use constructor injection.',
        ];
    }
}

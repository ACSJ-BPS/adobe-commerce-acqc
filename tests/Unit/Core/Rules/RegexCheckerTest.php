<?php

declare(strict_types=1);

namespace ACQC\Tests\Unit\Core\Rules;

use ACQC\Core\Rules\Checkers\RegexChecker;
use ACQC\Core\Rules\Checkers\RuleViolation;
use ACQC\Core\Rules\RuleDefinition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(RegexChecker::class)]
#[CoversClass(RuleViolation::class)]
#[Group('unit')]
class RegexCheckerTest extends TestCase
{
    private RegexChecker $checker;
    private string $fixtureBase;

    protected function setUp(): void
    {
        $this->checker     = new RegexChecker();
        $this->fixtureBase = dirname(__DIR__, 3) . '/Fixtures/Rules';
    }

    // ── AC_DI_001 — ObjectManager::getInstance ────────────────────────────────

    public function testObjectManagerInBadFileTriggersViolation(): void
    {
        $violations = $this->checker->check(
            $this->makeDiRule(),
            $this->fixtureBase . '/BadCode/di/object-manager-direct.php'
        );

        $this->assertNotEmpty($violations);
        $this->assertContainsOnlyInstancesOf(RuleViolation::class, $violations);
        $this->assertSame('AC_DI_001', $violations[0]->ruleId);
        $this->assertSame('critical', $violations[0]->severity);
        $this->assertIsInt($violations[0]->lineNumber);
        $this->assertGreaterThan(0, $violations[0]->lineNumber);
    }

    public function testGoodFileWithConstructorInjectionProducesNoViolations(): void
    {
        $violations = $this->checker->check(
            $this->makeDiRule(),
            $this->fixtureBase . '/GoodCode/di/constructor-injection.php'
        );

        $this->assertEmpty($violations);
    }

    // ── GL_PHP_001 — catch (Exception ─────────────────────────────────────────

    public function testThrowableCatchPatternMatchesBadCode(): void
    {
        $tmp = $this->writeTmpPhp('<?php' . "\n" . 'try {} catch (Exception $e) { throw $e; }');
        try {
            $violations = $this->checker->check($this->makeThrowableRule(), $tmp);
            $this->assertNotEmpty($violations);
            $this->assertSame(2, $violations[0]->lineNumber);
        } finally {
            @unlink($tmp);
        }
    }

    public function testThrowablePatternDoesNotMatchGoodCode(): void
    {
        $tmp = $this->writeTmpPhp('<?php' . "\n" . 'try {} catch (\Throwable $e) { throw $e; }');
        try {
            $violations = $this->checker->check($this->makeThrowableRule(), $tmp);
            $this->assertEmpty($violations);
        } finally {
            @unlink($tmp);
        }
    }

    // ── line number accuracy ──────────────────────────────────────────────────

    public function testLineNumberIsAccurate(): void
    {
        $lines = [
            '<?php',
            '',
            'function safe() {}',
            '',
            'try {',
            '    risky();',
            '} catch (Exception $e) {',
            '    throw $e;',
            '}',
        ];
        $tmp = $this->writeTmpPhp(implode("\n", $lines));
        try {
            $violations = $this->checker->check($this->makeThrowableRule(), $tmp);
            $this->assertNotEmpty($violations);
            $this->assertSame(7, $violations[0]->lineNumber);
        } finally {
            @unlink($tmp);
        }
    }

    // ── multiple matches in one file ──────────────────────────────────────────

    public function testMultipleMatchesAllReported(): void
    {
        $code = implode("\n", [
            '<?php',
            'try {} catch (Exception $e) {}',
            'try {} catch (Exception $e) {}',
        ]);
        $tmp = $this->writeTmpPhp($code);
        try {
            $violations = $this->checker->check($this->makeThrowableRule(), $tmp);
            $this->assertCount(2, $violations);
            $this->assertSame(2, $violations[0]->lineNumber);
            $this->assertSame(3, $violations[1]->lineNumber);
        } finally {
            @unlink($tmp);
        }
    }

    // ── edge cases ────────────────────────────────────────────────────────────

    public function testMissingFileReturnsEmpty(): void
    {
        $violations = $this->checker->check($this->makeDiRule(), '/non/existent/file.php');
        $this->assertEmpty($violations);
    }

    public function testEmptyPatternReturnsEmpty(): void
    {
        $tmp = $this->writeTmpPhp('<?php $x = 1;');
        try {
            $violations = $this->checker->check($this->makeRuleWithPattern(''), $tmp);
            $this->assertEmpty($violations);
        } finally {
            @unlink($tmp);
        }
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    private function makeDiRule(): RuleDefinition
    {
        return $this->makeRuleWithPattern('ObjectManager::getInstance\s*\(', 'AC_DI_001', 'critical');
    }

    private function makeThrowableRule(): RuleDefinition
    {
        return $this->makeRuleWithPattern('catch\s*\(\s*\\\\?Exception\s', 'GL_PHP_001', 'warning');
    }

    private function makeRuleWithPattern(string $pattern, string $id = 'AC_DI_001', string $severity = 'warning'): RuleDefinition
    {
        return RuleDefinition::fromArray([
            'schema_version' => '1.0',
            'id'             => $id,
            'name'           => strtolower(str_replace('_', '-', $id)),
            'title'          => "Rule {$id}",
            'description'    => 'Test rule.',
            'category'       => 'test',
            'severity'       => $severity,
            'tier'           => 1,
            'roles'          => ['developer', 'ci'],
            'platforms'      => ['cli'],
            'introduced_in'  => '1.0.0',
            'globs'          => ['**/*.php'],
            'check'          => ['type' => 'regex', 'pattern' => $pattern],
            'examples'       => ['bad' => '', 'good' => ''],
            'recommendation' => 'Fix it.',
        ], '/rules/test.yaml');
    }

    private function writeTmpPhp(string $content): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'acqc_test_php_') . '.php';
        file_put_contents($tmp, $content);
        return $tmp;
    }
}

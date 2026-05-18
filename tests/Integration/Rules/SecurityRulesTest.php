<?php

declare(strict_types=1);

namespace ACQC\Tests\Integration\Rules;

use ACQC\Core\Rules\CheckerRegistry;
use ACQC\Core\Rules\RuleDefinition;
use ACQC\Core\Rules\RuleValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

#[CoversClass(CheckerRegistry::class)]
#[Group('integration')]
class SecurityRulesTest extends TestCase
{
    private CheckerRegistry $registry;
    private RuleValidator $validator;

    protected function setUp(): void
    {
        $this->registry  = new CheckerRegistry();
        $this->validator = new RuleValidator();
    }

    public static function securityRuleProvider(): array
    {
        $rulesDir    = ACQC_PROJECT_ROOT . '/config/rules/adobe-commerce/security/';
        $fixturesDir = ACQC_FIXTURES_PATH . '/Rules/';

        return [
            'AC_SEC_001 sql injection bad' => [
                $rulesDir . 'ac-sec-001-sql-injection.yaml',
                $fixturesDir . 'BadCode/security/sql-injection.php',
                true,
            ],
            'AC_SEC_001 sql injection good' => [
                $rulesDir . 'ac-sec-001-sql-injection.yaml',
                $fixturesDir . 'GoodCode/security/sql-injection.php',
                false,
            ],
            'AC_SEC_002 unescaped output bad' => [
                $rulesDir . 'ac-sec-002-unescaped-output.yaml',
                $fixturesDir . 'BadCode/security/unescaped-output.phtml',
                true,
            ],
            'AC_SEC_002 unescaped output good' => [
                $rulesDir . 'ac-sec-002-unescaped-output.yaml',
                $fixturesDir . 'GoodCode/security/unescaped-output.phtml',
                false,
            ],
            'AC_SEC_003 direct superglobal bad' => [
                $rulesDir . 'ac-sec-003-direct-superglobal.yaml',
                $fixturesDir . 'BadCode/security/direct-superglobal.php',
                true,
            ],
            'AC_SEC_003 direct superglobal good' => [
                $rulesDir . 'ac-sec-003-direct-superglobal.yaml',
                $fixturesDir . 'GoodCode/security/direct-superglobal.php',
                false,
            ],
            'AC_SEC_005 path traversal bad' => [
                $rulesDir . 'ac-sec-005-path-traversal.yaml',
                $fixturesDir . 'BadCode/security/path-traversal.php',
                true,
            ],
            'AC_SEC_005 path traversal good' => [
                $rulesDir . 'ac-sec-005-path-traversal.yaml',
                $fixturesDir . 'GoodCode/security/path-traversal.php',
                false,
            ],
            'AC_SEC_006 unsafe deserialize bad' => [
                $rulesDir . 'ac-sec-006-unsafe-deserialize.yaml',
                $fixturesDir . 'BadCode/security/unsafe-deserialize.php',
                true,
            ],
            'AC_SEC_006 unsafe deserialize good' => [
                $rulesDir . 'ac-sec-006-unsafe-deserialize.yaml',
                $fixturesDir . 'GoodCode/security/unsafe-deserialize.php',
                false,
            ],
            'AC_SEC_007 missing csrf bad' => [
                $rulesDir . 'ac-sec-007-missing-csrf.yaml',
                $fixturesDir . 'BadCode/security/missing-csrf.phtml',
                true,
            ],
            'AC_SEC_007 missing csrf good' => [
                $rulesDir . 'ac-sec-007-missing-csrf.yaml',
                $fixturesDir . 'GoodCode/security/missing-csrf.phtml',
                false,
            ],
        ];
    }

    #[DataProvider('securityRuleProvider')]
    public function testSecurityRule(string $ruleFile, string $fixtureFile, bool $expectViolation): void
    {
        $data       = Yaml::parseFile($ruleFile);
        $rule       = $this->validator->validate($data, $ruleFile);
        $checkType  = $rule->check['type'] ?? '';
        $checker    = $this->registry->getChecker($checkType);
        $violations = $checker->check($rule, $fixtureFile);

        if ($expectViolation) {
            $this->assertNotEmpty($violations, "Expected violation in {$fixtureFile} for rule {$rule->id}");
        } else {
            $this->assertEmpty($violations, "Expected no violations in {$fixtureFile} for rule {$rule->id}");
        }
    }
}

<?php

declare(strict_types=1);

namespace ACQC\Tests\Integration\Rules;

use ACQC\Core\Rules\CheckerRegistry;
use ACQC\Core\Rules\RuleValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

#[CoversClass(CheckerRegistry::class)]
#[Group('integration')]
class DeprecationRulesTest extends TestCase
{
    private CheckerRegistry $registry;
    private RuleValidator $validator;

    protected function setUp(): void
    {
        $this->registry  = new CheckerRegistry();
        $this->validator = new RuleValidator();
    }

    public static function deprecationRuleProvider(): array
    {
        $rulesDir    = ACQC_PROJECT_ROOT . '/config/rules/adobe-commerce/deprecation/';
        $fixturesDir = ACQC_FIXTURES_PATH . '/Rules/';

        return [
            'AC_DEP_001 zend class bad' => [
                $rulesDir . 'ac-dep-001-zend-class.yaml',
                $fixturesDir . 'BadCode/deprecation/zend-class.php',
                true,
            ],
            'AC_DEP_001 zend class good' => [
                $rulesDir . 'ac-dep-001-zend-class.yaml',
                $fixturesDir . 'GoodCode/deprecation/zend-class.php',
                false,
            ],
            'AC_DEP_002 zend namespace bad' => [
                $rulesDir . 'ac-dep-002-zend-namespace.yaml',
                $fixturesDir . 'BadCode/deprecation/zend-namespace.php',
                true,
            ],
            'AC_DEP_002 zend namespace good' => [
                $rulesDir . 'ac-dep-002-zend-namespace.yaml',
                $fixturesDir . 'GoodCode/deprecation/zend-namespace.php',
                false,
            ],
            'AC_DEP_003 deprecated timezone bad' => [
                $rulesDir . 'ac-dep-003-deprecated-timezone.yaml',
                $fixturesDir . 'BadCode/deprecation/deprecated-timezone.php',
                true,
            ],
            'AC_DEP_003 deprecated timezone good' => [
                $rulesDir . 'ac-dep-003-deprecated-timezone.yaml',
                $fixturesDir . 'GoodCode/deprecation/deprecated-timezone.php',
                false,
            ],
            'AC_DEP_004 deprecated helper bad' => [
                $rulesDir . 'ac-dep-004-deprecated-helper.yaml',
                $fixturesDir . 'BadCode/deprecation/deprecated-helper.php',
                true,
            ],
            'AC_DEP_004 deprecated helper good' => [
                $rulesDir . 'ac-dep-004-deprecated-helper.yaml',
                $fixturesDir . 'GoodCode/deprecation/deprecated-helper.php',
                false,
            ],
            'AC_DEP_005 mage facade bad' => [
                $rulesDir . 'ac-dep-005-mage-facade.yaml',
                $fixturesDir . 'BadCode/deprecation/mage-facade.php',
                true,
            ],
            'AC_DEP_005 mage facade good' => [
                $rulesDir . 'ac-dep-005-mage-facade.yaml',
                $fixturesDir . 'GoodCode/deprecation/mage-facade.php',
                false,
            ],
        ];
    }

    #[DataProvider('deprecationRuleProvider')]
    public function testDeprecationRule(string $ruleFile, string $fixtureFile, bool $expectViolation): void
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

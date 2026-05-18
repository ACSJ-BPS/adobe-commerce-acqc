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
class DiRulesTest extends TestCase
{
    private CheckerRegistry $registry;
    private RuleValidator $validator;

    protected function setUp(): void
    {
        $this->registry  = new CheckerRegistry();
        $this->validator = new RuleValidator();
    }

    public static function diRuleProvider(): array
    {
        $rulesDir    = ACQC_PROJECT_ROOT . '/config/rules/adobe-commerce/di/';
        $fixturesDir = ACQC_FIXTURES_PATH . '/Rules/';

        return [
            'AC_DI_001 object manager bad' => [
                $rulesDir . 'ac-di-001-object-manager.yaml',
                $fixturesDir . 'BadCode/di/object-manager-direct.php',
                true,
            ],
            'AC_DI_001 object manager good' => [
                $rulesDir . 'ac-di-001-object-manager.yaml',
                $fixturesDir . 'GoodCode/di/object-manager-direct.php',
                false,
            ],
            'AC_DI_002 direct instantiation bad' => [
                $rulesDir . 'ac-di-002-direct-instantiation.yaml',
                $fixturesDir . 'BadCode/di/direct-instantiation.php',
                true,
            ],
            'AC_DI_002 direct instantiation good' => [
                $rulesDir . 'ac-di-002-direct-instantiation.yaml',
                $fixturesDir . 'GoodCode/di/direct-instantiation.php',
                false,
            ],
            'AC_DI_003 concrete injection bad' => [
                $rulesDir . 'ac-di-003-concrete-injection.yaml',
                $fixturesDir . 'BadCode/di/concrete-injection.php',
                true,
            ],
            'AC_DI_003 concrete injection good' => [
                $rulesDir . 'ac-di-003-concrete-injection.yaml',
                $fixturesDir . 'GoodCode/di/concrete-injection.php',
                false,
            ],
            'AC_DI_004 too many dependencies bad' => [
                $rulesDir . 'ac-di-004-too-many-dependencies.yaml',
                $fixturesDir . 'BadCode/di/too-many-dependencies.php',
                true,
            ],
            'AC_DI_004 too many dependencies good' => [
                $rulesDir . 'ac-di-004-too-many-dependencies.yaml',
                $fixturesDir . 'GoodCode/di/too-many-dependencies.php',
                false,
            ],
            'AC_DI_005 missing api annotation bad' => [
                $rulesDir . 'ac-di-005-missing-api-annotation.yaml',
                $fixturesDir . 'BadCode/di/Api/ProductManagementInterface.php',
                true,
            ],
            'AC_DI_005 missing api annotation good' => [
                $rulesDir . 'ac-di-005-missing-api-annotation.yaml',
                $fixturesDir . 'GoodCode/di/Api/ProductManagementInterface.php',
                false,
            ],
        ];
    }

    #[DataProvider('diRuleProvider')]
    public function testDiRule(string $ruleFile, string $fixtureFile, bool $expectViolation): void
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

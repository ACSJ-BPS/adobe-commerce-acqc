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
class GraphqlRulesTest extends TestCase
{
    private CheckerRegistry $registry;
    private RuleValidator $validator;

    protected function setUp(): void
    {
        $this->registry  = new CheckerRegistry();
        $this->validator = new RuleValidator();
    }

    public static function graphqlRuleProvider(): array
    {
        $rulesDir    = ACQC_PROJECT_ROOT . '/config/rules/adobe-commerce/graphql/';
        $fixturesDir = ACQC_FIXTURES_PATH . '/Rules/';

        return [
            'AC_GQL_001 full model load bad' => [
                $rulesDir . 'ac-gql-001-full-model-load.yaml',
                $fixturesDir . 'BadCode/graphql/full-model-load.php',
                true,
            ],
            'AC_GQL_001 full model load good' => [
                $rulesDir . 'ac-gql-001-full-model-load.yaml',
                $fixturesDir . 'GoodCode/graphql/full-model-load.php',
                false,
            ],
            'AC_GQL_002 n plus one resolver bad' => [
                $rulesDir . 'ac-gql-002-n-plus-one-resolver.yaml',
                $fixturesDir . 'BadCode/graphql/n-plus-one-resolver.php',
                true,
            ],
            'AC_GQL_002 n plus one resolver good' => [
                $rulesDir . 'ac-gql-002-n-plus-one-resolver.yaml',
                $fixturesDir . 'GoodCode/graphql/n-plus-one-resolver.php',
                false,
            ],
            'AC_GQL_003 no input validation bad' => [
                $rulesDir . 'ac-gql-003-no-input-validation.yaml',
                $fixturesDir . 'BadCode/graphql/no-input-validation.php',
                true,
            ],
            'AC_GQL_003 no input validation good' => [
                $rulesDir . 'ac-gql-003-no-input-validation.yaml',
                $fixturesDir . 'GoodCode/graphql/no-input-validation.php',
                false,
            ],
            'AC_GQL_004 missing cache directive bad' => [
                $rulesDir . 'ac-gql-004-missing-cache-directive.yaml',
                $fixturesDir . 'BadCode/graphql/missing-cache-directive.graphqls',
                true,
            ],
            'AC_GQL_004 missing cache directive good' => [
                $rulesDir . 'ac-gql-004-missing-cache-directive.yaml',
                $fixturesDir . 'GoodCode/graphql/missing-cache-directive.graphqls',
                false,
            ],
            'AC_GQL_005 resolver direct model bad' => [
                $rulesDir . 'ac-gql-005-resolver-direct-model.yaml',
                $fixturesDir . 'BadCode/graphql/resolver-direct-model.php',
                true,
            ],
            'AC_GQL_005 resolver direct model good' => [
                $rulesDir . 'ac-gql-005-resolver-direct-model.yaml',
                $fixturesDir . 'GoodCode/graphql/resolver-direct-model.php',
                false,
            ],
        ];
    }

    #[DataProvider('graphqlRuleProvider')]
    public function testGraphqlRule(string $ruleFile, string $fixtureFile, bool $expectViolation): void
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

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
class CloudRulesTest extends TestCase
{
    private CheckerRegistry $registry;
    private RuleValidator $validator;

    protected function setUp(): void
    {
        $this->registry  = new CheckerRegistry();
        $this->validator = new RuleValidator();
    }

    public static function cloudRuleProvider(): array
    {
        $rulesDir    = ACQC_PROJECT_ROOT . '/config/rules/adobe-commerce/cloud/';
        $fixturesDir = ACQC_FIXTURES_PATH . '/Rules/';

        return [
            'AC_CLOUD_001 local storage write bad' => [
                $rulesDir . 'ac-cloud-001-local-storage-write.yaml',
                $fixturesDir . 'BadCode/cloud/local-storage-write.php',
                true,
            ],
            'AC_CLOUD_001 local storage write good' => [
                $rulesDir . 'ac-cloud-001-local-storage-write.yaml',
                $fixturesDir . 'GoodCode/cloud/local-storage-write.php',
                false,
            ],
            'AC_CLOUD_002 env variable hardcoded bad' => [
                $rulesDir . 'ac-cloud-002-env-variable-hardcoded.yaml',
                $fixturesDir . 'BadCode/cloud/env-variable-hardcoded.php',
                true,
            ],
            'AC_CLOUD_002 env variable hardcoded good' => [
                $rulesDir . 'ac-cloud-002-env-variable-hardcoded.yaml',
                $fixturesDir . 'GoodCode/cloud/env-variable-hardcoded.php',
                false,
            ],
            'AC_CLOUD_003 session file storage bad' => [
                $rulesDir . 'ac-cloud-003-session-file-storage.yaml',
                $fixturesDir . 'BadCode/cloud/session-file-storage.php',
                true,
            ],
            'AC_CLOUD_003 session file storage good' => [
                $rulesDir . 'ac-cloud-003-session-file-storage.yaml',
                $fixturesDir . 'GoodCode/cloud/session-file-storage.php',
                false,
            ],
            'AC_CLOUD_005 symlink creation bad' => [
                $rulesDir . 'ac-cloud-005-symlink-creation.yaml',
                $fixturesDir . 'BadCode/cloud/symlink-creation.php',
                true,
            ],
            'AC_CLOUD_005 symlink creation good' => [
                $rulesDir . 'ac-cloud-005-symlink-creation.yaml',
                $fixturesDir . 'GoodCode/cloud/symlink-creation.php',
                false,
            ],
        ];
    }

    #[DataProvider('cloudRuleProvider')]
    public function testCloudRule(string $ruleFile, string $fixtureFile, bool $expectViolation): void
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

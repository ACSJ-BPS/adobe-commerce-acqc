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
class CronRulesTest extends TestCase
{
    private CheckerRegistry $registry;
    private RuleValidator $validator;

    protected function setUp(): void
    {
        $this->registry  = new CheckerRegistry();
        $this->validator = new RuleValidator();
    }

    public static function cronRuleProvider(): array
    {
        $rulesDir    = ACQC_PROJECT_ROOT . '/config/rules/adobe-commerce/cron/';
        $fixturesDir = ACQC_FIXTURES_PATH . '/Rules/';

        return [
            'AC_CRON_001 process isolation bad' => [
                $rulesDir . 'ac-cron-001-process-isolation.yaml',
                $fixturesDir . 'BadCode/cron/crontab-missing-separate-process.xml',
                true,
            ],
            'AC_CRON_001 process isolation good' => [
                $rulesDir . 'ac-cron-001-process-isolation.yaml',
                $fixturesDir . 'GoodCode/cron/crontab-missing-separate-process.xml',
                false,
            ],
            'AC_CRON_002 default group bad' => [
                $rulesDir . 'ac-cron-002-default-group.yaml',
                $fixturesDir . 'BadCode/cron/crontab-default-group.xml',
                true,
            ],
            'AC_CRON_002 default group good' => [
                $rulesDir . 'ac-cron-002-default-group.yaml',
                $fixturesDir . 'GoodCode/cron/crontab-default-group.xml',
                false,
            ],
            'AC_CRON_003 every minute bad' => [
                $rulesDir . 'ac-cron-003-every-minute.yaml',
                $fixturesDir . 'BadCode/cron/crontab-every-minute.xml',
                true,
            ],
            'AC_CRON_003 every minute good' => [
                $rulesDir . 'ac-cron-003-every-minute.yaml',
                $fixturesDir . 'GoodCode/cron/crontab-every-minute.xml',
                false,
            ],
            'AC_CRON_004 heavy operation bad' => [
                $rulesDir . 'ac-cron-004-heavy-cron-operation.yaml',
                $fixturesDir . 'BadCode/cron/heavy-cron-operation.php',
                true,
            ],
            'AC_CRON_004 heavy operation good' => [
                $rulesDir . 'ac-cron-004-heavy-cron-operation.yaml',
                $fixturesDir . 'GoodCode/cron/heavy-cron-operation.php',
                false,
            ],
            'AC_CRON_005 no interface bad' => [
                $rulesDir . 'ac-cron-005-no-interface.yaml',
                $fixturesDir . 'BadCode/cron/no-interface.php',
                true,
            ],
            'AC_CRON_005 no interface good' => [
                $rulesDir . 'ac-cron-005-no-interface.yaml',
                $fixturesDir . 'GoodCode/cron/no-interface.php',
                false,
            ],
        ];
    }

    #[DataProvider('cronRuleProvider')]
    public function testCronRule(string $ruleFile, string $fixtureFile, bool $expectViolation): void
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

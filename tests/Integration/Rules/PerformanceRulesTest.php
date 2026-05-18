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
class PerformanceRulesTest extends TestCase
{
    private CheckerRegistry $registry;
    private RuleValidator $validator;

    protected function setUp(): void
    {
        $this->registry  = new CheckerRegistry();
        $this->validator = new RuleValidator();
    }

    public static function performanceRuleProvider(): array
    {
        $rulesDir    = ACQC_PROJECT_ROOT . '/config/rules/adobe-commerce/performance/';
        $fixturesDir = ACQC_FIXTURES_PATH . '/Rules/';

        return [
            'AC_PERF_001 n plus one bad' => [
                $rulesDir . 'ac-perf-001-n-plus-one.yaml',
                $fixturesDir . 'BadCode/performance/n-plus-one.php',
                true,
            ],
            'AC_PERF_001 n plus one good' => [
                $rulesDir . 'ac-perf-001-n-plus-one.yaml',
                $fixturesDir . 'GoodCode/performance/n-plus-one.php',
                false,
            ],
            'AC_PERF_002 full collection load bad' => [
                $rulesDir . 'ac-perf-002-full-collection-load.yaml',
                $fixturesDir . 'BadCode/performance/full-collection-load.php',
                true,
            ],
            'AC_PERF_002 full collection load good' => [
                $rulesDir . 'ac-perf-002-full-collection-load.yaml',
                $fixturesDir . 'GoodCode/performance/full-collection-load.php',
                false,
            ],
            'AC_PERF_003 sql count bad' => [
                $rulesDir . 'ac-perf-003-sql-count.yaml',
                $fixturesDir . 'BadCode/performance/sql-count.php',
                true,
            ],
            'AC_PERF_003 sql count good' => [
                $rulesDir . 'ac-perf-003-sql-count.yaml',
                $fixturesDir . 'GoodCode/performance/sql-count.php',
                false,
            ],
            'AC_PERF_004 missing cache lifetime bad' => [
                $rulesDir . 'ac-perf-004-missing-cache-lifetime.yaml',
                $fixturesDir . 'BadCode/performance/missing-cache-lifetime.xml',
                true,
            ],
            'AC_PERF_004 missing cache lifetime good' => [
                $rulesDir . 'ac-perf-004-missing-cache-lifetime.yaml',
                $fixturesDir . 'GoodCode/performance/missing-cache-lifetime.xml',
                false,
            ],
            'AC_PERF_005 images no lazy loading bad' => [
                $rulesDir . 'ac-perf-005-images-no-lazy-loading.yaml',
                $fixturesDir . 'BadCode/performance/images-no-lazy-loading.html',
                true,
            ],
            'AC_PERF_005 images no lazy loading good' => [
                $rulesDir . 'ac-perf-005-images-no-lazy-loading.yaml',
                $fixturesDir . 'GoodCode/performance/images-no-lazy-loading.html',
                false,
            ],
            'AC_PERF_006 requirejs bundle bad' => [
                $rulesDir . 'ac-perf-006-requirejs-bundle.yaml',
                $fixturesDir . 'BadCode/performance/requirejs-bundle.js',
                true,
            ],
            'AC_PERF_006 requirejs bundle good' => [
                $rulesDir . 'ac-perf-006-requirejs-bundle.yaml',
                $fixturesDir . 'GoodCode/performance/requirejs-bundle.js',
                false,
            ],
            'AC_PERF_007 script no defer bad' => [
                $rulesDir . 'ac-perf-007-script-no-defer.yaml',
                $fixturesDir . 'BadCode/performance/script-no-defer.html',
                true,
            ],
            'AC_PERF_007 script no defer good' => [
                $rulesDir . 'ac-perf-007-script-no-defer.yaml',
                $fixturesDir . 'GoodCode/performance/script-no-defer.html',
                false,
            ],
            'AC_PERF_008 css import bad' => [
                $rulesDir . 'ac-perf-008-css-import.yaml',
                $fixturesDir . 'BadCode/performance/css-import.css',
                true,
            ],
            'AC_PERF_008 css import good' => [
                $rulesDir . 'ac-perf-008-css-import.yaml',
                $fixturesDir . 'GoodCode/performance/css-import.css',
                false,
            ],
        ];
    }

    #[DataProvider('performanceRuleProvider')]
    public function testPerformanceRule(string $ruleFile, string $fixtureFile, bool $expectViolation): void
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

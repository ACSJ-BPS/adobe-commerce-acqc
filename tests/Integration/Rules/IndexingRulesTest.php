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
class IndexingRulesTest extends TestCase
{
    private CheckerRegistry $registry;
    private RuleValidator $validator;

    protected function setUp(): void
    {
        $this->registry  = new CheckerRegistry();
        $this->validator = new RuleValidator();
    }

    public static function indexingRuleProvider(): array
    {
        $rulesDir    = ACQC_PROJECT_ROOT . '/config/rules/adobe-commerce/indexing/';
        $fixturesDir = ACQC_FIXTURES_PATH . '/Rules/';

        return [
            'AC_IDX_001 programmatic reindex bad' => [
                $rulesDir . 'ac-idx-001-programmatic-reindex.yaml',
                $fixturesDir . 'BadCode/indexing/programmatic-reindex.php',
                true,
            ],
            'AC_IDX_001 programmatic reindex good' => [
                $rulesDir . 'ac-idx-001-programmatic-reindex.yaml',
                $fixturesDir . 'GoodCode/indexing/programmatic-reindex.php',
                false,
            ],
            'AC_IDX_002 missing indexer dependency bad' => [
                $rulesDir . 'ac-idx-002-missing-indexer-dependency.yaml',
                $fixturesDir . 'BadCode/indexing/missing-indexer-dependency.xml',
                true,
            ],
            'AC_IDX_002 missing indexer dependency good' => [
                $rulesDir . 'ac-idx-002-missing-indexer-dependency.yaml',
                $fixturesDir . 'GoodCode/indexing/missing-indexer-dependency.xml',
                false,
            ],
            'AC_IDX_003 observer full reindex bad' => [
                $rulesDir . 'ac-idx-003-observer-full-reindex.yaml',
                $fixturesDir . 'BadCode/indexing/observer-full-reindex.php',
                true,
            ],
            'AC_IDX_003 observer full reindex good' => [
                $rulesDir . 'ac-idx-003-observer-full-reindex.yaml',
                $fixturesDir . 'GoodCode/indexing/observer-full-reindex.php',
                false,
            ],
        ];
    }

    #[DataProvider('indexingRuleProvider')]
    public function testIndexingRule(string $ruleFile, string $fixtureFile, bool $expectViolation): void
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

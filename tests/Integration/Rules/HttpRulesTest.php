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
class HttpRulesTest extends TestCase
{
    private CheckerRegistry $registry;
    private RuleValidator $validator;

    protected function setUp(): void
    {
        $this->registry  = new CheckerRegistry();
        $this->validator = new RuleValidator();
    }

    public static function httpRuleProvider(): array
    {
        $rulesDir    = ACQC_PROJECT_ROOT . '/config/rules/adobe-commerce/http/';
        $fixturesDir = ACQC_FIXTURES_PATH . '/Rules/';

        return [
            'AC_HTTP_001 curl timeout bad' => [
                $rulesDir . 'ac-http-001-curl-timeout.yaml',
                $fixturesDir . 'BadCode/http/curl-timeout.php',
                true,
            ],
            'AC_HTTP_001 curl timeout good' => [
                $rulesDir . 'ac-http-001-curl-timeout.yaml',
                $fixturesDir . 'GoodCode/http/curl-timeout.php',
                false,
            ],
            'AC_HTTP_002 curl error check bad' => [
                $rulesDir . 'ac-http-002-curl-error-check.yaml',
                $fixturesDir . 'BadCode/http/curl-error-check.php',
                true,
            ],
            'AC_HTTP_002 curl error check good' => [
                $rulesDir . 'ac-http-002-curl-error-check.yaml',
                $fixturesDir . 'GoodCode/http/curl-error-check.php',
                false,
            ],
            'AC_HTTP_003 serial http calls bad' => [
                $rulesDir . 'ac-http-003-serial-http-calls.yaml',
                $fixturesDir . 'BadCode/http/serial-http-calls.php',
                true,
            ],
            'AC_HTTP_003 serial http calls good' => [
                $rulesDir . 'ac-http-003-serial-http-calls.yaml',
                $fixturesDir . 'GoodCode/http/serial-http-calls.php',
                false,
            ],
            'AC_HTTP_004 http url bad' => [
                $rulesDir . 'ac-http-004-http-url.yaml',
                $fixturesDir . 'BadCode/http/http-url.php',
                true,
            ],
            'AC_HTTP_004 http url good' => [
                $rulesDir . 'ac-http-004-http-url.yaml',
                $fixturesDir . 'GoodCode/http/http-url.php',
                false,
            ],
            'AC_HTTP_005 ssl verify disabled bad' => [
                $rulesDir . 'ac-http-005-ssl-verify-disabled.yaml',
                $fixturesDir . 'BadCode/http/ssl-verify-disabled.php',
                true,
            ],
            'AC_HTTP_005 ssl verify disabled good' => [
                $rulesDir . 'ac-http-005-ssl-verify-disabled.yaml',
                $fixturesDir . 'GoodCode/http/ssl-verify-disabled.php',
                false,
            ],
            'AC_HTTP_006 file get contents bad' => [
                $rulesDir . 'ac-http-006-file-get-contents-http.yaml',
                $fixturesDir . 'BadCode/http/file-get-contents-http.php',
                true,
            ],
            'AC_HTTP_006 file get contents good' => [
                $rulesDir . 'ac-http-006-file-get-contents-http.yaml',
                $fixturesDir . 'GoodCode/http/file-get-contents-http.php',
                false,
            ],
        ];
    }

    #[DataProvider('httpRuleProvider')]
    public function testHttpRule(string $ruleFile, string $fixtureFile, bool $expectViolation): void
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

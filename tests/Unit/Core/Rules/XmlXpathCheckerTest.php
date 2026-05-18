<?php

declare(strict_types=1);

namespace ACQC\Tests\Unit\Core\Rules;

use ACQC\Core\Rules\Checkers\RuleViolation;
use ACQC\Core\Rules\Checkers\XmlXpathChecker;
use ACQC\Core\Rules\RuleDefinition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(XmlXpathChecker::class)]
#[CoversClass(RuleViolation::class)]
#[Group('unit')]
class XmlXpathCheckerTest extends TestCase
{
    private XmlXpathChecker $checker;
    private string $fixtureBase;

    protected function setUp(): void
    {
        $this->checker     = new XmlXpathChecker();
        $this->fixtureBase = dirname(__DIR__, 3) . '/Fixtures/Rules';
    }

    // ── AC_CRON_001 — use_separate_process ────────────────────────────────────

    public function testBadCrontabTriggersCronViolation(): void
    {
        $violations = $this->checker->check(
            $this->makeCronRule(),
            $this->fixtureBase . '/BadCode/cron/crontab-missing-separate-process.xml'
        );

        $this->assertNotEmpty($violations);
        $this->assertContainsOnlyInstancesOf(RuleViolation::class, $violations);
        $this->assertSame('AC_CRON_001', $violations[0]->ruleId);
        $this->assertSame('error', $violations[0]->severity);
    }

    public function testGoodCrontabProducesNoViolations(): void
    {
        $violations = $this->checker->check(
            $this->makeCronRule(),
            $this->fixtureBase . '/GoodCode/cron/crontab-with-separate-process.xml'
        );

        $this->assertEmpty($violations);
    }

    // ── edge cases ────────────────────────────────────────────────────────────

    public function testMissingFileReturnsEmpty(): void
    {
        $violations = $this->checker->check($this->makeCronRule(), '/non/existent/file.xml');
        $this->assertEmpty($violations);
    }

    public function testMalformedXmlReturnsWarningViolation(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'acqc_test_xml_') . '.xml';
        file_put_contents($tmp, '<broken><unclosed>');

        try {
            $violations = $this->checker->check($this->makeCronRule(), $tmp);
            $this->assertNotEmpty($violations);
            $this->assertSame('warning', $violations[0]->severity);
            $this->assertStringContainsString('Malformed XML', $violations[0]->message);
        } finally {
            @unlink($tmp);
        }
    }

    public function testEmptyXmlFileReturnsEmpty(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'acqc_test_xml_') . '.xml';
        file_put_contents($tmp, '');

        try {
            $violations = $this->checker->check($this->makeCronRule(), $tmp);
            $this->assertEmpty($violations);
        } finally {
            @unlink($tmp);
        }
    }

    public function testAttributeExtractionWorks(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'acqc_test_xml_') . '.xml';
        file_put_contents($tmp, '<config><group id="default"><job name="foo"/></group></config>');

        $rule = $this->makeRuleWithCheck([
            'type'       => 'xml_xpath',
            'expression' => '//job',
            'attribute'  => 'name',
        ]);

        try {
            $violations = $this->checker->check($rule, $tmp);
            $this->assertNotEmpty($violations);
            $this->assertStringContainsString('foo', $violations[0]->message);
        } finally {
            @unlink($tmp);
        }
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    private function makeCronRule(): RuleDefinition
    {
        return $this->makeRuleWithCheck([
            'type'       => 'xml_xpath',
            'expression' => '//group[not(use_separate_process)]',
            'attribute'  => null,
        ]);
    }

    private function makeRuleWithCheck(array $check): RuleDefinition
    {
        return RuleDefinition::fromArray([
            'schema_version' => '1.0',
            'id'             => 'AC_CRON_001',
            'name'           => 'ac-cron-001-process-isolation',
            'title'          => 'Cron Group Missing use_separate_process',
            'description'    => 'Cron groups should use separate process.',
            'category'       => 'cron',
            'severity'       => 'error',
            'tier'           => 1,
            'roles'          => ['developer', 'ci'],
            'platforms'      => ['cli', 'ci'],
            'introduced_in'  => '1.0.0',
            'globs'          => ['**/crontab.xml'],
            'check'          => $check,
            'examples'       => ['bad' => '<group/>', 'good' => '<group><use_separate_process>1</use_separate_process></group>'],
            'recommendation' => 'Add use_separate_process.',
        ], '/rules/ac-cron-001.yaml');
    }
}

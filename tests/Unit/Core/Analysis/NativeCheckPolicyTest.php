<?php

declare(strict_types=1);

namespace ACQC\Tests\Unit\Core\Analysis;

use ACQC\Analyzers\PHP\PHPCSAnalyzer;
use ACQC\Analyzers\PHP\PHPMDAnalyzer;
use ACQC\Commands\ReportCommand;
use ACQC\Core\Analysis\NativeCheckPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NativeCheckPolicy::class)]
class NativeCheckPolicyTest extends TestCase
{
    private array $configuration = [
        'phpmd' => ['rulesets' => ['codesize'], 'minimum_priority' => 5],
        'phpcs' => ['warning_severity' => 7],
    ];
    private array $results = ['php_phpmd' => ['status' => 'success'], 'php_phpcs' => ['status' => 'success']];
    private array $coverage = ['php_phpmd' => ['/project/Example.php'], 'php_phpcs' => ['/project/Example.php']];
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            unlink($file);
        }
    }

    public function testOnlyCoveredNativeChecksAreSuppressed(): void
    {
        $removed = ['HighCyclomaticComplexity', 'LongMethod', 'LongParameterList', 'GodObject', 'DeeplyNestedCode'];
        $retained = [
            'HighCognitiveComplexity', 'MissingDocumentation', 'LargeClass', 'LargeFile',
            'WeakPasswordHashing', 'DirectSqlQuery', 'MissingNamespace',
            'DirectSuperGlobalAccess', 'CommandInjectionRisk', 'FileInclusionRisk',
            'phpmd.Code Size Rules.CyclomaticComplexity', 'PHPCS.Generic.Metrics.NestingLevel.TooHigh',
        ];
        $violations = array_map($this->finding(...), array_merge($removed, $retained));
        $result = $this->apply($violations);
        $this->assertSame($retained, array_column($result['violations'], 'rule'));
        $this->assertSame(5, $result['suppressed_by_analyzer']['php_code_health']);
        $this->assertSame([], $result['fallback_analyzers']);
    }

    public function testFailuresAndUnavailableOwnersPreserveNativeFallback(): void
    {
        $native = [$this->finding('LongMethod'), $this->finding('DeeplyNestedCode')];
        $this->results = ['php_phpmd' => ['status' => 'failed']];
        $result = $this->apply($native);
        $this->assertSame($native, $result['violations']);
        $this->assertSame(['php_phpmd', 'php_phpcs'], $result['fallback_analyzers']);
        $this->results = ['php_phpmd' => ['status' => 'success']];
        $this->coverage = ['php_phpmd' => []];
        $this->assertSame($native, $this->apply($native)['violations']);
    }

    public function testNativeChecksOutsideActuallyAnalyzedFilesRemain(): void
    {
        $finding = $this->finding('LongMethod');
        $finding['file'] = '/project/Unscanned.php';
        $this->assertSame([$finding], $this->apply([$finding])['violations']);
    }

    public function testPartialPhpmdCoverageRetainsNativeChecksForSkippedFilesOnly(): void
    {
        $good = $this->finding('LongMethod');
        $bad = $good;
        $bad['file'] = '/project/Skipped.php';
        $this->results['php_phpmd'] = [
            'status' => 'partial',
            'skipped_files' => [['file' => $bad['file'], 'error' => 'Fatal declaration error']],
        ];
        $this->coverage['php_phpmd'][] = $bad['file'];
        $result = $this->apply([$good, $bad]);
        $this->assertSame([$bad], $result['violations']);
        $this->assertSame(['php_phpmd'], $result['fallback_analyzers']);
        $this->results['php_phpmd'] = ['status' => 'partial'];
        $this->assertSame([$good, $bad], $this->apply([$good, $bad])['violations']);
    }

    public function testRulesAndPriorityMustProvideReplacement(): void
    {
        $native = [$this->finding('HighCyclomaticComplexity'), $this->finding('LongMethod')];
        $this->configuration['phpmd']['rulesets'] = ['unusedcode'];
        $this->assertSame($native, $this->apply($native)['violations']);
        $this->configuration['phpmd']['rulesets'] = ['codesize'];
        $this->configuration['phpmd']['minimum_priority'] = 1;
        $this->assertSame($native, $this->apply($native)['violations']);
    }

    public function testCustomMagentoStyleReferencesAndExclusionsAreHonored(): void
    {
        $file = $this->xml('<rule ref="rulesets/codesize.xml"><exclude name="ExcessiveMethodLength"/></rule>');
        $this->configuration['phpmd']['rulesets'] = [$file];
        $native = [$this->finding('LongMethod'), $this->finding('HighCyclomaticComplexity')];
        $this->assertSame([$native[0]], $this->apply($native)['violations']);
        file_put_contents($file, '<ruleset><rule ref="rulesets/codesize.xml/CyclomaticComplexity"><priority>1</priority></rule></ruleset>');
        $this->configuration['phpmd']['minimum_priority'] = 1;
        $this->assertSame([$native[0]], $this->apply($native)['violations']);
    }

    public function testNestingUsesSelectedStandardSeverityAndSniffExclusions(): void
    {
        $native = [$this->finding('DeeplyNestedCode')];
        foreach ([0, 8] as $threshold) {
            $this->configuration['phpcs']['warning_severity'] = $threshold;
            $this->assertSame($native, $this->apply($native)['violations']);
        }
        $this->configuration['phpcs']['warning_severity'] = 7;
        $this->configuration['standard'] = 'PSR12';
        $this->assertSame($native, $this->apply($native)['violations']);
        $this->configuration['standard'] = 'Magento2';
        $this->configuration['exclude_sniffs'] = ['Generic.Metrics.NestingLevel'];
        $this->assertSame($native, $this->apply($native)['violations']);
        $this->configuration['exclude_sniffs'] = [];
        $this->configuration['sniffs'] = ['Magento2.Security.Superglobal'];
        $this->assertSame($native, $this->apply($native)['violations']);
    }

    public function testNestingRulesetFileExclusionsRetainNativeCheck(): void
    {
        $file = '/project/PHPCSUtils/Example.php';
        $native = $this->finding('DeeplyNestedCode');
        $native['file'] = $file;
        $this->coverage['php_phpcs'] = [$file];
        $this->assertSame([$native], $this->apply([$native])['violations']);
    }

    public function testPartialChecksAreRemovedOnlyWithMatchingExternalFinding(): void
    {
        $native = $this->finding('CommandInjectionRisk');
        $external = $this->finding('PHPCS.Magento2.Security.InsecureFunction.Found');
        $this->assertSame([$external], $this->apply([$native, $external])['violations']);
        $external['line']++;
        $this->assertSame([$native, $external], $this->apply([$native, $external])['violations']);
        $namespace = $this->finding('MissingNamespace');
        $external = $this->finding('PHPCS.PSR1.Classes.ClassDeclaration.MissingNamespace');
        $external['line'] = 30;
        $this->assertSame([$external], $this->apply([$namespace, $external])['violations']);
    }

    public function testObjectManagerKeepsStrongestOriginAndDistinctCallsFromThatOrigin(): void
    {
        $performance = $this->finding('magento-performance-object-manager-usage');
        $inline = $this->finding('ObjectManagerUsage');
        $inline['severity'] = 'critical';
        $upgrade = $this->finding('magento-upgrade-objectmanager-usage');
        $other = $inline;
        $other['line'] = 20;
        $result = $this->apply([$performance, $inline, $inline, $upgrade, $other]);
        $this->assertSame([$inline, $inline, $other], $result['violations']);
        $this->assertSame(1, $result['suppressed_by_analyzer']['magento_performance']);
        $this->assertSame(1, $result['suppressed_by_analyzer']['magento_upgrade']);
    }

    public function testInvalidMetadataFailsExplicitlyWithoutMutatingInput(): void
    {
        $this->configuration['phpmd']['rulesets'] = [$this->xml('<invalid')];
        $this->expectException(\RuntimeException::class);
        $this->apply([$this->finding('LongMethod')]);
    }

    public function testInvalidPolicyValueIsRejected(): void
    {
        $this->assertFalse(NativeCheckPolicy::validate(false));
        $this->assertTrue(NativeCheckPolicy::validate(true));
        $this->expectException(\InvalidArgumentException::class);
        NativeCheckPolicy::validate('true');
    }

    public function testInlineLengthHeuristicsAreDisabledOnlyWhenOptedIn(): void
    {
        $command = new ReportCommand();
        $method = new \ReflectionMethod($command, 'checkMaintainabilityViolationsWithModule');
        $violations = [];
        $method->invokeArgs($command, ['function example() {', 1, '/project/Example.php', &$violations, 'Example']);
        $this->assertContains('LongMethod', array_column($violations, 'rule'));
        (new \ReflectionProperty($command, 'preferExternalChecks'))->setValue($command, true);
        $violations = [];
        $method->invokeArgs($command, ['function example() {', 1, '/project/Example.php', &$violations, 'Example']);
        $this->assertNotContains('LongMethod', array_column($violations, 'rule'));
        $this->assertContains('MissingPhpDoc', array_column($violations, 'rule'));
    }

    public function testExternalParseFailuresAreNotSuccessShapedWhenOptedIn(): void
    {
        $phpcs = new PHPCSAnalyzer(['prefer_external_checks' => true]);
        $method = new \ReflectionMethod($phpcs, 'parseOutput');
        try {
            $method->invoke($phpcs, '{"invalid":true}', ['/project/Example.php']);
            $this->fail('Invalid PHPCS output must fail.');
        } catch (\ACQC\Analyzers\AnalyzerException $e) {
            $this->assertStringContainsString('files mapping', $e->getMessage());
        }
        $default = new PHPCSAnalyzer();
        $this->assertSame([], $method->invoke($default, '{"invalid":true}', []));
        $phpmd = new PHPMDAnalyzer(['prefer_external_checks' => true]);
        $this->expectException(\ACQC\Analyzers\AnalyzerException::class);
        (new \ReflectionMethod($phpmd, 'parseOutput'))->invoke($phpmd, '<invalid/>', ['/project/Example.php']);
    }

    public function testPhpcsCoverageIncludesOnlyFilesInActualReport(): void
    {
        $phpcs = new PHPCSAnalyzer(['prefer_external_checks' => true]);
        $json = json_encode(['files' => ['/project/Processed.php' => ['messages' => []]]], JSON_THROW_ON_ERROR);
        (new \ReflectionMethod($phpcs, 'parseOutput'))->invoke($phpcs, $json, ['/project/Processed.php', '/project/Excluded.php']);
        $this->assertSame(['/project/Processed.php'], $phpcs->getLastResults()['files']);
    }

    public function testIncompletePhpcsFindingsDoNotGrantCoverage(): void
    {
        $phpcs = new PHPCSAnalyzer(['prefer_external_checks' => true]);
        $json = json_encode(['files' => ['/project/Processed.php' => ['messages' => [['line' => 1]]]]], JSON_THROW_ON_ERROR);
        $this->expectException(\ACQC\Analyzers\AnalyzerException::class);
        (new \ReflectionMethod($phpcs, 'parseOutput'))->invoke($phpcs, $json, []);
    }

    public function testPhpmdProcessingErrorsDoNotGrantCoverage(): void
    {
        $phpmd = new PHPMDAnalyzer(['prefer_external_checks' => true]);
        $this->expectException(\ACQC\Analyzers\AnalyzerException::class);
        (new \ReflectionMethod($phpmd, 'parseOutput'))->invoke($phpmd, '<pmd><error filename="Example.php" msg="Parse failed"/></pmd>', []);
    }

    public function testUnsupportedPhpmdJsonCannotSuppressNativeChecks(): void
    {
        $phpmd = new PHPMDAnalyzer(['prefer_external_checks' => true, 'report_format' => 'json']);
        $this->expectException(\ACQC\Analyzers\AnalyzerException::class);
        (new \ReflectionMethod($phpmd, 'parseOutput'))->invoke($phpmd, '{}', []);
    }

    private function finding(string $rule): array
    {
        return ['rule' => $rule, 'file' => '/project/Example.php', 'line' => 5, 'severity' => 'warning'];
    }

    private function apply(array $violations): array
    {
        return (new NativeCheckPolicy())->apply($violations, $this->configuration, $this->results, $this->coverage);
    }

    private function xml(string $rules): string
    {
        $file = tempnam(sys_get_temp_dir(), 'acqc-native-policy-');
        $this->assertNotFalse($file);
        $this->temporaryFiles[] = $file;
        file_put_contents($file, '<ruleset>' . $rules . '</ruleset>');
        return $file;
    }
}

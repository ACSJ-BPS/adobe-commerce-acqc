<?php

declare(strict_types=1);

namespace ACQC\Tests\Unit\Analyzers\PHP;

use ACQC\Analyzers\PHP\PHPMDAnalyzer;
use ACQC\Analyzers\AnalyzerException;
use ACQC\Core\Analysis\AnalyzerOrchestrator;
use ACQC\Core\Rules\Violation;
use ACQC\Reports\Generators\HTMLReportGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;

#[CoversClass(PHPMDAnalyzer::class)]
#[Group('unit')]
#[Group('analyzers')]
class PHPMDAnalyzerTest extends TestCase
{
    public function testDefaultLabelsRemainUnchanged(): void
    {
        foreach ([1 => 'critical', 2 => 'error', 3 => 'warning', 4 => 'info', 5 => 'info'] as $priority => $severity) {
            $this->assertSame($severity, $this->parse([], $priority)->getSeverity());
        }
    }

    public function testProjectPolicyAndExactRuleOverrides(): void
    {
        $policy = [
            'severity_mapping' => [1 => 'error', 2 => 'warning', 3 => 'warning', 4 => 'info', 5 => 'info'],
            'rule_severity_overrides' => [
                'phpmd.Magento Specific Design Rules.AllPurposeAction' => 'error',
                'phpmd.Magento Specific Design Rules.CookieAndSessionMisuse' => 'error',
            ],
        ];
        foreach ($policy['severity_mapping'] as $priority => $severity) {
            $violation = $this->parse(['phpmd' => $policy], $priority);
            $this->assertSame($severity, $violation->getSeverity());
            $this->assertSame($priority, $violation->getMetadataValue('phpmd_priority'));
        }
        foreach (['AllPurposeAction', 'CookieAndSessionMisuse'] as $rule) {
            $violation = $this->parse(['phpmd' => $policy], 2, 'Magento Specific Design Rules', $rule);
            $this->assertSame('error', $violation->getSeverity());
            $this->assertSame("phpmd.Magento Specific Design Rules.{$rule}", $violation->getRuleId());
            $this->assertSame(2, $violation->getMetadataValue('phpmd_priority'));
            $this->assertSame('warning', $this->parse(['phpmd' => $policy], 2, 'Other Rules', $rule)->getSeverity());
        }
    }

    public function testPartialMappingsFallBackAndDoNotChangeRuleSelection(): void
    {
        $configuration = ['phpmd' => ['severity_mapping' => [2 => 'warning']]];
        $this->assertSame('critical', $this->parse($configuration, 1)->getSeverity());
        $this->assertSame('info', $this->parse($configuration, 5)->getSeverity());
        $analyzer = new PHPMDAnalyzer($configuration);
        $this->assertSame((new PHPMDAnalyzer())->getConfiguration('rulesets'), $analyzer->getConfiguration('rulesets'));
        $this->assertSame((new PHPMDAnalyzer())->getConfiguration('minimum_priority'), $analyzer->getConfiguration('minimum_priority'));
    }

    public function testFlatGlobalMappingKeysAreNotUsedAsPhpmdPolicy(): void
    {
        $this->assertSame('error', $this->parse([
            'severity_mapping' => [2 => 'info'],
            'rule_severity_overrides' => ['phpmd.Design Rules.CouplingBetweenObjects' => 'critical'],
        ], 2)->getSeverity());
    }

    public function testRuleOnlyOverrideTakesPrecedenceOverDefaultPriorityLabel(): void
    {
        $this->assertSame('critical', $this->parse([
            'phpmd' => ['rule_severity_overrides' => ['phpmd.Design Rules.CouplingBetweenObjects' => 'critical']],
        ], 3)->getSeverity());
    }

    public function testFatalFileIsIsolatedWithoutLosingOrDuplicatingOtherFindings(): void
    {
        $files = $this->fixtureFiles();
        $bad = $files[1];
        foreach ([false, true] as $preferExternal) {
            $analyzer = $this->simulatedAnalyzer(fn(array $batch) => in_array($bad, $batch, true)
                ? ["PHP Fatal error: Fixture declaration failure in {$bad} on line 1", 255]
                : [$this->xmlReport($batch), 2], ['prefer_external_checks' => $preferExternal]);
            $violations = $analyzer->analyze($files);
            $this->assertSame([$files[0], $files[2]], array_map(static fn(Violation $v) => $v->getFilePath(), $violations));
            $this->assertSame([$files[0], $files[2]], $analyzer->getLastResults()['files']);
            $this->assertSame([$bad], array_column($analyzer->getLastResults()['skipped_files'], 'file'));
            $this->assertStringContainsString('declaration failure', $analyzer->getLastResults()['skipped_files'][0]['error']);
        }
    }

    public function testXmlProcessingErrorsAreIsolatedAndSuccessfulCoverageIsPartial(): void
    {
        $files = $this->fixtureFiles();
        $bad = $files[1];
        $analyzer = $this->simulatedAnalyzer(fn(array $batch) => in_array($bad, $batch, true)
            ? [str_replace('</pmd>', '<error filename="' . $bad . '" msg="Parse failed"/></pmd>', $this->xmlReport($batch)), 3]
            : [$this->xmlReport($batch), 2]);
        $orchestrator = new AnalyzerOrchestrator([]);
        (new \ReflectionProperty($orchestrator, 'analyzers'))->setValue($orchestrator, [
            'php_phpmd' => ['instance' => $analyzer, 'enabled' => true],
        ]);
        $result = $orchestrator->analyze($files, ['track_native_replacements' => true]);
        $receipt = $result['analyzer_results']['php_phpmd'];
        $this->assertSame('partial', $receipt['status']);
        $this->assertSame(2, $receipt['files_analyzed']);
        $this->assertSame(2, $receipt['violations']);
        $this->assertSame([$bad], array_column($receipt['skipped_files'], 'file'));
        $this->assertSame([$files[0], $files[2]], $result['analyzed_files']['php_phpmd']);
    }

    public function testFileTimeoutIsIsolated(): void
    {
        $files = $this->fixtureFiles();
        $bad = $files[1];
        $analyzer = $this->simulatedAnalyzer(function (array $batch) use ($bad): array {
            if (in_array($bad, $batch, true)) {
                throw new ProcessTimedOutException(new Process([PHP_BINARY, '--version']), ProcessTimedOutException::TYPE_GENERAL);
            }
            return [$this->xmlReport($batch), 2];
        });
        $this->assertCount(2, $analyzer->analyze($files));
        $this->assertStringContainsString('timed out', $analyzer->getLastResults()['skipped_files'][0]['error']);
    }

    public function testAllFilesFailingIsAnAnalyzerFailureWithDiagnostics(): void
    {
        $analyzer = $this->simulatedAnalyzer(fn(array $batch) => ["PHP Fatal error: Fixture failure in {$batch[0]} on line 1", 255]);
        $orchestrator = new AnalyzerOrchestrator([]);
        (new \ReflectionProperty($orchestrator, 'analyzers'))->setValue($orchestrator, [
            'php_phpmd' => ['instance' => $analyzer, 'enabled' => true],
        ]);
        $result = $orchestrator->analyze($this->fixtureFiles(), ['track_native_replacements' => true]);
        $receipt = $result['analyzer_results']['php_phpmd'];
        $this->assertSame('failed', $receipt['status']);
        $this->assertSame(0, $receipt['files_analyzed']);
        $this->assertCount(3, $receipt['skipped_files']);
        $this->assertSame([], $analyzer->getLastResults()['files']);
        $this->assertSame([], $result['violations']);
        $this->assertArrayNotHasKey('php_phpmd', $result['analyzed_files']);
    }

    public function testMalformedReportInvalidatesPreviouslySuccessfulBatches(): void
    {
        $files = $this->fixtureFiles();
        $analyzer = $this->simulatedAnalyzer(fn(array $batch) => [
            $batch[0] === $files[1] ? '<invalid/>' : $this->xmlReport($batch), 0,
        ], ['batch_size' => 1]);
        try {
            $analyzer->analyze($files);
            $this->fail('Malformed output must remain an analyzer failure.');
        } catch (AnalyzerException $e) {
            $this->assertStringContainsString('XML report root', $e->getMessage());
            $this->assertSame([], $analyzer->getLastResults()['files']);
        }
    }

    public function testConfigurationFailuresAreNotSkippedAsBadFiles(): void
    {
        $analyzer = $this->simulatedAnalyzer(static fn(array $batch) => ['Cannot load configured ruleset', 1]);
        $this->expectException(AnalyzerException::class);
        try {
            $analyzer->analyze($this->fixtureFiles());
        } finally {
            $this->assertSame([], $analyzer->getLastResults()['skipped_files']);
            $this->assertSame([], $analyzer->getLastResults()['files']);
        }
    }

    public function testBootstrapFatalErrorsRemainGlobalFailures(): void
    {
        $analyzer = $this->simulatedAnalyzer(
            static fn(array $batch) => ['PHP Fatal error: Bootstrap failed in ' . __FILE__ . ' on line 1', 255],
            ['phpmd' => ['bootstrap' => __FILE__]]
        );
        $this->expectException(AnalyzerException::class);
        try {
            $analyzer->analyze($this->fixtureFiles());
        } finally {
            $this->assertSame([], $analyzer->getLastResults()['skipped_files']);
        }
    }

    public function testSkippedFilesAreRetriedDuringIncrementalAnalysisAndStateIsReset(): void
    {
        $files = $this->fixtureFiles();
        $bad = $files[1];
        $fail = true;
        $analyzer = $this->simulatedAnalyzer(function (array $batch) use ($bad, &$fail): array {
            return $fail && in_array($bad, $batch, true)
                ? ["PHP Fatal error: Fixture failure in {$bad} on line 1", 255]
                : [$this->xmlReport($batch), 2];
        });
        $analyzer->analyze($files);
        $last = $analyzer->getLastResults();
        $last['timestamp'] = time() + 100;
        (new \ReflectionProperty($analyzer, 'lastAnalysisResults'))->setValue($analyzer, $last);
        (new \ReflectionProperty($analyzer, 'incrementalMode'))->setValue($analyzer, true);
        $fail = false;
        $this->assertCount(1, $analyzer->analyze($files));
        $this->assertSame([$bad], $analyzer->getLastResults()['files']);
        $this->assertSame([], $analyzer->getLastResults()['skipped_files']);
        $this->assertSame([], $analyzer->analyze([]));
        $this->assertSame([], $analyzer->getLastResults()['files']);
    }

    public function testHtmlShowsPartialCoverageAndEscapesSkipErrors(): void
    {
        $data = [
            'analysis' => ['analyzer_results' => [
                'php_phpmd' => [
                    'status' => 'partial',
                    'files_analyzed' => 2,
                    'skipped_files' => [['file' => '/project/Bad.php', 'error' => '<script>failure</script>']],
                ],
            ]],
        ];
        foreach (['summary', 'detailed'] as $type) {
            $result = (new HTMLReportGenerator())->setReportType($type)->generate($data);
            $this->assertTrue($result->isSuccess(), $result->getError() ?? '');
            $html = $result->getContent();
            $this->assertStringContainsString('Incomplete analyzer coverage: php_phpmd (partial)', $html);
            $this->assertStringContainsString('/project/Bad.php', $html);
            $this->assertStringContainsString('&lt;script&gt;failure&lt;/script&gt;', $html);
            $this->assertStringNotContainsString('<script>failure</script>', $html);
        }
    }

    public function testCustomHtmlThemeStillUsesBundledCoverageWarnings(): void
    {
        $generator = new HTMLReportGenerator();
        $twig = (new \ReflectionProperty($generator, 'twig'))->getValue($generator);
        $loader = $twig->getLoader();
        $this->assertInstanceOf(FilesystemLoader::class, $loader);
        $loader->setPaths([sys_get_temp_dir()]);
        $twig->setLoader(new ChainLoader([
            new ArrayLoader(['summary.html.twig' => '<html><body>Custom theme</body></html>']),
            $loader,
        ]));
        $result = $generator->generate([
            'analysis' => ['analyzer_results' => ['php_phpmd' => ['status' => 'failed', 'error' => 'Tool failure']]],
        ]);
        $this->assertTrue($result->isSuccess(), $result->getError() ?? '');
        $this->assertStringContainsString('Custom theme', $result->getContent());
        $this->assertStringContainsString('Incomplete analyzer coverage: php_phpmd (failed)', $result->getContent());
    }

    private function fixtureFiles(): array
    {
        return [__FILE__, __DIR__ . '/PHPCSAnalyzerTest.php', dirname(__DIR__, 2) . '/Core/Analysis/NativeCheckPolicyTest.php'];
    }

    private function xmlReport(array $files): string
    {
        $output = '<pmd>';
        foreach ($files as $file) {
            $output .= '<file name="' . htmlspecialchars($file, ENT_XML1 | ENT_QUOTES, 'UTF-8')
                . '"><violation beginline="1" endline="1" rule="CouplingBetweenObjects" ruleset="Design Rules" priority="3">'
                . 'Review this code.</violation></file>';
        }
        return $output . '</pmd>';
    }

    private function simulatedAnalyzer(callable $respond, array $configuration = []): PHPMDAnalyzer
    {
        $analyzer = $this->getMockBuilder(PHPMDAnalyzer::class)
            ->setConstructorArgs([array_replace(['ignore_patterns' => [], 'batch_size' => 3, 'minimum_priority' => 5], $configuration)])
            ->onlyMethods(['isAvailable', 'executeCommand', 'getVersion'])->getMock();
        $analyzer->method('isAvailable')->willReturn(true);
        $analyzer->method('getVersion')->willReturn('fixture');
        $analyzer->method('executeCommand')->willReturnCallback(function (array $command) use ($respond): Process {
            $files = [];
            if (!in_array('--version', $command, true)) {
                $index = array_search('xml', $command, true);
                $this->assertNotFalse($index);
                $files = explode(',', $command[$index - 1]);
            }
            [$output, $exit] = $respond($files);
            $process = new Process([PHP_BINARY, '-r', 'fwrite(STDOUT, $argv[1]); exit((int) $argv[2]);', $output, (string) $exit]);
            $process->run();
            if (!in_array($exit, [0, 2, 3], true)) {
                throw new ProcessFailedException($process);
            }
            return $process;
        });
        return $analyzer;
    }

    #[DataProvider('invalidPolicies')]
    public function testInvalidPolicyFailsExplicitly(array $policy): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PHPMDAnalyzer::resolveProjectConfiguration($policy, __DIR__);
    }

    public static function invalidPolicies(): array
    {
        return [
            'null mapping' => [['severity_mapping' => null]],
            'scalar mapping' => [['severity_mapping' => 'warning']],
            'priority zero' => [['severity_mapping' => [0 => 'error']]],
            'priority six' => [['severity_mapping' => [6 => 'error']]],
            'noncanonical priority' => [['severity_mapping' => ['01' => 'error']]],
            'invalid severity' => [['severity_mapping' => [1 => 'high']]],
            'nonscalar severity' => [['severity_mapping' => [1 => ['error']]]],
            'scalar overrides' => [['rule_severity_overrides' => 'error']],
            'short rule name' => [['rule_severity_overrides' => ['AllPurposeAction' => 'error']]],
            'wrong analyzer' => [['rule_severity_overrides' => ['phpcs.Design Rules.SomeRule' => 'error']]],
            'missing ruleset' => [['rule_severity_overrides' => ['phpmd.AllPurposeAction' => 'error']]],
            'missing rule' => [['rule_severity_overrides' => ['phpmd.Design Rules.' => 'error']]],
            'wildcard rule' => [['rule_severity_overrides' => ['phpmd.Design Rules.*' => 'error']]],
            'wildcard ruleset' => [['rule_severity_overrides' => ['phpmd.*.SomeRule' => 'error']]],
            'invalid override severity' => [['rule_severity_overrides' => ['phpmd.Design Rules.SomeRule' => 'high']]],
            'numeric rule key' => [['rule_severity_overrides' => [1 => 'error']]],
        ];
    }

    private function parse(array $configuration, int $priority, string $ruleset = 'Design Rules', string $rule = 'CouplingBetweenObjects'): Violation
    {
        $analyzer = new PHPMDAnalyzer($configuration);
        $file = htmlspecialchars(__FILE__, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $output = '<pmd><file name="' . $file . '"><violation beginline="1" endline="1" rule="' . $rule
            . '" ruleset="' . $ruleset . '" priority="' . $priority . '">Review this code.</violation></file></pmd>';
        $violations = (new \ReflectionMethod($analyzer, 'parseOutput'))->invoke($analyzer, $output, [__FILE__]);
        $this->assertCount(1, $violations);
        return $violations[0];
    }
}

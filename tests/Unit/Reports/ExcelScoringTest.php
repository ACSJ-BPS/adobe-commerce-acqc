<?php

declare(strict_types=1);

namespace ACQC\Tests\Unit\Reports;

use ACQC\Core\Config\ConfigManager;
use ACQC\Core\Scoring\ScoreCalculator;
use ACQC\Core\Scoring\ScoringConfigLoader;
use ACQC\Reports\Generators\ExcelReportGenerator;
use ACQC\Reports\Generators\JUnitReportGenerator;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class ExcelScoringTest extends TestCase
{
    public function testExcelKpiScorecardParityOnShellCounts(): void
    {
        $generator = (new ReflectionClass(ExcelReportGenerator::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($generator, 'getWeightedScoreBreakdown');
        $method->setAccessible(true);

        $data = [
            'violations' => [
                'total_count' => 12454,
                'by_severity' => ['blocker' => 0, 'critical' => 0, 'error' => 2135, 'warning' => 3405, 'info' => 6914, 'minor' => 0],
                'by_category' => ['seo' => 8625, 'accessibility' => 3073, 'documentation' => 270, 'maintainability' => 233, 'api' => 138, 'performance' => 90, 'logic' => 24, 'security' => 1],
            ],
            'metrics' => [
                'quality_score' => 91.9,
                'technical_debt' => ['total_hours' => 501.3],
                'maintainability' => ['index' => 0.0],
            ],
        ];

        $breakdown = $method->invoke($generator, $data);

        $this->assertSame(['90/100', 'Pass'], array_slice($breakdown['Release Readiness Score'], 0, 2));
        $this->assertSame(['20/100 risk', 'Pass'], array_slice($breakdown['Business Risk Score'], 0, 2));
        $this->assertSame(['92/100', 'Pass'], array_slice($breakdown['Quality Confidence Score'], 0, 2));
        $this->assertSame(['88/100', 'Warn'], array_slice($breakdown['Security Health Score'], 0, 2));
        $this->assertSame(['0.0', 'Fail'], array_slice($breakdown['Maintainability Index'], 0, 2));
        $this->assertSame(['98/100', 'Pass'], array_slice($breakdown['Performance Stability Score'], 0, 2));
        $this->assertSame(['17/100 risk', 'Warn'], array_slice($breakdown['Criticality Score'], 0, 2));
        $this->assertSame(['100/100', 'Pass'], array_slice($breakdown['Upgrade Readiness Score'], 0, 2));
        $this->assertSame(['10/100 risk', 'Pass'], array_slice($breakdown['Customer Impact Risk Score'], 0, 2));
    }

    public function testExcelDefaultSummarySheetPopulatesReadinessFilesAndLoc(): void
    {
        $cm = new ConfigManager(null, false);
        $generator = new ExcelReportGenerator($cm);
        $loader = new ScoringConfigLoader();
        $config = $loader->load(null, [
            'readiness' => [
                ['verdict' => 'RISKY', 'gate' => 'WARN', 'when_any' => ['error' => '> 10']],
                ['verdict' => 'READY TO SHIP', 'gate' => 'PASS'],
            ],
        ]);

        $data = [
            'violations' => [
                'total_count' => 25,
                'by_severity' => ['blocker' => 0, 'critical' => 0, 'error' => 25, 'warning' => 0, 'info' => 0],
                'by_category' => ['logic' => 25],
                'violations' => array_fill(0, 25, ['severity' => 'error', 'category' => 'logic', 'file' => 'Foo.php', 'line' => 10, 'rule_id' => 'r1', 'message' => 'err']),
            ],
            'code_size' => [
                'file_count' => 516,
                'total_lines' => 61982,
            ],
            'metrics' => [
                'technical_debt' => ['total_hours' => 25.0],
                'test_coverage' => ['line_coverage' => 0.5],
            ],
        ];
        $data['scores'] = (new ScoreCalculator())->calculateReportScores($data, $config);
        $data['metrics']['quality_score'] = $data['scores']['overall'];

        $result = $generator->generate($data, ['type' => 'summary']);
        $this->assertTrue($result->isSuccess());

        $tmpFile = sys_get_temp_dir() . '/acqc_xl_test_' . uniqid('', true) . '.xlsx';
        file_put_contents($tmpFile, $result->getContent());

        try {
            $spreadsheet = IOFactory::load($tmpFile);
            $sheet = $spreadsheet->getSheetByName('Summary');
            $this->assertNotNull($sheet);

            $kv = [];
            for ($r = 1; $r <= 20; $r++) {
                $k = (string) $sheet->getCell("A{$r}")->getValue();
                $v = (string) $sheet->getCell("B{$r}")->getValue();
                if ($k !== '') {
                    $kv[$k] = $v;
                }
            }

            $this->assertSame('RISKY', $kv['Release Readiness'] ?? null);
            $this->assertStringContainsString('error = 25', $kv['Readiness Reason'] ?? '');
            $this->assertSame('516', $kv['Files Analyzed'] ?? null);
            $this->assertSame('61,982', $kv['Lines of Code'] ?? null);
        } finally {
            @unlink($tmpFile);
        }
    }

    public function testExcelStatusBandsUseInjectedProjectConfig(): void
    {
        $generator = new ExcelReportGenerator(new ConfigManager(null, false));
        $config = (new ScoringConfigLoader())->load(null, [
            'status' => ['security_sheet' => ['score' => ['good' => '>= 99', 'warn' => '>= 98']]],
        ]);

        // generate() stores the injected config; a 90 security score must now be Critical
        $generator->generate(['violations' => ['total_count' => 0, 'by_severity' => [], 'by_category' => [], 'violations' => []]], [
            'type' => 'summary',
            'scoring_config' => $config,
        ]);
        $method = new ReflectionMethod($generator, 'getSecurityStatus');
        $method->setAccessible(true);
        $this->assertSame('Critical', $method->invoke($generator, 90.0));

        // Threshold text follows the same injected band
        $threshold = new ReflectionMethod($generator, 'statusThresholdText');
        $threshold->setAccessible(true);
        $generator->generate(['violations' => ['total_count' => 0, 'by_severity' => [], 'by_category' => [], 'violations' => []]], [
            'type' => 'summary',
            'scoring_config' => $config,
        ]);
        $this->assertSame('≥ 99%', $threshold->invoke($generator, 'security_sheet', 'score', ['good' => '>= 85'], 'good', 1.0, '%'));

        // Without an injected config the built-in band applies (90 >= 85 = Good)
        $generator->generate(['violations' => ['total_count' => 0, 'by_severity' => [], 'by_category' => [], 'violations' => []]], ['type' => 'summary']);
        $this->assertSame('Good', $method->invoke($generator, 90.0));

        // Built-in threshold texts keep the old meaning
        $this->assertSame('≥ 85%', $threshold->invoke($generator, 'security_sheet', 'score', ['good' => '>= 85'], 'good', 1.0, '%'));
        $this->assertSame('≥ 80%', $threshold->invoke($generator, 'code_quality_sheet', 'coverage', ['good' => '>= 0.80'], 'good', 100.0, '%'));
        $this->assertSame('≤ 200 hrs', $threshold->invoke($generator, 'code_quality_sheet', 'debt_hours', ['good' => '<= 200'], 'good', 1.0, ' hrs'));
        $this->assertSame('≤ 10', $threshold->invoke($generator, 'performance_sheet', 'total_violations', ['warn' => '<= 10'], 'warn'));
        $this->assertSame('≤ 5', $threshold->invoke($generator, 'code_quality_sheet', 'complexity', ['good' => '<= 5.0']));
    }

    public function testJunitRejectsUnknownGateConditionAndEvaluatesErrorGate(): void
    {
        $cm = new ConfigManager(null, false);
        $junit = new JUnitReportGenerator($cm);

        $data = [
            'violations' => [
                'total_count' => 12,
                'by_severity' => ['blocker' => 0, 'critical' => 0, 'error' => 12, 'warning' => 0, 'info' => 0],
            ],
            'metrics' => [
                'quality_score' => 95.0,
                'technical_debt' => ['total_hours' => 12.0],
            ],
        ];

        $res = $junit->generate($data, [
            'quality_gates' => [
                [
                    'name' => 'max_errors',
                    'condition' => 'error <= 5',
                    'description' => 'Max 5 errors',
                    'failure_message' => 'Too many errors',
                ],
            ],
        ]);

        $this->assertTrue($res->isSuccess());
        $this->assertStringContainsString('QualityGateFailure', $res->getContent());

        // Older gate keys still work
        $legacy = $junit->generate($data + ['metrics' => []], [
            'quality_gates' => [
                ['name' => 'coverage', 'condition' => 'test_coverage >= 0.8'],
                ['name' => 'complexity', 'condition' => 'average_complexity <= 10'],
            ],
        ]);
        $this->assertTrue($legacy->isSuccess());

        $this->expectException(InvalidArgumentException::class);
        $junit->generate($data, [
            'quality_gates' => [
                [
                    'name' => 'bad_gate',
                    'condition' => 'made_up_metric <= 5',
                ],
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $metrics
     * @return array<string, list<list<string>>> sheet name => rows (label rows only)
     */
    private function generateMaintainabilityRows(array $metrics, ?string $placeholders): array
    {
        $generator = new ExcelReportGenerator(new ConfigManager(null, false));
        $generator->setReportType('comprehensive_magento_audit');
        $overrides = $placeholders !== null ? ['placeholders' => $placeholders] : [];
        $config = (new ScoringConfigLoader())->load(null, $overrides);
        $data = [
            'violations' => ['total_count' => 0, 'by_severity' => [], 'by_category' => [], 'violations' => []],
            'metrics' => $metrics + ['technical_debt' => ['total_hours' => 1.0]],
        ];
        $data['scores'] = (new ScoreCalculator())->calculateReportScores($data, $config);
        $data['metrics']['quality_score'] = $data['scores']['overall'];

        $result = $generator->generate($data, ['scoring_config' => $config]);
        $this->assertTrue($result->isSuccess());

        $tmp = sys_get_temp_dir() . '/acqc_xl_maint_' . uniqid('', true) . '.xlsx';
        file_put_contents($tmp, $result->getContent());
        try {
            $book = IOFactory::load($tmp);
            $out = [];
            foreach (['Code Quality Analysis', 'Executive Summary'] as $name) {
                $sheet = $book->getSheetByName($name);
                $this->assertNotNull($sheet, $name);
                foreach ($sheet->toArray(null, true, false, false) as $row) {
                    $label = (string) ($row[0] ?? '');
                    if (in_array($label, ['Code Complexity Score', 'Maintainability Index', 'Documentation Coverage', 'Code Standards Compliance'], true)) {
                        $out[$name][] = array_map(static fn($c): string => (string) $c, array_slice($row, 0, 6));
                    }
                }
            }
            return $out;
        } finally {
            @unlink($tmp);
        }
    }

    public function testUnmeasuredMetricsShowNaInCodeQualityAndExecutiveSummary(): void
    {
        $rows = $this->generateMaintainabilityRows([
            'complexity' => ['average' => null],
            'maintainability' => ['index' => null],
            'documentation_coverage' => null,
            'standards_compliance' => null,
        ], null);

        $cq = array_column($rows['Code Quality Analysis'], null, 0);
        foreach (['Code Complexity Score', 'Maintainability Index', 'Documentation Coverage', 'Code Standards Compliance'] as $label) {
            $this->assertSame('N/A', $cq[$label][1], "$label value");
            $this->assertSame('N/A', $cq[$label][3], "$label status");
            $this->assertSame('N/A', $cq[$label][4], "$label impact");
        }
        $this->assertNotEmpty($rows['Executive Summary']);
        foreach ($rows['Executive Summary'] as $row) {
            $this->assertSame('N/A', $row[1]);
            $this->assertNotContains('✗ FAIL', $row);
        }
    }

    public function testMeasuredMetricsShowRealNumbers(): void
    {
        $rows = $this->generateMaintainabilityRows([
            'complexity' => ['average' => 3.2],
            'maintainability' => ['index' => 72.0],
            'documentation_coverage' => 80.0,
            'standards_compliance' => 95.0,
        ], null);

        $cq = array_column($rows['Code Quality Analysis'], null, 0);
        $this->assertSame('3.2', $cq['Code Complexity Score'][1]);
        $this->assertEquals(72.0, $cq['Maintainability Index'][1]);
        $this->assertStringStartsWith('80', $cq['Documentation Coverage'][1]);
        $this->assertStringStartsWith('95', $cq['Code Standards Compliance'][1]);
        foreach ($cq as $row) {
            $this->assertNotSame('N/A', $row[3]);
        }
        foreach ($rows['Executive Summary'] as $row) {
            $this->assertEquals(72.0, $row[1]);
        }
    }

    public function testLegacyPlaceholdersKeepZeroBehaviour(): void
    {
        $rows = $this->generateMaintainabilityRows([
            'complexity' => ['average' => null],
            'maintainability' => ['index' => null],
            'documentation_coverage' => null,
            'standards_compliance' => null,
        ], 'legacy');

        $cq = array_column($rows['Code Quality Analysis'], null, 0);
        $this->assertNotSame('N/A', $cq['Maintainability Index'][1]);
        $this->assertNotSame('N/A', $cq['Code Complexity Score'][1]);
        foreach ($rows['Executive Summary'] as $row) {
            $this->assertEquals(0.0, $row[1]);
        }
    }
}

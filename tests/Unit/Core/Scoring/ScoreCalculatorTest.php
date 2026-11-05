<?php

declare(strict_types=1);

namespace ACQC\Tests\Unit\Core\Scoring;

use ACQC\Core\Scoring\ScoreCalculator;
use ACQC\Core\Scoring\ScoreInputs;
use ACQC\Core\Scoring\ScoringConfigLoader;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class ScoreCalculatorTest extends TestCase
{
    /**
     * Exact Shell c-report.xlsx counts from Plan Section 3b.1 & 7.
     *
     * @return array<string, mixed>
     */
    private function buildShellAggregateData(bool $includeDeprecationRules = true): array
    {
        return [
            'code_size' => [
                'file_count' => 516,
                'total_lines' => 62000,
            ],
            'violations' => [
                'total_count' => 12454,
                'by_severity' => [
                    'blocker' => 0,
                    'critical' => 0,
                    'error' => 2135,
                    'warning' => 3405,
                    'info' => 6914,
                    'minor' => 0,
                ],
                'by_category' => [
                    'seo' => 8625,
                    'accessibility' => 3073,
                    'documentation' => 270,
                    'maintainability' => 233,
                    'api' => 138,
                    'performance' => 90,
                    'logic' => 24,
                    'security' => 1,
                ],
                'by_category_severity' => [
                    'seo' => ['error' => 1024, 'warning' => 1536, 'info' => 6065],
                    'accessibility' => ['error' => 1025, 'warning' => 1536, 'info' => 512],
                    'documentation' => ['info' => 270],
                    'maintainability' => ['error' => 67, 'warning' => 165, 'info' => 1],
                    'api' => ['warning' => 98, 'info' => 40],
                    'performance' => ['error' => 7, 'warning' => 57, 'info' => 26],
                    'logic' => ['error' => 11, 'warning' => 13],
                    'security' => ['error' => 1],
                ],
                'by_rule_id' => $includeDeprecationRules ? [
                    'magento-deprecated-pattern' => ['count' => 9, 'category' => 'logic'],
                    'magento-upgrade-deprecated-method-call' => ['count' => 7, 'category' => 'logic'],
                    'magento-upgrade-deprecated-class-usage' => ['count' => 6, 'category' => 'logic'],
                ] : [],
            ],
            'metrics' => [
                'maintainability' => ['index' => 0.0],
            ],
        ];
    }

    public function testLegacyParityWithWeightsOneAndNoRuleRemap(): void
    {
        $loader = new ScoringConfigLoader();
        $config = $loader->load(null, [
            'category_weights' => ['seo' => 1.0, 'accessibility' => 1.0],
        ]);
        $calc = new ScoreCalculator();
        $scores = $calc->calculateReportScores($this->buildShellAggregateData(false), $config);

        // Plan Section 7 Column A: Overall 92 (91.9), rings 66/92/98/92/100/97/79, READY TO SHIP, debt 501.3
        $this->assertSame(91.9, $scores['overall']);
        $this->assertSame(92, $scores['overall_rounded']);
        $this->assertSame('READY TO SHIP', $scores['readiness']['verdict']);
        $this->assertSame('PASS', $scores['readiness']['gate']);
        $this->assertSame(501.3, $scores['debt_hours']);

        $this->assertSame(66, $scores['dimensions']['reliability']['score']);
        $this->assertSame(92, $scores['dimensions']['security']['score']);
        $this->assertSame(98, $scores['dimensions']['performance']['score']);
        $this->assertSame(92, $scores['dimensions']['maintainability']['score']);
        $this->assertSame(100, $scores['dimensions']['compliance']['score']);
        $this->assertSame(97, $scores['dimensions']['api_quality']['score']);
        $this->assertSame(79, $scores['dimensions']['code_quality']['score']);
    }

    public function testNewDefaultsMatchPlanColumnB(): void
    {
        $loader = new ScoringConfigLoader();
        $config = $loader->load();
        $calc = new ScoreCalculator();
        $scores = $calc->calculateReportScores($this->buildShellAggregateData(true), $config);

        // Plan Section 7 Column B: Overall 98 (97.5), rings 67/92/94/73/97/89/78, READY TO SHIP, debt 501.3
        $this->assertSame(98, $scores['overall_rounded']);
        $this->assertSame('READY TO SHIP', $scores['readiness']['verdict']);
        $this->assertSame(501.3, $scores['debt_hours']);

        $this->assertSame(67, $scores['dimensions']['reliability']['score']);
        $this->assertSame(92, $scores['dimensions']['security']['score']);
        $this->assertSame(94, $scores['dimensions']['performance']['score']);
        $this->assertSame(73, $scores['dimensions']['maintainability']['score']);
        $this->assertSame(97, $scores['dimensions']['compliance']['score']);
        $this->assertSame(89, $scores['dimensions']['api_quality']['score']);
        $this->assertSame(78, $scores['dimensions']['code_quality']['score']);
    }

    public function testProjectOverridesMatchPlanColumnE(): void
    {
        $loader = new ScoringConfigLoader();
        $config = $loader->load(null, [
            'readiness' => [
                ['verdict' => 'BLOCKED', 'gate' => 'FAIL', 'when_any' => ['blocker' => '> 0', 'critical' => '> 5']],
                ['verdict' => 'RISKY', 'gate' => 'WARN', 'when_any' => ['critical' => '> 0', 'error_weighted' => '> 50', 'overall' => '< 75']],
                ['verdict' => 'READY TO SHIP', 'gate' => 'PASS'],
            ],
            'overall' => [
                'caps' => [
                    ['when' => ['error_weighted' => '>= 100'], 'max_score' => 75],
                ],
            ],
            'kpis' => [
                'criticality' => ['pass' => '<= 2', 'warn' => '<= 10'],
            ],
        ]);

        $calc = new ScoreCalculator();
        $scores = $calc->calculateReportScores($this->buildShellAggregateData(true), $config);

        $this->assertSame(75.0, $scores['overall']);
        $this->assertSame(75, $scores['overall_rounded']);
        $this->assertNotEmpty($scores['caps_applied']);
        $this->assertStringContainsString('Capped at 75', $scores['caps_applied'][0]);

        $this->assertSame('RISKY', $scores['readiness']['verdict']);
        $this->assertSame('WARN', $scores['readiness']['gate']);
        $this->assertStringContainsString('error_weighted', $scores['readiness']['reason']);

        $this->assertSame('75/100', $scores['kpis']['quality_confidence']['display']);
        $this->assertSame('Warn', $scores['kpis']['quality_confidence']['status']);
        $this->assertSame('Fail', $scores['kpis']['criticality']['status']);
    }

    public function testZeroFindingsScoresHundredAndReady(): void
    {
        $loader = new ScoringConfigLoader();
        $calc = new ScoreCalculator();
        $scores = $calc->calculateReportScores([
            'violations' => ['total_count' => 0, 'violations' => []],
            'code_size' => ['file_count' => 25, 'total_lines' => 3000],
        ], $loader->load());

        $this->assertSame(100.0, $scores['overall']);
        $this->assertSame(100, $scores['overall_rounded']);
        $this->assertSame('READY TO SHIP', $scores['readiness']['verdict']);
        $this->assertSame(0.0, $scores['debt_hours']);
        foreach ($scores['dimensions'] as $dim) {
            $this->assertSame(100, $dim['score']);
        }
    }

    public function testDebtWholeTotalAndMonotonicMarginal(): void
    {
        $loader = new ScoringConfigLoader();
        $calc = new ScoreCalculator();
        $data = $this->buildShellAggregateData(true);

        $wholeScores = $calc->calculateReportScores($data, $loader->load());
        $this->assertSame(501.3, $wholeScores['debt_hours']);

        $marginalConfig = $loader->load(null, ['debt' => ['scaling' => 'marginal']]);
        $marginalScores = $calc->calculateReportScores($data, $marginalConfig);
        $this->assertSame(2401.3, $marginalScores['debt_hours']);

        // Verify marginal scaling is strictly monotonic when 20 errors are fixed
        $fewerData = $data;
        $fewerData['violations']['by_severity']['error'] -= 20;
        $fewerScores = $calc->calculateReportScores($fewerData, $marginalConfig);
        $this->assertLessThan($marginalScores['debt_hours'], $fewerScores['debt_hours']);
    }

    public function testRuleIdsBeatCategoriesAndUnmappedCategoriesAreTracked(): void
    {
        $loader = new ScoringConfigLoader();
        $config = $loader->load();
        $inputs = ScoreInputs::fromReportData([
            'violations' => [
                'violations' => [
                    ['severity' => 'warning', 'category' => 'logic', 'rule_id' => 'magento-upgrade-deprecated-method-call'],
                    ['severity' => 'error', 'category' => 'http', 'rule_id' => 'ac-http-005-curl-ssl-verify'],
                    ['severity' => 'info', 'category' => 'mystery_custom_cat', 'rule_id' => 'custom-1'],
                ],
            ],
        ], $config);

        $this->assertSame(1, $inputs->getRawDimensionCount('compliance'));
        $this->assertSame(1, $inputs->getRawDimensionCount('security'));
        $this->assertSame(0, $inputs->getRawDimensionCount('code_quality'));
        $this->assertContains('mystery_custom_cat', $inputs->getUnmappedCategories());
    }

    public function testAuditScoreQ2aParityAndBlockerCap(): void
    {
        $loader = new ScoringConfigLoader();
        $config = $loader->load();
        $calc = new ScoreCalculator();

        // Gate run: 17 files, 5 errors, 181 warnings, 40 info -> 67 C
        $issues = [];
        for ($i = 0; $i < 5; $i++) {
            $issues[] = ['severity' => 'error', 'category' => 'architecture'];
        }
        for ($i = 0; $i < 181; $i++) {
            $issues[] = ['severity' => 'warning', 'category' => 'guidance'];
        }
        for ($i = 0; $i < 40; $i++) {
            $issues[] = ['severity' => 'info', 'category' => 'guidance'];
        }

        $gateRes = $calc->calculateAuditScore($issues, 17, $config);
        $this->assertSame(67, $gateRes['score']);
        $this->assertSame('C', $gateRes['grade']);
        $this->assertSame('Fair', $gateRes['label']);

        // +1 blocker -> capped at 40 F
        $issuesWithBlocker = $issues;
        $issuesWithBlocker[] = ['severity' => 'blocker', 'category' => 'security'];
        $cappedRes = $calc->calculateAuditScore($issuesWithBlocker, 17, $config);
        $this->assertSame(40, $cappedRes['score']);
        $this->assertSame('F', $cappedRes['grade']);
        $this->assertNotEmpty($cappedRes['caps_applied']);

        // Empty scope (0 files, 0 issues) -> 100 A+
        $emptyRes = $calc->calculateAuditScore([], 0, $config);
        $this->assertSame(100, $emptyRes['score']);
        $this->assertSame('A+', $emptyRes['grade']);
    }

    public function testConditionOnConfiguredCategoryWithoutFindingsDoesNotFail(): void
    {
        $config = (new ScoringConfigLoader())->load(null, [
            'readiness' => [
                ['verdict' => 'RISKY', 'gate' => 'WARN', 'when_any' => ['xss' => '> 0', 'injection_pct' => '> 5']],
                ['verdict' => 'READY TO SHIP', 'gate' => 'PASS'],
            ],
            'dimensions' => [
                'security' => ['caps' => [['when' => ['reliability_score' => '< 10'], 'max_score' => 50]]],
            ],
        ]);
        $scores = (new ScoreCalculator())->calculateReportScores(
            ['violations' => ['violations' => [['severity' => 'error', 'category' => 'logic']]]],
            $config
        );

        $this->assertSame('READY TO SHIP', $scores['readiness']['verdict']);
    }

    public function testAuditMapsYamlHighMediumLowSeverities(): void
    {
        $config = (new ScoringConfigLoader())->load();
        $calc = new ScoreCalculator();

        // 'high' = error (not critical): no critical cap, -10 per error/file
        $high = $calc->calculateAuditScore([['severity' => 'high', 'category' => 'security']], 10, $config);
        $this->assertSame(99, $high['score']);
        $this->assertSame([], $high['caps_applied']);

        // 'medium' = warning: -5 per warning/file
        $medium = $calc->calculateAuditScore([['severity' => 'medium', 'category' => 'performance']], 10, $config);
        $this->assertSame(100, $medium['score']); // 100 - 0.5 = 99.5 -> rounds to 100

        $mediums = $calc->calculateAuditScore(array_fill(0, 10, ['severity' => 'medium', 'category' => 'performance']), 10, $config);
        $this->assertSame(95, $mediums['score']);

        // real 'critical' still capped at 60
        $critical = $calc->calculateAuditScore([['severity' => 'critical', 'category' => 'security']], 10, $config);
        $this->assertSame(60, $critical['score']);
    }

    public function testSummaryTwigRendersCustomVerdictAndCapsReason(): void
    {
        $loader = new ScoringConfigLoader();
        $config = $loader->load(null, [
            'readiness' => [
                ['verdict' => 'RISKY', 'gate' => 'WARN', 'when_any' => ['error_weighted' => '> 50']],
                ['verdict' => 'READY TO SHIP', 'gate' => 'PASS'],
            ],
            'overall' => [
                'caps' => [
                    ['when' => ['error_weighted' => '>= 100'], 'max_score' => 75],
                ],
            ],
        ]);

        $data = $this->buildShellAggregateData(true);
        $data['scores'] = (new ScoreCalculator())->calculateReportScores($data, $config);
        $data['metrics']['quality_score'] = $data['scores']['overall'];

        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 4) . '/templates/reports/html'));
        $html = $twig->render('summary.html.twig', [
            'report' => ['title' => 'Quality Report', 'project_name' => 'Shell', 'generated_at' => '2026-11-04', 'user_role' => 'architect'],
            'data' => $data,
            'options' => [],
        ]);

        $this->assertStringContainsString('RISKY', $html);
        $this->assertStringContainsString('Capped at 75', $html);
        $this->assertStringContainsString('WARN — Proceed with mandatory review gate', $html);
    }
}

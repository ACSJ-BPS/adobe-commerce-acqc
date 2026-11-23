<?php

declare(strict_types=1);

namespace ACQC\Tests\Unit\Core\Scoring;

use ACQC\Core\Scoring\ScoringConfigLoader;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ScoringConfigLoaderTest extends TestCase
{
    public function testLoadsBuiltInDefaults(): void
    {
        $loader = new ScoringConfigLoader();
        $config = $loader->load();

        $this->assertSame(['config/scoring.yaml'], $config->getSources());
        $this->assertSame('per_kloc', $config->getOverall()['normalize']);
        $this->assertSame('per_file', $config->getAuditOverall()['normalize']);
        $this->assertSame(['seo' => 0.25, 'accessibility' => 0.25], $config->getCategoryWeights());
        $this->assertSame(['healthy' => 80, 'attention' => 60], $config->getBands());
        $this->assertSame('show_na', $config->getPlaceholdersMode());
        $this->assertArrayHasKey('reliability', $config->getDimensions());
        $this->assertArrayHasKey('release_readiness', $config->getKpis());
    }

    public function testAssociativeMapsDeepMergeWhileListsReplace(): void
    {
        $loader = new ScoringConfigLoader();
        $config = $loader->load(null, [
            'bands' => ['healthy' => 90],
            'overall' => [
                'weights' => ['error' => 5.0],
                'caps' => [
                    ['when' => ['error_weighted' => '>= 100'], 'max_score' => 75],
                ],
            ],
            'readiness' => [
                ['verdict' => 'BLOCKED', 'gate' => 'FAIL', 'when_any' => ['error' => '> 10']],
                ['verdict' => 'READY TO SHIP', 'gate' => 'PASS'],
            ],
            'kpis' => [
                'criticality' => ['pass' => '<= 2', 'warn' => '<= 10'],
            ],
        ]);

        // Map deep-merged: attention preserved at 60 while healthy updated to 90
        $this->assertSame(['healthy' => 90, 'attention' => 60], $config->getBands());
        // Map deep-merged: blocker preserved at 15.0 while error updated to 5.0
        $this->assertSame(15.0, (float) $config->getOverall()['weights']['blocker']);
        $this->assertSame(5.0, (float) $config->getOverall()['weights']['error']);
        // List replaced: readiness now has 2 rules instead of 3
        $this->assertCount(2, $config->getReadinessRules());
        // KPI map deep-merged: criticality keeps its terms/base while pass/warn updated
        $critKpi = $config->getKpis()['criticality'];
        $this->assertSame('<= 2', $critKpi['pass']);
        $this->assertSame('<= 10', $critKpi['warn']);
        $this->assertNotEmpty($critKpi['terms']);
    }

    public function testRejectsInvalidConditionOperatorWithClearPath(): void
    {
        $loader = new ScoringConfigLoader();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('scoring.readiness[0].when_any.blocker');

        $loader->load(null, [
            'readiness' => [
                ['verdict' => 'BLOCKED', 'when_any' => ['blocker' => '=== 0']],
            ],
        ]);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidNumberProvider(): array
    {
        return [
            'negative category weight' => [['category_weights' => ['seo' => -1]], 'scoring.category_weights.seo'],
            'text category weight' => [['category_weights' => ['seo' => 'abc']], 'scoring.category_weights.seo'],
            'text overall weight' => [['overall' => ['weights' => ['error' => 'x']]], 'scoring.overall.weights.error'],
            'negative debt hours' => [['debt' => ['hours' => ['error' => -2]]], 'scoring.debt.hours.error'],
            'text debt tier' => [['debt' => ['tiers' => [['above' => 'many', 'factor' => 0.5]]]], 'scoring.debt.tiers[0].above'],
            'text ring mult' => [['dimensions' => ['security' => ['mult' => 'high']]], 'scoring.dimensions.security.mult'],
            'negative audit cap' => [['audit' => ['overall' => ['weights' => ['error' => ['mult' => 10, 'cap' => -5]]]]], 'scoring.audit.overall.weights.error.cap'],
        ];
    }

    /**
     * @dataProvider invalidNumberProvider
     * @param array<string, mixed> $override
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidNumberProvider')]
    public function testRejectsInvalidNumbersWithClearPath(array $override, string $expectedPath): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedPath);

        (new ScoringConfigLoader())->load(null, $override);
    }

    public function testAcceptsZeroAndFractionalWeights(): void
    {
        $config = (new ScoringConfigLoader())->load(null, ['category_weights' => ['seo' => 0, 'guidance' => 0.1]]);

        $this->assertSame(0.0, $config->getCategoryWeights()['seo']);
        $this->assertSame(0.1, $config->getCategoryWeights()['guidance']);
    }

    public function testRejectsUnknownConditionKeyWithClearPath(): void
    {
        $loader = new ScoringConfigLoader();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('scoring.overall.caps[0].when.non_existent_metric');

        $loader->load(null, [
            'overall' => [
                'caps' => [
                    ['when' => ['non_existent_metric' => '> 5'], 'max_score' => 50],
                ],
            ],
        ]);
    }
}

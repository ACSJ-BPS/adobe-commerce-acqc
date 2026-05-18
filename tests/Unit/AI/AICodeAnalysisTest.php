<?php

declare(strict_types=1);

namespace ACQC\Tests\Unit\AI;

use ACQC\Core\AI\AICodeAnalysis;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ACQC\Core\AI\AICodeAnalysis
 * @group unit
 * @group ai
 */
class AICodeAnalysisTest extends TestCase
{
    /**
     * @test
     * @group fast
     */
    public function testSuccessfulAnalysisResult(): void
    {
        $result = new AICodeAnalysis(
            true,
            'Code quality is good',
            ['Uses proper DI', 'Follows PSR-12'],
            8.5,
            'low',
            9.0,
            'Clean architecture',
            ['Type hints used'],
            [],
            ['Add docblocks']
        );

        $this->assertTrue($result->isSuccess());
        $this->assertEquals('Code quality is good', $result->getSummary());
        $this->assertEquals(['Uses proper DI', 'Follows PSR-12'], $result->getInsights());
        $this->assertEquals(8.5, $result->getQualityScore());
        $this->assertEquals('low', $result->getComplexityAssessment());
        $this->assertEquals(9.0, $result->getMaintainabilityScore());
        $this->assertEquals('Clean architecture', $result->getArchitectureFeedback());
        $this->assertEquals(['Type hints used'], $result->getBestPractices());
        $this->assertEmpty($result->getPotentialIssues());
        $this->assertEquals(['Add docblocks'], $result->getImprovementSuggestions());
    }

    /**
     * @test
     * @group fast
     */
    public function testFailedAnalysisResult(): void
    {
        $result = new AICodeAnalysis(false, 'AI service unavailable');

        $this->assertFalse($result->isSuccess());
        $this->assertEquals('AI service unavailable', $result->getSummary());
        $this->assertEmpty($result->getInsights());
        $this->assertEquals(0.0, $result->getQualityScore());
    }

    /**
     * @test
     * @group fast
     */
    public function testDefaultValues(): void
    {
        $result = new AICodeAnalysis(true, 'Minimal result');

        $this->assertEquals('Unknown', $result->getComplexityAssessment());
        $this->assertEquals(0.0, $result->getMaintainabilityScore());
        $this->assertEquals('', $result->getArchitectureFeedback());
        $this->assertIsArray($result->getBestPractices());
        $this->assertIsArray($result->getPotentialIssues());
        $this->assertIsArray($result->getImprovementSuggestions());
    }

    /**
     * @test
     * @group fast
     */
    public function testPotentialIssuesAreReturnedCorrectly(): void
    {
        $issues = ['ObjectManager direct usage', 'Missing type hint on line 42'];

        $result = new AICodeAnalysis(
            true,
            'Issues found',
            [],
            5.0,
            'medium',
            6.0,
            '',
            [],
            $issues,
            []
        );

        $this->assertEquals($issues, $result->getPotentialIssues());
    }

    /**
     * @test
     * @group fast
     */
    public function testHighQualityScoreRange(): void
    {
        $result = new AICodeAnalysis(true, 'Perfect code', [], 10.0);

        $this->assertEquals(10.0, $result->getQualityScore());
        $this->assertTrue($result->getQualityScore() >= 0.0);
        $this->assertTrue($result->getQualityScore() <= 10.0);
    }
}

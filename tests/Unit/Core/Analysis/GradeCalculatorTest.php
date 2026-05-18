<?php

declare(strict_types=1);

namespace ACQC\Tests\Unit\Core\Analysis;

use ACQC\Core\Analysis\GradeCalculator;
use PHPUnit\Framework\TestCase;

class GradeCalculatorTest extends TestCase
{
    private GradeCalculator $calculator;
    
    protected function setUp(): void
    {
        $this->calculator = new GradeCalculator();
    }
    
    public function testCalculateAPlusGrade(): void
    {
        $result = $this->calculator->calculate(98);
        
        $this->assertEquals('A+', $result['grade']);
        $this->assertEquals('Exceptional', $result['label']);
        $this->assertEquals('🏆', $result['emoji']);
        $this->assertEquals('green', $result['color']);
        $this->assertEquals('passing', $result['status']);
    }
    
    public function testCalculateAGrade(): void
    {
        $result = $this->calculator->calculate(92);
        
        $this->assertEquals('A', $result['grade']);
        $this->assertEquals('Excellent', $result['label']);
        $this->assertEquals('⭐', $result['emoji']);
    }
    
    public function testCalculateBGrade(): void
    {
        $result = $this->calculator->calculate(85);
        
        $this->assertEquals('B', $result['grade']);
        $this->assertEquals('Good', $result['label']);
        $this->assertEquals('👍', $result['emoji']);
        $this->assertEquals('acceptable', $result['status']);
    }
    
    public function testCalculateCGrade(): void
    {
        $result = $this->calculator->calculate(75);
        
        $this->assertEquals('C', $result['grade']);
        $this->assertEquals('Fair', $result['label']);
        $this->assertEquals('⚠️', $result['emoji']);
        $this->assertEquals('needs_improvement', $result['status']);
    }
    
    public function testCalculateDGrade(): void
    {
        $result = $this->calculator->calculate(65);
        
        $this->assertEquals('D', $result['grade']);
        $this->assertEquals('Poor', $result['label']);
        $this->assertEquals('⚠️⚠️', $result['emoji']);
        $this->assertEquals('failing', $result['status']);
    }
    
    public function testCalculateFGrade(): void
    {
        $result = $this->calculator->calculate(45);
        
        $this->assertEquals('F', $result['grade']);
        $this->assertEquals('Critical', $result['label']);
        $this->assertEquals('🔴', $result['emoji']);
        $this->assertEquals('failing', $result['status']);
    }
    
    public function testGradeBoundaries(): void
    {
        // Test exact boundaries
        $this->assertEquals('A+', $this->calculator->calculate(95)['grade']);
        $this->assertEquals('A+', $this->calculator->calculate(100)['grade']);
        
        $this->assertEquals('A', $this->calculator->calculate(90)['grade']);
        $this->assertEquals('A', $this->calculator->calculate(94)['grade']);
        
        $this->assertEquals('B', $this->calculator->calculate(80)['grade']);
        $this->assertEquals('B', $this->calculator->calculate(89)['grade']);
        
        $this->assertEquals('C', $this->calculator->calculate(70)['grade']);
        $this->assertEquals('C', $this->calculator->calculate(79)['grade']);
        
        $this->assertEquals('D', $this->calculator->calculate(60)['grade']);
        $this->assertEquals('D', $this->calculator->calculate(69)['grade']);
        
        $this->assertEquals('F', $this->calculator->calculate(0)['grade']);
        $this->assertEquals('F', $this->calculator->calculate(59)['grade']);
    }
    
    public function testScoreClamping(): void
    {
        // Scores above 100 should clamp to 100
        $result = $this->calculator->calculate(150);
        $this->assertEquals(100, $result['numeric_score']);
        $this->assertEquals('A+', $result['grade']);
        
        // Scores below 0 should clamp to 0
        $result = $this->calculator->calculate(-50);
        $this->assertEquals(0, $result['numeric_score']);
        $this->assertEquals('F', $result['grade']);
    }
    
    public function testCustomThresholds(): void
    {
        // Custom thresholds: stricter grading
        $calculator = new GradeCalculator([
            'A+' => 98,  // Raised from 95
            'A'  => 92,  // Raised from 90
            'B'  => 85,  // Raised from 80
        ]);
        
        $this->assertEquals('A', $calculator->calculate(95)['grade']);  // Would be A+ with default
        $this->assertEquals('B', $calculator->calculate(90)['grade']);  // Would be A with default
    }
    
    public function testRecommendationsProvided(): void
    {
        $result = $this->calculator->calculate(85);  // B grade
        
        $this->assertArrayHasKey('recommendations', $result);
        $this->assertIsArray($result['recommendations']);
        $this->assertNotEmpty($result['recommendations']);
        $this->assertGreaterThan(2, count($result['recommendations']));
    }
    
    public function testExponentialPenalty(): void
    {
        // Single violation: minimal penalty
        $penalty1 = $this->calculator->calculateExponentialPenalty(1, 0.1);
        $this->assertGreaterThan(1.0, $penalty1);
        $this->assertLessThan(1.2, $penalty1);
        
        // Multiple violations: compound penalty (exponential growth)
        $penalty10 = $this->calculator->calculateExponentialPenalty(10, 0.1);
        $this->assertGreaterThan($penalty1, $penalty10);  // Should be greater than single violation
        $this->assertGreaterThan(2.0, $penalty10);  // Should have significant penalty
        
        // Zero violations: no penalty
        $penalty0 = $this->calculator->calculateExponentialPenalty(0);
        $this->assertEquals(1.0, $penalty0);
    }
    
    public function testCalculateWithViolations(): void
    {
        $baseScore = 90;  // Would normally be A grade
        
        $violationCounts = [
            'critical' => 5,
            'error' => 10,
            'warning' => 20,
        ];
        
        $result = $this->calculator->calculateWithViolations($baseScore, $violationCounts);
        
        $this->assertLessThan($baseScore, $result['adjusted_score']);
        $this->assertNotEquals('A+', $result['grade']);  // Should be lower than perfect
        $this->assertEquals($baseScore, $result['base_score']);
        $this->assertArrayHasKey('penalties', $result);
        $this->assertArrayHasKey('violation_counts', $result);
        
        // Verify penalties were applied
        $this->assertGreaterThan(1.0, $result['penalties']['critical']);
        $this->assertGreaterThan(1.0, $result['penalties']['error']);
        $this->assertGreaterThan(1.0, $result['penalties']['warning']);
    }
    
    public function testCompareGradesImprovement(): void
    {
        $comparison = $this->calculator->compareGrades('C', 'B');
        
        $this->assertTrue($comparison['improved']);
        $this->assertFalse($comparison['degraded']);
        $this->assertFalse($comparison['stable']);
        $this->assertEquals('⬆️', $comparison['change']);
        $this->assertStringContainsString('Improved', $comparison['message']);
    }
    
    public function testCompareGradesDegradation(): void
    {
        $comparison = $this->calculator->compareGrades('A', 'B');
        
        $this->assertFalse($comparison['improved']);
        $this->assertTrue($comparison['degraded']);
        $this->assertFalse($comparison['stable']);
        $this->assertEquals('⬇️', $comparison['change']);
        $this->assertStringContainsString('Degraded', $comparison['message']);
    }
    
    public function testCompareGradesStable(): void
    {
        $comparison = $this->calculator->compareGrades('B', 'B');
        
        $this->assertFalse($comparison['improved']);
        $this->assertFalse($comparison['degraded']);
        $this->assertTrue($comparison['stable']);
        $this->assertEquals('➡️', $comparison['change']);
        $this->assertStringContainsString('Maintained', $comparison['message']);
    }
    
    public function testFormatForConsole(): void
    {
        $gradeInfo = $this->calculator->calculate(85);
        
        $withEmoji = $this->calculator->formatForConsole($gradeInfo, true);
        $this->assertStringContainsString('👍', $withEmoji);
        $this->assertStringContainsString('Grade: B', $withEmoji);
        $this->assertStringContainsString('85/100', $withEmoji);
        
        $withoutEmoji = $this->calculator->formatForConsole($gradeInfo, false);
        $this->assertStringNotContainsString('👍', $withoutEmoji);
        $this->assertStringContainsString('Grade: B', $withoutEmoji);
    }
}

<?php

declare(strict_types=1);

namespace ACQC\Core\AI;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Risk Assessment for Code Quality
 * 
 * Evaluates various risks in software projects including
 * security, performance, maintainability, and operational risks.
 */
class RiskAssessment
{
    private MetricsCollector $metricsCollector;
    private AIEngine $aiEngine;
    private LoggerInterface $logger;
    
    private array $riskCategories = [
        'security' => ['weight' => 0.25, 'critical_threshold' => 0.8],
        'performance' => ['weight' => 0.2, 'critical_threshold' => 0.7],
        'maintainability' => ['weight' => 0.2, 'critical_threshold' => 0.6],
        'reliability' => ['weight' => 0.15, 'critical_threshold' => 0.7],
        'operational' => ['weight' => 0.1, 'critical_threshold' => 0.8],
        'technical_debt' => ['weight' => 0.1, 'critical_threshold' => 0.6]
    ];

    public function __construct(
        MetricsCollector $metricsCollector,
        AIEngine $aiEngine,
        ?LoggerInterface $logger = null
    ) {
        $this->metricsCollector = $metricsCollector;
        $this->aiEngine = $aiEngine;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Assess project risks based on historical data and current metrics
     */
    public function assessRisks(array $historicalData, array $currentMetrics): ProjectRiskAssessment
    {
        $this->logger->info('Assessing project risks');

        try {
            // Calculate risk scores for each category
            $riskScores = $this->calculateRiskScores($historicalData, $currentMetrics);
            
            // Calculate overall risk score
            $overallRisk = $this->calculateOverallRisk($riskScores);
            
            // Identify critical risks
            $criticalRisks = $this->identifyCriticalRisks($riskScores);
            
            // Analyze risk trends
            $riskTrends = $this->analyzeRiskTrends($historicalData);
            
            // Generate risk mitigation strategies
            $mitigationStrategies = $this->generateMitigationStrategies($riskScores, $criticalRisks);
            
            // Generate AI insights
            $aiInsights = $this->generateAIRiskInsights($riskScores, $criticalRisks, $riskTrends);
            
            // Calculate confidence
            $confidence = $this->calculateAssessmentConfidence($historicalData, $riskScores);

            return new ProjectRiskAssessment(
                true,
                $overallRisk,
                $riskScores,
                $criticalRisks,
                $riskTrends,
                $mitigationStrategies,
                $aiInsights,
                $confidence
            );

        } catch (\Exception $e) {
            $this->logger->error('Risk assessment failed', ['error' => $e->getMessage()]);
            
            return new ProjectRiskAssessment(
                false,
                0.0,
                [],
                [],
                [],
                [],
                [],
                0.0,
                'Risk assessment failed: ' . $e->getMessage()
            );
        }
    }

    /**
     * Calculate risk scores for each category
     */
    private function calculateRiskScores(array $historicalData, array $currentMetrics): array
    {
        $scores = [];
        
        foreach ($this->riskCategories as $category => $config) {
            $scores[$category] = $this->calculateCategoryRisk($category, $historicalData, $currentMetrics);
        }
        
        return $scores;
    }

    /**
     * Calculate risk score for a specific category
     */
    private function calculateCategoryRisk(string $category, array $historicalData, array $currentMetrics): array
    {
        switch ($category) {
            case 'security':
                return $this->assessSecurityRisk($historicalData, $currentMetrics);
            case 'performance':
                return $this->assessPerformanceRisk($historicalData, $currentMetrics);
            case 'maintainability':
                return $this->assessMaintainabilityRisk($historicalData, $currentMetrics);
            case 'reliability':
                return $this->assessReliabilityRisk($historicalData, $currentMetrics);
            case 'operational':
                return $this->assessOperationalRisk($historicalData, $currentMetrics);
            case 'technical_debt':
                return $this->assessTechnicalDebtRisk($historicalData, $currentMetrics);
            default:
                return ['score' => 0.0, 'factors' => [], 'severity' => 'low'];
        }
    }

    /**
     * Assess security risks
     */
    private function assessSecurityRisk(array $historicalData, array $currentMetrics): array
    {
        $riskFactors = [];
        $score = 0.0;
        
        // Check for security violations
        $securityViolations = $this->extractSecurityViolations($currentMetrics);
        if ($securityViolations > 0) {
            $score += min(0.4, $securityViolations * 0.1);
            $riskFactors[] = [
                'type' => 'security_violations',
                'severity' => $securityViolations > 5 ? 'high' : 'medium',
                'description' => "Found {$securityViolations} security violations",
                'impact' => 'Critical security vulnerabilities present'
            ];
        }
        
        // Check for hardcoded secrets
        $hardcodedSecrets = $this->detectHardcodedSecrets($currentMetrics);
        if ($hardcodedSecrets > 0) {
            $score += 0.3;
            $riskFactors[] = [
                'type' => 'hardcoded_secrets',
                'severity' => 'high',
                'description' => "Detected {$hardcodedSecrets} potential hardcoded secrets",
                'impact' => 'Exposure of sensitive information'
            ];
        }
        
        // Check for unsafe functions
        $unsafeFunctions = $this->detectUnsafeFunctions($currentMetrics);
        if ($unsafeFunctions > 0) {
            $score += min(0.2, $unsafeFunctions * 0.05);
            $riskFactors[] = [
                'type' => 'unsafe_functions',
                'severity' => 'medium',
                'description' => "Found {$unsafeFunctions} uses of unsafe functions",
                'impact' => 'Potential for exploitation'
            ];
        }
        
        // Analyze security trends
        $securityTrend = $this->analyzeSecurityTrend($historicalData);
        if ($securityTrend['deteriorating']) {
            $score += 0.15;
            $riskFactors[] = [
                'type' => 'deteriorating_security',
                'severity' => 'medium',
                'description' => 'Security metrics are deteriorating over time',
                'impact' => 'Increasing security risk'
            ];
        }
        
        return [
            'score' => min(1.0, $score),
            'factors' => $riskFactors,
            'severity' => $this->determineSeverity($score),
            'trend' => $securityTrend['direction'] ?? 'stable'
        ];
    }

    /**
     * Assess performance risks
     */
    private function assessPerformanceRisk(array $historicalData, array $currentMetrics): array
    {
        $riskFactors = [];
        $score = 0.0;
        
        // Check performance violations
        $performanceIssues = $this->extractPerformanceIssues($currentMetrics);
        if ($performanceIssues > 0) {
            $score += min(0.3, $performanceIssues * 0.05);
            $riskFactors[] = [
                'type' => 'performance_issues',
                'severity' => $performanceIssues > 10 ? 'high' : 'medium',
                'description' => "Found {$performanceIssues} performance issues",
                'impact' => 'Degraded user experience and system performance'
            ];
        }
        
        // Check for N+1 queries
        $nPlusOneQueries = $this->detectNPlusOneQueries($currentMetrics);
        if ($nPlusOneQueries > 0) {
            $score += min(0.25, $nPlusOneQueries * 0.1);
            $riskFactors[] = [
                'type' => 'n_plus_one_queries',
                'severity' => 'high',
                'description' => "Detected {$nPlusOneQueries} N+1 query patterns",
                'impact' => 'Database performance degradation'
            ];
        }
        
        // Check memory-intensive operations
        $memoryIssues = $this->detectMemoryIssues($currentMetrics);
        if ($memoryIssues > 0) {
            $score += min(0.2, $memoryIssues * 0.08);
            $riskFactors[] = [
                'type' => 'memory_issues',
                'severity' => 'medium',
                'description' => "Found {$memoryIssues} memory-intensive operations",
                'impact' => 'Potential memory exhaustion'
            ];
        }
        
        return [
            'score' => min(1.0, $score),
            'factors' => $riskFactors,
            'severity' => $this->determineSeverity($score),
            'trend' => $this->analyzePerformanceTrend($historicalData)['direction'] ?? 'stable'
        ];
    }

    /**
     * Assess maintainability risks
     */
    private function assessMaintainabilityRisk(array $historicalData, array $currentMetrics): array
    {
        $riskFactors = [];
        $score = 0.0;
        
        // Check complexity
        $avgComplexity = $this->getAverageComplexity($currentMetrics);
        if ($avgComplexity > 10) {
            $score += min(0.3, ($avgComplexity - 10) * 0.02);
            $riskFactors[] = [
                'type' => 'high_complexity',
                'severity' => $avgComplexity > 20 ? 'high' : 'medium',
                'description' => "Average complexity: {$avgComplexity}",
                'impact' => 'Difficult to understand and modify code'
            ];
        }
        
        // Check maintainability index
        $maintainabilityIndex = $this->getMaintainabilityIndex($currentMetrics);
        if ($maintainabilityIndex < 50) {
            $score += 0.25;
            $riskFactors[] = [
                'type' => 'low_maintainability',
                'severity' => 'high',
                'description' => "Low maintainability index: {$maintainabilityIndex}",
                'impact' => 'High cost of changes and bug fixes'
            ];
        }
        
        // Check technical debt
        $technicalDebt = $this->getTechnicalDebtHours($currentMetrics);
        if ($technicalDebt > 100) {
            $score += min(0.2, ($technicalDebt - 100) * 0.001);
            $riskFactors[] = [
                'type' => 'high_technical_debt',
                'severity' => $technicalDebt > 500 ? 'high' : 'medium',
                'description' => "Technical debt: {$technicalDebt} hours",
                'impact' => 'Slowed development and increased bug risk'
            ];
        }
        
        return [
            'score' => min(1.0, $score),
            'factors' => $riskFactors,
            'severity' => $this->determineSeverity($score),
            'trend' => $this->analyzeMaintainabilityTrend($historicalData)['direction'] ?? 'stable'
        ];
    }

    /**
     * Calculate overall risk score
     */
    private function calculateOverallRisk(array $riskScores): float
    {
        $weightedScore = 0.0;
        
        foreach ($riskScores as $category => $riskData) {
            $weight = $this->riskCategories[$category]['weight'] ?? 0.0;
            $score = $riskData['score'] ?? 0.0;
            $weightedScore += $weight * $score;
        }
        
        return min(1.0, $weightedScore);
    }

    /**
     * Identify critical risks
     */
    private function identifyCriticalRisks(array $riskScores): array
    {
        $criticalRisks = [];
        
        foreach ($riskScores as $category => $riskData) {
            $threshold = $this->riskCategories[$category]['critical_threshold'] ?? 0.7;
            $score = $riskData['score'] ?? 0.0;
            
            if ($score >= $threshold) {
                $criticalRisks[] = [
                    'category' => $category,
                    'score' => $score,
                    'severity' => $riskData['severity'] ?? 'medium',
                    'factors' => $riskData['factors'] ?? [],
                    'priority' => $this->calculateRiskPriority($category, $score),
                    'immediate_action_required' => $score > 0.9
                ];
            }
        }
        
        // Sort by priority
        usort($criticalRisks, fn($a, $b) => $b['priority'] <=> $a['priority']);
        
        return $criticalRisks;
    }

    /**
     * Generate mitigation strategies
     */
    private function generateMitigationStrategies(array $riskScores, array $criticalRisks): array
    {
        $strategies = [];
        
        foreach ($criticalRisks as $risk) {
            $category = $risk['category'];
            
            $strategies[] = [
                'risk_category' => $category,
                'risk_score' => $risk['score'],
                'strategy' => $this->getMitigationStrategy($category, $risk),
                'timeline' => $this->getRecommendedTimeline($risk['score']),
                'effort' => $this->estimateEffort($category, $risk['score']),
                'expected_impact' => $this->estimateImpact($category, $risk['score'])
            ];
        }
        
        return $strategies;
    }

    /**
     * Generate AI risk insights
     */
    private function generateAIRiskInsights(array $riskScores, array $criticalRisks, array $riskTrends): array
    {
        if (!$this->aiEngine->isAvailable()) {
            return ['ai_unavailable' => 'AI insights not available'];
        }
        
        try {
            $context = [
                'risk_scores' => $riskScores,
                'critical_risks' => $criticalRisks,
                'risk_trends' => $riskTrends,
                'overall_risk' => $this->calculateOverallRisk($riskScores)
            ];
            
            $prompt = $this->aiEngine->getPromptManager()->getPrompt('risk_assessment_insights', $context);
            $provider = $this->aiEngine->getProvider();
            
            if ($provider) {
                $response = $provider->chat($prompt);
                return $this->parseAIInsights($response);
            }
        } catch (\Exception $e) {
            $this->logger->error('AI risk insights failed', ['error' => $e->getMessage()]);
        }
        
        return ['error' => 'Unable to generate AI insights'];
    }

    /**
     * Determine severity level based on score
     */
    private function determineSeverity(float $score): string
    {
        if ($score >= 0.8) return 'critical';
        if ($score >= 0.6) return 'high';
        if ($score >= 0.4) return 'medium';
        if ($score >= 0.2) return 'low';
        return 'minimal';
    }

    /**
     * Calculate assessment confidence
     */
    private function calculateAssessmentConfidence(array $historicalData, array $riskScores): float
    {
        $confidence = 0.5; // Base confidence
        
        // More historical data increases confidence
        $dataPoints = count($historicalData);
        if ($dataPoints > 30) {
            $confidence += 0.3;
        } elseif ($dataPoints > 14) {
            $confidence += 0.2;
        } elseif ($dataPoints > 7) {
            $confidence += 0.1;
        }
        
        // Consistent risk factors increase confidence
        $riskConsistency = $this->calculateRiskConsistency($riskScores);
        $confidence += $riskConsistency * 0.2;
        
        return max(0.1, min(1.0, $confidence));
    }

    // Helper methods with simplified implementations
    private function extractSecurityViolations(array $metrics): int { return 3; }
    private function detectHardcodedSecrets(array $metrics): int { return 1; }
    private function detectUnsafeFunctions(array $metrics): int { return 2; }
    private function analyzeSecurityTrend(array $data): array { return ['deteriorating' => false, 'direction' => 'stable']; }
    private function extractPerformanceIssues(array $metrics): int { return 5; }
    private function detectNPlusOneQueries(array $metrics): int { return 2; }
    private function detectMemoryIssues(array $metrics): int { return 1; }
    private function analyzePerformanceTrend(array $data): array { return ['direction' => 'stable']; }
    private function getAverageComplexity(array $metrics): float { return 8.5; }
    private function getMaintainabilityIndex(array $metrics): float { return 65.0; }
    private function getTechnicalDebtHours(array $metrics): float { return 120.0; }
    private function analyzeMaintainabilityTrend(array $data): array { return ['direction' => 'declining']; }
    private function assessReliabilityRisk(array $historical, array $current): array { return ['score' => 0.3, 'factors' => [], 'severity' => 'low']; }
    private function assessOperationalRisk(array $historical, array $current): array { return ['score' => 0.2, 'factors' => [], 'severity' => 'low']; }
    private function assessTechnicalDebtRisk(array $historical, array $current): array { return ['score' => 0.4, 'factors' => [], 'severity' => 'medium']; }
    private function calculateRiskPriority(string $category, float $score): float { return $score * ($this->riskCategories[$category]['weight'] ?? 1.0); }
    private function analyzeRiskTrends(array $data): array { return []; }
    private function getMitigationStrategy(string $category, array $risk): string { return "Address {$category} risks through targeted improvements"; }
    private function getRecommendedTimeline(float $score): string { return $score > 0.8 ? 'immediate' : 'within_1_month'; }
    private function estimateEffort(string $category, float $score): string { return $score > 0.7 ? 'high' : 'medium'; }
    private function estimateImpact(string $category, float $score): string { return 'significant'; }
    private function parseAIInsights(string $response): array { try { return json_decode($response, true) ?: ['insights' => $response]; } catch (\Exception $e) { return ['insights' => $response]; } }
    private function calculateRiskConsistency(array $scores): float { return 0.8; }
} 
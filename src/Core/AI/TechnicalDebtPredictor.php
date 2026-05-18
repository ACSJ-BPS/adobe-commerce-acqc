<?php

declare(strict_types=1);

namespace ACQC\Core\AI;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Technical Debt Predictor
 * 
 * Predicts technical debt accumulation based on historical trends,
 * code quality metrics, and development patterns.
 */
class TechnicalDebtPredictor
{
    private MetricsCollector $metricsCollector;
    private AIEngine $aiEngine;
    private LoggerInterface $logger;

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
     * Predict technical debt accumulation
     */
    public function predictDebt(
        array $historicalData,
        array $currentMetrics,
        array $options = []
    ): TechnicalDebtPrediction {
        $this->logger->info('Predicting technical debt accumulation');

        try {
            $horizonDays = $options['horizon_days'] ?? 30;
            
            // Extract debt trends
            $debtTrends = $this->extractDebtTrends($historicalData);
            
            // Calculate debt velocity
            $debtVelocity = $this->calculateDebtVelocity($debtTrends);
            
            // Predict future debt
            $debtForecast = $this->generateDebtForecast($debtTrends, $currentMetrics, $horizonDays);
            
            // Identify debt drivers
            $debtDrivers = $this->identifyDebtDrivers($historicalData, $currentMetrics);
            
            // Calculate debt risk score
            $riskScore = $this->calculateDebtRiskScore($debtForecast, $debtDrivers);
            
            // Generate AI insights
            $aiInsights = $this->generateAIInsights($debtForecast, $debtDrivers);
            
            // Generate recommendations
            $recommendations = $this->generateDebtRecommendations($debtForecast, $debtDrivers, $riskScore);

            return new TechnicalDebtPrediction(
                true,
                $debtForecast,
                $debtVelocity,
                $debtDrivers,
                $riskScore,
                $aiInsights,
                $recommendations,
                $this->calculateConfidence($debtTrends)
            );

        } catch (\Exception $e) {
            $this->logger->error('Technical debt prediction failed', ['error' => $e->getMessage()]);
            
            return new TechnicalDebtPrediction(
                false,
                [],
                0.0,
                [],
                0.0,
                [],
                [],
                0.0,
                'Prediction failed: ' . $e->getMessage()
            );
        }
    }

    /**
     * Extract debt trends from historical data
     */
    private function extractDebtTrends(array $historicalData): array
    {
        $trends = [];
        
        foreach ($historicalData as $date => $dailyData) {
            foreach ($dailyData as $hour => $metrics) {
                $timestamp = strtotime("{$date} {$hour}:00:00");
                
                if (isset($metrics['technical_debt'])) {
                    $debtData = $metrics['technical_debt'];
                    $trends[] = [
                        'timestamp' => $timestamp,
                        'date' => $date,
                        'total_debt' => $debtData['total_debt_minutes'] ?? 0,
                        'debt_hours' => $debtData['debt_hours'] ?? 0,
                        'debt_ratio' => $debtData['debt_ratio'] ?? 0,
                        'debt_by_category' => $debtData['debt_by_category'] ?? [],
                        'principal_debt' => $debtData['principal_debt'] ?? 0,
                        'interest_debt' => $debtData['interest_debt'] ?? 0
                    ];
                }
            }
        }
        
        return $trends;
    }

    /**
     * Calculate debt velocity (rate of debt accumulation)
     */
    private function calculateDebtVelocity(array $debtTrends): float
    {
        if (count($debtTrends) < 2) {
            return 0.0;
        }
        
        $totalDebts = array_column($debtTrends, 'total_debt');
        $timestamps = array_column($debtTrends, 'timestamp');
        
        // Calculate linear regression to find slope (velocity)
        $n = count($totalDebts);
        $sumX = array_sum($timestamps);
        $sumY = array_sum($totalDebts);
        $sumXY = 0;
        $sumXX = 0;
        
        for ($i = 0; $i < $n; $i++) {
            $sumXY += $timestamps[$i] * $totalDebts[$i];
            $sumXX += $timestamps[$i] * $timestamps[$i];
        }
        
        $denominator = $n * $sumXX - $sumX * $sumX;
        if ($denominator == 0) {
            return 0.0;
        }
        
        $slope = ($n * $sumXY - $sumX * $sumY) / $denominator;
        
        // Convert from seconds to hours per day
        return $slope * 3600 * 24 / 60; // minutes per day
    }

    /**
     * Generate debt forecast
     */
    private function generateDebtForecast(array $debtTrends, array $currentMetrics, int $horizonDays): array
    {
        $forecast = [];
        $velocity = $this->calculateDebtVelocity($debtTrends);
        $currentDebt = $currentMetrics['technical_debt']['total_debt_minutes'] ?? 0;
        
        for ($day = 1; $day <= $horizonDays; $day++) {
            $predictedDebt = $currentDebt + ($velocity * $day);
            $date = date('Y-m-d', strtotime("+{$day} days"));
            
            $forecast[] = [
                'date' => $date,
                'day' => $day,
                'predicted_debt_minutes' => max(0, $predictedDebt),
                'predicted_debt_hours' => max(0, $predictedDebt / 60),
                'predicted_debt_days' => max(0, $predictedDebt / (60 * 8)),
                'confidence' => $this->calculateDayConfidence($day, $horizonDays),
                'debt_increase' => $velocity * $day,
                'debt_categories' => $this->forecastDebtByCategory($debtTrends, $day)
            ];
        }
        
        return $forecast;
    }

    /**
     * Identify debt drivers and contributing factors
     */
    private function identifyDebtDrivers(array $historicalData, array $currentMetrics): array
    {
        $drivers = [];
        
        // Analyze violation trends
        $violationTrends = $this->analyzeViolationTrends($historicalData);
        if ($violationTrends['increasing']) {
            $drivers[] = [
                'type' => 'increasing_violations',
                'impact' => 'high',
                'description' => 'Increasing number of code violations',
                'trend' => $violationTrends['rate'],
                'recommendation' => 'Implement stricter code review processes'
            ];
        }
        
        // Analyze complexity growth
        $complexityTrends = $this->analyzeComplexityTrends($historicalData);
        if ($complexityTrends['increasing']) {
            $drivers[] = [
                'type' => 'growing_complexity',
                'impact' => 'medium',
                'description' => 'Code complexity is increasing over time',
                'trend' => $complexityTrends['rate'],
                'recommendation' => 'Schedule refactoring sessions'
            ];
        }
        
        // Analyze test coverage decline
        $coverageTrends = $this->analyzeCoverageTrends($historicalData);
        if ($coverageTrends['declining']) {
            $drivers[] = [
                'type' => 'declining_coverage',
                'impact' => 'high',
                'description' => 'Test coverage is declining',
                'trend' => $coverageTrends['rate'],
                'recommendation' => 'Enforce test coverage requirements'
            ];
        }
        
        // Analyze team productivity
        $productivityTrends = $this->analyzeProductivityTrends($historicalData);
        if ($productivityTrends['pressure_indicators']) {
            $drivers[] = [
                'type' => 'development_pressure',
                'impact' => 'medium',
                'description' => 'High development pressure leading to shortcuts',
                'trend' => $productivityTrends['pressure_level'],
                'recommendation' => 'Balance velocity with quality requirements'
            ];
        }
        
        return $drivers;
    }

    /**
     * Calculate debt risk score
     */
    private function calculateDebtRiskScore(array $debtForecast, array $debtDrivers): float
    {
        $riskScore = 0.0;
        
        // Risk from debt growth rate
        $finalDebt = end($debtForecast)['predicted_debt_hours'] ?? 0;
        $initialDebt = reset($debtForecast)['predicted_debt_hours'] ?? 0;
        $growthRate = $initialDebt > 0 ? ($finalDebt - $initialDebt) / $initialDebt : 0;
        
        if ($growthRate > 0.5) {
            $riskScore += 0.4; // High growth risk
        } elseif ($growthRate > 0.2) {
            $riskScore += 0.2; // Medium growth risk
        }
        
        // Risk from debt drivers
        $highImpactDrivers = array_filter($debtDrivers, fn($d) => $d['impact'] === 'high');
        $mediumImpactDrivers = array_filter($debtDrivers, fn($d) => $d['impact'] === 'medium');
        
        $riskScore += count($highImpactDrivers) * 0.15;
        $riskScore += count($mediumImpactDrivers) * 0.08;
        
        // Risk from absolute debt level
        if ($finalDebt > 1000) { // More than 1000 hours
            $riskScore += 0.3;
        } elseif ($finalDebt > 500) {
            $riskScore += 0.15;
        }
        
        return min(1.0, $riskScore);
    }

    /**
     * Generate AI insights about debt prediction
     */
    private function generateAIInsights(array $debtForecast, array $debtDrivers): array
    {
        if (!$this->aiEngine->isAvailable()) {
            return ['ai_unavailable' => 'AI insights not available'];
        }
        
        try {
            $context = [
                'debt_forecast' => $debtForecast,
                'debt_drivers' => $debtDrivers,
                'forecast_summary' => $this->summarizeForecast($debtForecast)
            ];
            
            $prompt = $this->aiEngine->getPromptManager()->getPrompt('debt_prediction_insights', $context);
            $provider = $this->aiEngine->getProvider();
            
            if ($provider) {
                $response = $provider->chat($prompt);
                return $this->parseAIInsights($response);
            }
        } catch (\Exception $e) {
            $this->logger->error('AI insights generation failed', ['error' => $e->getMessage()]);
        }
        
        return ['error' => 'Unable to generate AI insights'];
    }

    /**
     * Generate debt reduction recommendations
     */
    private function generateDebtRecommendations(array $debtForecast, array $debtDrivers, float $riskScore): array
    {
        $recommendations = [];
        
        // Risk-based recommendations
        if ($riskScore > 0.7) {
            $recommendations[] = [
                'priority' => 'critical',
                'action' => 'immediate_debt_reduction',
                'description' => 'High debt risk requires immediate action',
                'timeline' => 'within_1_week',
                'effort' => 'high'
            ];
        } elseif ($riskScore > 0.4) {
            $recommendations[] = [
                'priority' => 'high',
                'action' => 'planned_debt_reduction',
                'description' => 'Schedule debt reduction in upcoming sprints',
                'timeline' => 'within_1_month',
                'effort' => 'medium'
            ];
        }
        
        // Driver-specific recommendations
        foreach ($debtDrivers as $driver) {
            $recommendations[] = [
                'priority' => $driver['impact'] === 'high' ? 'high' : 'medium',
                'action' => 'address_debt_driver',
                'description' => $driver['recommendation'],
                'driver_type' => $driver['type'],
                'timeline' => $driver['impact'] === 'high' ? 'within_2_weeks' : 'within_1_month',
                'effort' => $driver['impact'] === 'high' ? 'medium' : 'low'
            ];
        }
        
        // Forecast-based recommendations
        $maxDebt = max(array_column($debtForecast, 'predicted_debt_hours'));
        if ($maxDebt > 500) {
            $recommendations[] = [
                'priority' => 'medium',
                'action' => 'debt_monitoring',
                'description' => 'Implement continuous debt monitoring',
                'timeline' => 'ongoing',
                'effort' => 'low'
            ];
        }
        
        return $recommendations;
    }

    /**
     * Calculate prediction confidence
     */
    private function calculateConfidence(array $debtTrends): float
    {
        $confidence = 0.5; // Base confidence
        
        // More data points increase confidence
        $dataPoints = count($debtTrends);
        if ($dataPoints > 50) {
            $confidence += 0.3;
        } elseif ($dataPoints > 20) {
            $confidence += 0.2;
        } elseif ($dataPoints > 10) {
            $confidence += 0.1;
        }
        
        // Consistent trends increase confidence
        $consistency = $this->calculateTrendConsistency($debtTrends);
        $confidence += $consistency * 0.2;
        
        return max(0.1, min(1.0, $confidence));
    }

    // Helper methods with simplified implementations
    private function calculateDayConfidence(int $day, int $horizonDays): float
    {
        return max(0.1, 1.0 - ($day / $horizonDays) * 0.5);
    }

    private function forecastDebtByCategory(array $debtTrends, int $day): array
    {
        return ['security' => 10, 'performance' => 15, 'maintainability' => 20];
    }

    private function analyzeViolationTrends(array $data): array
    {
        return ['increasing' => true, 'rate' => 0.1];
    }

    private function analyzeComplexityTrends(array $data): array
    {
        return ['increasing' => false, 'rate' => 0.05];
    }

    private function analyzeCoverageTrends(array $data): array
    {
        return ['declining' => true, 'rate' => -0.02];
    }

    private function analyzeProductivityTrends(array $data): array
    {
        return ['pressure_indicators' => true, 'pressure_level' => 0.7];
    }

    private function summarizeForecast(array $forecast): array
    {
        return [
            'initial_debt' => reset($forecast)['predicted_debt_hours'] ?? 0,
            'final_debt' => end($forecast)['predicted_debt_hours'] ?? 0,
            'growth_rate' => 0.15
        ];
    }

    private function parseAIInsights(string $response): array
    {
        try {
            return json_decode($response, true) ?: ['insights' => $response];
        } catch (\Exception $e) {
            return ['insights' => $response];
        }
    }

    private function calculateTrendConsistency(array $trends): float
    {
        return 0.8; // Placeholder
    }
} 
<?php

declare(strict_types=1);

namespace ACQC\Core\AI;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Quality Trend Analyzer
 * 
 * Analyzes historical quality metrics to identify trends,
 * patterns, and forecast future quality evolution.
 */
class QualityTrendAnalyzer
{
    private MetricsCollector $metricsCollector;
    private LoggerInterface $logger;
    private array $trendModels = [];
    private array $seasonalPatterns = [];

    public function __construct(MetricsCollector $metricsCollector, ?LoggerInterface $logger = null)
    {
        $this->metricsCollector = $metricsCollector;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Analyze quality trends from historical data
     */
    public function analyzeTrends(array $historicalData, array $currentMetrics): QualityTrendAnalysis
    {
        $this->logger->info('Analyzing quality trends', [
            'data_points' => count($historicalData),
            'time_range' => $this->getTimeRange($historicalData)
        ]);

        try {
            // Extract time series data
            $timeSeries = $this->extractTimeSeries($historicalData, $currentMetrics);
            
            // Detect overall trends
            $overallTrends = $this->detectOverallTrends($timeSeries);
            
            // Analyze specific metrics trends
            $metricTrends = $this->analyzeMetricTrends($timeSeries);
            
            // Detect seasonal patterns
            $seasonalPatterns = $this->detectSeasonalPatterns($timeSeries);
            
            // Identify anomalies
            $anomalies = $this->detectAnomalies($timeSeries);
            
            // Generate forecasts
            $forecasts = $this->generateForecasts($timeSeries, 30); // 30-day forecast
            
            // Calculate trend confidence
            $confidence = $this->calculateTrendConfidence($timeSeries, $overallTrends);
            
            // Generate insights
            $insights = $this->generateTrendInsights($overallTrends, $metricTrends, $seasonalPatterns, $anomalies);

            return new QualityTrendAnalysis(
                true,
                $overallTrends,
                $metricTrends,
                $seasonalPatterns,
                $anomalies,
                $forecasts,
                $insights,
                $confidence
            );

        } catch (\Exception $e) {
            $this->logger->error('Trend analysis failed', ['error' => $e->getMessage()]);
            
            return new QualityTrendAnalysis(
                false,
                [],
                [],
                [],
                [],
                [],
                [],
                0.0,
                'Trend analysis failed: ' . $e->getMessage()
            );
        }
    }

    /**
     * Extract time series data from historical metrics
     */
    private function extractTimeSeries(array $historicalData, array $currentMetrics): array
    {
        $timeSeries = [];
        
        // Process historical data
        foreach ($historicalData as $date => $dailyData) {
            foreach ($dailyData as $hour => $metrics) {
                $timestamp = strtotime("{$date} {$hour}:00:00");
                $timeSeries[$timestamp] = $this->normalizeMetrics($metrics);
            }
        }
        
        // Add current metrics
        $timeSeries[time()] = $this->normalizeMetrics($currentMetrics);
        
        // Sort by timestamp
        ksort($timeSeries);
        
        return $timeSeries;
    }

    /**
     * Detect overall quality trends
     */
    private function detectOverallTrends(array $timeSeries): array
    {
        $trends = [];
        
        // Extract quality scores over time
        $qualityScores = array_column($timeSeries, 'quality_score');
        $timestamps = array_keys($timeSeries);
        
        // Calculate linear trend
        $linearTrend = $this->calculateLinearTrend($qualityScores);
        
        // Calculate moving averages
        $movingAverages = $this->calculateMovingAverages($qualityScores, [7, 14, 30]);
        
        // Detect trend direction and strength
        $trendDirection = $this->determineTrendDirection($qualityScores);
        $trendStrength = $this->calculateTrendStrength($qualityScores);
        
        // Calculate volatility
        $volatility = $this->calculateVolatility($qualityScores);
        
        // Detect trend changes
        $trendChanges = $this->detectTrendChanges($qualityScores, $timestamps);

        return [
            'direction' => $trendDirection,
            'strength' => $trendStrength,
            'volatility' => $volatility,
            'linear_trend' => $linearTrend,
            'moving_averages' => $movingAverages,
            'trend_changes' => $trendChanges,
            'acceleration' => $this->calculateTrendAcceleration($qualityScores),
            'momentum' => $this->calculateMomentum($qualityScores)
        ];
    }

    /**
     * Analyze trends for specific metrics
     */
    private function analyzeMetricTrends(array $timeSeries): array
    {
        $metricTrends = [];
        $metrics = ['technical_debt', 'violation_metrics', 'complexity_metrics', 'maintainability_index'];
        
        foreach ($metrics as $metric) {
            $values = $this->extractMetricValues($timeSeries, $metric);
            
            if (!empty($values)) {
                $metricTrends[$metric] = [
                    'trend' => $this->calculateLinearTrend($values),
                    'direction' => $this->determineTrendDirection($values),
                    'volatility' => $this->calculateVolatility($values),
                    'correlation_with_quality' => $this->calculateCorrelation(
                        $values,
                        array_column($timeSeries, 'quality_score')
                    ),
                    'seasonal_component' => $this->extractSeasonalComponent($values),
                    'outliers' => $this->detectOutliers($values)
                ];
            }
        }
        
        return $metricTrends;
    }

    /**
     * Detect seasonal patterns in quality metrics
     */
    private function detectSeasonalPatterns(array $timeSeries): array
    {
        $patterns = [];
        
        // Extract timestamps and quality scores
        $timestamps = array_keys($timeSeries);
        $qualityScores = array_column($timeSeries, 'quality_score');
        
        // Analyze daily patterns
        $patterns['daily'] = $this->analyzeDailyPatterns($timestamps, $qualityScores);
        
        // Analyze weekly patterns
        $patterns['weekly'] = $this->analyzeWeeklyPatterns($timestamps, $qualityScores);
        
        // Analyze monthly patterns
        $patterns['monthly'] = $this->analyzeMonthlyPatterns($timestamps, $qualityScores);
        
        // Calculate seasonal strength
        $patterns['seasonal_strength'] = $this->calculateSeasonalStrength($patterns);
        
        return $patterns;
    }

    /**
     * Detect anomalies in quality trends
     */
    private function detectAnomalies(array $timeSeries): array
    {
        $anomalies = [];
        $qualityScores = array_column($timeSeries, 'quality_score');
        $timestamps = array_keys($timeSeries);
        
        // Statistical anomaly detection
        $mean = array_sum($qualityScores) / count($qualityScores);
        $stdDev = $this->calculateStandardDeviation($qualityScores);
        $threshold = 2 * $stdDev; // 2-sigma threshold
        
        foreach ($qualityScores as $index => $score) {
            if (abs($score - $mean) > $threshold) {
                $anomalies[] = [
                    'timestamp' => $timestamps[$index],
                    'date' => date('Y-m-d H:i:s', $timestamps[$index]),
                    'value' => $score,
                    'deviation' => abs($score - $mean),
                    'severity' => $this->categorizeAnomalySeverity(abs($score - $mean), $threshold),
                    'type' => $score > $mean ? 'improvement_spike' : 'quality_drop'
                ];
            }
        }
        
        // Additional anomaly detection methods
        $anomalies = array_merge($anomalies, $this->detectTrendAnomalies($qualityScores, $timestamps));
        $anomalies = array_merge($anomalies, $this->detectSeasonalAnomalies($timeSeries));
        
        return $anomalies;
    }

    /**
     * Generate quality forecasts
     */
    private function generateForecasts(array $timeSeries, int $forecastDays): array
    {
        $forecasts = [];
        $qualityScores = array_column($timeSeries, 'quality_score');
        
        // Linear extrapolation
        $linearForecast = $this->generateLinearForecast($qualityScores, $forecastDays);
        
        // Exponential smoothing
        $exponentialForecast = $this->generateExponentialForecast($qualityScores, $forecastDays);
        
        // Seasonal decomposition forecast
        $seasonalForecast = $this->generateSeasonalForecast($timeSeries, $forecastDays);
        
        // Ensemble forecast (weighted average)
        $ensembleForecast = $this->generateEnsembleForecast([
            'linear' => $linearForecast,
            'exponential' => $exponentialForecast,
            'seasonal' => $seasonalForecast
        ]);
        
        return [
            'linear' => $linearForecast,
            'exponential' => $exponentialForecast,
            'seasonal' => $seasonalForecast,
            'ensemble' => $ensembleForecast,
            'confidence_intervals' => $this->calculateConfidenceIntervals($ensembleForecast),
            'forecast_accuracy' => $this->estimateForecastAccuracy($timeSeries)
        ];
    }

    /**
     * Generate insights from trend analysis
     */
    private function generateTrendInsights(
        array $overallTrends,
        array $metricTrends,
        array $seasonalPatterns,
        array $anomalies
    ): array {
        $insights = [];
        
        // Overall trend insights
        $insights['overall'] = $this->generateOverallInsights($overallTrends);
        
        // Metric-specific insights
        $insights['metrics'] = $this->generateMetricInsights($metricTrends);
        
        // Seasonal insights
        $insights['seasonal'] = $this->generateSeasonalInsights($seasonalPatterns);
        
        // Anomaly insights
        $insights['anomalies'] = $this->generateAnomalyInsights($anomalies);
        
        // Predictive insights
        $insights['predictive'] = $this->generatePredictiveInsights($overallTrends, $metricTrends);
        
        return $insights;
    }

    /**
     * Calculate linear trend using least squares regression
     */
    private function calculateLinearTrend(array $values): array
    {
        $n = count($values);
        if ($n < 2) {
            return ['slope' => 0, 'intercept' => 0, 'r_squared' => 0];
        }

        $x = range(1, $n);
        $sumX = array_sum($x);
        $sumY = array_sum($values);
        $sumXY = 0;
        $sumXX = 0;

        for ($i = 0; $i < $n; $i++) {
            $sumXY += $x[$i] * $values[$i];
            $sumXX += $x[$i] * $x[$i];
        }

        $slope = ($n * $sumXY - $sumX * $sumY) / ($n * $sumXX - $sumX * $sumX);
        $intercept = ($sumY - $slope * $sumX) / $n;

        // Calculate R-squared
        $yMean = $sumY / $n;
        $ssRes = 0;
        $ssTot = 0;

        for ($i = 0; $i < $n; $i++) {
            $predicted = $slope * $x[$i] + $intercept;
            $ssRes += pow($values[$i] - $predicted, 2);
            $ssTot += pow($values[$i] - $yMean, 2);
        }

        $rSquared = $ssTot > 0 ? 1 - ($ssRes / $ssTot) : 0;

        return [
            'slope' => $slope,
            'intercept' => $intercept,
            'r_squared' => $rSquared
        ];
    }

    /**
     * Calculate moving averages
     */
    private function calculateMovingAverages(array $values, array $windows): array
    {
        $movingAverages = [];
        
        foreach ($windows as $window) {
            $ma = [];
            for ($i = $window - 1; $i < count($values); $i++) {
                $sum = array_sum(array_slice($values, $i - $window + 1, $window));
                $ma[] = $sum / $window;
            }
            $movingAverages[$window] = $ma;
        }
        
        return $movingAverages;
    }

    /**
     * Determine trend direction
     */
    private function determineTrendDirection(array $values): string
    {
        if (count($values) < 3) {
            return 'insufficient_data';
        }
        
        $recent = array_slice($values, -7); // Last 7 points
        $previous = array_slice($values, -14, 7); // Previous 7 points
        
        $recentAvg = array_sum($recent) / count($recent);
        $previousAvg = array_sum($previous) / count($previous);
        
        $change = ($recentAvg - $previousAvg) / max($previousAvg, 0.01);
        
        if ($change > 0.05) {
            return 'improving';
        } elseif ($change < -0.05) {
            return 'declining';
        } else {
            return 'stable';
        }
    }

    /**
     * Calculate trend strength
     */
    private function calculateTrendStrength(array $values): float
    {
        $trend = $this->calculateLinearTrend($values);
        return abs($trend['slope']) * $trend['r_squared'];
    }

    /**
     * Calculate volatility
     */
    private function calculateVolatility(array $values): float
    {
        if (count($values) < 2) {
            return 0.0;
        }
        
        $returns = [];
        for ($i = 1; $i < count($values); $i++) {
            if ($values[$i - 1] != 0) {
                $returns[] = ($values[$i] - $values[$i - 1]) / $values[$i - 1];
            }
        }
        
        return $this->calculateStandardDeviation($returns);
    }

    /**
     * Calculate standard deviation
     */
    private function calculateStandardDeviation(array $values): float
    {
        $n = count($values);
        if ($n < 2) {
            return 0.0;
        }
        
        $mean = array_sum($values) / $n;
        $squaredDiffs = array_map(fn($x) => pow($x - $mean, 2), $values);
        $variance = array_sum($squaredDiffs) / ($n - 1);
        
        return sqrt($variance);
    }

    /**
     * Normalize metrics for analysis
     */
    private function normalizeMetrics(array $metrics): array
    {
        return [
            'quality_score' => $metrics['quality_score'] ?? 0,
            'technical_debt' => is_array($metrics['technical_debt']) ? 
                ($metrics['technical_debt']['debt_hours'] ?? 0) : 0,
            'violation_count' => is_array($metrics['violation_metrics']) ? 
                ($metrics['violation_metrics']['total_violations'] ?? 0) : 0,
            'complexity' => is_array($metrics['complexity_metrics']) ? 
                ($metrics['complexity_metrics']['average_complexity'] ?? 0) : 0,
            'maintainability' => is_array($metrics['maintainability_index']) ? 
                ($metrics['maintainability_index']['average_maintainability'] ?? 0) : 0
        ];
    }

    /**
     * Get time range of historical data
     */
    private function getTimeRange(array $historicalData): array
    {
        $dates = array_keys($historicalData);
        sort($dates);
        
        return [
            'start' => reset($dates),
            'end' => end($dates),
            'days' => count($dates)
        ];
    }

    /**
     * Calculate trend confidence
     */
    private function calculateTrendConfidence(array $timeSeries, array $overallTrends): float
    {
        $confidence = 0.5; // Base confidence
        
        // Increase confidence based on data points
        $dataPoints = count($timeSeries);
        if ($dataPoints > 30) {
            $confidence += 0.2;
        } elseif ($dataPoints > 14) {
            $confidence += 0.1;
        }
        
        // Increase confidence based on R-squared
        $rSquared = $overallTrends['linear_trend']['r_squared'] ?? 0;
        $confidence += $rSquared * 0.3;
        
        // Decrease confidence based on volatility
        $volatility = $overallTrends['volatility'] ?? 0;
        $confidence -= min($volatility * 0.5, 0.3);
        
        return max(0.1, min(1.0, $confidence));
    }

    // Placeholder methods for complex calculations
    private function detectTrendChanges(array $values, array $timestamps): array { return []; }
    private function calculateTrendAcceleration(array $values): float { return 0.0; }
    private function calculateMomentum(array $values): float { return 0.0; }
    private function extractMetricValues(array $timeSeries, string $metric): array { return []; }
    private function calculateCorrelation(array $x, array $y): float { return 0.0; }
    private function extractSeasonalComponent(array $values): array { return []; }
    private function detectOutliers(array $values): array { return []; }
    private function analyzeDailyPatterns(array $timestamps, array $values): array { return []; }
    private function analyzeWeeklyPatterns(array $timestamps, array $values): array { return []; }
    private function analyzeMonthlyPatterns(array $timestamps, array $values): array { return []; }
    private function calculateSeasonalStrength(array $patterns): float { return 0.0; }
    private function categorizeAnomalySeverity(float $deviation, float $threshold): string { return 'medium'; }
    private function detectTrendAnomalies(array $values, array $timestamps): array { return []; }
    private function detectSeasonalAnomalies(array $timeSeries): array { return []; }
    private function generateLinearForecast(array $values, int $days): array { return []; }
    private function generateExponentialForecast(array $values, int $days): array { return []; }
    private function generateSeasonalForecast(array $timeSeries, int $days): array { return []; }
    private function generateEnsembleForecast(array $forecasts): array { return []; }
    private function calculateConfidenceIntervals(array $forecast): array { return []; }
    private function estimateForecastAccuracy(array $timeSeries): float { return 0.8; }
    private function generateOverallInsights(array $trends): array { return []; }
    private function generateMetricInsights(array $metricTrends): array { return []; }
    private function generateSeasonalInsights(array $patterns): array { return []; }
    private function generateAnomalyInsights(array $anomalies): array { return []; }
    private function generatePredictiveInsights(array $overallTrends, array $metricTrends): array { return []; }
} 
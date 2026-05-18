<?php

declare(strict_types=1);

namespace ACQC\Core\AI;

use ACQC\Core\Config\ConfigManager;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Predictive Analytics Engine for ACQC
 * 
 * Provides code quality forecasting, technical debt prediction,
 * risk assessment, and performance trend analysis using statistical
 * models and machine learning techniques.
 */
class PredictiveAnalytics
{
    private ConfigManager $configManager;
    private LoggerInterface $logger;
    private AIEngine $aiEngine;
    
    private MetricsCollector $metricsCollector;
    private QualityTrendAnalyzer $trendAnalyzer;
    private TechnicalDebtPredictor $debtPredictor;
    private RiskAssessment $riskAssessment;
    
    private array $models = [];
    private array $forecasts = [];
    private array $historicalData = [];

    public function __construct(
        ?AIEngine $aiEngine = null,
        ?ConfigManager $configManager = null,
        ?LoggerInterface $logger = null
    ) {
        $this->aiEngine = $aiEngine ?? new AIEngine();
        $this->configManager = $configManager ?? new ConfigManager();
        $this->logger = $logger ?? new NullLogger();
        
        $this->initializeComponents();
        $this->loadConfiguration();
    }

    /**
     * Initialize predictive analytics components
     */
    private function initializeComponents(): void
    {
        $this->metricsCollector = new MetricsCollector($this->configManager, $this->logger);
        $this->trendAnalyzer = new QualityTrendAnalyzer($this->metricsCollector, $this->logger);
        $this->debtPredictor = new TechnicalDebtPredictor($this->metricsCollector, $this->aiEngine, $this->logger);
        $this->riskAssessment = new RiskAssessment($this->metricsCollector, $this->aiEngine, $this->logger);
    }

    /**
     * Load predictive analytics configuration
     */
    private function loadConfiguration(): void
    {
        $config = $this->configManager->get('predictive_analytics', []);
        
        $this->models = $config['models'] ?? [
            'quality_trend' => ['enabled' => true, 'lookback_days' => 90],
            'technical_debt' => ['enabled' => true, 'prediction_days' => 30],
            'risk_scoring' => ['enabled' => true, 'sensitivity' => 0.8],
            'performance_forecast' => ['enabled' => true, 'horizon_days' => 60]
        ];

        $this->logger->info('Predictive Analytics initialized', [
            'enabled_models' => array_keys(array_filter($this->models, fn($m) => $m['enabled'] ?? false))
        ]);
    }

    /**
     * Generate comprehensive quality forecast
     */
    public function generateQualityForecast(
        string $projectPath,
        array $options = []
    ): QualityForecast {
        $this->logger->info('Generating quality forecast', ['project' => $projectPath]);

        try {
            // Collect current metrics
            $currentMetrics = $this->metricsCollector->collectCurrentMetrics($projectPath);
            
            // Get historical data
            $historicalData = $this->metricsCollector->getHistoricalData($projectPath, [
                'days_back' => $options['lookback_days'] ?? 90
            ]);

            // Analyze quality trends
            $trendAnalysis = $this->trendAnalyzer->analyzeTrends($historicalData, $currentMetrics);
            
            // Predict technical debt
            $debtPrediction = $this->debtPredictor->predictDebt($historicalData, $currentMetrics, [
                'horizon_days' => $options['prediction_days'] ?? 30
            ]);
            
            // Assess risks
            $riskAssessment = $this->riskAssessment->assessRisks($historicalData, $currentMetrics);
            
            // Generate AI-enhanced insights
            $aiInsights = $this->generateAIInsights($trendAnalysis, $debtPrediction, $riskAssessment);

            return new QualityForecast(
                true,
                $trendAnalysis,
                $debtPrediction,
                $riskAssessment,
                $aiInsights,
                $this->generateRecommendations($trendAnalysis, $debtPrediction, $riskAssessment),
                $this->calculateConfidence($trendAnalysis, $debtPrediction, $riskAssessment)
            );

        } catch (\Exception $e) {
            $this->logger->error('Quality forecast generation failed', [
                'project' => $projectPath,
                'error' => $e->getMessage()
            ]);

            return new QualityForecast(
                false,
                null,
                null,
                null,
                [],
                [],
                0.0,
                'Forecast generation failed: ' . $e->getMessage()
            );
        }
    }

    /**
     * Analyze quality trends over time
     */
    public function analyzeQualityTrends(
        string $projectPath,
        array $options = []
    ): QualityTrendAnalysis {
        $historicalData = $this->metricsCollector->getHistoricalData($projectPath, $options);
        $currentMetrics = $this->metricsCollector->collectCurrentMetrics($projectPath);
        
        return $this->trendAnalyzer->analyzeTrends($historicalData, $currentMetrics);
    }

    /**
     * Predict technical debt accumulation
     */
    public function predictTechnicalDebt(
        string $projectPath,
        array $options = []
    ): TechnicalDebtPrediction {
        $historicalData = $this->metricsCollector->getHistoricalData($projectPath, $options);
        $currentMetrics = $this->metricsCollector->collectCurrentMetrics($projectPath);
        
        return $this->debtPredictor->predictDebt($historicalData, $currentMetrics, $options);
    }

    /**
     * Assess project risks
     */
    public function assessProjectRisks(
        string $projectPath,
        array $options = []
    ): ProjectRiskAssessment {
        $historicalData = $this->metricsCollector->getHistoricalData($projectPath, $options);
        $currentMetrics = $this->metricsCollector->collectCurrentMetrics($projectPath);
        
        return $this->riskAssessment->assessRisks($historicalData, $currentMetrics);
    }

    /**
     * Analyze team productivity metrics
     */
    public function analyzeTeamProductivity(
        string $projectPath,
        array $teamData = [],
        array $options = []
    ): TeamProductivityAnalysis {
        $historicalData = $this->metricsCollector->getHistoricalData($projectPath, $options);
        $currentMetrics = $this->metricsCollector->collectCurrentMetrics($projectPath);

        // Calculate productivity metrics
        $metrics = $this->calculateProductivityMetrics($historicalData, $teamData);
        
        // Analyze trends
        $trends = $this->analyzeProductivityTrends($metrics);
        
        // Generate insights
        $insights = $this->generateProductivityInsights($metrics, $trends);
        
        // AI-enhanced recommendations
        $recommendations = $this->generateProductivityRecommendations($metrics, $trends, $insights);

        return new TeamProductivityAnalysis(
            $metrics,
            $trends,
            $insights,
            $recommendations,
            $this->calculateProductivityScore($metrics)
        );
    }

    /**
     * Forecast performance degradation
     */
    public function forecastPerformanceDegradation(
        string $projectPath,
        array $options = []
    ): PerformanceForecast {
        $historicalData = $this->metricsCollector->getHistoricalData($projectPath, $options);
        $currentMetrics = $this->metricsCollector->collectCurrentMetrics($projectPath);

        // Extract performance metrics
        $performanceData = $this->extractPerformanceMetrics($historicalData);
        
        // Apply statistical models
        $forecast = $this->applyPerformanceForecastModels($performanceData, $options);
        
        // Identify degradation patterns
        $degradationPatterns = $this->identifyDegradationPatterns($performanceData);
        
        // Generate alerts and recommendations
        $alerts = $this->generatePerformanceAlerts($forecast, $degradationPatterns);
        $recommendations = $this->generatePerformanceRecommendations($forecast, $degradationPatterns);

        return new PerformanceForecast(
            $forecast,
            $degradationPatterns,
            $alerts,
            $recommendations,
            $this->calculatePerformanceRisk($forecast)
        );
    }

    /**
     * Generate quality improvement recommendations
     */
    public function generateImprovementRecommendations(
        string $projectPath,
        array $options = []
    ): array {
        $forecast = $this->generateQualityForecast($projectPath, $options);
        
        if (!$forecast->isSuccess()) {
            return ['error' => 'Unable to generate recommendations: ' . $forecast->getErrorMessage()];
        }

        $recommendations = [];
        
        // Quality trend recommendations
        if ($forecast->getTrendAnalysis()) {
            $recommendations['quality_trends'] = $this->getQualityTrendRecommendations($forecast->getTrendAnalysis());
        }
        
        // Technical debt recommendations
        if ($forecast->getDebtPrediction()) {
            $recommendations['technical_debt'] = $this->getTechnicalDebtRecommendations($forecast->getDebtPrediction());
        }
        
        // Risk mitigation recommendations
        if ($forecast->getRiskAssessment()) {
            $recommendations['risk_mitigation'] = $this->getRiskMitigationRecommendations($forecast->getRiskAssessment());
        }

        // AI-enhanced strategic recommendations
        $recommendations['strategic'] = $this->generateStrategicRecommendations($forecast);

        return $recommendations;
    }

    /**
     * Calculate productivity metrics
     */
    private function calculateProductivityMetrics(array $historicalData, array $teamData): array
    {
        $metrics = [
            'code_velocity' => $this->calculateCodeVelocity($historicalData),
            'defect_rate' => $this->calculateDefectRate($historicalData),
            'fix_time' => $this->calculateAverageFixTime($historicalData),
            'review_efficiency' => $this->calculateReviewEfficiency($historicalData),
            'technical_debt_velocity' => $this->calculateDebtVelocity($historicalData),
            'quality_gate_pass_rate' => $this->calculateQualityGatePassRate($historicalData)
        ];

        if (!empty($teamData)) {
            $metrics['team_metrics'] = $this->calculateTeamSpecificMetrics($historicalData, $teamData);
        }

        return $metrics;
    }

    /**
     * Apply statistical models for forecasting
     */
    private function applyStatisticalModels(array $data, string $modelType): array
    {
        switch ($modelType) {
            case 'linear_regression':
                return $this->applyLinearRegression($data);
            case 'exponential_smoothing':
                return $this->applyExponentialSmoothing($data);
            case 'arima':
                return $this->applyARIMA($data);
            case 'seasonal_decomposition':
                return $this->applySeasonalDecomposition($data);
            default:
                return $this->applySimpleTrendAnalysis($data);
        }
    }

    /**
     * Apply linear regression model
     */
    private function applyLinearRegression(array $data): array
    {
        $n = count($data);
        if ($n < 2) {
            return ['slope' => 0, 'intercept' => 0, 'r_squared' => 0];
        }

        $x = range(1, $n);
        $y = array_values($data);

        $sumX = array_sum($x);
        $sumY = array_sum($y);
        $sumXY = 0;
        $sumXX = 0;

        for ($i = 0; $i < $n; $i++) {
            $sumXY += $x[$i] * $y[$i];
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
            $ssRes += pow($y[$i] - $predicted, 2);
            $ssTot += pow($y[$i] - $yMean, 2);
        }

        $rSquared = $ssTot > 0 ? 1 - ($ssRes / $ssTot) : 0;

        return [
            'slope' => $slope,
            'intercept' => $intercept,
            'r_squared' => $rSquared,
            'model_type' => 'linear_regression'
        ];
    }

    /**
     * Apply exponential smoothing
     */
    private function applyExponentialSmoothing(array $data, float $alpha = 0.3): array
    {
        if (empty($data)) {
            return ['forecast' => [], 'smoothed' => []];
        }

        $smoothed = [$data[0]];
        
        for ($i = 1; $i < count($data); $i++) {
            $smoothed[] = $alpha * $data[$i] + (1 - $alpha) * $smoothed[$i - 1];
        }

        // Generate forecast for next periods
        $lastSmoothed = end($smoothed);
        $forecast = array_fill(0, 30, $lastSmoothed); // 30-day forecast

        return [
            'smoothed' => $smoothed,
            'forecast' => $forecast,
            'alpha' => $alpha,
            'model_type' => 'exponential_smoothing'
        ];
    }

    /**
     * Apply simple trend analysis
     */
    private function applySimpleTrendAnalysis(array $data): array
    {
        if (count($data) < 3) {
            return ['trend' => 'insufficient_data', 'direction' => 'unknown'];
        }

        $recent = array_slice($data, -7); // Last 7 data points
        $older = array_slice($data, -14, 7); // Previous 7 data points

        $recentAvg = array_sum($recent) / count($recent);
        $olderAvg = array_sum($older) / count($older);

        $trendDirection = $recentAvg > $olderAvg ? 'improving' : 
                         ($recentAvg < $olderAvg ? 'declining' : 'stable');
        
        $trendStrength = abs($recentAvg - $olderAvg) / max($olderAvg, 0.01);

        return [
            'trend' => $trendDirection,
            'strength' => $trendStrength,
            'recent_average' => $recentAvg,
            'previous_average' => $olderAvg,
            'model_type' => 'simple_trend'
        ];
    }

    /**
     * Generate AI-enhanced insights
     */
    private function generateAIInsights(
        ?QualityTrendAnalysis $trendAnalysis,
        ?TechnicalDebtPrediction $debtPrediction,
        ?ProjectRiskAssessment $riskAssessment
    ): array {
        if (!$this->aiEngine->isAvailable()) {
            return ['ai_insights_unavailable' => 'AI engine not available'];
        }

        try {
            $context = [
                'trend_analysis' => $trendAnalysis ? $trendAnalysis->toArray() : null,
                'debt_prediction' => $debtPrediction ? $debtPrediction->toArray() : null,
                'risk_assessment' => $riskAssessment ? $riskAssessment->toArray() : null
            ];

            $prompt = $this->aiEngine->getPromptManager()->getPrompt('predictive_insights', $context);
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
     * Parse AI insights response
     */
    private function parseAIInsights(string $response): array
    {
        try {
            $data = json_decode($response, true);
            return $data ?: ['insights' => $response];
        } catch (\Exception $e) {
            return ['insights' => $response];
        }
    }

    /**
     * Generate comprehensive recommendations
     */
    private function generateRecommendations(
        ?QualityTrendAnalysis $trendAnalysis,
        ?TechnicalDebtPrediction $debtPrediction,
        ?ProjectRiskAssessment $riskAssessment
    ): array {
        $recommendations = [];

        if ($trendAnalysis) {
            $recommendations = array_merge($recommendations, $this->getQualityTrendRecommendations($trendAnalysis));
        }

        if ($debtPrediction) {
            $recommendations = array_merge($recommendations, $this->getTechnicalDebtRecommendations($debtPrediction));
        }

        if ($riskAssessment) {
            $recommendations = array_merge($recommendations, $this->getRiskMitigationRecommendations($riskAssessment));
        }

        return $recommendations;
    }

    /**
     * Calculate overall forecast confidence
     */
    private function calculateConfidence(
        ?QualityTrendAnalysis $trendAnalysis,
        ?TechnicalDebtPrediction $debtPrediction,
        ?ProjectRiskAssessment $riskAssessment
    ): float {
        $confidences = [];

        if ($trendAnalysis && method_exists($trendAnalysis, 'getConfidence')) {
            $confidences[] = $trendAnalysis->getConfidence();
        }

        if ($debtPrediction && method_exists($debtPrediction, 'getConfidence')) {
            $confidences[] = $debtPrediction->getConfidence();
        }

        if ($riskAssessment && method_exists($riskAssessment, 'getConfidence')) {
            $confidences[] = $riskAssessment->getConfidence();
        }

        return !empty($confidences) ? array_sum($confidences) / count($confidences) : 0.5;
    }

    /**
     * Get quality trend recommendations
     */
    private function getQualityTrendRecommendations(QualityTrendAnalysis $analysis): array
    {
        // Implementation depends on QualityTrendAnalysis structure
        return [
            'priority' => 'high',
            'category' => 'quality_trends',
            'recommendations' => ['Monitor quality trends closely', 'Implement quality gates']
        ];
    }

    /**
     * Get technical debt recommendations
     */
    private function getTechnicalDebtRecommendations(TechnicalDebtPrediction $prediction): array
    {
        return [
            'priority' => 'medium',
            'category' => 'technical_debt',
            'recommendations' => ['Schedule refactoring sprints', 'Implement debt reduction targets']
        ];
    }

    /**
     * Get risk mitigation recommendations
     */
    private function getRiskMitigationRecommendations(ProjectRiskAssessment $assessment): array
    {
        return [
            'priority' => 'high',
            'category' => 'risk_mitigation',
            'recommendations' => ['Implement risk monitoring', 'Create contingency plans']
        ];
    }

    /**
     * Get predictive analytics metrics
     */
    public function getAnalyticsMetrics(): array
    {
        return [
            'models_enabled' => array_keys(array_filter($this->models, fn($m) => $m['enabled'] ?? false)),
            'forecasts_generated' => count($this->forecasts),
            'data_points_collected' => $this->metricsCollector->getDataPointCount(),
            'prediction_accuracy' => $this->calculatePredictionAccuracy(),
            'system_health' => $this->getSystemHealth()
        ];
    }

    /**
     * Calculate prediction accuracy
     */
    private function calculatePredictionAccuracy(): float
    {
        // Implementation would compare previous predictions with actual outcomes
        return 0.85; // Placeholder
    }

    /**
     * Get system health status
     */
    private function getSystemHealth(): array
    {
        return [
            'metrics_collector' => $this->metricsCollector->isHealthy(),
            'ai_engine' => $this->aiEngine->isAvailable(),
            'data_quality' => $this->assessDataQuality(),
            'model_performance' => $this->assessModelPerformance()
        ];
    }

    /**
     * Assess data quality
     */
    private function assessDataQuality(): float
    {
        // Implementation would assess completeness, accuracy, and consistency of data
        return 0.9; // Placeholder
    }

    /**
     * Assess model performance
     */
    private function assessModelPerformance(): float
    {
        // Implementation would evaluate model accuracy and reliability
        return 0.88; // Placeholder
    }

    // Placeholder methods for missing implementations
    private function analyzeProductivityTrends(array $metrics): array { return []; }
    private function generateProductivityInsights(array $metrics, array $trends): array { return []; }
    private function generateProductivityRecommendations(array $metrics, array $trends, array $insights): array { return []; }
    private function calculateProductivityScore(array $metrics): float { return 0.8; }
    private function extractPerformanceMetrics(array $data): array { return []; }
    private function applyPerformanceForecastModels(array $data, array $options): array { return []; }
    private function identifyDegradationPatterns(array $data): array { return []; }
    private function generatePerformanceAlerts(array $forecast, array $patterns): array { return []; }
    private function generatePerformanceRecommendations(array $forecast, array $patterns): array { return []; }
    private function calculatePerformanceRisk(array $forecast): float { return 0.3; }
    private function generateStrategicRecommendations(QualityForecast $forecast): array { return []; }
    private function calculateCodeVelocity(array $data): float { return 0.0; }
    private function calculateDefectRate(array $data): float { return 0.0; }
    private function calculateAverageFixTime(array $data): float { return 0.0; }
    private function calculateReviewEfficiency(array $data): float { return 0.0; }
    private function calculateDebtVelocity(array $data): float { return 0.0; }
    private function calculateQualityGatePassRate(array $data): float { return 0.0; }
    private function calculateTeamSpecificMetrics(array $data, array $teamData): array { return []; }
    private function applyARIMA(array $data): array { return []; }
    private function applySeasonalDecomposition(array $data): array { return []; }
} 
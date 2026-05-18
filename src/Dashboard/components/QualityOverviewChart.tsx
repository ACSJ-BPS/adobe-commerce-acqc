// eslint-disable-next-line @typescript-eslint/ban-ts-comment
// @ts-nocheck
import React, { useMemo } from 'react';
import {
  Box,
  Card,
  CardContent,
  Typography,
  Grid,
  LinearProgress,
  Chip,
  useTheme,
  alpha
} from '@mui/material';
import {
  RadialBarChart,
  RadialBar,
  ResponsiveContainer,
  PieChart,
  Pie,
  Cell,
  BarChart,
  Bar,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  Legend,
  LineChart,
  Line
} from 'recharts';
import { motion } from 'framer-motion';
import { QualityMetrics, QualityScore } from '../types/quality';
import { useQualityData } from '../hooks/useQualityData';
import { formatMetricValue, getScoreColor, calculateTrend } from '../utils/formatters';

interface QualityOverviewChartProps {
  projectId?: string;
  timeRange?: string;
  showDetails?: boolean;
  compact?: boolean;
}

const QualityOverviewChart: React.FC<QualityOverviewChartProps> = ({
  projectId,
  timeRange = '30d',
  showDetails = true,
  compact = false
}) => {
  const theme = useTheme();
  const { data: qualityData, isLoading, error } = useQualityData(projectId, timeRange);

  const chartData = useMemo(() => {
    if (!qualityData) return null;

    const { currentMetrics, historicalData, trends } = qualityData;

    // Quality score over time
    const scoreHistory = historicalData.map((point, index) => ({
      date: point.date,
      score: point.qualityScore,
      violations: point.totalViolations,
      technicalDebt: point.technicalDebtHours,
      testCoverage: point.testCoverage * 100
    }));

    // Current metrics for radial chart
    const radialData = [
      {
        name: 'Quality Score',
        value: currentMetrics.qualityScore,
        fill: getScoreColor(currentMetrics.qualityScore, theme)
      }
    ];

    // Metrics breakdown for pie chart
    const metricsBreakdown = [
      { name: 'Code Quality', value: currentMetrics.codeQuality, fill: theme.palette.primary.main },
      { name: 'Test Coverage', value: currentMetrics.testCoverage * 100, fill: theme.palette.success.main },
      { name: 'Performance', value: currentMetrics.performanceScore, fill: theme.palette.warning.main },
      { name: 'Security', value: currentMetrics.securityScore, fill: theme.palette.error.main },
      { name: 'Maintainability', value: currentMetrics.maintainabilityIndex, fill: theme.palette.info.main }
    ];

    // Violation distribution
    const violationData = Object.entries(currentMetrics.violationsBySeverity).map(([severity, count]) => ({
      severity: severity.charAt(0).toUpperCase() + severity.slice(1),
      count,
      fill: getViolationColor(severity, theme)
    }));

    return {
      scoreHistory,
      radialData,
      metricsBreakdown,
      violationData,
      trends
    };
  }, [qualityData, theme]);

  if (isLoading) {
    return <QualityOverviewSkeleton compact={compact} />;
  }

  if (error || !chartData) {
    return <QualityOverviewError error={error} />;
  }

  return (
    <motion.div
      initial={{ opacity: 0, y: 20 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.5 }}
    >
      <Grid container spacing={3}>
        {/* Main Quality Score */}
        <Grid item xs={12} md={compact ? 12 : 4}>
          <Card sx={{ height: '100%' }}>
            <CardContent>
              <Typography variant="h6" gutterBottom>
                Overall Quality Score
              </Typography>
              <Box sx={{ height: 200 }}>
                <ResponsiveContainer width="100%" height="100%">
                  <RadialBarChart
                    cx="50%"
                    cy="50%"
                    innerRadius="60%"
                    outerRadius="90%"
                    data={chartData.radialData}
                    startAngle={90}
                    endAngle={450}
                  >
                    <RadialBar
                      dataKey="value"
                      cornerRadius={10}
                      fill={chartData.radialData[0].fill}
                    />
                    <text
                      x="50%"
                      y="50%"
                      textAnchor="middle"
                      dominantBaseline="middle"
                      className="quality-score"
                      fontSize="2rem"
                      fontWeight="bold"
                      fill={theme.palette.text.primary}
                    >
                      {chartData.radialData[0].value.toFixed(1)}
                    </text>
                  </RadialBarChart>
                </ResponsiveContainer>
              </Box>
              <Box sx={{ mt: 2 }}>
                <QualityScoreDetails
                  score={chartData.radialData[0].value}
                  trend={chartData.trends.qualityScore}
                  theme={theme}
                />
              </Box>
            </CardContent>
          </Card>
        </Grid>

        {/* Metrics Breakdown */}
        <Grid item xs={12} md={compact ? 12 : 4}>
          <Card sx={{ height: '100%' }}>
            <CardContent>
              <Typography variant="h6" gutterBottom>
                Metrics Breakdown
              </Typography>
              <Box sx={{ height: 200 }}>
                <ResponsiveContainer width="100%" height="100%">
                  <PieChart>
                    <Pie
                      data={chartData.metricsBreakdown}
                      cx="50%"
                      cy="50%"
                      outerRadius={80}
                      dataKey="value"
                      label={({ name, value }) => `${name}: ${value.toFixed(1)}`}
                    >
                      {chartData.metricsBreakdown.map((entry, index) => (
                        <Cell key={`cell-${index}`} fill={entry.fill} />
                      ))}
                    </Pie>
                    <Tooltip formatter={(value) => [`${value.toFixed(1)}%`, 'Score']} />
                  </PieChart>
                </ResponsiveContainer>
              </Box>
            </CardContent>
          </Card>
        </Grid>

        {/* Violations Distribution */}
        <Grid item xs={12} md={compact ? 12 : 4}>
          <Card sx={{ height: '100%' }}>
            <CardContent>
              <Typography variant="h6" gutterBottom>
                Violations by Severity
              </Typography>
              <Box sx={{ height: 200 }}>
                <ResponsiveContainer width="100%" height="100%">
                  <BarChart data={chartData.violationData}>
                    <CartesianGrid strokeDasharray="3 3" />
                    <XAxis dataKey="severity" />
                    <YAxis />
                    <Tooltip />
                    <Bar dataKey="count" radius={[4, 4, 0, 0]}>
                      {chartData.violationData.map((entry, index) => (
                        <Cell key={`cell-${index}`} fill={entry.fill} />
                      ))}
                    </Bar>
                  </BarChart>
                </ResponsiveContainer>
              </Box>
            </CardContent>
          </Card>
        </Grid>

        {/* Historical Trends */}
        {showDetails && (
          <Grid item xs={12}>
            <Card>
              <CardContent>
                <Typography variant="h6" gutterBottom>
                  Quality Trends ({timeRange})
                </Typography>
                <Box sx={{ height: 300 }}>
                  <ResponsiveContainer width="100%" height="100%">
                    <LineChart data={chartData.scoreHistory}>
                      <CartesianGrid strokeDasharray="3 3" />
                      <XAxis 
                        dataKey="date" 
                        tickFormatter={(value) => new Date(value).toLocaleDateString()}
                      />
                      <YAxis />
                      <Tooltip 
                        labelFormatter={(value) => new Date(value).toLocaleDateString()}
                        formatter={(value, name) => [
                          typeof value === 'number' ? value.toFixed(1) : value,
                          name
                        ]}
                      />
                      <Legend />
                      <Line
                        type="monotone"
                        dataKey="score"
                        stroke={theme.palette.primary.main}
                        strokeWidth={3}
                        dot={{ r: 4 }}
                        name="Quality Score"
                      />
                      <Line
                        type="monotone"
                        dataKey="testCoverage"
                        stroke={theme.palette.success.main}
                        strokeWidth={2}
                        dot={{ r: 3 }}
                        name="Test Coverage %"
                      />
                      <Line
                        type="monotone"
                        dataKey="violations"
                        stroke={theme.palette.error.main}
                        strokeWidth={2}
                        dot={{ r: 3 }}
                        name="Total Violations"
                      />
                    </LineChart>
                  </ResponsiveContainer>
                </Box>
              </CardContent>
            </Card>
          </Grid>
        )}

        {/* Key Metrics Cards */}
        {showDetails && (
          <Grid item xs={12}>
            <Grid container spacing={2}>
              <Grid item xs={12} sm={6} md={3}>
                <MetricCard
                  title="Technical Debt"
                  value={qualityData.currentMetrics.technicalDebtHours}
                  unit="hours"
                  trend={chartData.trends.technicalDebt}
                  color={theme.palette.warning.main}
                  icon="⏱️"
                />
              </Grid>
              <Grid item xs={12} sm={6} md={3}>
                <MetricCard
                  title="Test Coverage"
                  value={qualityData.currentMetrics.testCoverage * 100}
                  unit="%"
                  trend={chartData.trends.testCoverage}
                  color={theme.palette.success.main}
                  icon="🧪"
                />
              </Grid>
              <Grid item xs={12} sm={6} md={3}>
                <MetricCard
                  title="Code Complexity"
                  value={qualityData.currentMetrics.averageComplexity}
                  unit="avg"
                  trend={chartData.trends.complexity}
                  color={theme.palette.info.main}
                  icon="🔀"
                />
              </Grid>
              <Grid item xs={12} sm={6} md={3}>
                <MetricCard
                  title="Total Violations"
                  value={qualityData.currentMetrics.totalViolations}
                  unit="issues"
                  trend={chartData.trends.violations}
                  color={theme.palette.error.main}
                  icon="🚨"
                />
              </Grid>
            </Grid>
          </Grid>
        )}
      </Grid>
    </motion.div>
  );
};

// Quality Score Details Component
const QualityScoreDetails: React.FC<{
  score: number;
  trend: { direction: string; change: number };
  theme: any;
}> = ({ score, trend, theme }) => {
  const getScoreLabel = (score: number) => {
    if (score >= 9) return 'Excellent';
    if (score >= 8) return 'Very Good';
    if (score >= 7) return 'Good';
    if (score >= 6) return 'Fair';
    if (score >= 5) return 'Poor';
    return 'Critical';
  };

  const getTrendIcon = (direction: string) => {
    switch (direction) {
      case 'improving': return '📈';
      case 'declining': return '📉';
      default: return '➡️';
    }
  };

  return (
    <Box>
      <Box sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', mb: 1 }}>
        <Chip
          label={getScoreLabel(score)}
          color={score >= 7 ? 'success' : score >= 5 ? 'warning' : 'error'}
          size="small"
        />
        <Typography variant="body2" color="textSecondary">
          {getTrendIcon(trend.direction)} {Math.abs(trend.change).toFixed(1)}%
        </Typography>
      </Box>
      <LinearProgress
        variant="determinate"
        value={score * 10}
        sx={{
          height: 8,
          borderRadius: 4,
          backgroundColor: alpha(theme.palette.grey[500], 0.2),
          '& .MuiLinearProgress-bar': {
            borderRadius: 4,
            backgroundColor: getScoreColor(score, theme)
          }
        }}
      />
    </Box>
  );
};

// Metric Card Component
const MetricCard: React.FC<{
  title: string;
  value: number;
  unit: string;
  trend: { direction: string; change: number };
  color: string;
  icon: string;
}> = ({ title, value, unit, trend, color, icon }) => {
  const theme = useTheme();
  
  return (
    <Card
      sx={{
        background: `linear-gradient(45deg, ${alpha(color, 0.1)}, ${alpha(color, 0.05)})`,
        border: `1px solid ${alpha(color, 0.2)}`
      }}
    >
      <CardContent sx={{ textAlign: 'center' }}>
        <Typography variant="h4" sx={{ mb: 1 }}>
          {icon}
        </Typography>
        <Typography variant="h4" fontWeight="bold" color={color}>
          {formatMetricValue(value)}
          <Typography component="span" variant="body2" color="textSecondary">
            {unit}
          </Typography>
        </Typography>
        <Typography variant="body2" color="textSecondary" gutterBottom>
          {title}
        </Typography>
        <Chip
          label={`${trend.change > 0 ? '+' : ''}${trend.change.toFixed(1)}%`}
          size="small"
          color={trend.direction === 'improving' ? 'success' : trend.direction === 'declining' ? 'error' : 'default'}
          sx={{ fontSize: '0.75rem' }}
        />
      </CardContent>
    </Card>
  );
};

// Loading Skeleton
const QualityOverviewSkeleton: React.FC<{ compact: boolean }> = ({ compact }) => {
  return (
    <Grid container spacing={3}>
      {[1, 2, 3].map((item) => (
        <Grid item xs={12} md={compact ? 12 : 4} key={item}>
          <Card>
            <CardContent>
              <Box sx={{ height: 200, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                <Typography color="textSecondary">Loading...</Typography>
              </Box>
            </CardContent>
          </Card>
        </Grid>
      ))}
    </Grid>
  );
};

// Error Component
const QualityOverviewError: React.FC<{ error: any }> = ({ error }) => {
  return (
    <Card>
      <CardContent>
        <Typography color="error" align="center">
          Failed to load quality data: {error?.message || 'Unknown error'}
        </Typography>
      </CardContent>
    </Card>
  );
};

// Helper function for violation colors
const getViolationColor = (severity: string, theme: any) => {
  switch (severity.toLowerCase()) {
    case 'critical': return theme.palette.error.main;
    case 'error': return theme.palette.error.light;
    case 'warning': return theme.palette.warning.main;
    case 'info': return theme.palette.info.main;
    default: return theme.palette.grey[500];
  }
};

export default QualityOverviewChart; 
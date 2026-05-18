// eslint-disable-next-line @typescript-eslint/ban-ts-comment
// @ts-nocheck
import React, { useState, useMemo } from 'react';
import {
  Card,
  CardContent,
  Typography,
  Box,
  Grid,
  FormControl,
  InputLabel,
  Select,
  MenuItem,
  ButtonGroup,
  Button,
  Switch,
  FormControlLabel,
  useTheme
} from '@mui/material';
import {
  LineChart,
  Line,
  AreaChart,
  Area,
  ComposedChart,
  Bar,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip as RechartsTooltip,
  Legend,
  Brush,
  ReferenceLine
} from 'recharts';
import { motion } from 'framer-motion';
import { useTrendData } from '../hooks/useTrendData';
import { TimeRange, MetricType } from '../types/trends';

interface TrendAnalysisChartProps {
  projectId?: string;
  initialTimeRange?: TimeRange;
  metrics?: MetricType[];
  showPrediction?: boolean;
  compact?: boolean;
}

// Simple metric configurations without yAxisId to avoid conflicts
const METRIC_CONFIGS = {
  qualityScore: {
    name: 'Quality Score',
    color: '#1976d2',
    format: (value: number) => value.toFixed(1)
  },
  totalViolations: {
    name: 'Total Violations',
    color: '#d32f2f',
    format: (value: number) => value.toString()
  },
  technicalDebtHours: {
    name: 'Technical Debt (hours)',
    color: '#ed6c02',
    format: (value: number) => `${value.toFixed(1)}h`
  },
  testCoverage: {
    name: 'Test Coverage (%)',
    color: '#2e7d32',
    format: (value: number) => `${(value * 100).toFixed(1)}%`
  }
};

const TrendAnalysisChart: React.FC<TrendAnalysisChartProps> = ({
  projectId,
  initialTimeRange = '30d',
  metrics = ['qualityScore', 'totalViolations'],
  showPrediction = true,
  compact = false
}) => {
  const theme = useTheme();
  const [timeRange, setTimeRange] = useState<TimeRange>(initialTimeRange);
  const [chartType, setChartType] = useState<'line' | 'area' | 'composed'>('line');
  const [showMovingAverage, setShowMovingAverage] = useState(false);

  // Simple test - render only one metric to isolate the issue
  const testMetrics = ['qualityScore'];
  const testValidMetrics = testMetrics.filter(metric => METRIC_CONFIGS[metric as keyof typeof METRIC_CONFIGS]);

  // Use only valid metrics that have configurations
  // const validMetrics = useMemo(() => {
  //   return metrics.filter(metric => METRIC_CONFIGS[metric as keyof typeof METRIC_CONFIGS]);
  // }, [metrics]);

  const { data: trendData, isLoading, error } = useTrendData(projectId, timeRange, testValidMetrics);

  const chartData = useMemo(() => {
    if (!trendData) return null;

    const processedData = trendData.dataPoints.map((point, index) => {
      const processed: any = {
        date: point.date,
        timestamp: new Date(point.date).getTime(),
        ...point.metrics
      };

      // Add moving averages if enabled
      if (showMovingAverage) {
        testValidMetrics.forEach(metric => {
          const values = trendData.dataPoints.slice(Math.max(0, index - 6), index + 1)
            .map(p => p.metrics[metric] || 0);
          processed[`${metric}_ma`] = values.reduce((a, b) => a + b, 0) / values.length;
        });
      }

      return processed;
    });

    // Add predictions if available and enabled
    if (showPrediction && trendData.predictions) {
      trendData.predictions.forEach(prediction => {
        processedData.push({
          date: prediction.date,
          timestamp: new Date(prediction.date).getTime(),
          ...prediction.metrics,
          isPrediction: true
        });
      });
    }

    return {
      data: processedData,
      trends: trendData.trends,
      anomalies: trendData.anomalies || []
    };
  }, [trendData, testValidMetrics, showMovingAverage, showPrediction]);

  // Debug logging to identify the issue
  console.log('TrendAnalysisChart Debug:', {
    testValidMetrics,
    availableConfigs: Object.keys(METRIC_CONFIGS),
    trendData: !!trendData,
    chartData: chartData ? {
      dataLength: chartData.data.length,
      firstDataPoint: chartData.data[0],
      availableKeys: Object.keys(chartData.data[0] || {}),
      metricKeys: testValidMetrics.map(m => ({ metric: m, hasData: m in (chartData.data[0] || {}) }))
    } : null
  });

  const formatDate = (date: Date, format: 'short' | 'full' = 'full') => {
    if (format === 'short') {
      return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
    }
    return date.toLocaleDateString('en-US', { 
      year: 'numeric', 
      month: 'short', 
      day: 'numeric' 
    });
  };

  if (isLoading) {
    return (
      <Card>
        <CardContent>
          <Typography>Loading trend data...</Typography>
        </CardContent>
      </Card>
    );
  }

  if (error || !chartData) {
    return (
      <Card>
        <CardContent>
          <Typography color="error">Error loading trend data: {error?.message || 'Unknown error'}</Typography>
        </CardContent>
      </Card>
    );
  }

  if (testValidMetrics.length === 0) {
    return (
      <Card>
        <CardContent>
          <Typography color="error">No valid metrics available</Typography>
        </CardContent>
      </Card>
    );
  }

  // Check if chart data contains the expected metric keys
  if (chartData && chartData.data.length > 0) {
    const firstDataPoint = chartData.data[0];
    const missingKeys = testValidMetrics.filter(metric => !(metric in firstDataPoint));
    if (missingKeys.length > 0) {
      console.warn('Missing metric keys in chart data:', missingKeys);
      return (
        <Card>
          <CardContent>
            <Typography color="error">
              Missing data for metrics: {missingKeys.join(', ')}
            </Typography>
          </CardContent>
        </Card>
      );
    }
  }

  const renderChart = () => {
    const commonProps = {
      data: chartData.data,
      margin: { top: 20, right: 30, left: 20, bottom: 5 }
    };

    // Minimal test - render only one line without yAxisId
    const testLine = (
      <Line
        type="monotone"
        dataKey="qualityScore"
        stroke="#1976d2"
        strokeWidth={2}
        dot={{ r: 3 }}
        connectNulls={false}
        name="Quality Score"
      />
    );

    return (
      <LineChart {...commonProps}>
        <CartesianGrid strokeDasharray="3 3" />
        <XAxis
          dataKey="timestamp"
          type="number"
          scale="time"
          tickFormatter={(value) => formatDate(new Date(value), 'short')}
        />
        <YAxis />
        <RechartsTooltip
          labelFormatter={(value) => formatDate(new Date(value))}
          formatter={(value: any, name: string) => [value, name]}
        />
        <Legend />
        {testLine}
        <Brush
          dataKey="timestamp"
          height={30}
          stroke={theme.palette.primary.main}
          tickFormatter={(value) => formatDate(new Date(value), 'short')}
        />
      </LineChart>
    );
  };

  return (
    <motion.div
      initial={{ opacity: 0, y: 20 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.5 }}
    >
      <Card>
        <CardContent>
          {/* Header Controls */}
          <Box sx={{ mb: 3 }}>
            <Grid container spacing={2} alignItems="center" justifyContent="space-between">
              <Grid item>
                <Typography variant="h6">
                  Trend Analysis
                  {!compact && (
                    <Typography variant="body2" color="textSecondary" component="span" sx={{ ml: 1 }}>
                      ({timeRange})
                    </Typography>
                  )}
                </Typography>
              </Grid>
              <Grid item>
                <Box sx={{ display: 'flex', gap: 1, alignItems: 'center' }}>
                  {/* Time Range Selector */}
                  <FormControl size="small" sx={{ minWidth: 120 }}>
                    <InputLabel>Time Range</InputLabel>
                    <Select
                      value={timeRange}
                      label="Time Range"
                      onChange={(e) => setTimeRange(e.target.value as TimeRange)}
                    >
                      <MenuItem value="7d">7 Days</MenuItem>
                      <MenuItem value="30d">30 Days</MenuItem>
                      <MenuItem value="90d">90 Days</MenuItem>
                      <MenuItem value="1y">1 Year</MenuItem>
                    </Select>
                  </FormControl>

                  {/* Chart Type Selector */}
                  <ButtonGroup size="small">
                    <Button
                      variant={chartType === 'line' ? 'contained' : 'outlined'}
                      onClick={() => setChartType('line')}
                    >
                      Line
                    </Button>
                    <Button
                      variant={chartType === 'area' ? 'contained' : 'outlined'}
                      onClick={() => setChartType('area')}
                    >
                      Area
                    </Button>
                    <Button
                      variant={chartType === 'composed' ? 'contained' : 'outlined'}
                      onClick={() => setChartType('composed')}
                    >
                      Composed
                    </Button>
                  </ButtonGroup>

                  {/* Moving Average Toggle */}
                  <FormControlLabel
                    control={
                      <Switch
                        checked={showMovingAverage}
                        onChange={(e) => setShowMovingAverage(e.target.checked)}
                        size="small"
                      />
                    }
                    label="MA"
                    sx={{ ml: 1 }}
                  />
                </Box>
              </Grid>
            </Grid>
          </Box>

          {/* Chart */}
          <Box sx={{ height: compact ? 300 : 400, width: '100%' }}>
            {renderChart()}
          </Box>
        </CardContent>
      </Card>
    </motion.div>
  );
};

export default TrendAnalysisChart; 
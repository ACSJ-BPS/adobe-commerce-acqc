import React, { useState } from 'react';
import {
  Box,
  Paper,
  Typography,
  Grid,
  Card,
  CardContent,
  Select,
  MenuItem,
  FormControl,
  InputLabel,
  ToggleButtonGroup,
  ToggleButton,
  Chip,
  Stack
} from '@mui/material';
import {
  TrendingUp,
  TrendingDown,
  TrendingFlat,
  Warning,
  CheckCircle,
  Error,
  Info
} from '@mui/icons-material';
import TrendAnalysisChart from '../components/TrendAnalysisChart';
import QualityOverviewChart from '../components/QualityOverviewChart';
import ViolationDistributionChart from '../components/ViolationDistributionChart';
import TeamPerformanceChart from '../components/TeamPerformanceChart';
import RiskAssessmentMatrix from '../components/RiskAssessmentMatrix';

const AnalyticsPage: React.FC = () => {
  const [timeRange, setTimeRange] = useState('30d');
  const [viewType, setViewType] = useState<string>('overview');

  const metrics = [
    {
      label: 'Overall Quality Score',
      value: 87,
      change: +5.2,
      trend: 'up',
      icon: <TrendingUp />,
      color: 'success'
    },
    {
      label: 'Critical Issues',
      value: 12,
      change: -3,
      trend: 'down',
      icon: <TrendingDown />,
      color: 'success'
    },
    {
      label: 'Code Coverage',
      value: '78%',
      change: +2.5,
      trend: 'up',
      icon: <TrendingUp />,
      color: 'info'
    },
    {
      label: 'Technical Debt',
      value: '156h',
      change: 0,
      trend: 'flat',
      icon: <TrendingFlat />,
      color: 'warning'
    }
  ];

  const insights = [
    {
      type: 'success',
      icon: <CheckCircle />,
      title: 'Performance Improving',
      description: 'Code quality has improved by 5.2% over the last 30 days'
    },
    {
      type: 'warning',
      icon: <Warning />,
      title: 'Security Attention Needed',
      description: '8 medium-priority security issues require attention'
    },
    {
      type: 'error',
      icon: <Error />,
      title: 'Critical Issues Present',
      description: '3 critical issues in authentication module need immediate fix'
    },
    {
      type: 'info',
      icon: <Info />,
      title: 'Upgrade Recommended',
      description: 'Consider upgrading to PHP 8.3 for better performance'
    }
  ];

  const getTrendIcon = (trend: string) => {
    switch (trend) {
      case 'up':
        return <TrendingUp />;
      case 'down':
        return <TrendingDown />;
      default:
        return <TrendingFlat />;
    }
  };

  const getTrendColor = (trend: string, change: number) => {
    if (trend === 'flat') return 'text.secondary';
    // For metrics like "Critical Issues", down is good
    if (change < 0) return 'success.main';
    return 'error.main';
  };

  return (
    <Box sx={{ p: 3 }}>
      <Box sx={{ mb: 4, display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
        <Typography variant="h4" component="h1">
          Advanced Analytics
        </Typography>

        <Box sx={{ display: 'flex', gap: 2 }}>
          <FormControl size="small" sx={{ minWidth: 120 }}>
            <InputLabel>Time Range</InputLabel>
            <Select
              value={timeRange}
              label="Time Range"
              onChange={(e) => setTimeRange(e.target.value)}
            >
              <MenuItem value="7d">Last 7 Days</MenuItem>
              <MenuItem value="30d">Last 30 Days</MenuItem>
              <MenuItem value="90d">Last 90 Days</MenuItem>
              <MenuItem value="1y">Last Year</MenuItem>
            </Select>
          </FormControl>

          <ToggleButtonGroup
            value={viewType}
            exclusive
            onChange={(e, newValue) => newValue && setViewType(newValue)}
            size="small"
          >
            <ToggleButton value="overview">Overview</ToggleButton>
            <ToggleButton value="trends">Trends</ToggleButton>
            <ToggleButton value="team">Team</ToggleButton>
            <ToggleButton value="risks">Risks</ToggleButton>
          </ToggleButtonGroup>
        </Box>
      </Box>

      {/* Key Metrics */}
      <Grid container spacing={3} sx={{ mb: 4 }}>
        {metrics.map((metric, index) => (
          <Grid item xs={12} sm={6} md={3} key={index}>
            <Card>
              <CardContent>
                <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'start' }}>
                  <Box>
                    <Typography variant="caption" color="text.secondary">
                      {metric.label}
                    </Typography>
                    <Typography variant="h4" sx={{ mt: 1, mb: 0.5 }}>
                      {metric.value}
                    </Typography>
                    <Box sx={{ display: 'flex', alignItems: 'center', gap: 0.5 }}>
                      <Box sx={{ color: getTrendColor(metric.trend, metric.change), display: 'flex', alignItems: 'center' }}>
                        {getTrendIcon(metric.trend)}
                        <Typography variant="body2" sx={{ ml: 0.5 }}>
                          {metric.change > 0 ? '+' : ''}{metric.change}%
                        </Typography>
                      </Box>
                    </Box>
                  </Box>
                  <Chip
                    icon={metric.icon}
                    label={metric.trend}
                    color={metric.color as any}
                    size="small"
                  />
                </Box>
              </CardContent>
            </Card>
          </Grid>
        ))}
      </Grid>

      {/* Main Content Area */}
      <Grid container spacing={3}>
        {/* Left Column - Charts */}
        <Grid item xs={12} md={8}>
          <Stack spacing={3}>
            {(viewType === 'overview' || viewType === 'trends') && (
              <Paper sx={{ p: 3 }}>
                <Typography variant="h6" sx={{ mb: 2 }}>
                  Quality Trends
                </Typography>
                <TrendAnalysisChart />
              </Paper>
            )}

            {(viewType === 'overview') && (
              <Paper sx={{ p: 3 }}>
                <Typography variant="h6" sx={{ mb: 2 }}>
                  Violation Distribution
                </Typography>
                <ViolationDistributionChart />
              </Paper>
            )}

            {(viewType === 'team') && (
              <Paper sx={{ p: 3 }}>
                <Typography variant="h6" sx={{ mb: 2 }}>
                  Team Performance
                </Typography>
                <TeamPerformanceChart />
              </Paper>
            )}

            {(viewType === 'risks') && (
              <Paper sx={{ p: 3 }}>
                <Typography variant="h6" sx={{ mb: 2 }}>
                  Risk Assessment Matrix
                </Typography>
                <RiskAssessmentMatrix />
              </Paper>
            )}
          </Stack>
        </Grid>

        {/* Right Column - Insights */}
        <Grid item xs={12} md={4}>
          <Stack spacing={2}>
            <Typography variant="h6">
              Key Insights
            </Typography>

            {insights.map((insight, index) => (
              <Card key={index}>
                <CardContent>
                  <Box sx={{ display: 'flex', gap: 2 }}>
                    <Box sx={{ color: `${insight.type}.main`, display: 'flex', alignItems: 'start', pt: 0.5 }}>
                      {insight.icon}
                    </Box>
                    <Box>
                      <Typography variant="subtitle2" sx={{ mb: 0.5 }}>
                        {insight.title}
                      </Typography>
                      <Typography variant="body2" color="text.secondary">
                        {insight.description}
                      </Typography>
                    </Box>
                  </Box>
                </CardContent>
              </Card>
            ))}

            <Paper sx={{ p: 2, bgcolor: 'primary.main', color: 'primary.contrastText' }}>
              <Typography variant="subtitle2" sx={{ mb: 1 }}>
                💡 Pro Tip
              </Typography>
              <Typography variant="body2">
                Focus on fixing critical security issues first to improve your overall quality score by up to 15%.
              </Typography>
            </Paper>
          </Stack>
        </Grid>
      </Grid>
    </Box>
  );
};

export default AnalyticsPage;


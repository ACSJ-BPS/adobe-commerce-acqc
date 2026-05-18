import React, { useMemo } from 'react';
import {
  Box,
  Card,
  CardContent,
  Typography,
  Grid,
  Avatar,
  Chip,
  LinearProgress,
  useTheme,
  alpha
} from '@mui/material';
import {
  BarChart,
  Bar,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  Legend,
  ResponsiveContainer,
  RadarChart,
  PolarGrid,
  PolarAngleAxis,
  PolarRadiusAxis,
  Radar,
  LineChart,
  Line
} from 'recharts';
import { motion } from 'framer-motion';
import {
  TrendingUp,
  TrendingDown,
  Group,
  Speed,
  Security,
  Code
} from '@mui/icons-material';

interface TeamMetrics {
  teamId: string;
  teamName: string;
  members: number;
  metrics: {
    codeVelocity: number;
    qualityRate: number;
    defectRate: number;
    reviewEfficiency: number;
    knowledgeSharing: number;
    innovation: number;
  };
  trends: {
    [metric: string]: {
      direction: 'up' | 'down' | 'stable';
      change: number;
    };
  };
  ranking: number;
}

interface TeamPerformanceChartProps {
  projectId?: string;
  timeRange?: string;
  compact?: boolean;
}

// Mock data - replace with actual API call
const mockTeamData: TeamMetrics[] = [
  {
    teamId: 'team-1',
    teamName: 'Frontend Team',
    members: 8,
    metrics: {
      codeVelocity: 85,
      qualityRate: 92,
      defectRate: 12,
      reviewEfficiency: 88,
      knowledgeSharing: 75,
      innovation: 80
    },
    trends: {
      codeVelocity: { direction: 'up', change: 5.2 },
      qualityRate: { direction: 'up', change: 2.1 },
      defectRate: { direction: 'down', change: -8.5 }
    },
    ranking: 1
  },
  {
    teamId: 'team-2',
    teamName: 'Backend Team',
    members: 6,
    metrics: {
      codeVelocity: 78,
      qualityRate: 89,
      defectRate: 15,
      reviewEfficiency: 82,
      knowledgeSharing: 70,
      innovation: 75
    },
    trends: {
      codeVelocity: { direction: 'stable', change: 0.5 },
      qualityRate: { direction: 'up', change: 1.8 },
      defectRate: { direction: 'down', change: -5.2 }
    },
    ranking: 2
  },
  {
    teamId: 'team-3',
    teamName: 'DevOps Team',
    members: 4,
    metrics: {
      codeVelocity: 90,
      qualityRate: 95,
      defectRate: 8,
      reviewEfficiency: 95,
      knowledgeSharing: 85,
      innovation: 90
    },
    trends: {
      codeVelocity: { direction: 'up', change: 7.8 },
      qualityRate: { direction: 'up', change: 3.2 },
      defectRate: { direction: 'down', change: -12.1 }
    },
    ranking: 3
  }
];

const TeamPerformanceChart: React.FC<TeamPerformanceChartProps> = ({
  projectId,
  timeRange = '30d',
  compact = false
}) => {
  const theme = useTheme();

  const chartData = useMemo(() => {
    // Radar chart data for team comparison
    const radarData = mockTeamData.map(team => ({
      team: team.teamName,
      'Code Velocity': team.metrics.codeVelocity,
      'Quality Rate': team.metrics.qualityRate,
      'Review Efficiency': team.metrics.reviewEfficiency,
      'Knowledge Sharing': team.metrics.knowledgeSharing,
      'Innovation': team.metrics.innovation
    }));

    // Bar chart data for defect rates
    const defectData = mockTeamData.map(team => ({
      team: team.teamName,
      'Defect Rate': team.metrics.defectRate,
      'Quality Rate': team.metrics.qualityRate
    }));

    // Line chart data for trends
    const trendData = mockTeamData.map(team => ({
      team: team.teamName,
      'Velocity Trend': team.trends.codeVelocity?.change || 0,
      'Quality Trend': team.trends.qualityRate?.change || 0,
      'Defect Trend': team.trends.defectRate?.change || 0
    }));

    return { radarData, defectData, trendData };
  }, []);

  const getTrendIcon = (direction: 'up' | 'down' | 'stable') => {
    switch (direction) {
      case 'up':
        return <TrendingUp color="success" />;
      case 'down':
        return <TrendingDown color="error" />;
      default:
        return <TrendingUp color="disabled" />;
    }
  };

  const getTrendColor = (direction: 'up' | 'down' | 'stable') => {
    switch (direction) {
      case 'up':
        return theme.palette.success.main;
      case 'down':
        return theme.palette.error.main;
      default:
        return theme.palette.grey[500];
    }
  };

  if (compact) {
    return (
      <Card>
        <CardContent>
          <Typography variant="h6" gutterBottom>
            Team Performance
          </Typography>
          <Box sx={{ height: 200 }}>
            <ResponsiveContainer width="100%" height="100%">
              <BarChart data={chartData.defectData}>
                <CartesianGrid strokeDasharray="3 3" />
                <XAxis dataKey="team" />
                <YAxis />
                <Tooltip />
                <Legend />
                <Bar dataKey="Quality Rate" fill={theme.palette.primary.main} />
                <Bar dataKey="Defect Rate" fill={theme.palette.error.main} />
              </BarChart>
            </ResponsiveContainer>
          </Box>
        </CardContent>
      </Card>
    );
  }

  return (
    <motion.div
      initial={{ opacity: 0, y: 20 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.5 }}
    >
      <Card>
        <CardContent>
          <Typography variant="h5" gutterBottom>
            Team Performance Overview
          </Typography>
          
          <Grid container spacing={3}>
            {/* Team Rankings */}
            <Grid item xs={12} md={4}>
              <Typography variant="h6" gutterBottom>
                Team Rankings
              </Typography>
              {mockTeamData.map((team, index) => (
                <Box key={team.teamId} sx={{ mb: 2, p: 2, border: 1, borderColor: 'divider', borderRadius: 1 }}>
                  <Box sx={{ display: 'flex', alignItems: 'center', mb: 1 }}>
                    <Avatar sx={{ mr: 2, bgcolor: index === 0 ? 'gold' : index === 1 ? 'silver' : 'bronze' }}>
                      {team.ranking}
                    </Avatar>
                    <Box sx={{ flex: 1 }}>
                      <Typography variant="subtitle1">{team.teamName}</Typography>
                      <Typography variant="body2" color="textSecondary">
                        {team.members} members
                      </Typography>
                    </Box>
                  </Box>
                  
                  <Box sx={{ display: 'flex', gap: 1, flexWrap: 'wrap' }}>
                    {Object.entries(team.trends).map(([metric, trend]) => (
                      <Chip
                        key={metric}
                        icon={getTrendIcon(trend.direction)}
                        label={`${metric}: ${trend.change > 0 ? '+' : ''}${trend.change.toFixed(1)}%`}
                        size="small"
                        color={trend.direction === 'up' ? 'success' : trend.direction === 'down' ? 'error' : 'default'}
                        variant="outlined"
                      />
                    ))}
                  </Box>
                </Box>
              ))}
            </Grid>

            {/* Radar Chart */}
            <Grid item xs={12} md={8}>
              <Typography variant="h6" gutterBottom>
                Team Capabilities Comparison
              </Typography>
              <Box sx={{ height: 300 }}>
                <ResponsiveContainer width="100%" height="100%">
                  <RadarChart data={chartData.radarData}>
                    <PolarGrid />
                    <PolarAngleAxis dataKey="team" />
                    <PolarRadiusAxis />
                    <Radar
                      name="Frontend Team"
                      dataKey="Frontend Team"
                      stroke={theme.palette.primary.main}
                      fill={alpha(theme.palette.primary.main, 0.3)}
                    />
                    <Radar
                      name="Backend Team"
                      dataKey="Backend Team"
                      stroke={theme.palette.secondary.main}
                      fill={alpha(theme.palette.secondary.main, 0.3)}
                    />
                    <Radar
                      name="DevOps Team"
                      dataKey="DevOps Team"
                      stroke={theme.palette.success.main}
                      fill={alpha(theme.palette.success.main, 0.3)}
                    />
                    <Legend />
                  </RadarChart>
                </ResponsiveContainer>
              </Box>
            </Grid>

            {/* Performance Metrics */}
            <Grid item xs={12}>
              <Typography variant="h6" gutterBottom>
                Performance Metrics
              </Typography>
              <Box sx={{ height: 300 }}>
                <ResponsiveContainer width="100%" height="100%">
                  <LineChart data={chartData.trendData}>
                    <CartesianGrid strokeDasharray="3 3" />
                    <XAxis dataKey="team" />
                    <YAxis />
                    <Tooltip />
                    <Legend />
                    <Line
                      type="monotone"
                      dataKey="Velocity Trend"
                      stroke={theme.palette.primary.main}
                      strokeWidth={2}
                    />
                    <Line
                      type="monotone"
                      dataKey="Quality Trend"
                      stroke={theme.palette.success.main}
                      strokeWidth={2}
                    />
                    <Line
                      type="monotone"
                      dataKey="Defect Trend"
                      stroke={theme.palette.error.main}
                      strokeWidth={2}
                    />
                  </LineChart>
                </ResponsiveContainer>
              </Box>
            </Grid>
          </Grid>
        </CardContent>
      </Card>
    </motion.div>
  );
};

export default TeamPerformanceChart; 
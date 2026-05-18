import React, { useMemo } from 'react';
import {
  Box,
  Card,
  CardContent,
  Typography,
  Grid,
  Chip,
  Table,
  TableBody,
  TableCell,
  TableContainer,
  TableHead,
  TableRow,
  Paper,
  useTheme,
  alpha,
  LinearProgress
} from '@mui/material';
import {
  ScatterChart,
  Scatter,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  ResponsiveContainer,
  Cell
} from 'recharts';
import { motion } from 'framer-motion';
import {
  Warning,
  Error,
  CheckCircle,
  Info,
  TrendingUp,
  TrendingDown
} from '@mui/icons-material';

interface RiskItem {
  id: string;
  name: string;
  probability: number; // 0-100
  impact: number; // 0-100
  severity: 'low' | 'medium' | 'high' | 'critical';
  category: 'security' | 'performance' | 'maintainability' | 'business' | 'technical';
  description: string;
  mitigation: string;
  status: 'active' | 'mitigated' | 'monitoring';
  lastUpdated: string;
}

interface RiskAssessmentMatrixProps {
  projectId?: string;
  compact?: boolean;
  timeRange?: string;
}

// Mock data - replace with actual API call
const mockRiskData: RiskItem[] = [
  {
    id: 'risk-1',
    name: 'SQL Injection Vulnerability',
    probability: 75,
    impact: 90,
    severity: 'critical',
    category: 'security',
    description: 'Potential SQL injection in user authentication module',
    mitigation: 'Implement parameterized queries and input validation',
    status: 'active',
    lastUpdated: '2024-01-15'
  },
  {
    id: 'risk-2',
    name: 'Memory Leak in Image Processing',
    probability: 45,
    impact: 60,
    severity: 'high',
    category: 'performance',
    description: 'Memory not properly released in image upload functionality',
    mitigation: 'Add proper cleanup and memory management',
    status: 'monitoring',
    lastUpdated: '2024-01-10'
  },
  {
    id: 'risk-3',
    name: 'Deprecated API Usage',
    probability: 85,
    impact: 40,
    severity: 'medium',
    category: 'technical',
    description: 'Using deprecated payment gateway API',
    mitigation: 'Migrate to new API version',
    status: 'mitigated',
    lastUpdated: '2024-01-05'
  },
  {
    id: 'risk-4',
    name: 'Code Duplication',
    probability: 60,
    impact: 30,
    severity: 'medium',
    category: 'maintainability',
    description: 'High code duplication in utility functions',
    mitigation: 'Refactor common code into shared utilities',
    status: 'active',
    lastUpdated: '2024-01-12'
  },
  {
    id: 'risk-5',
    name: 'Third-party Service Dependency',
    probability: 30,
    impact: 80,
    severity: 'high',
    category: 'business',
    description: 'Critical dependency on external email service',
    mitigation: 'Implement fallback mechanisms and monitoring',
    status: 'monitoring',
    lastUpdated: '2024-01-08'
  }
];

export const RiskAssessmentMatrix: React.FC<RiskAssessmentMatrixProps> = ({
  projectId,
  compact = false
}) => {
  const theme = useTheme();

  const chartData = useMemo(() => {
    return mockRiskData.map(risk => ({
      name: risk.name,
      probability: risk.probability,
      impact: risk.impact,
      severity: risk.severity,
      category: risk.category,
      status: risk.status
    }));
  }, []);

  const getSeverityColor = (severity: string) => {
    switch (severity) {
      case 'critical':
        return theme.palette.error.main;
      case 'high':
        return theme.palette.warning.main;
      case 'medium':
        return theme.palette.info.main;
      case 'low':
        return theme.palette.success.main;
      default:
        return theme.palette.grey[500];
    }
  };

  const getStatusColor = (status: string) => {
    switch (status) {
      case 'active':
        return theme.palette.error.main;
      case 'mitigated':
        return theme.palette.success.main;
      case 'monitoring':
        return theme.palette.warning.main;
      default:
        return theme.palette.grey[500];
    }
  };

  const getCategoryIcon = (category: string) => {
    switch (category) {
      case 'security':
        return <Error />;
      case 'performance':
        return <TrendingUp />;
      case 'maintainability':
        return <Info />;
      case 'business':
        return <Warning />;
      case 'technical':
        return <CheckCircle />;
      default:
        return <Info />;
    }
  };

  const riskSummary = useMemo(() => {
    const total = mockRiskData.length;
    const bySeverity = mockRiskData.reduce((acc, risk) => {
      acc[risk.severity] = (acc[risk.severity] || 0) + 1;
      return acc;
    }, {} as Record<string, number>);
    
    const byStatus = mockRiskData.reduce((acc, risk) => {
      acc[risk.status] = (acc[risk.status] || 0) + 1;
      return acc;
    }, {} as Record<string, number>);

    return { total, bySeverity, byStatus };
  }, []);

  if (compact) {
    return (
      <Card>
        <CardContent>
          <Typography variant="h6" gutterBottom>
            Risk Assessment
          </Typography>
          <Box sx={{ height: 200 }}>
            <ResponsiveContainer width="100%" height="100%">
              <ScatterChart>
                <CartesianGrid strokeDasharray="3 3" />
                <XAxis 
                  type="number" 
                  dataKey="probability" 
                  name="Probability" 
                  domain={[0, 100]}
                  label={{ value: 'Probability (%)', position: 'insideBottom', offset: -10 }}
                />
                <YAxis 
                  type="number" 
                  dataKey="impact" 
                  name="Impact" 
                  domain={[0, 100]}
                  label={{ value: 'Impact (%)', angle: -90, position: 'insideLeft' }}
                />
                <Tooltip 
                  formatter={(value: any, name: any) => [value, name]}
                  labelFormatter={(label) => `Risk: ${label}`}
                />
                <Scatter data={chartData} fill={theme.palette.primary.main}>
                  {chartData.map((entry, index) => (
                    <Cell key={`cell-${index}`} fill={getSeverityColor(entry.severity)} />
                  ))}
                </Scatter>
              </ScatterChart>
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
            Risk Assessment Matrix
          </Typography>
          
          <Grid container spacing={3}>
            {/* Risk Summary */}
            <Grid item xs={12} md={4}>
              <Typography variant="h6" gutterBottom>
                Risk Summary
              </Typography>
              
              <Box sx={{ mb: 3 }}>
                <Typography variant="subtitle1" gutterBottom>
                  Total Risks: {riskSummary.total}
                </Typography>
                
                <Typography variant="subtitle2" gutterBottom>
                  By Severity:
                </Typography>
                <Box sx={{ display: 'flex', gap: 1, flexWrap: 'wrap', mb: 2 }}>
                  {Object.entries(riskSummary.bySeverity).map(([severity, count]) => (
                    <Chip
                      key={severity}
                      label={`${severity}: ${count}`}
                      color={severity === 'critical' ? 'error' : 
                             severity === 'high' ? 'warning' : 
                             severity === 'medium' ? 'info' : 'success'}
                      size="small"
                    />
                  ))}
                </Box>
                
                <Typography variant="subtitle2" gutterBottom>
                  By Status:
                </Typography>
                <Box sx={{ display: 'flex', gap: 1, flexWrap: 'wrap' }}>
                  {Object.entries(riskSummary.byStatus).map(([status, count]) => (
                    <Chip
                      key={status}
                      label={`${status}: ${count}`}
                      color={status === 'active' ? 'error' : 
                             status === 'mitigated' ? 'success' : 'warning'}
                      size="small"
                    />
                  ))}
                </Box>
              </Box>
            </Grid>

            {/* Risk Matrix Chart */}
            <Grid item xs={12} md={8}>
              <Typography variant="h6" gutterBottom>
                Risk Matrix (Probability vs Impact)
              </Typography>
              <Box sx={{ height: 400 }}>
                <ResponsiveContainer width="100%" height="100%">
                  <ScatterChart>
                    <CartesianGrid strokeDasharray="3 3" />
                    <XAxis 
                      type="number" 
                      dataKey="probability" 
                      name="Probability" 
                      domain={[0, 100]}
                      label={{ value: 'Probability (%)', position: 'insideBottom', offset: -10 }}
                    />
                    <YAxis 
                      type="number" 
                      dataKey="impact" 
                      name="Impact" 
                      domain={[0, 100]}
                      label={{ value: 'Impact (%)', angle: -90, position: 'insideLeft' }}
                    />
                    <Tooltip 
                      formatter={(value: any, name: any) => [value, name]}
                      labelFormatter={(label) => `Risk: ${label}`}
                    />
                    <Scatter data={chartData} fill={theme.palette.primary.main}>
                      {chartData.map((entry, index) => (
                        <Cell key={`cell-${index}`} fill={getSeverityColor(entry.severity)} />
                      ))}
                    </Scatter>
                  </ScatterChart>
                </ResponsiveContainer>
              </Box>
            </Grid>

            {/* Risk Details Table */}
            <Grid item xs={12}>
              <Typography variant="h6" gutterBottom>
                Risk Details
              </Typography>
              <TableContainer component={Paper}>
                <Table>
                  <TableHead>
                    <TableRow>
                      <TableCell>Risk Name</TableCell>
                      <TableCell>Category</TableCell>
                      <TableCell>Probability</TableCell>
                      <TableCell>Impact</TableCell>
                      <TableCell>Severity</TableCell>
                      <TableCell>Status</TableCell>
                      <TableCell>Last Updated</TableCell>
                    </TableRow>
                  </TableHead>
                  <TableBody>
                    {mockRiskData.map((risk) => (
                      <TableRow key={risk.id}>
                        <TableCell>
                          <Typography variant="subtitle2">{risk.name}</Typography>
                          <Typography variant="body2" color="textSecondary">
                            {risk.description}
                          </Typography>
                        </TableCell>
                        <TableCell>
                          <Chip
                            icon={getCategoryIcon(risk.category)}
                            label={risk.category}
                            size="small"
                            variant="outlined"
                          />
                        </TableCell>
                        <TableCell>
                          <Box sx={{ display: 'flex', alignItems: 'center' }}>
                            <Box sx={{ width: '100%', mr: 1 }}>
                              <LinearProgress
                                variant="determinate"
                                value={risk.probability}
                                sx={{ height: 8, borderRadius: 5 }}
                              />
                            </Box>
                            <Typography variant="body2">
                              {risk.probability}%
                            </Typography>
                          </Box>
                        </TableCell>
                        <TableCell>
                          <Box sx={{ display: 'flex', alignItems: 'center' }}>
                            <Box sx={{ width: '100%', mr: 1 }}>
                              <LinearProgress
                                variant="determinate"
                                value={risk.impact}
                                sx={{ height: 8, borderRadius: 5 }}
                              />
                            </Box>
                            <Typography variant="body2">
                              {risk.impact}%
                            </Typography>
                          </Box>
                        </TableCell>
                        <TableCell>
                          <Chip
                            label={risk.severity}
                            color={risk.severity === 'critical' ? 'error' : 
                                   risk.severity === 'high' ? 'warning' : 
                                   risk.severity === 'medium' ? 'info' : 'success'}
                            size="small"
                          />
                        </TableCell>
                        <TableCell>
                          <Chip
                            label={risk.status}
                            color={risk.status === 'active' ? 'error' : 
                                   risk.status === 'mitigated' ? 'success' : 'warning'}
                            size="small"
                          />
                        </TableCell>
                        <TableCell>
                          <Typography variant="body2">
                            {new Date(risk.lastUpdated).toLocaleDateString()}
                          </Typography>
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </TableContainer>
            </Grid>
          </Grid>
        </CardContent>
      </Card>
    </motion.div>
  );
};

export default RiskAssessmentMatrix; 
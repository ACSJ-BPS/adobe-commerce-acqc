// eslint-disable-next-line @typescript-eslint/ban-ts-comment
// @ts-nocheck
import React, { useState, useMemo } from 'react';
import {
  Box,
  Card,
  CardContent,
  Typography,
  FormControl,
  InputLabel,
  Select,
  MenuItem,
  Chip,
  Grid,
  ButtonGroup,
  Button,
  Table,
  TableBody,
  TableCell,
  TableContainer,
  TableHead,
  TableRow,
  TablePagination,
  IconButton,
  Collapse,
  Badge,
  useTheme,
  alpha
} from '@mui/material';
import {
  PieChart,
  Pie,
  Cell,
  ResponsiveContainer,
  BarChart,
  Bar,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  Legend,
  Treemap} from 'recharts';
import {
  ExpandMore,
  ExpandLess,
  FilterList,
  ViewModule,
  TableChart,
  PieChartOutlined,
  BarChart as BarChartIcon,
  Download
} from '@mui/icons-material';
import { motion, AnimatePresence } from 'framer-motion';
import { useViolationData } from '../hooks/useViolationData';
import { ViolationData, SeverityLevel } from '../types/violations';
import { formatNumber, getSeverityColor, getCategoryIcon } from '../utils/formatters';

interface ViolationDistributionChartProps {
  projectId?: string;
  timeRange?: string;
  groupBy?: 'severity' | 'category' | 'file' | 'rule';
  showDetails?: boolean;
  compact?: boolean;
}

const ViolationDistributionChart: React.FC<ViolationDistributionChartProps> = ({
  projectId,
  timeRange = '30d',
  groupBy = 'severity',
  showDetails = true,
  compact = false
}) => {
  const theme = useTheme();
  const [viewMode, setViewMode] = useState<'pie' | 'bar' | 'treemap' | 'table'>('pie');
  const [selectedGroup, setSelectedGroup] = useState(groupBy);
  const [selectedSeverity, setSelectedSeverity] = useState<SeverityLevel | 'all'>('all');
  const [expandedRows, setExpandedRows] = useState<Set<string>>(new Set());
  const [tablePage, setTablePage] = useState(0);
  const [tableRowsPerPage, setTableRowsPerPage] = useState(10);

  const { data: violationData, isLoading, error } = useViolationData(projectId, timeRange);

  const chartData = useMemo(() => {
    if (!violationData) return null;

    const { violations, summary } = violationData;

    // Filter by selected severity
    const filteredViolations = selectedSeverity === 'all' 
      ? violations 
      : violations.filter(v => v.severity === selectedSeverity);

    // Group violations based on selected grouping
    const grouped = groupViolations(filteredViolations, selectedGroup);
    
    // Prepare data for different chart types
    const pieData = Object.entries(grouped).map(([key, items]) => ({
      name: key,
      value: items.length,
      percentage: (items.length / filteredViolations.length) * 100,
      color: getGroupColor(key, selectedGroup, theme),
      violations: items
    }));

    const barData = pieData.map(item => ({
      ...item,
      label: item.name.length > 15 ? `${item.name.substring(0, 15)}...` : item.name
    }));

    // Treemap data with hierarchical structure
    const treemapData = createTreemapData(filteredViolations, selectedGroup);

    // Table data with expandable rows
    const tableData = createTableData(grouped, selectedGroup);

    return {
      pieData,
      barData,
      treemapData,
      tableData,
      summary: {
        total: filteredViolations.length,
        bySeverity: summary.bySeverity,
        byCategory: summary.byCategory,
        topFiles: summary.topFiles,
        topRules: summary.topRules
      }
    };
  }, [violationData, selectedGroup, selectedSeverity, theme]);

  if (isLoading) {
    return <ViolationDistributionSkeleton compact={compact} />;
  }

  if (error || !chartData) {
    return <ViolationDistributionError error={error} />;
  }

  const renderChart = () => {
    switch (viewMode) {
      case 'pie':
        return (
          <ResponsiveContainer width="100%" height="100%">
            <PieChart>
              <Pie
                data={chartData.pieData}
                cx="50%"
                cy="50%"
                outerRadius={compact ? 60 : 100}
                dataKey="value"
                label={({ name, percentage }) => 
                  compact ? `${percentage.toFixed(0)}%` : `${name}: ${percentage.toFixed(1)}%`
                }
                labelLine={false}
              >
                {chartData.pieData.map((entry, index) => (
                  <Cell key={`cell-${index}`} fill={entry.color} />
                ))}
              </Pie>
              <Tooltip 
                formatter={(value: number, name: string) => [
                  `${value} violations (${((value / chartData.summary.total) * 100).toFixed(1)}%)`,
                  name
                ]}
              />
              {!compact && <Legend />}
            </PieChart>
          </ResponsiveContainer>
        );

      case 'bar':
        return (
          <ResponsiveContainer width="100%" height="100%">
            <BarChart data={chartData.barData} margin={{ top: 20, right: 30, left: 20, bottom: 5 }}>
              <CartesianGrid strokeDasharray="3 3" />
              <XAxis 
                dataKey="label" 
                angle={-45}
                textAnchor="end"
                height={80}
                fontSize={12}
              />
              <YAxis />
              <Tooltip 
                formatter={(value: number) => [`${value} violations`, 'Count']}
                labelFormatter={(label) => {
                  const item = chartData.barData.find(d => d.label === label);
                  return item ? item.name : label;
                }}
              />
              <Bar dataKey="value" radius={[4, 4, 0, 0]}>
                {chartData.barData.map((entry, index) => (
                  <Cell key={`cell-${index}`} fill={entry.color} />
                ))}
              </Bar>
            </BarChart>
          </ResponsiveContainer>
        );

      case 'treemap':
        return (
          <ResponsiveContainer width="100%" height="100%">
            <Treemap
              data={chartData.treemapData}
              dataKey="size"
              aspectRatio={4 / 3}
              stroke={theme.palette.background.paper}
              strokeWidth={2}
            />
          </ResponsiveContainer>
        );

      case 'table':
        return <ViolationTable data={chartData.tableData} />;

      default:
        return null;
    }
  };

  return (
    <motion.div
      initial={{ opacity: 0, y: 20 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.5 }}
    >
      <Card>
        <CardContent>
          {/* Header */}
          <Box sx={{ mb: 3 }}>
            <Grid container spacing={2} alignItems="center" justifyContent="space-between">
              <Grid item>
                <Typography variant="h6">
                  Violation Distribution
                  {!compact && (
                    <Typography variant="body2" color="textSecondary" component="span" sx={{ ml: 1 }}>
                      ({formatNumber(chartData.summary.total)} total)
                    </Typography>
                  )}
                </Typography>
              </Grid>
              <Grid item>
                <Box sx={{ display: 'flex', gap: 1, alignItems: 'center' }}>
                  {/* View Mode Selector */}
                  <ButtonGroup size="small">
                    <Button
                      variant={viewMode === 'pie' ? 'contained' : 'outlined'}
                      onClick={() => setViewMode('pie')}
                      startIcon={<PieChartOutlined />}
                    >
                      Pie
                    </Button>
                    <Button
                      variant={viewMode === 'bar' ? 'contained' : 'outlined'}
                      onClick={() => setViewMode('bar')}
                      startIcon={<BarChartIcon />}
                    >
                      Bar
                    </Button>
                    <Button
                      variant={viewMode === 'treemap' ? 'contained' : 'outlined'}
                      onClick={() => setViewMode('treemap')}
                      startIcon={<ViewModule />}
                    >
                      Tree
                    </Button>
                    <Button
                      variant={viewMode === 'table' ? 'contained' : 'outlined'}
                      onClick={() => setViewMode('table')}
                      startIcon={<TableChart />}
                    >
                      Table
                    </Button>
                  </ButtonGroup>

                  <IconButton size="small" onClick={() => {}}>
                    <Download />
                  </IconButton>
                </Box>
              </Grid>
            </Grid>

            {/* Filters */}
            {!compact && (
              <Box sx={{ mt: 2, display: 'flex', gap: 2, alignItems: 'center' }}>
                <FormControl size="small" sx={{ minWidth: 120 }}>
                  <InputLabel>Group By</InputLabel>
                  <Select
                    value={selectedGroup}
                    label="Group By"
                    onChange={(e) => setSelectedGroup(e.target.value as typeof selectedGroup)}
                  >
                    <MenuItem value="severity">Severity</MenuItem>
                    <MenuItem value="category">Category</MenuItem>
                    <MenuItem value="file">File</MenuItem>
                    <MenuItem value="rule">Rule</MenuItem>
                  </Select>
                </FormControl>

                <FormControl size="small" sx={{ minWidth: 120 }}>
                  <InputLabel>Severity</InputLabel>
                  <Select
                    value={selectedSeverity}
                    label="Severity"
                    onChange={(e) => setSelectedSeverity(e.target.value as SeverityLevel | 'all')}
                  >
                    <MenuItem value="all">All</MenuItem>
                    <MenuItem value="critical">Critical</MenuItem>
                    <MenuItem value="error">Error</MenuItem>
                    <MenuItem value="warning">Warning</MenuItem>
                    <MenuItem value="info">Info</MenuItem>
                  </Select>
                </FormControl>
              </Box>
            )}
          </Box>

          {/* Summary Stats */}
          {showDetails && !compact && (
            <Grid container spacing={2} sx={{ mb: 3 }}>
              {Object.entries(chartData.summary.bySeverity).map(([severity, count]) => (
                <Grid item key={severity}>
                  <Box
                    sx={{
                      p: 1.5,
                      borderRadius: 1,
                      backgroundColor: alpha(getSeverityColor(severity as SeverityLevel, theme), 0.1),
                      border: `1px solid ${alpha(getSeverityColor(severity as SeverityLevel, theme), 0.3)}`
                    }}
                  >
                    <Typography variant="h6" color={getSeverityColor(severity as SeverityLevel, theme)}>
                      {count}
                    </Typography>
                    <Typography variant="body2" color="textSecondary">
                      {severity.charAt(0).toUpperCase() + severity.slice(1)}
                    </Typography>
                  </Box>
                </Grid>
              ))}
            </Grid>
          )}

          {/* Chart */}
          <Box sx={{ height: compact ? 250 : viewMode === 'table' ? 'auto' : 400 }}>
            {renderChart()}
          </Box>

          {/* Top Items */}
          {showDetails && !compact && viewMode !== 'table' && (
            <Box sx={{ mt: 3 }}>
              <Typography variant="subtitle2" gutterBottom>
                Top Items by {selectedGroup.charAt(0).toUpperCase() + selectedGroup.slice(1)}
              </Typography>
              <Grid container spacing={1}>
                {chartData.pieData.slice(0, 5).map((item, index) => (
                  <Grid item key={item.name}>
                    <Chip
                      label={`${item.name}: ${item.value}`}
                      size="small"
                      sx={{
                        backgroundColor: alpha(item.color, 0.1),
                        borderColor: item.color,
                        color: item.color
                      }}
                      variant="outlined"
                    />
                  </Grid>
                ))}
              </Grid>
            </Box>
          )}
        </CardContent>
      </Card>
    </motion.div>
  );
};

// Table component for detailed view
const ViolationTable: React.FC<{ data: any[] }> = ({ data }) => {
  const [page, setPage] = useState(0);
  const [rowsPerPage, setRowsPerPage] = useState(10);
  const [expandedRows, setExpandedRows] = useState<Set<string>>(new Set());

  const handleExpandClick = (id: string) => {
    const newExpanded = new Set(expandedRows);
    if (newExpanded.has(id)) {
      newExpanded.delete(id);
    } else {
      newExpanded.add(id);
    }
    setExpandedRows(newExpanded);
  };

  return (
    <Box>
      <TableContainer>
        <Table size="small">
          <TableHead>
            <TableRow>
              <TableCell />
              <TableCell>Group</TableCell>
              <TableCell align="right">Count</TableCell>
              <TableCell align="right">Percentage</TableCell>
            </TableRow>
          </TableHead>
          <TableBody>
            {data
              .slice(page * rowsPerPage, page * rowsPerPage + rowsPerPage)
              .map((row) => (
                <React.Fragment key={row.id}>
                  <TableRow>
                    <TableCell>
                      <IconButton
                        size="small"
                        onClick={() => handleExpandClick(row.id)}
                      >
                        {expandedRows.has(row.id) ? <ExpandLess /> : <ExpandMore />}
                      </IconButton>
                    </TableCell>
                    <TableCell>
                      <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
                        {getCategoryIcon(row.name)}
                        {row.name}
                      </Box>
                    </TableCell>
                    <TableCell align="right">
                      <Badge badgeContent={row.count} color="primary">
                        <span />
                      </Badge>
                    </TableCell>
                    <TableCell align="right">{row.percentage.toFixed(1)}%</TableCell>
                  </TableRow>
                  <TableRow>
                    <TableCell style={{ paddingBottom: 0, paddingTop: 0 }} colSpan={4}>
                      <Collapse in={expandedRows.has(row.id)} timeout="auto" unmountOnExit>
                        <Box sx={{ margin: 1 }}>
                          <Typography variant="h6" gutterBottom component="div">
                            Details
                          </Typography>
                          <Table size="small">
                            <TableHead>
                              <TableRow>
                                <TableCell>File</TableCell>
                                <TableCell>Rule</TableCell>
                                <TableCell>Severity</TableCell>
                                <TableCell>Line</TableCell>
                              </TableRow>
                            </TableHead>
                            <TableBody>
                              {row.violations.slice(0, 5).map((violation: ViolationData, index: number) => (
                                <TableRow key={index}>
                                  <TableCell>{violation.file}</TableCell>
                                  <TableCell>{violation.rule}</TableCell>
                                  <TableCell>
                                    <Chip
                                      label={violation.severity}
                                      size="small"
                                      color={
                                        violation.severity === 'critical' ? 'error' :
                                        violation.severity === 'error' ? 'warning' :
                                        violation.severity === 'warning' ? 'info' :
                                        'default'
                                      }
                                    />
                                  </TableCell>
                                  <TableCell>{violation.line}</TableCell>
                                </TableRow>
                              ))}
                            </TableBody>
                          </Table>
                        </Box>
                      </Collapse>
                    </TableCell>
                  </TableRow>
                </React.Fragment>
              ))}
          </TableBody>
        </Table>
      </TableContainer>
      <TablePagination
        rowsPerPageOptions={[5, 10, 25]}
        component="div"
        count={data.length}
        rowsPerPage={rowsPerPage}
        page={page}
        onPageChange={(_, newPage) => setPage(newPage)}
        onRowsPerPageChange={(e) => {
          setRowsPerPage(parseInt(e.target.value, 10));
          setPage(0);
        }}
      />
    </Box>
  );
};

// Helper functions
const groupViolations = (violations: ViolationData[], groupBy: string): Record<string, ViolationData[]> => {
  return violations.reduce((groups, violation) => {
    let key: string;
    switch (groupBy) {
      case 'severity':
        key = violation.severity;
        break;
      case 'category':
        key = violation.category || 'Other';
        break;
      case 'file':
        key = violation.file;
        break;
      case 'rule':
        key = violation.rule;
        break;
      default:
        key = 'Unknown';
    }
    
    if (!groups[key]) {
      groups[key] = [];
    }
    groups[key].push(violation);
    return groups;
  }, {} as Record<string, ViolationData[]>);
};

const getGroupColor = (key: string, groupBy: string, theme: any): string => {
  switch (groupBy) {
    case 'severity':
      return getSeverityColor(key as SeverityLevel, theme);
    case 'category':
      const categoryColors: Record<string, string> = {
        'Security': theme.palette.error.main,
        'Performance': theme.palette.warning.main,
        'Maintainability': theme.palette.info.main,
        'Logic': theme.palette.primary.main,
        'Style': theme.palette.secondary.main
      };
      return categoryColors[key] || theme.palette.grey[500];
    default:
      const colors = [
        theme.palette.primary.main,
        theme.palette.secondary.main,
        theme.palette.error.main,
        theme.palette.warning.main,
        theme.palette.info.main,
        theme.palette.success.main
      ];
      return colors[Math.abs(key.split('').reduce((a, b) => a + b.charCodeAt(0), 0)) % colors.length];
  }
};

const createTreemapData = (violations: ViolationData[], groupBy: string): any[] => {
  const grouped = groupViolations(violations, groupBy);
  return Object.entries(grouped).map(([key, items]) => ({
    name: key,
    size: items.length,
    fill: getGroupColor(key, groupBy, {})
  }));
};

const createTableData = (grouped: Record<string, ViolationData[]>, groupBy: string): any[] => {
  const total = Object.values(grouped).reduce((sum, items) => sum + items.length, 0);
  
  return Object.entries(grouped)
    .map(([key, violations]) => ({
      id: key,
      name: key,
      count: violations.length,
      percentage: (violations.length / total) * 100,
      violations
    }))
    .sort((a, b) => b.count - a.count);
};

// Loading and Error components
const ViolationDistributionSkeleton: React.FC<{ compact: boolean }> = ({ compact }) => (
  <Card>
    <CardContent>
      <Box sx={{ height: compact ? 250 : 400, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
        <Typography color="textSecondary">Loading violation data...</Typography>
      </Box>
    </CardContent>
  </Card>
);

const ViolationDistributionError: React.FC<{ error: any }> = ({ error }) => (
  <Card>
    <CardContent>
      <Typography color="error" align="center">
        Failed to load violation data: {error?.message || 'Unknown error'}
      </Typography>
    </CardContent>
  </Card>
);

export default ViolationDistributionChart; 
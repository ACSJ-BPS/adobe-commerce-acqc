import { TrendAnalysis, MetricType } from '../types/trends';

export const formatDate = (date: string | Date, format: 'short' | 'long' = 'long'): string => {
  const d = typeof date === 'string' ? new Date(date) : date;
  
  if (format === 'short') {
    return d.toLocaleDateString('en-US', {
      month: 'short',
      day: 'numeric'
    });
  }
  
  return d.toLocaleDateString('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric'
  });
};

export const calculateMovingAverage = (values: number[], window: number = 7): number => {
  if (values.length === 0) return 0;
  if (values.length < window) {
    return values.reduce((sum, val) => sum + val, 0) / values.length;
  }
  
  const recentValues = values.slice(-window);
  return recentValues.reduce((sum, val) => sum + val, 0) / recentValues.length;
};

export const detectTrendDirection = (
  values: number[],
  threshold: number = 0.05
): TrendAnalysis => {
  if (values.length < 2) {
    return { direction: 'flat', percentage: 0, significance: 'low' };
  }

  const recent = values.slice(-Math.min(7, values.length));
  const older = values.slice(0, Math.max(0, values.length - recent.length));
  
  if (older.length === 0) {
    return { direction: 'flat', percentage: 0, significance: 'low' };
  }

  const recentAvg = recent.reduce((sum, val) => sum + val, 0) / recent.length;
  const olderAvg = older.reduce((sum, val) => sum + val, 0) / older.length;
  
  const change = recentAvg - olderAvg;
  const percentage = Math.abs(change / olderAvg) * 100;
  
  let direction: 'up' | 'down' | 'flat';
  let significance: 'high' | 'medium' | 'low';
  
  if (Math.abs(change) < threshold * olderAvg) {
    direction = 'flat';
    significance = 'low';
  } else {
    direction = change > 0 ? 'up' : 'down';
    significance = percentage > 20 ? 'high' : percentage > 10 ? 'medium' : 'low';
  }
  
  return { direction, percentage, significance };
};

export const calculateTrendPercentage = (values: number[]): number => {
  if (values.length < 2) return 0;
  
  const first = values[0];
  const last = values[values.length - 1];
  
  if (first === 0) return last > 0 ? 100 : 0;
  
  return ((last - first) / first) * 100;
};

export const getTrendColor = (direction: 'up' | 'down' | 'flat'): string => {
  switch (direction) {
    case 'up': return '#4caf50';
    case 'down': return '#f44336';
    case 'flat': return '#9e9e9e';
    default: return '#9e9e9e';
  }
};

export const getMetricDisplayName = (metric: MetricType): string => {
  const names: Record<MetricType, string> = {
    qualityScore: 'Quality Score',
    totalViolations: 'Total Violations',
    technicalDebtHours: 'Technical Debt',
    testCoverage: 'Test Coverage',
    averageComplexity: 'Complexity',
    performanceScore: 'Performance Score'
  };
  return names[metric] || metric;
}; 
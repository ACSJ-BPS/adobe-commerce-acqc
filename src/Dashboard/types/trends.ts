export type TimeRange = '7d' | '30d' | '90d' | '180d' | '1y' | 'all';

export type MetricType = 
  | 'qualityScore' 
  | 'totalViolations' 
  | 'technicalDebtHours' 
  | 'testCoverage' 
  | 'averageComplexity' 
  | 'performanceScore';

export interface TrendMetrics {
  qualityScore?: number;
  totalViolations?: number;
  technicalDebtHours?: number;
  testCoverage?: number;
  averageComplexity?: number;
  performanceScore?: number;
}

export interface DataPoint {
  date: string;
  metrics: TrendMetrics;
}

export interface Prediction {
  date: string;
  metrics: TrendMetrics;
  confidence?: number;
}

export interface TrendAnalysis {
  direction: 'up' | 'down' | 'flat';
  percentage: number;
  significance: 'high' | 'medium' | 'low';
  slope?: number;
  change?: number;
}

export interface TrendData {
  dataPoints: DataPoint[];
  predictions?: Prediction[];
  trends: Record<MetricType, TrendAnalysis>;
  anomalies?: Array<{
    date: string;
    metric: MetricType;
    value: number;
    severity: 'high' | 'medium' | 'low';
    description: string;
  }>;
}

export interface TrendConfig {
  name: string;
  color: string;
  yAxisId: 'left' | 'right';
  domain?: [number, number];
  format: (value: number) => string;
} 
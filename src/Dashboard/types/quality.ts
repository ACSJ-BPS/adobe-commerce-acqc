// Core quality metrics interface
export interface QualityMetrics {
  qualityScore: number;
  codeQuality: number;
  testCoverage: number;
  performanceScore: number;
  securityScore: number;
  maintainabilityIndex: number;
  technicalDebtHours: number;
  totalViolations: number;
  averageComplexity: number;
  violationsBySeverity: {
    critical: number;
    error: number;
    warning: number;
    info: number;
  };
  violationsByCategory: {
    security: number;
    performance: number;
    maintainability: number;
    logic: number;
    style: number;
  };
  timestamp: string;
}

// Historical data point
export interface QualityDataPoint {
  date: string;
  qualityScore: number;
  totalViolations: number;
  technicalDebtHours: number;
  testCoverage: number;
  averageComplexity: number;
  performanceScore: number;
  securityScore: number;
  maintainabilityIndex: number;
}

// Complete quality data response
export interface QualityDataResponse {
  currentMetrics: QualityMetrics;
  historicalData: QualityDataPoint[];
  lastUpdated: string;
  projectId?: string;
  timeRange: string;
}

// Quality trends analysis
export interface QualityTrend {
  direction: 'improving' | 'declining' | 'stable';
  change: number; // Percentage change
  slope: number;
  confidence: number;
  rSquared: number;
}

export interface QualityTrends {
  qualityScore: QualityTrend;
  violations: QualityTrend;
  technicalDebt: QualityTrend;
  testCoverage: QualityTrend;
  complexity: QualityTrend;
  performance: QualityTrend;
  security: QualityTrend;
  maintainability: QualityTrend;
}

// Quality score breakdown
export interface QualityScore {
  overall: number;
  components: {
    codeQuality: {
      score: number;
      weight: number;
      factors: {
        complexity: number;
        duplication: number;
        maintainability: number;
      };
    };
    testCoverage: {
      score: number;
      weight: number;
      factors: {
        lineCoverage: number;
        branchCoverage: number;
        functionCoverage: number;
      };
    };
    performance: {
      score: number;
      weight: number;
      factors: {
        speed: number;
        memory: number;
        efficiency: number;
      };
    };
    security: {
      score: number;
      weight: number;
      factors: {
        vulnerabilities: number;
        encryption: number;
        authentication: number;
      };
    };
  };
}

// Quality goals and targets
export interface QualityGoal {
  id: string;
  name: string;
  metric: keyof QualityMetrics;
  target: number;
  current: number;
  deadline?: string;
  priority: 'low' | 'medium' | 'high' | 'critical';
  description?: string;
  progress: number; // 0-100
  status: 'not_started' | 'in_progress' | 'completed' | 'overdue';
}

export interface QualityGoals {
  goals: QualityGoal[];
  overallProgress: number;
  completedGoals: number;
  totalGoals: number;
  nextDeadline?: string;
}

// Quality insights
export interface QualityInsight {
  id: string;
  type: 'improvement' | 'warning' | 'achievement' | 'prediction';
  title: string;
  description: string;
  impact: 'low' | 'medium' | 'high' | 'critical';
  confidence: number;
  relatedMetrics: string[];
  actionItems?: string[];
  estimatedEffort?: string;
  priority: number;
  timestamp: string;
}

export interface QualityInsights {
  insights: QualityInsight[];
  summary: {
    totalInsights: number;
    criticalInsights: number;
    improvementOpportunities: number;
    achievements: number;
  };
  categories: {
    [category: string]: QualityInsight[];
  };
}

// Quality recommendations
export interface QualityRecommendation {
  id: string;
  title: string;
  description: string;
  category: 'code_quality' | 'performance' | 'security' | 'testing' | 'architecture';
  priority: 'low' | 'medium' | 'high' | 'critical';
  effort: 'small' | 'medium' | 'large' | 'extra_large';
  impact: 'low' | 'medium' | 'high' | 'very_high';
  implementation: {
    steps: string[];
    estimatedTime: string;
    requiredSkills: string[];
    risks: string[];
  };
  metrics: {
    expectedImprovements: {
      [metric: string]: number;
    };
  };
  status: 'new' | 'acknowledged' | 'in_progress' | 'completed' | 'dismissed';
  assignee?: string;
  dueDate?: string;
  tags: string[];
}

export interface QualityRecommendations {
  recommendations: QualityRecommendation[];
  summary: {
    totalRecommendations: number;
    highPriorityRecommendations: number;
    inProgressRecommendations: number;
    completedRecommendations: number;
  };
  categories: {
    [category: string]: QualityRecommendation[];
  };
}

// Predictive analytics
export interface QualityPrediction {
  date: string;
  predictedScore: number;
  confidence: number;
  factors: {
    [factor: string]: number;
  };
}

export interface QualityForecasting {
  predictions: QualityPrediction[];
  trends: {
    shortTerm: QualityTrend; // Next 7 days
    mediumTerm: QualityTrend; // Next 30 days
    longTerm: QualityTrend; // Next 90 days
  };
  risks: {
    degradationRisk: number;
    timeToThreshold: number;
    criticalFactors: string[];
  };
  recommendations: {
    preventive: string[];
    corrective: string[];
  };
}

// Anomaly detection
export interface QualityAnomaly {
  date: string;
  metric: string;
  value: number;
  expectedValue: number;
  deviation: number;
  severity: 'low' | 'medium' | 'high' | 'critical';
  type: 'spike' | 'drop' | 'trend_break' | 'outlier';
  description: string;
  possibleCauses: string[];
  impact: string;
}

export interface QualityAnomalies {
  anomalies: QualityAnomaly[];
  summary: {
    totalAnomalies: number;
    criticalAnomalies: number;
    recentAnomalies: number;
    mostAffectedMetric: string;
  };
}

// Team performance metrics
export interface TeamMetrics {
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
    [metric: string]: QualityTrend;
  };
  ranking: number;
  benchmarks: {
    internal: number;
    industry: number;
  };
}

export interface TeamComparison {
  teams: TeamMetrics[];
  averages: {
    [metric: string]: number;
  };
  topPerformers: {
    [metric: string]: string; // Team ID
  };
  improvements: {
    [teamId: string]: {
      strengths: string[];
      weaknesses: string[];
      recommendations: string[];
    };
  };
}

// Configuration and settings
export interface QualityConfiguration {
  projectId: string;
  thresholds: {
    qualityScore: {
      excellent: number;
      good: number;
      fair: number;
      poor: number;
    };
    violations: {
      critical: number;
      error: number;
      warning: number;
    };
    technicalDebt: {
      low: number;
      medium: number;
      high: number;
      critical: number;
    };
    testCoverage: {
      minimum: number;
      target: number;
      excellent: number;
    };
  };
  weights: {
    codeQuality: number;
    testCoverage: number;
    performance: number;
    security: number;
    maintainability: number;
  };
  notifications: {
    enabled: boolean;
    channels: string[];
    conditions: {
      qualityDrop: number;
      criticalViolations: number;
      debtIncrease: number;
    };
  };
  refreshInterval: number;
  retentionPeriod: number;
}

// API response types
export interface ApiResponse<T> {
  success: boolean;
  data: T;
  message?: string;
  timestamp: string;
  requestId: string;
}

export interface ApiError {
  success: false;
  error: {
    code: string;
    message: string;
    details?: any;
  };
  timestamp: string;
  requestId: string;
}

// Chart data types
export interface ChartDataPoint {
  x: string | number;
  y: number;
  label?: string;
  color?: string;
  metadata?: any;
}

export interface ChartSeries {
  name: string;
  data: ChartDataPoint[];
  color: string;
  type?: 'line' | 'bar' | 'area' | 'scatter';
  yAxisId?: 'left' | 'right';
}

export interface ChartConfiguration {
  title: string;
  subtitle?: string;
  xAxisLabel?: string;
  yAxisLabel?: string;
  showLegend: boolean;
  showGrid: boolean;
  responsive: boolean;
  height?: number;
  theme: 'light' | 'dark';
  colors: string[];
  animations: boolean;
}

// Export types for external consumption
export type TimeRange = '7d' | '30d' | '90d' | '1y' | 'custom';
export type MetricType = keyof QualityMetrics;
export type SeverityLevel = 'critical' | 'error' | 'warning' | 'info';
export type CategoryType = 'security' | 'performance' | 'maintainability' | 'logic' | 'style';
export type TrendDirection = 'improving' | 'declining' | 'stable';
export type PriorityLevel = 'low' | 'medium' | 'high' | 'critical';
export type StatusType = 'not_started' | 'in_progress' | 'completed' | 'overdue' | 'dismissed';

// Utility types
export type PartialBy<T, K extends keyof T> = Omit<T, K> & Partial<Pick<T, K>>;
export type RequiredBy<T, K extends keyof T> = T & Required<Pick<T, K>>;

// Dashboard state types
export interface DashboardState {
  selectedProject?: string;
  timeRange: TimeRange;
  selectedMetrics: MetricType[];
  theme: 'light' | 'dark';
  layout: 'grid' | 'list';
  filters: {
    severity: SeverityLevel[];
    category: CategoryType[];
    team?: string;
  };
  preferences: {
    autoRefresh: boolean;
    notifications: boolean;
    compactMode: boolean;
  };
}

export interface DashboardActions {
  setProject: (projectId: string) => void;
  setTimeRange: (range: TimeRange) => void;
  setMetrics: (metrics: MetricType[]) => void;
  setTheme: (theme: 'light' | 'dark') => void;
  setLayout: (layout: 'grid' | 'list') => void;
  updateFilters: (filters: Partial<DashboardState['filters']>) => void;
  updatePreferences: (preferences: Partial<DashboardState['preferences']>) => void;
  reset: () => void;
  clearStorage: () => void;
} 
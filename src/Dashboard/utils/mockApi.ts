// @ts-expect-error: Type definitions for '../types' are missing in mock environment
import { QualityDataResponse, ViolationData, TrendData } from '../types';

// Mock quality data
export const mockQualityData: QualityDataResponse = {
  currentMetrics: {
    qualityScore: 8.5,
    codeQuality: 85,
    testCoverage: 0.78,
    performanceScore: 7.8,
    securityScore: 9.2,
    maintainabilityIndex: 82,
    technicalDebtHours: 45.5,
    totalViolations: 23,
    averageComplexity: 3.2,
    violationsBySeverity: {
      critical: 2,
      error: 5,
      warning: 12,
      info: 4
    },
    violationsByCategory: {
      security: 3,
      performance: 8,
      maintainability: 7,
      logic: 3,
      style: 2
    },
    timestamp: new Date().toISOString()
  },
  historicalData: Array.from({ length: 30 }, (_, i) => ({
    date: new Date(Date.now() - (29 - i) * 24 * 60 * 60 * 1000).toISOString().split('T')[0],
    qualityScore: 8.0 + Math.random() * 1.5,
    totalViolations: 20 + Math.floor(Math.random() * 10),
    technicalDebtHours: 40 + Math.random() * 20,
    testCoverage: 0.7 + Math.random() * 0.2,
    averageComplexity: 3.0 + Math.random() * 1.0,
    performanceScore: 7.5 + Math.random() * 1.0,
    securityScore: 8.8 + Math.random() * 0.8,
    maintainabilityIndex: 80 + Math.random() * 10
  })),
  lastUpdated: new Date().toISOString(),
  projectId: 'demo-project',
  timeRange: '30d'
};

// Mock violation data
export const mockViolationData: ViolationData = {
  violations: [
    {
      id: 'v1',
      type: 'security',
      severity: 'critical',
      category: 'security',
      message: 'SQL injection vulnerability detected',
      file: 'app/Http/Controllers/UserController.php',
      line: 25,
      column: 10,
      rule: 'security.sql-injection',
      description: 'User input not properly sanitized',
      suggestion: 'Use parameterized queries',
      timestamp: new Date().toISOString()
    },
    {
      id: 'v2',
      type: 'performance',
      severity: 'warning',
      category: 'performance',
      message: 'N+1 query problem detected',
      file: 'app/Models/User.php',
      line: 45,
      column: 15,
      rule: 'performance.n-plus-one',
      description: 'Multiple database queries in loop',
      suggestion: 'Use eager loading with with()',
      timestamp: new Date().toISOString()
    }
  ],
  summary: {
    total: 23,
    bySeverity: {
      critical: 2,
      error: 5,
      warning: 12,
      info: 4
    },
    byCategory: {
      security: 3,
      performance: 8,
      maintainability: 7,
      logic: 3,
      style: 2
    },
    byFile: {
      'app/Http/Controllers/UserController.php': 5,
      'app/Models/User.php': 3,
      'resources/views/dashboard.blade.php': 2
    },
    byRule: {
      'security.sql-injection': 2,
      'performance.n-plus-one': 3,
      'style.unused-variable': 4
    }
  },
  trends: {},
  lastUpdated: new Date().toISOString()
};

// Mock trend data
export const mockTrendData: TrendData = {
  dataPoints: Array.from({ length: 30 }, (_, i) => ({
    date: new Date(Date.now() - (29 - i) * 24 * 60 * 60 * 1000).toISOString().split('T')[0],
    metrics: {
      qualityScore: 8.0 + Math.random() * 1.5,
      totalViolations: 20 + Math.floor(Math.random() * 10),
      technicalDebtHours: 40 + Math.random() * 20,
      testCoverage: 0.7 + Math.random() * 0.2,
      averageComplexity: 3.0 + Math.random() * 1.0,
      performanceScore: 7.5 + Math.random() * 1.0
    }
  })),
  predictions: Array.from({ length: 7 }, (_, i) => ({
    date: new Date(Date.now() + (i + 1) * 24 * 60 * 60 * 1000).toISOString().split('T')[0],
    metrics: {
      qualityScore: 8.2 + Math.random() * 0.5,
      totalViolations: 18 + Math.floor(Math.random() * 5),
      technicalDebtHours: 42 + Math.random() * 10,
      testCoverage: 0.75 + Math.random() * 0.1,
      averageComplexity: 3.1 + Math.random() * 0.5,
      performanceScore: 7.8 + Math.random() * 0.5
    },
    confidence: 0.85 + Math.random() * 0.1
  })),
  trends: {
    qualityScore: { direction: 'up', percentage: 5.2, significance: 'medium' },
    totalViolations: { direction: 'down', percentage: 8.5, significance: 'high' },
    technicalDebtHours: { direction: 'up', percentage: 2.1, significance: 'low' },
    testCoverage: { direction: 'up', percentage: 3.8, significance: 'medium' },
    averageComplexity: { direction: 'stable', percentage: 0.5, significance: 'low' },
    performanceScore: { direction: 'up', percentage: 4.2, significance: 'medium' }
  },
  anomalies: [
    {
      date: new Date(Date.now() - 5 * 24 * 60 * 60 * 1000).toISOString().split('T')[0],
      metric: 'totalViolations',
      value: 35,
      severity: 'high',
      description: 'Unusual spike in violations detected'
    }
  ]
};

// Mock API client that returns mock data
export const mockApiClient = {
  get: async (url: string, config?: any) => {
    // Simulate network delay
    await new Promise(resolve => setTimeout(resolve, 300));
    
    if (url.includes('/quality/overview')) {
      return { data: mockQualityData };
    }
    
    if (url.includes('/violations')) {
      return { data: mockViolationData };
    }
    
    if (url.includes('/trends')) {
      return { data: mockTrendData };
    }
    
    if (url.includes('/quality/predictive')) {
      return { data: mockTrendData };
    }
    
    throw new Error(`Mock API endpoint not found: ${url}`);
  }
}; 
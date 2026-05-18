export interface Violation {
  id: string;
  type: string;
  severity: 'critical' | 'error' | 'warning' | 'info';
  category: 'security' | 'performance' | 'maintainability' | 'logic' | 'style';
  message: string;
  file: string;
  line: number;
  column?: number;
  rule: string;
  description?: string;
  suggestion?: string;
  timestamp: string;
}

export interface ViolationSummary {
  total: number;
  bySeverity: {
    critical: number;
    error: number;
    warning: number;
    info: number;
  };
  byCategory: {
    security: number;
    performance: number;
    maintainability: number;
    logic: number;
    style: number;
  };
  byFile: {
    [file: string]: number;
  };
  byRule: {
    [rule: string]: number;
  };
}

export interface ViolationData {
  violations: Violation[];
  summary: ViolationSummary;
  trends: {
    [date: string]: {
      total: number;
      bySeverity: ViolationSummary['bySeverity'];
      byCategory: ViolationSummary['byCategory'];
    };
  };
  lastUpdated: string;
} 
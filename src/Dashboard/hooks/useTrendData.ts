import { useQuery } from '@tanstack/react-query';
import { apiClient } from '../utils/apiClient';
import { TrendData, TimeRange, MetricType } from '../types/trends';

interface UseTrendDataParams {
  projectId?: string;
  timeRange: TimeRange;
  metrics: MetricType[];
}

export const useTrendData = (
  projectId?: string,
  timeRange: TimeRange = '30d',
  metrics: MetricType[] = ['qualityScore', 'totalViolations', 'technicalDebtHours', 'testCoverage']
) => {
  return useQuery({
    queryKey: ['trendData', projectId, timeRange, metrics] as const,
    queryFn: async (): Promise<TrendData> => {
      const response = await apiClient.get('/api/trends', {
        params: {
          projectId,
          timeRange,
          metrics: metrics.join(',')
        }
      });
      return response.data as TrendData;
    },
    enabled: !!projectId,
    staleTime: 5 * 60 * 1000, // 5 minutes
    cacheTime: 10 * 60 * 1000, // 10 minutes
  });
}; 
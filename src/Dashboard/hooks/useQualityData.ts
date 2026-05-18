import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useCallback, useMemo } from 'react';
import { QualityMetrics, QualityDataResponse, QualityTrends } from '../types/quality';
import { apiClient } from '../utils/apiClient';
import { useNotification } from './useNotification';

interface UseQualityDataOptions {
  refetchInterval?: number;
  enabled?: boolean;
  staleTime?: number;
}

export const useQualityData = (
  projectId?: string,
  timeRange: string = '30d',
  options: UseQualityDataOptions = {}
) => {
  const { showNotification } = useNotification();
  const queryClient = useQueryClient();

  const {
    refetchInterval = 5 * 60 * 1000, // 5 minutes
    enabled = true,
    staleTime = 2 * 60 * 1000 // 2 minutes
  } = options;

  // Main quality data query
  const qualityQuery = useQuery({
    queryKey: ['quality-data', projectId, timeRange],
    queryFn: async (): Promise<QualityDataResponse> => {
      const params = new URLSearchParams({
        timeRange,
        ...(projectId && { projectId })
      });

      const response = await apiClient.get(`/api/quality/overview?${params}`);
      return response.data as QualityDataResponse;
    },
    refetchInterval,
    enabled,
    staleTime,
    retry: 3,
    retryDelay: (attemptIndex) => Math.min(1000 * 2 ** attemptIndex, 30000),
    onError: (error) => {
      showNotification('Failed to load quality data', 'error');
      console.error('Quality data fetch error:', error);
    }
  });

  // Historical trends query
  const trendsQuery = useQuery({
    queryKey: ['quality-trends', projectId, timeRange],
    queryFn: async (): Promise<QualityTrends> => {
      const params = new URLSearchParams({
        timeRange,
        ...(projectId && { projectId })
      });

      const response = await apiClient.get(`/api/quality/trends?${params}`);
      return response.data as QualityTrends;
    },
    enabled: enabled && !!qualityQuery.data,
    staleTime
  });

  // Predictive analytics query
  const predictiveQuery = useQuery({
    queryKey: ['quality-predictive', projectId],
    queryFn: async () => {
      const params = new URLSearchParams({
        ...(projectId && { projectId })
      });

      const response = await apiClient.get(`/api/quality/predictive?${params}`);
      return response.data;
    },
    enabled: enabled && !!qualityQuery.data,
    staleTime: 10 * 60 * 1000 // 10 minutes for predictive data
  });

  // Mutation for refreshing data
  const refreshMutation = useMutation({
    mutationFn: async () => {
      const params = new URLSearchParams({
        timeRange,
        ...(projectId && { projectId })
      });

      const response = await apiClient.post(`/api/quality/refresh?${params}`);
      return response.data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries(['quality-data', projectId, timeRange]);
      queryClient.invalidateQueries(['quality-trends', projectId, timeRange]);
      showNotification('Quality data refreshed successfully', 'success');
    },
    onError: (error) => {
      showNotification('Failed to refresh quality data', 'error');
      console.error('Quality data refresh error:', error);
    }
  });

  // Export data mutation
  const exportMutation = useMutation({
    mutationFn: async (format: 'json' | 'csv' | 'pdf') => {
      const params = new URLSearchParams({
        format,
        timeRange,
        ...(projectId && { projectId })
      });

      const response = await apiClient.get(`/api/quality/export?${params}`, {
        responseType: 'blob'
      });
      return { data: response.data as BlobPart, format };
    },
    onSuccess: ({ data, format }) => {
      // Create download link
      const url = window.URL.createObjectURL(new Blob([data]));
      const link = document.createElement('a');
      link.href = url;
      link.setAttribute('download', `quality-report-${Date.now()}.${format}`);
      document.body.appendChild(link);
      link.click();
      link.remove();
      window.URL.revokeObjectURL(url);
      
      showNotification('Quality data exported successfully', 'success');
    },
    onError: () => {
      showNotification('Failed to export quality data', 'error');
    }
  });

  // Computed values
  const computedData = useMemo(() => {
    if (!qualityQuery.data) return null;

    const { currentMetrics, historicalData } = qualityQuery.data;
    
    // Calculate quality score trend
    const scoreHistory = historicalData.slice(-7); // Last 7 days
    const currentScore = currentMetrics.qualityScore;
    const previousScore = scoreHistory.length > 1 ? scoreHistory[scoreHistory.length - 2].qualityScore : currentScore;
    const scoreTrend = {
      direction: currentScore > previousScore ? 'improving' : currentScore < previousScore ? 'declining' : 'stable',
      change: ((currentScore - previousScore) / previousScore) * 100
    };

    // Calculate other trends
    const violationsTrend = calculateTrend(
      historicalData.map(d => d.totalViolations),
      'decreasing' // Lower is better for violations
    );

    const debtTrend = calculateTrend(
      historicalData.map(d => d.technicalDebtHours),
      'decreasing' // Lower is better for debt
    );

    const coverageTrend = calculateTrend(
      historicalData.map(d => d.testCoverage),
      'increasing' // Higher is better for coverage
    );

    const complexityTrend = calculateTrend(
      historicalData.map(d => d.averageComplexity),
      'decreasing' // Lower is better for complexity
    );

    return {
      ...qualityQuery.data,
      trends: {
        qualityScore: scoreTrend,
        violations: violationsTrend,
        technicalDebt: debtTrend,
        testCoverage: coverageTrend,
        complexity: complexityTrend
      }
    };
  }, [qualityQuery.data]);

  // Utility functions
  const refresh = useCallback(() => {
    refreshMutation.mutate();
  }, [refreshMutation]);

  const exportData = useCallback((format: 'json' | 'csv' | 'pdf') => {
    exportMutation.mutate(format);
  }, [exportMutation]);

  const invalidateCache = useCallback(() => {
    queryClient.invalidateQueries(['quality-data', projectId, timeRange]);
    queryClient.invalidateQueries(['quality-trends', projectId, timeRange]);
    queryClient.invalidateQueries(['quality-predictive', projectId]);
  }, [queryClient, projectId, timeRange]);

  // Real-time subscription (WebSocket)
  const subscribeToUpdates = useCallback((callback: (data: QualityMetrics) => void) => {
    if (!projectId) return () => {};

    const ws = new WebSocket(`ws://localhost:8080/quality-updates/${projectId}`);
    
    ws.onmessage = (event) => {
      try {
        const data = JSON.parse(event.data);
        callback(data);
        // Invalidate cache to trigger refetch
        invalidateCache();
      } catch (error) {
        console.error('WebSocket message error:', error);
      }
    };

    ws.onerror = (error) => {
      console.error('WebSocket error:', error);
    };

    return () => {
      ws.close();
    };
  }, [projectId, invalidateCache]);

  return {
    // Data
    data: computedData,
    trends: trendsQuery.data,
    predictive: predictiveQuery.data,
    
    // Loading states
    isLoading: qualityQuery.isLoading,
    isTrendsLoading: trendsQuery.isLoading,
    isPredictiveLoading: predictiveQuery.isLoading,
    isRefreshing: refreshMutation.isLoading,
    isExporting: exportMutation.isLoading,
    
    // Error states
    error: qualityQuery.error,
    trendsError: trendsQuery.error,
    predictiveError: predictiveQuery.error,
    
    // Actions
    refresh,
    exportData,
    invalidateCache,
    subscribeToUpdates,
    
    // Status
    isStale: qualityQuery.isStale,
    isFetching: qualityQuery.isFetching,
    lastUpdated: qualityQuery.dataUpdatedAt
  };
};

// Helper function to calculate trend
function calculateTrend(values: number[], direction: 'increasing' | 'decreasing') {
  if (values.length < 2) {
    return { direction: 'stable', change: 0 };
  }

  const current = values[values.length - 1];
  const previous = values[values.length - 2];
  const change = previous !== 0 ? ((current - previous) / previous) * 100 : 0;

  let trendDirection: 'improving' | 'declining' | 'stable';
  
  if (Math.abs(change) < 1) {
    trendDirection = 'stable';
  } else if (direction === 'increasing') {
    trendDirection = change > 0 ? 'improving' : 'declining';
  } else {
    trendDirection = change < 0 ? 'improving' : 'declining';
  }

  return {
    direction: trendDirection,
    change
  };
}

// Hook for quality goals/targets
export const useQualityGoals = (projectId?: string) => {
  const queryClient = useQueryClient();

  const goalsQuery = useQuery({
    queryKey: ['quality-goals', projectId],
    queryFn: async () => {
      const params = new URLSearchParams({
        ...(projectId && { projectId })
      });
      const response = await apiClient.get(`/api/quality/goals?${params}`);
      return response.data;
    },
    staleTime: 10 * 60 * 1000 // 10 minutes
  });

  const updateGoalsMutation = useMutation({
    mutationFn: async (goals: any) => {
      const response = await apiClient.put('/api/quality/goals', {
        projectId,
        goals
      });
      return response.data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries(['quality-goals', projectId]);
    }
  });

  return {
    goals: goalsQuery.data,
    isLoading: goalsQuery.isLoading,
    error: goalsQuery.error,
    updateGoals: updateGoalsMutation.mutate,
    isUpdating: updateGoalsMutation.isLoading
  };
};

// Hook for quality insights
export const useQualityInsights = (projectId?: string) => {
  return useQuery({
    queryKey: ['quality-insights', projectId],
    queryFn: async () => {
      const params = new URLSearchParams({
        ...(projectId && { projectId })
      });
      const response = await apiClient.get(`/api/quality/insights?${params}`);
      return response.data;
    },
    staleTime: 15 * 60 * 1000, // 15 minutes
    retry: 2
  });
};

// Hook for quality recommendations
export const useQualityRecommendations = (projectId?: string) => {
  return useQuery({
    queryKey: ['quality-recommendations', projectId],
    queryFn: async () => {
      const params = new URLSearchParams({
        ...(projectId && { projectId })
      });
      const response = await apiClient.get(`/api/quality/recommendations?${params}`);
      return response.data;
    },
    staleTime: 20 * 60 * 1000, // 20 minutes
    retry: 2
  });
};

export default useQualityData; 
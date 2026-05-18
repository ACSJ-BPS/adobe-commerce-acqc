import { useQuery } from '@tanstack/react-query';
import { apiClient } from '../utils/apiClient';
import { ViolationData } from '../types/violations';

export const useViolationData = (
  projectId?: string,
  timeRange: string = '30d'
) => {
  return useQuery({
    queryKey: ['violationData', projectId, timeRange] as const,
    queryFn: async (): Promise<ViolationData> => {
      const response = await apiClient.get('/api/violations', {
        params: {
          projectId,
          timeRange
        }
      });
      return response.data as ViolationData;
    },
    enabled: !!projectId,
    staleTime: 5 * 60 * 1000, // 5 minutes
    cacheTime: 10 * 60 * 1000, // 10 minutes
  });
}; 
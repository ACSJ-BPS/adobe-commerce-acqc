import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import {
  dashboardApi,
  findingsApi,
  projectsApi,
  scansApi,
  suppressionsApi,
  upgradePortfolioApi,
  upgradeScansApi,
  usersApi,
  versionRegistryApi,
} from '../utils/serverApi';

export function useDashboardStats() {
  return useQuery({
    queryKey: ['dashboard'],
    queryFn: () => dashboardApi.stats().then((r) => r.data),
    staleTime: 60_000,
  });
}

export function useProjects(page = 1) {
  return useQuery({
    queryKey: ['projects', page],
    queryFn: () => projectsApi.list(page).then((r) => r.data),
    refetchInterval: (data) => {
      const hasActive = data?.data?.some(
        (p) => p.latest_scan?.status === 'queued' || p.latest_scan?.status === 'running'
      );
      return hasActive ? 3000 : false;
    },
    staleTime: 60_000,
  });
}

export function useProject(id: number) {
  return useQuery({
    queryKey: ['projects', id],
    queryFn: () => projectsApi.get(id).then((r) => r.data),
    enabled: !!id,
  });
}

export function useScans(projectId: number, page = 1) {
  return useQuery({
    queryKey: ['scans', projectId, page],
    queryFn: () => scansApi.list(projectId, page).then((r) => r.data),
    enabled: !!projectId,
    refetchInterval: (data) => {
      const hasActive = data?.data?.some(
        (s) => s.status === 'queued' || s.status === 'running'
      );
      return hasActive ? 3000 : false;
    },
  });
}

export function useTriggerScan(projectId: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: () => scansApi.trigger(projectId).then((r) => r.data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['scans', projectId] });
      qc.invalidateQueries({ queryKey: ['projects'] });
      qc.invalidateQueries({ queryKey: ['dashboard'] });
    },
    onError: (err: unknown) => {
      const msg = (err as { response?: { data?: { message?: string } } })?.response?.data?.message;
      toast.error(msg ?? 'Failed to trigger scan.');
    },
  });
}

export function useUpdateProject(id: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (data: Partial<import('../utils/serverApi').Project>) =>
      projectsApi.update(id, data).then((r) => r.data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['projects'] });
      qc.invalidateQueries({ queryKey: ['projects', id] });
      qc.invalidateQueries({ queryKey: ['dashboard'] });
    },
    onError: (err: unknown) => {
      const msg = (err as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } })?.response?.data;
      toast.error(msg?.message ?? Object.values(msg?.errors ?? {}).flat().join(' ') ?? 'Failed to update project.');
    },
  });
}

export function useCreateProject() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (data: Partial<import('../utils/serverApi').Project> & { local_path?: string }) =>
      projectsApi.create(data).then((r) => r.data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['projects'] });
      qc.invalidateQueries({ queryKey: ['dashboard'] });
    },
  });
}

export function useFindings(
  scanId: number,
  filters: { severity?: string; category?: string; resolved?: string; page?: number; profile?: string } = {}
) {
  return useQuery({
    queryKey: ['findings', scanId, filters],
    queryFn: () => findingsApi.list(scanId, filters).then((r) => r.data),
    enabled: !!scanId,
    staleTime: 30_000,
  });
}

export function useSuppressions(projectId: number) {
  return useQuery({
    queryKey: ['suppressions', projectId],
    queryFn: () => suppressionsApi.list(projectId).then((r) => r.data),
    enabled: !!projectId,
    staleTime: 60_000,
  });
}

export function useAddSuppression(projectId: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (data: { rule_id: string; reason: string; review_by?: string }) =>
      suppressionsApi.add(projectId, data).then((r) => r.data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['suppressions', projectId] });
      qc.invalidateQueries({ queryKey: ['dashboard'] });
      toast.success('Suppression added.');
    },
    onError: (err: unknown) => {
      const msg = (err as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } })?.response?.data;
      toast.error(msg?.message ?? Object.values(msg?.errors ?? {}).flat().join(' ') ?? 'Failed to add suppression.');
    },
  });
}

export function useDeleteSuppression(projectId: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (suppressionId: number) => suppressionsApi.remove(projectId, suppressionId),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['suppressions', projectId] });
      qc.invalidateQueries({ queryKey: ['dashboard'] });
      toast.success('Suppression removed.');
    },
    onError: () => toast.error('Failed to remove suppression.'),
  });
}

export function useUsers(page = 1) {
  return useQuery({
    queryKey: ['users', page],
    queryFn: () => usersApi.list(page).then((r) => r.data),
    staleTime: 30_000,
  });
}

export function useInviteUser() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (data: Parameters<typeof usersApi.invite>[0]) =>
      usersApi.invite(data).then((r) => r.data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['users'] });
      toast.success('User invited successfully.');
    },
    onError: (err: unknown) => {
      const msg = (err as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } })?.response?.data;
      toast.error(msg?.message ?? Object.values(msg?.errors ?? {}).flat().join(' ') ?? 'Failed to invite user.');
    },
  });
}

export function useUpdateUser() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, ...data }: { id: number; name?: string; role?: import('../utils/serverApi').User['role'] }) =>
      usersApi.update(id, data).then((r) => r.data),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['users'] }),
    onError: (err: unknown) => {
      const msg = (err as { response?: { data?: { message?: string } } })?.response?.data?.message;
      toast.error(msg ?? 'Failed to update user.');
    },
  });
}

export function useDeleteUser() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => usersApi.destroy(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['users'] });
      toast.success('User removed.');
    },
    onError: (err: unknown) => {
      const msg = (err as { response?: { data?: { message?: string } } })?.response?.data?.message;
      toast.error(msg ?? 'Failed to delete user.');
    },
  });
}

// ─── Upgrade Intelligence hooks ───────────────────────────────────────────────

export function useUpgradeScans(projectId: number) {
  return useQuery({
    queryKey: ['upgrade-scans', projectId],
    queryFn: () => upgradeScansApi.list(projectId).then((r) => r.data),
    enabled: !!projectId,
    staleTime: 60_000,
  });
}

export function useUpgradeScan(projectId: number, scanId: number) {
  return useQuery({
    queryKey: ['upgrade-scans', projectId, scanId],
    queryFn: () => upgradeScansApi.show(projectId, scanId).then((r) => r.data),
    enabled: !!projectId && !!scanId,
    staleTime: 60_000,
  });
}

export function usePortfolioMatrix() {
  return useQuery({
    queryKey: ['upgrade-portfolio-matrix'],
    queryFn: () => upgradePortfolioApi.matrix().then((r) => r.data),
    staleTime: 120_000,
  });
}

export function useVersionRegistry() {
  return useQuery({
    queryKey: ['version-registry'],
    queryFn: () => versionRegistryApi.list().then((r) => r.data),
    staleTime: 300_000,
  });
}

export function useExportFindings() {
  return useMutation({
    mutationFn: ({ scanId, filters }: {
      scanId: number;
      filters?: { severity?: string; category?: string; resolved?: string };
    }) => findingsApi.export(scanId, filters),
    onError: () => toast.error('Export failed — please try again.'),
  });
}

export function useResolveFinding(scanId: number) {
  const qc = useQueryClient();
  const invalidate = () => qc.invalidateQueries({ queryKey: ['findings', scanId] });
  const resolve   = useMutation({ mutationFn: (id: number) => findingsApi.resolve(id).then((r) => r.data),   onSuccess: invalidate });
  const unresolve = useMutation({ mutationFn: (id: number) => findingsApi.unresolve(id).then((r) => r.data), onSuccess: invalidate });
  return { resolve, unresolve };
}

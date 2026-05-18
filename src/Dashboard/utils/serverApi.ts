import axios from 'axios';

const http = axios.create({
  baseURL: '/',
  withCredentials: true,
  headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
});

// Attach CSRF cookie before every mutating request
http.interceptors.request.use(async (config) => {
  if (['post', 'put', 'patch', 'delete'].includes(config.method ?? '')) {
    await axios.get('/sanctum/csrf-cookie', { withCredentials: true });
  }
  return config;
});

// Redirect to login on 401
http.interceptors.response.use(
  (r) => r,
  (err) => {
    if (err.response?.status === 401) {
      window.dispatchEvent(new CustomEvent('auth:unauthenticated'));
    }
    return Promise.reject(err);
  }
);

export interface User {
  id: number;
  name: string;
  email: string;
  role: 'admin' | 'project_lead' | 'member';
  projects_count?: number;
  created_at?: string;
}

export interface Project {
  id: number;
  name: string;
  dr_no: string;
  description: string | null;
  repo_url: string | null;
  local_path: string | null;
  tech_stack: string[] | null;
  status: 'active' | 'in_review' | 'archived';
  latest_scan?: Scan | null;
  rule_coverage?: number | null;
}

export interface Scan {
  id: number;
  project_id: number;
  status: 'queued' | 'running' | 'complete' | 'failed';
  score: number | null;
  grade: string | null;
  total_files: number;
  total_lines: number;
  critical_count: number;
  error_count: number;
  warning_count: number;
  info_count: number;
  category_breakdown: Record<string, number> | null;
  sub_indexes: Record<string, number> | null;
  error_message: string | null;
  completed_at: string | null;
  health_color?: 'green' | 'yellow' | 'red';
}

export interface DashboardStats {
  total_projects: number;
  healthy: number;
  at_risk: number;
  no_scan: number;
  avg_score: number | null;
  avg_score_change: number | null;
  total_open_critical: number;
  total_open_error: number;
  resolved_this_month: number;
  scans_this_week: number;
  total_suppressions: number;
  expired_suppressions: number;
}

export interface Suppression {
  id: number;
  project_id: number;
  rule_id: string;
  reason: string;
  suppressed_by_email: string;
  acknowledged_by: number | null;
  suppressed_at: string;
  review_by: string | null;
  dashboard_acked_at: string | null;
  acknowledged_by_user?: { id: number; name: string; email: string } | null;
}

export interface ScoreHistoryPoint {
  week_start: string;
  avg_score: number;
  scan_count: number;
}

export interface PaginatedResponse<T> {
  data: T[];
  current_page: number;
  last_page: number;
  total: number;
}

export const authApi = {
  login: (email: string, password: string) =>
    http.post<{ user: User }>('/api/v1/login', { email, password }),

  logout: () => http.post('/api/v1/logout'),

  me: () => http.get<{ user: User }>('/api/v1/me'),
};

export const dashboardApi = {
  stats: () =>
    http.get<{
      stats: DashboardStats;
      projects: Project[];
      recent_scans: Scan[];
      score_history: ScoreHistoryPoint[];
    }>('/api/v1/dashboard'),
};

export const projectsApi = {
  list: (page = 1) =>
    http.get<PaginatedResponse<Project>>(`/api/v1/projects?page=${page}`),

  get: (id: number) => http.get<Project>(`/api/v1/projects/${id}`),

  create: (data: Partial<Project>) => http.post<Project>('/api/v1/projects', data),

  update: (id: number, data: Partial<Project>) =>
    http.patch<Project>(`/api/v1/projects/${id}`, data),

  delete: (id: number) => http.delete(`/api/v1/projects/${id}`),
};

export const scansApi = {
  list: (projectId: number, page = 1) =>
    http.get<PaginatedResponse<Scan>>(`/api/v1/projects/${projectId}/scans?page=${page}`),

  trigger: (projectId: number) =>
    http.post<Scan>(`/api/v1/projects/${projectId}/scans`),

  get: (projectId: number, scanId: number) =>
    http.get<Scan>(`/api/v1/projects/${projectId}/scans/${scanId}`),
};

export interface Finding {
  id: number;
  scan_id: number;
  file_path: string;
  line_number: number | null;
  rule_id: string | null;
  rule_name: string;
  severity: 'blocker' | 'critical' | 'error' | 'warning' | 'info' | 'minor';
  category: string | null;
  description: string;
  recommendation: string | null;
  fingerprint: string | null;
  resolved_by: number | null;
  resolved_at: string | null;
}

export const findingsApi = {
  list: (scanId: number, params: { severity?: string; category?: string; resolved?: string; page?: number; profile?: string } = {}) => {
    const qs = new URLSearchParams();
    if (params.severity) qs.set('severity', params.severity);
    if (params.category) qs.set('category', params.category);
    if (params.resolved !== undefined) qs.set('resolved', params.resolved);
    if (params.profile) qs.set('profile', params.profile);
    qs.set('page', String(params.page ?? 1));
    return http.get<PaginatedResponse<Finding>>(`/api/v1/scans/${scanId}/findings?${qs}`);
  },

  export: async (scanId: number, params: { severity?: string; category?: string; resolved?: string } = {}) => {
    const qs = new URLSearchParams();
    if (params.severity) qs.set('severity', params.severity);
    if (params.category) qs.set('category', params.category);
    if (params.resolved) qs.set('resolved', params.resolved);

    const res = await fetch(`/api/v1/scans/${scanId}/findings/export?${qs}`, { credentials: 'include' });
    if (!res.ok) throw new Error('Export failed');

    const blob = await res.blob();
    const disposition = res.headers.get('Content-Disposition') ?? '';
    const match = disposition.match(/filename="?([^";]+)"?/);
    const filename = match?.[1] ?? `scan-${scanId}-findings.csv`;

    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
  },

  resolve: (findingId: number) =>
    http.patch<Finding>(`/api/v1/findings/${findingId}/resolve`),

  unresolve: (findingId: number) =>
    http.patch<Finding>(`/api/v1/findings/${findingId}/unresolve`),
};

export const suppressionsApi = {
  list: (projectId: number) =>
    http.get<Suppression[]>(`/api/v1/projects/${projectId}/suppressions`),

  add: (projectId: number, data: { rule_id: string; reason: string; review_by?: string }) =>
    http.post<Suppression>(`/api/v1/projects/${projectId}/suppressions`, data),

  remove: (projectId: number, suppressionId: number) =>
    http.delete(`/api/v1/projects/${projectId}/suppressions/${suppressionId}`),
};

// ─── Upgrade Intelligence ─────────────────────────────────────────────────────

export interface UpgradeScan {
  id: number;
  project_id: number;
  source_version: string;
  target_version: string;
  uri_score: number;
  uri_grade: 'A' | 'B' | 'C' | 'D' | 'F';
  engineering_hours: number;
  qa_hours: number;
  infra_hours: number;
  total_hours_with_contingency: number;
  recommended_timeline: string;
  blocker_count: number;
  critical_count: number;
  error_count: number;
  warning_count: number;
  full_report_json?: Record<string, unknown> | null;
  created_at: string;
}

export interface PortfolioMatrixProject {
  project_id: number;
  project_name: string;
  scans: Array<Pick<UpgradeScan, 'target_version' | 'uri_score' | 'uri_grade' | 'engineering_hours' | 'created_at'>>;
}

export interface PortfolioMatrix {
  projects: PortfolioMatrixProject[];
  portfolio_total_hours: number;
  project_count: number;
}

export const upgradeScansApi = {
  list: (projectId: number) =>
    http.get<PaginatedResponse<UpgradeScan>>(`/api/v1/projects/${projectId}/upgrade-scans`),

  show: (projectId: number, scanId: number) =>
    http.get<UpgradeScan>(`/api/v1/projects/${projectId}/upgrade-scans/${scanId}`),

  store: (projectId: number, data: Partial<UpgradeScan>) =>
    http.post<UpgradeScan>(`/api/v1/projects/${projectId}/upgrade-scans`, data),
};

export const upgradePortfolioApi = {
  matrix: () => http.get<PortfolioMatrix>('/api/v1/upgrade/portfolio-matrix'),
};

export const versionRegistryApi = {
  list: () => http.get<{ versions: string[] }>('/api/v1/upgrade/version-registry'),
};

export const usersApi = {
  list: (page = 1) =>
    http.get<PaginatedResponse<User>>(`/api/v1/admin/users?page=${page}`),

  invite: (data: { name: string; email: string; role: User['role']; password: string }) =>
    http.post<User>('/api/v1/admin/users', data),

  update: (id: number, data: { name?: string; role?: User['role'] }) =>
    http.patch<User>(`/api/v1/admin/users/${id}`, data),

  destroy: (id: number) => http.delete(`/api/v1/admin/users/${id}`),
};

export default http;

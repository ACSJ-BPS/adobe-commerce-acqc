/**
 * ACQC Dashboard API Client
 * Connects to the local dashboard server started with `acqc dashboard`.
 * Falls back to mock data when the server is not running.
 */

const BASE_URL = (import.meta.env.VITE_API_URL as string) || 'http://127.0.0.1:8765';

async function fetchJson<T>(path: string, fallback: T): Promise<T> {
  try {
    const res = await fetch(`${BASE_URL}${path}`, {
      headers: { 'Accept': 'application/json' },
      signal: AbortSignal.timeout(5000),
    });
    if (!res.ok) return fallback;
    return (await res.json()) as T;
  } catch {
    return fallback;
  }
}

export interface HistoryEntry {
  id: number;
  project_path: string;
  project_name: string;
  command: string;
  score: number;
  total_violations: number;
  critical_violations: number;
  files_analyzed: number;
  line_coverage: number;
  metadata: Record<string, unknown>;
  created_at: string;
}

export interface TrendPoint {
  date: string;
  avg_score: number;
  violations: number;
  scans: number;
}

export interface ProjectSummary {
  project_path: string;
  project_name: string;
  last_scan: string;
  avg_score: number;
  scan_count: number;
}

async function fetchMethod<T = unknown>(
  method: string,
  path: string,
  options?: { params?: Record<string, unknown>; responseType?: string; data?: unknown }
): Promise<{ data: T }> {
  const url = new URL(`${BASE_URL}${path}`);
  if (options?.params) {
    for (const [k, v] of Object.entries(options.params)) {
      if (v !== undefined && v !== null) url.searchParams.set(k, String(v));
    }
  }
  try {
    const res = await fetch(url.toString(), {
      method,
      headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
      signal: AbortSignal.timeout(5000),
      body: options?.data ? JSON.stringify(options.data) : undefined,
    });
    if (!res.ok) return { data: null as T };
    if (options?.responseType === 'blob') return { data: (await res.blob()) as T };
    return { data: (await res.json()) as T };
  } catch {
    return { data: null as T };
  }
}

export const apiClient = {
  async getStatus() {
    return fetchJson('/api/status', { status: 'offline', version: '2.0.0' });
  },

  async getHistory(limit = 50, project = ''): Promise<{ data: HistoryEntry[]; total: number }> {
    const qs = new URLSearchParams({ limit: String(limit) });
    if (project) qs.set('project', project);
    return fetchJson(`/api/history?${qs}`, { data: [], total: 0 });
  },

  async getTrend(): Promise<{ data: TrendPoint[] }> {
    return fetchJson('/api/history/trend', { data: [] });
  },

  async getProjects(): Promise<{ data: ProjectSummary[] }> {
    return fetchJson('/api/projects', { data: [] });
  },

  get<T = unknown>(path: string, options?: { params?: Record<string, unknown>; responseType?: string }) {
    return fetchMethod<T>('GET', path, options);
  },

  post<T = unknown>(path: string, data?: unknown) {
    return fetchMethod<T>('POST', path, { data });
  },

  put<T = unknown>(path: string, data?: unknown) {
    return fetchMethod<T>('PUT', path, { data });
  },
};

export default apiClient;

import { create } from 'zustand';
import { persist, createJSONStorage } from 'zustand/middleware';
import { immer } from 'zustand/middleware/immer';
import { DashboardState, DashboardActions, TimeRange, MetricType } from '../types/quality';

interface DashboardStore extends DashboardState, DashboardActions {}

const initialState: DashboardState = {
  selectedProject: undefined,
  timeRange: '30d',
  selectedMetrics: ['qualityScore', 'totalViolations'],
  theme: 'light',
  layout: 'grid',
  filters: {
    severity: ['critical', 'error', 'warning', 'info'],
    category: ['security', 'performance', 'maintainability', 'logic', 'style'],
    team: undefined
  },
  preferences: {
    autoRefresh: true,
    notifications: true,
    compactMode: false
  }
};

export const useDashboardStore = create<DashboardStore>()(
  persist(
    immer((set, get) => ({
      ...initialState,

      // Actions
      setProject: (projectId: string) =>
        set((state) => {
          state.selectedProject = projectId;
        }),

      setTimeRange: (range: TimeRange) =>
        set((state) => {
          state.timeRange = range;
        }),

      setMetrics: (metrics: MetricType[]) =>
        set((state) => {
          state.selectedMetrics = metrics;
        }),

      setTheme: (theme: 'light' | 'dark') =>
        set((state) => {
          state.theme = theme;
        }),

      setLayout: (layout: 'grid' | 'list') =>
        set((state) => {
          state.layout = layout;
        }),

      updateFilters: (filters) =>
        set((state) => {
          Object.assign(state.filters, filters);
        }),

      updatePreferences: (preferences) =>
        set((state) => {
          Object.assign(state.preferences, preferences);
        }),

      reset: () =>
        set((state) => {
          Object.assign(state, initialState);
        }),

      // Clear localStorage and reset to initial state
      clearStorage: () => {
        localStorage.removeItem('acqc-dashboard');
        set((state) => {
          Object.assign(state, initialState);
        });
      }
    })),
    {
      name: 'acqc-dashboard',
      version: 1,
      migrate: (state, version) => version === 0 ? initialState : state,
      storage: createJSONStorage(() => localStorage),
      partialize: (state) => ({
        selectedProject: state.selectedProject,
        timeRange: state.timeRange,
        selectedMetrics: state.selectedMetrics,
        theme: state.theme,
        layout: state.layout,
        filters: state.filters,
        preferences: state.preferences
      })
    }
  )
);

// Selectors
export const useSelectedProject = () => useDashboardStore((state) => state.selectedProject);
export const useTimeRange = () => useDashboardStore((state) => state.timeRange);
export const useSelectedMetrics = () => useDashboardStore((state) => state.selectedMetrics);
export const useTheme = () => useDashboardStore((state) => state.theme);
export const useLayout = () => useDashboardStore((state) => state.layout);
export const useFilters = () => useDashboardStore((state) => state.filters);
export const usePreferences = () => useDashboardStore((state) => state.preferences);

// Actions
export const useDashboardActions = () => useDashboardStore((state) => ({
  setProject: state.setProject,
  setTimeRange: state.setTimeRange,
  setMetrics: state.setMetrics,
  setTheme: state.setTheme,
  setLayout: state.setLayout,
  updateFilters: state.updateFilters,
  updatePreferences: state.updatePreferences,
  reset: state.reset,
  clearStorage: state.clearStorage
})); 
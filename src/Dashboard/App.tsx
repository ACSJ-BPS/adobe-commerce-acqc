import { ThemeProvider, createTheme } from '@mui/material/styles';
import CssBaseline from '@mui/material/CssBaseline';
import { Box, CircularProgress } from '@mui/material';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom';
import { Toaster } from 'react-hot-toast';
import { AuthProvider, useAuth } from './context/AuthContext';
import LoginPage from './pages/LoginPage';
import OrgDashboardPage from './pages/OrgDashboardPage';
import ProjectDetailPage from './pages/ProjectDetailPage';
import AdminUsersPage from './pages/AdminUsersPage';
import AnalyticsPage from './pages/AnalyticsPage';
import ReportsPage from './pages/ReportsPage';
import SettingsPage from './pages/SettingsPage';
import UpgradePlanningPage from './pages/UpgradePlanningPage';
import { useDashboardStore } from './stores/dashboardStore';
import ErrorBoundary from './components/ErrorBoundary';

const queryClient = new QueryClient({
  defaultOptions: {
    queries: { retry: 1, refetchOnWindowFocus: false },
  },
});

function AppShell() {
  const { user, loading } = useAuth();
  const { theme } = useDashboardStore();

  const muiTheme = createTheme({
    palette: {
      mode: theme,
      primary: { main: '#1976d2' },
      secondary: { main: '#dc004e' },
    },
  });

  if (loading) {
    return (
      <ThemeProvider theme={muiTheme}>
        <CssBaseline />
        <Box sx={{ display: 'flex', alignItems: 'center', justifyContent: 'center', minHeight: '100vh' }}>
          <CircularProgress />
        </Box>
      </ThemeProvider>
    );
  }

  return (
    <ThemeProvider theme={muiTheme}>
      <CssBaseline />
      {user ? (
        <Routes>
          <Route path="/" element={<ErrorBoundary><OrgDashboardPage /></ErrorBoundary>} />
          <Route path="/org" element={<ErrorBoundary><OrgDashboardPage /></ErrorBoundary>} />
          <Route path="/projects/:id" element={<ErrorBoundary><ProjectDetailPage /></ErrorBoundary>} />
          <Route path="/admin/users" element={<ErrorBoundary><AdminUsersPage /></ErrorBoundary>} />
          <Route path="/analytics" element={<ErrorBoundary><AnalyticsPage /></ErrorBoundary>} />
          <Route path="/reports" element={<ErrorBoundary><ReportsPage /></ErrorBoundary>} />
          <Route path="/settings" element={<ErrorBoundary><SettingsPage /></ErrorBoundary>} />
          <Route path="/upgrade-planning" element={<ErrorBoundary><UpgradePlanningPage /></ErrorBoundary>} />
          <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
      ) : (
        <LoginPage />
      )}
      <Toaster
        position="top-right"
        toastOptions={{
          duration: 4000,
          style: {
            background: muiTheme.palette.background.paper,
            color: muiTheme.palette.text.primary,
            border: `1px solid ${muiTheme.palette.divider}`,
          },
        }}
      />
    </ThemeProvider>
  );
}

function App() {
  return (
    <BrowserRouter>
      <QueryClientProvider client={queryClient}>
        <AuthProvider>
          <AppShell />
        </AuthProvider>
      </QueryClientProvider>
    </BrowserRouter>
  );
}

export default App; 
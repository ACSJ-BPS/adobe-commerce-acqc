import {
  Alert,
  Autocomplete,
  Avatar,
  Box,
  Button,
  Card,
  CardContent,
  Chip,
  CircularProgress,
  Container,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  Divider,
  FormControl,
  Grid,
  IconButton,
  InputAdornment,
  InputLabel,
  LinearProgress,
  MenuItem,
  Select,
  Skeleton,
  Tab,
  Tabs,
  TextField,
  Tooltip,
  Typography,
  useTheme,
} from '@mui/material';
import {
  Add,
  AdminPanelSettings,
  DarkMode,
  Edit,
  Error as ErrorIcon,
  GitHub,
  LightMode,
  Logout,
  PlayArrow,
  Refresh,
  Search,
  SystemUpdateAlt,
  TrendingDown,
  TrendingFlat,
  TrendingUp,
} from '@mui/icons-material';
import {
  AreaChart, Area,
  XAxis, YAxis,
  CartesianGrid,
  Tooltip as ReTooltip,
  ReferenceLine,
  ResponsiveContainer,
} from 'recharts';
import type { ScoreHistoryPoint } from '../utils/serverApi';
import { useMemo, useEffect, useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { useDashboardStore } from '../stores/dashboardStore';
import { useCreateProject, useDashboardStats, useProjects, useTriggerScan } from '../hooks/useServerData';
import EditProjectDialog from '../components/EditProjectDialog';
import type { Project } from '../utils/serverApi';

const TECH_STACK_OPTIONS = ['php', 'magento2', 'mysql', 'elasticsearch', 'redis', 'rabbitmq', 'varnish', 'nginx', 'javascript', 'typescript', 'react', 'vue'];

// ─── Create Project Dialog ─────────────────────────────────────────────────────
function CreateProjectDialog({ open, onClose }: { open: boolean; onClose: () => void }) {
  const create = useCreateProject();
  const [form, setForm] = useState({ name: '', dr_no: '', description: '', repo_url: '', local_path: '', tech_stack: [] as string[] });
  const [error, setError] = useState<string | null>(null);

  const set = (field: string) => (e: React.ChangeEvent<HTMLInputElement>) =>
    setForm((f) => ({ ...f, [field]: e.target.value }));

  const handleSubmit = async () => {
    setError(null);
    try {
      await create.mutateAsync({ ...form, tech_stack: form.tech_stack.length ? form.tech_stack : null });
      setForm({ name: '', dr_no: '', description: '', repo_url: '', local_path: '', tech_stack: [] });
      onClose();
    } catch (err: unknown) {
      const msg = (err as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } })?.response?.data;
      setError(msg?.message ?? Object.values(msg?.errors ?? {}).flat().join(' ') ?? 'Failed to create project.');
    }
  };

  return (
    <Dialog open={open} onClose={onClose} maxWidth="sm" fullWidth>
      <DialogTitle fontWeight={700}>New Project</DialogTitle>
      <DialogContent>
        {error && <Alert severity="error" sx={{ mb: 2 }}>{error}</Alert>}

        <TextField label="Project Name" fullWidth required margin="normal" value={form.name} onChange={set('name')} />

        <TextField
          label="DR Number" fullWidth required margin="normal"
          value={form.dr_no} onChange={set('dr_no')}
          placeholder="DR47000022" helperText="Adobe DR number from your tracking system (e.g. DR47000018)"
          inputProps={{ maxLength: 50 }}
        />

        <TextField label="Description" fullWidth multiline rows={2} margin="normal" value={form.description} onChange={set('description')} />

        <TextField label="Repository URL" fullWidth margin="normal" value={form.repo_url} onChange={set('repo_url')} placeholder="https://github.com/org/repo" />

        <TextField
          label="Local Path (for scanning)" fullWidth margin="normal"
          value={form.local_path} onChange={set('local_path')}
          placeholder="/var/www/magento2" helperText="Absolute path on the server where the code lives"
        />

        <Autocomplete
          multiple freeSolo options={TECH_STACK_OPTIONS}
          value={form.tech_stack}
          onChange={(_, v) => setForm((f) => ({ ...f, tech_stack: v as string[] }))}
          renderInput={(params) => <TextField {...params} label="Tech Stack" margin="normal" />}
        />
      </DialogContent>
      <DialogActions sx={{ px: 3, pb: 2 }}>
        <Button onClick={onClose} disabled={create.isPending}>Cancel</Button>
        <Button
          variant="contained" onClick={handleSubmit}
          disabled={create.isPending || !form.name || !form.dr_no}
          startIcon={create.isPending ? <CircularProgress size={14} color="inherit" /> : undefined}
        >
          {create.isPending ? 'Creating…' : 'Create Project'}
        </Button>
      </DialogActions>
    </Dialog>
  );
}

// ─── Executive metric card ────────────────────────────────────────────────────
function ExecMetric({
  label, value, delta, color = 'text.primary', unit = '',
}: {
  label: string;
  value: number | null;
  delta?: number | null;
  color?: string;
  unit?: string;
}) {
  const theme = useTheme();
  const deltaColor = delta == null ? undefined
    : delta > 0 ? theme.palette.success.main
    : delta < 0 ? theme.palette.error.main
    : theme.palette.text.secondary;
  const DeltaIcon = delta == null ? null : delta > 0 ? TrendingUp : delta < 0 ? TrendingDown : TrendingFlat;

  return (
    <Card variant="outlined" sx={{ borderRadius: 2, flex: 1, minWidth: 140 }}>
      <CardContent sx={{ pb: '12px !important' }}>
        <Typography variant="caption" color="text.secondary" display="block" mb={0.5}>{label}</Typography>
        <Box sx={{ display: 'flex', alignItems: 'baseline', gap: 1 }}>
          <Typography variant="h4" fontWeight={700} color={color}>
            {value ?? '—'}{unit}
          </Typography>
          {DeltaIcon && delta != null && (
            <Box sx={{ display: 'flex', alignItems: 'center', gap: 0.25, color: deltaColor }}>
              <DeltaIcon sx={{ fontSize: 16 }} />
              <Typography variant="caption" fontWeight={600} color={deltaColor}>
                {delta > 0 ? `+${delta}` : delta}
              </Typography>
            </Box>
          )}
        </Box>
      </CardContent>
    </Card>
  );
}

// ─── Score sparkline ──────────────────────────────────────────────────────────
function ScoreSparkline({ history }: { history: ScoreHistoryPoint[] }) {
  const theme = useTheme();
  if (!history.length) return null;
  const data = history.map((h) => ({
    week: new Date(h.week_start).toLocaleDateString('en-US', { month: 'short', day: 'numeric' }),
    score: Number(h.avg_score),
  }));

  return (
    <Card variant="outlined" sx={{ borderRadius: 2, mb: 3 }}>
      <CardContent>
        <Typography variant="caption" color="text.secondary" fontWeight={600}>Org-wide avg score — last 8 weeks</Typography>
        <ResponsiveContainer width="100%" height={90}>
          <AreaChart data={data} margin={{ top: 8, right: 16, bottom: 0, left: 0 }}>
            <defs>
              <linearGradient id="scoreGrad" x1="0" y1="0" x2="0" y2="1">
                <stop offset="5%"  stopColor={theme.palette.primary.main} stopOpacity={0.3} />
                <stop offset="95%" stopColor={theme.palette.primary.main} stopOpacity={0}   />
              </linearGradient>
            </defs>
            <CartesianGrid strokeDasharray="3 3" stroke={theme.palette.divider} />
            <XAxis dataKey="week" tick={{ fontSize: 10 }} />
            <YAxis domain={[0, 100]} tick={{ fontSize: 10 }} width={24} />
            <ReTooltip formatter={(v: number) => [`${v}/100`, 'Avg Score']} />
            <ReferenceLine y={80} stroke={theme.palette.success.main} strokeDasharray="3 2" />
            <ReferenceLine y={50} stroke={theme.palette.error.main}   strokeDasharray="3 2" />
            <Area type="monotone" dataKey="score" stroke={theme.palette.primary.main} strokeWidth={2} fill="url(#scoreGrad)" dot={{ r: 3 }} />
          </AreaChart>
        </ResponsiveContainer>
      </CardContent>
    </Card>
  );
}

type HealthTab = 'all' | 'healthy' | 'warning' | 'critical' | 'none';

// ─── Stat summary card ────────────────────────────────────────────────────────
function StatCard({
  label, value, color, onClick, active,
}: { label: string; value: number; color: string; onClick?: () => void; active?: boolean }) {
  return (
    <Card
      onClick={onClick}
      sx={{
        borderRadius: 2,
        cursor: onClick ? 'pointer' : 'default',
        outline: active ? `2px solid` : 'none',
        outlineColor: color,
        transition: 'box-shadow 0.15s',
        '&:hover': onClick ? { boxShadow: 4 } : {},
      }}
    >
      <CardContent>
        <Typography variant="h3" fontWeight={700} color={color}>{value}</Typography>
        <Typography variant="body2" color="text.secondary">{label}</Typography>
      </CardContent>
    </Card>
  );
}

// ─── Health score ring ────────────────────────────────────────────────────────
function ScoreRing({ score, color }: { score: number | null; color: string }) {
  if (score === null) {
    return (
      <Box sx={{ position: 'relative', display: 'inline-flex' }}>
        <CircularProgress variant="determinate" value={0} size={72} sx={{ color: 'divider' }} />
        <Box sx={{ position: 'absolute', inset: 0, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
          <Typography variant="caption" color="text.disabled">N/A</Typography>
        </Box>
      </Box>
    );
  }
  const muiColor = color === 'green' ? 'success' : color === 'yellow' ? 'warning' : 'error';
  return (
    <Box sx={{ position: 'relative', display: 'inline-flex' }}>
      <CircularProgress variant="determinate" value={score} size={72} color={muiColor} />
      <Box sx={{ position: 'absolute', inset: 0, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
        <Typography variant="body2" fontWeight={700}>{score}</Typography>
      </Box>
    </Box>
  );
}

// ─── Severity pills ───────────────────────────────────────────────────────────
function SeverityPills({
  scan, onSeverityClick,
}: { scan: Project['latest_scan']; onSeverityClick?: (sev: string) => void }) {
  if (!scan) return <Typography variant="caption" color="text.disabled">No scan yet</Typography>;
  const chip = (label: string, count: number, color: 'error' | 'warning' | 'default' | 'info', sev: string) =>
    count > 0 && (
      <Tooltip key={sev} title={`View ${sev} findings`} arrow>
        <Chip
          label={`${count} ${label}`} size="small" color={color}
          onClick={(e) => { e.stopPropagation(); onSeverityClick?.(sev); }}
          sx={{ cursor: onSeverityClick ? 'pointer' : 'default' }}
        />
      </Tooltip>
    );
  return (
    <Box sx={{ display: 'flex', gap: 0.5, flexWrap: 'wrap' }}>
      {chip('critical', scan.critical_count, 'error',   'critical')}
      {chip('error',    scan.error_count,    'warning',  'error')}
      {chip('warn',     scan.warning_count,  'default',  'warning')}
      {chip('info',     scan.info_count,     'info',     'info')}
    </Box>
  );
}

// ─── Single project card ──────────────────────────────────────────────────────
function ProjectCard({ project }: { project: Project }) {
  const theme = useTheme();
  const navigate = useNavigate();
  const scan = project.latest_scan;
  const color = scan?.health_color ?? (scan ? (scan.score! >= 80 ? 'green' : scan.score! >= 50 ? 'yellow' : 'red') : 'grey');
  const trigger = useTriggerScan(project.id);
  const [editOpen, setEditOpen] = useState(false);

  const goToFindings = (severity?: string) =>
    navigate(`/projects/${project.id}${severity ? `?severity=${severity}` : ''}`);

  const borderColor =
    color === 'green' ? theme.palette.success.main :
    color === 'yellow' ? theme.palette.warning.main :
    color === 'red' ? theme.palette.error.main :
    theme.palette.divider;

  const isScanning = scan?.status === 'queued' || scan?.status === 'running';

  return (
    <>
    <EditProjectDialog project={project} open={editOpen} onClose={() => setEditOpen(false)} />
    <Card sx={{ borderRadius: 2, borderTop: `4px solid ${borderColor}`, height: '100%', display: 'flex', flexDirection: 'column' }}>
      {isScanning && (
        <Box>
          <LinearProgress color="info" sx={{ height: 2 }} />
          <Box sx={{ px: 2, py: 0.5, bgcolor: 'action.hover', display: 'flex', alignItems: 'center', gap: 1 }}>
            <CircularProgress size={10} color="info" />
            <Typography variant="caption" color="info.main">
              {scan?.status === 'queued' ? 'Scan queued…' : 'Scanning in progress…'}
            </Typography>
          </Box>
        </Box>
      )}
      <CardContent sx={{ flex: 1 }}>
        {/* Title row */}
        <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', mb: 1 }}>
          <Box>
            <Typography
              variant="subtitle1" fontWeight={700} noWrap
              sx={{ maxWidth: 200, cursor: 'pointer', '&:hover': { color: 'primary.main', textDecoration: 'underline' } }}
              onClick={() => navigate(`/projects/${project.id}`)}
            >
              {project.name}
            </Typography>
            <Typography variant="caption" color="text.secondary">{project.dr_no}</Typography>
          </Box>
          {scan?.grade && (
            <Chip label={scan.grade} size="small" color={color === 'green' ? 'success' : color === 'yellow' ? 'warning' : 'error'} sx={{ fontWeight: 700, fontSize: '0.85rem' }} />
          )}
        </Box>

        {/* Score + breakdown */}
        <Box sx={{ display: 'flex', alignItems: 'center', gap: 2, my: 2 }}>
          <Tooltip title="View all findings">
            <Box sx={{ cursor: 'pointer' }} onClick={() => goToFindings()}>
              <ScoreRing score={scan?.score ?? null} color={color} />
            </Box>
          </Tooltip>
          <Box sx={{ flex: 1 }}>
            <SeverityPills scan={scan} onSeverityClick={goToFindings} />
            {scan && (
              <Typography variant="caption" color="text.secondary" display="block" mt={0.5}>
                {scan.total_files.toLocaleString()} files · {scan.total_lines.toLocaleString()} lines
              </Typography>
            )}
          </Box>
        </Box>

        {/* Sub-indexes progress bars */}
        {scan?.sub_indexes && (
          <Box sx={{ mb: 1 }}>
            {Object.entries(scan.sub_indexes).map(([key, val]) => (
              <Box key={key} sx={{ mb: 0.5 }}>
                <Box sx={{ display: 'flex', justifyContent: 'space-between' }}>
                  <Typography variant="caption" textTransform="capitalize">{key}</Typography>
                  <Typography variant="caption">{val}</Typography>
                </Box>
                <LinearProgress
                  variant="determinate"
                  value={val}
                  color={val >= 80 ? 'success' : val >= 50 ? 'warning' : 'error'}
                  sx={{ height: 4, borderRadius: 2 }}
                />
              </Box>
            ))}
          </Box>
        )}

        {/* Tech stack */}
        {project.tech_stack && (
          <Box sx={{ display: 'flex', gap: 0.5, flexWrap: 'wrap', mt: 1 }}>
            {project.tech_stack.map((t) => (
              <Chip key={t} label={t} size="small" variant="outlined" sx={{ fontSize: '0.65rem' }} />
            ))}
          </Box>
        )}

        {/* Rule coverage badge */}
        {project.rule_coverage != null && (
          <Tooltip
            title={`${project.rule_coverage}% of evaluated rules pass — based on latest scan`}
            arrow
          >
            <Chip
              label={`${project.rule_coverage}% coverage`}
              size="small"
              color={project.rule_coverage >= 80 ? 'success' : project.rule_coverage >= 60 ? 'warning' : 'error'}
              variant="filled"
              sx={{ mt: 1, fontSize: '0.65rem' }}
            />
          </Tooltip>
        )}
      </CardContent>

      <Divider />

      {/* Actions */}
      <Box sx={{ px: 2, py: 1, display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
        <Box sx={{ display: 'flex', alignItems: 'center', gap: 0.5 }}>
          {project.repo_url && (
            <Tooltip title="Open repository">
              <IconButton size="small" href={project.repo_url} target="_blank" rel="noreferrer">
                <GitHub fontSize="small" />
              </IconButton>
            </Tooltip>
          )}
          <Tooltip title="Edit project">
            <IconButton size="small" onClick={() => setEditOpen(true)}>
              <Edit fontSize="small" />
            </IconButton>
          </Tooltip>
        </Box>

        <Tooltip title={!project.local_path ? 'No local path configured — edit project to enable scanning' : ''}>
          <span>
            <Button
              size="small"
              variant="outlined"
              startIcon={trigger.isPending ? <CircularProgress size={12} /> : <PlayArrow />}
              onClick={() => trigger.mutate()}
              disabled={trigger.isPending || !project.local_path}
            >
              {trigger.isPending ? 'Queued' : 'Run Scan'}
            </Button>
          </span>
        </Tooltip>
      </Box>
    </Card>
    </>
  );
}

// ─── Main page ────────────────────────────────────────────────────────────────
export default function OrgDashboardPage() {
  const { user, logout } = useAuth();
  const navigate = useNavigate();
  const qc = useQueryClient();
  const { theme, setTheme } = useDashboardStore();
  const [createOpen, setCreateOpen]   = useState(false);
  const [search, setSearch]           = useState('');
  const [healthTab, setHealthTab]     = useState<HealthTab>('all');
  const [statusFilter, setStatusFilter] = useState('');

  const { data: dash, isLoading: dashLoading } = useDashboardStats();
  const { data: projectsPage, isLoading: projLoading } = useProjects();
  const allProjects: Project[] = projectsPage?.data ?? dash?.projects ?? [];

  const filtered = useMemo(() => {
    let list = allProjects;
    if (search.trim()) {
      const q = search.toLowerCase();
      list = list.filter(p => p.name.toLowerCase().includes(q) || p.dr_no.toLowerCase().includes(q));
    }
    if (statusFilter) list = list.filter(p => p.status === statusFilter);
    if (healthTab === 'healthy')  list = list.filter(p => (p.latest_scan?.score ?? -1) >= 80);
    if (healthTab === 'warning')  list = list.filter(p => { const s = p.latest_scan?.score; return s != null && s >= 50 && s < 80; });
    if (healthTab === 'critical') list = list.filter(p => (p.latest_scan?.score ?? 100) < 50 && p.latest_scan != null);
    if (healthTab === 'none')     list = list.filter(p => !p.latest_scan);
    return list;
  }, [allProjects, search, statusFilter, healthTab]);

  const loading = projLoading || dashLoading;

  return (
    <Box sx={{ minHeight: '100vh', bgcolor: 'background.default' }}>
      {/* ── Top nav ── */}
      <Box sx={{ bgcolor: 'background.paper', borderBottom: 1, borderColor: 'divider', px: 3, py: 1.5, display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
        <Box>
          <Typography variant="h6" fontWeight={700} color="primary">ACQC</Typography>
          <Typography variant="caption" color="text.secondary">Adobe Commerce Quality Control</Typography>
        </Box>
        <Box sx={{ display: 'flex', alignItems: 'center', gap: 1.5 }}>
          <Tooltip title="Upgrade Planning Center">
            <IconButton size="small" onClick={() => navigate('/upgrade-planning')}>
              <SystemUpdateAlt fontSize="small" />
            </IconButton>
          </Tooltip>
          {user?.role === 'admin' && (
            <Tooltip title="User management">
              <IconButton size="small" onClick={() => navigate('/admin/users')}>
                <AdminPanelSettings fontSize="small" />
              </IconButton>
            </Tooltip>
          )}
          <Tooltip title={theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'}>
            <IconButton size="small" onClick={() => setTheme(theme === 'dark' ? 'light' : 'dark')}>
              {theme === 'dark' ? <LightMode fontSize="small" /> : <DarkMode fontSize="small" />}
            </IconButton>
          </Tooltip>
          <IconButton size="small" onClick={() => qc.invalidateQueries()} title="Refresh all">
            <Refresh />
          </IconButton>
          <Avatar sx={{ width: 32, height: 32, bgcolor: 'primary.main', fontSize: 14 }}>
            {user?.name?.[0]?.toUpperCase()}
          </Avatar>
          <Box sx={{ display: { xs: 'none', sm: 'block' } }}>
            <Typography variant="body2" fontWeight={600}>{user?.name}</Typography>
            <Typography variant="caption" color="text.secondary">{user?.role}</Typography>
          </Box>
          <Tooltip title="Sign out">
            <IconButton size="small" onClick={logout}><Logout fontSize="small" /></IconButton>
          </Tooltip>
        </Box>
      </Box>

      <CreateProjectDialog open={createOpen} onClose={() => setCreateOpen(false)} />

      <Container maxWidth="xl" sx={{ py: 4 }}>
        {/* ── Title + New Project ── */}
        <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', mb: 3 }}>
          <Box>
            <Typography variant="h4" fontWeight={700} mb={0.5}>Adobe Commerce Quality Overview</Typography>
            <Typography variant="body2" color="text.secondary">
              All projects · live data from the ACQC Central Server
            </Typography>
          </Box>
          <Button variant="contained" startIcon={<Add />} onClick={() => setCreateOpen(true)}>
            New Project
          </Button>
        </Box>

        {/* ── Executive summary ── */}
        {dashLoading ? (
          <Box sx={{ display: 'flex', gap: 2, mb: 3, flexWrap: 'wrap' }}>
            {[...Array(4)].map((_, i) => <Skeleton key={i} variant="rectangular" height={80} sx={{ borderRadius: 2, flex: 1, minWidth: 140 }} />)}
          </Box>
        ) : dash?.stats && (
          <>
            <Box sx={{ display: 'flex', gap: 2, mb: 2, flexWrap: 'wrap' }}>
              <ExecMetric
                label="Org Avg Quality Score"
                value={dash.stats.avg_score}
                delta={dash.stats.avg_score_change}
                unit="/100"
                color={dash.stats.avg_score == null ? 'text.disabled' : dash.stats.avg_score >= 80 ? 'success.main' : dash.stats.avg_score >= 50 ? 'warning.main' : 'error.main'}
              />
              <ExecMetric label="Open Critical Issues"  value={dash.stats.total_open_critical} color={dash.stats.total_open_critical > 0 ? 'error.main' : 'success.main'} />
              <ExecMetric label="Open Errors"           value={dash.stats.total_open_error}    color={dash.stats.total_open_error    > 0 ? 'warning.main' : 'success.main'} />
              <ExecMetric label="Resolved This Month"   value={dash.stats.resolved_this_month} color="primary.main" />
              <ExecMetric label="Scans This Week"       value={dash.stats.scans_this_week} />
              <ExecMetric
                label="Acknowledged Rules"
                value={dash.stats.total_suppressions}
                color={dash.stats.expired_suppressions > 0 ? 'warning.main' : 'text.primary'}
              />
            </Box>
            {dash.score_history && <ScoreSparkline history={dash.score_history} />}
          </>
        )}

        {/* ── Clickable stat cards ── */}
        {dashLoading ? (
          <Grid container spacing={2} mb={4}>
            {[...Array(4)].map((_, i) => <Grid item xs={6} sm={3} key={i}><Skeleton variant="rectangular" height={90} sx={{ borderRadius: 2 }} /></Grid>)}
          </Grid>
        ) : dash?.stats && (
          <Grid container spacing={2} mb={3}>
            <Grid item xs={6} sm={3}>
              <StatCard label="Total Projects"  value={dash.stats.total_projects} color="text.primary"   active={healthTab === 'all'}      onClick={() => setHealthTab('all')} />
            </Grid>
            <Grid item xs={6} sm={3}>
              <StatCard label="Healthy (≥80)"   value={dash.stats.healthy}        color="success.main"   active={healthTab === 'healthy'}  onClick={() => setHealthTab(healthTab === 'healthy'  ? 'all' : 'healthy')} />
            </Grid>
            <Grid item xs={6} sm={3}>
              <StatCard label="At Risk (<50)"   value={dash.stats.at_risk}         color="error.main"     active={healthTab === 'critical'} onClick={() => setHealthTab(healthTab === 'critical' ? 'all' : 'critical')} />
            </Grid>
            <Grid item xs={6} sm={3}>
              <StatCard label="Not Scanned"     value={dash.stats.no_scan}         color="text.disabled"  active={healthTab === 'none'}     onClick={() => setHealthTab(healthTab === 'none'     ? 'all' : 'none')} />
            </Grid>
          </Grid>
        )}

        {/* ── Filters bar ── */}
        <Box sx={{ display: 'flex', gap: 2, mb: 3, flexWrap: 'wrap', alignItems: 'center' }}>
          <TextField
            size="small" placeholder="Search name or DR no…" value={search}
            onChange={(e) => setSearch(e.target.value)}
            sx={{ minWidth: 240 }}
            InputProps={{ startAdornment: <InputAdornment position="start"><Search fontSize="small" /></InputAdornment> }}
          />

          <Tabs value={healthTab} onChange={(_, v) => setHealthTab(v)} sx={{ minHeight: 36 }}
            TabIndicatorProps={{ style: { height: 3 } }}>
            <Tab value="all"      label="All"      sx={{ minHeight: 36, py: 0 }} />
            <Tab value="healthy"  label="Healthy"  sx={{ minHeight: 36, py: 0, color: 'success.main' }} />
            <Tab value="warning"  label="Warning"  sx={{ minHeight: 36, py: 0, color: 'warning.main' }} />
            <Tab value="critical" label="Critical" sx={{ minHeight: 36, py: 0, color: 'error.main' }} />
            <Tab value="none"     label="No Scan"  sx={{ minHeight: 36, py: 0 }} />
          </Tabs>

          <FormControl size="small" sx={{ minWidth: 140 }}>
            <InputLabel>Status</InputLabel>
            <Select value={statusFilter} label="Status" onChange={(e) => setStatusFilter(e.target.value)}>
              <MenuItem value="">All</MenuItem>
              <MenuItem value="active">Active</MenuItem>
              <MenuItem value="in_review">In Review</MenuItem>
              <MenuItem value="archived">Archived</MenuItem>
            </Select>
          </FormControl>

          {(search || statusFilter || healthTab !== 'all') && (
            <Button size="small" onClick={() => { setSearch(''); setStatusFilter(''); setHealthTab('all'); }}>
              Clear filters
            </Button>
          )}

          <Typography variant="body2" color="text.secondary" sx={{ ml: 'auto' }}>
            {loading ? '' : `${filtered.length} of ${allProjects.length} projects`}
          </Typography>
        </Box>

        {/* ── Project grid ── */}
        {loading ? (
          <Grid container spacing={3}>
            {[...Array(4)].map((_, i) => (
              <Grid item xs={12} sm={6} lg={4} xl={3} key={i}>
                <Skeleton variant="rectangular" height={320} sx={{ borderRadius: 2 }} />
              </Grid>
            ))}
          </Grid>
        ) : filtered.length === 0 ? (
          <Box sx={{ textAlign: 'center', py: 10 }}>
            <ErrorIcon sx={{ fontSize: 48, color: 'text.disabled', mb: 2 }} />
            <Typography color="text.secondary">
              {allProjects.length === 0 ? 'No projects yet. Create your first project.' : 'No projects match the current filters.'}
            </Typography>
          </Box>
        ) : (
          <Grid container spacing={3}>
            {filtered.map((p) => (
              <Grid item xs={12} sm={6} lg={4} xl={3} key={p.id}>
                <ProjectCard project={p} />
              </Grid>
            ))}
          </Grid>
        )}
      </Container>
    </Box>
  );
}

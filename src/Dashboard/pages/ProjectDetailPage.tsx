import {
  Alert,
  Box,
  Button,
  Card,
  CardContent,
  Chip,
  CircularProgress,
  Container,
  Divider,
  FormControl,
  Grid,
  IconButton,
  InputLabel,
  MenuItem,
  Select,
  Skeleton,
  Table,
  TableBody,
  TableCell,
  TableContainer,
  TableHead,
  TableRow,
  TablePagination,
  Tooltip,
  Typography,
  LinearProgress,
  useTheme,
} from '@mui/material';
import {
  ResponsiveContainer,
  LineChart, Line,
  BarChart, Bar, Cell,
  XAxis, YAxis,
  CartesianGrid,
  Tooltip as ReTooltip,
  ReferenceLine,
} from 'recharts';
import {
  ArrowBack,
  CheckCircle,
  Download,
  Edit,
  ErrorOutline,
  GitHub,
  PlayArrow,
  RadioButtonUnchecked,
  TrendingDown,
  TrendingFlat,
  TrendingUp,
} from '@mui/icons-material';
import { useState, useEffect } from 'react';
import { useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { useExportFindings, useProject, useScans, useFindings, useResolveFinding, useTriggerScan } from '../hooks/useServerData';
import EditProjectDialog from '../components/EditProjectDialog';
import AcknowledgedSuppressions from '../components/AcknowledgedSuppressions';
import type { Finding, Scan } from '../utils/serverApi';

const SEVERITY_COLOR: Record<string, 'error' | 'warning' | 'info' | 'default'> = {
  blocker:  'error',
  critical: 'error',
  error:    'warning',
  warning:  'warning',
  info:     'info',
  minor:    'default',
};

// ─── Scan selector sidebar card ───────────────────────────────────────────────
function ScanRow({ scan, selected, onClick }: { scan: Scan; selected: boolean; onClick: () => void }) {
  const color = scan.health_color ?? (scan.score != null ? (scan.score >= 80 ? 'success' : scan.score >= 50 ? 'warning' : 'error') : 'default');
  const isActive = scan.status === 'queued' || scan.status === 'running';

  return (
    <Box
      onClick={onClick}
      sx={{
        p: 1.5, cursor: 'pointer', borderRadius: 1,
        bgcolor: selected ? 'action.selected' : 'transparent',
        '&:hover': { bgcolor: 'action.hover' },
        borderLeft: selected ? 4 : 0,
        borderColor: 'primary.main',
        transition: 'all 0.15s',
      }}
    >
      <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
        <Typography variant="body2" fontWeight={selected ? 700 : 400}>
          {scan.status === 'complete' && scan.completed_at
            ? new Date(scan.completed_at).toLocaleDateString()
            : scan.status === 'failed' ? 'Failed'
            : scan.status === 'running' ? 'Scanning…'
            : 'Queued'}
        </Typography>
        {scan.status === 'complete' && scan.score != null && (
          <Chip label={`${scan.score} ${scan.grade ?? ''}`} size="small" color={color as 'success' | 'warning' | 'error' | 'default'} />
        )}
        {scan.status === 'failed' && (
          <Tooltip title={scan.error_message ?? 'Scan failed — check server logs'} arrow>
            <ErrorOutline fontSize="small" color="error" />
          </Tooltip>
        )}
        {isActive && <CircularProgress size={14} color="info" />}
      </Box>
      {isActive ? (
        <LinearProgress color="info" sx={{ mt: 0.75, height: 2, borderRadius: 1 }} />
      ) : (
        <Typography variant="caption" color={scan.status === 'failed' ? 'error' : 'text.secondary'}
          sx={{ display: 'block', maxWidth: 180, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
          {scan.status === 'complete'
            ? `${scan.total_files} files · ${scan.critical_count + scan.error_count} issues`
            : scan.status === 'failed' ? (scan.error_message ?? 'Scan failed')
            : 'Waiting in queue…'}
        </Typography>
      )}
    </Box>
  );
}

// ─── Score trend chart ────────────────────────────────────────────────────────
function ScoreTrendChart({ scans }: { scans: Scan[] }) {
  const theme = useTheme();
  const data = [...scans]
    .filter((s) => s.status === 'complete' && s.score != null)
    .reverse()
    .map((s) => ({
      date: s.completed_at ? new Date(s.completed_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) : '—',
      score: s.score,
      grade: s.grade,
    }));

  if (data.length < 2) return null;

  return (
    <Card variant="outlined" sx={{ borderRadius: 2, mb: 3 }}>
      <CardContent>
        <Typography variant="subtitle2" fontWeight={700} mb={2}>Quality Score Trend</Typography>
        <ResponsiveContainer width="100%" height={160}>
          <LineChart data={data} margin={{ top: 4, right: 16, bottom: 4, left: 0 }}>
            <CartesianGrid strokeDasharray="3 3" stroke={theme.palette.divider} />
            <XAxis dataKey="date" tick={{ fontSize: 11 }} />
            <YAxis domain={[0, 100]} tick={{ fontSize: 11 }} width={28} />
            <ReTooltip
              formatter={(v: number) => [`${v}/100`, 'Score']}
            />
            <ReferenceLine y={80} stroke={theme.palette.success.main} strokeDasharray="4 2" label={{ value: 'Good', fontSize: 10, fill: theme.palette.success.main }} />
            <ReferenceLine y={50} stroke={theme.palette.error.main}   strokeDasharray="4 2" label={{ value: 'At Risk', fontSize: 10, fill: theme.palette.error.main }} />
            <Line type="monotone" dataKey="score" stroke={theme.palette.primary.main} strokeWidth={2} dot={{ r: 4 }} activeDot={{ r: 6 }} />
          </LineChart>
        </ResponsiveContainer>
      </CardContent>
    </Card>
  );
}

const PROFILES = ['developer', 'tech-lead', 'architect', 'executive', 'ci'];

// ─── Findings table ───────────────────────────────────────────────────────────
function FindingsTable({
  scanId, initialSeverity = '', initialCategory = '',
}: {
  scanId: number;
  initialSeverity?: string;
  initialCategory?: string;
}) {
  const [severity, setSeverity] = useState(initialSeverity);
  const [category, setCategory] = useState(initialCategory);
  const [resolved, setResolved] = useState('');
  const [profile, setProfile] = useState('');
  const [page, setPage] = useState(0);

  useEffect(() => { setSeverity(initialSeverity); setPage(0); }, [initialSeverity]);
  useEffect(() => { setCategory(initialCategory); setPage(0); }, [initialCategory]);
  const { resolve, unresolve } = useResolveFinding(scanId);

  const { data, isLoading } = useFindings(scanId, {
    severity:  severity  || undefined,
    category:  category  || undefined,
    resolved:  resolved  || undefined,
    profile:   profile   || undefined,
    page: page + 1,
  });

  const findings: Finding[] = data?.data ?? [];
  const total = data?.total ?? 0;

  return (
    <Box>
      {/* Filters */}
      <Box sx={{ display: 'flex', gap: 2, mb: 2, flexWrap: 'wrap' }}>
        <FormControl size="small" sx={{ minWidth: 130 }}>
          <InputLabel>Severity</InputLabel>
          <Select value={severity} label="Severity" onChange={(e) => { setSeverity(e.target.value); setPage(0); }}>
            <MenuItem value="">All</MenuItem>
            {['blocker','critical','error','warning','info','minor'].map((s) => (
              <MenuItem key={s} value={s}>{s}</MenuItem>
            ))}
          </Select>
        </FormControl>

        <FormControl size="small" sx={{ minWidth: 150 }}>
          <InputLabel>Category</InputLabel>
          <Select value={category} label="Category" onChange={(e) => { setCategory(e.target.value); setPage(0); }}>
            <MenuItem value="">All</MenuItem>
            {['security','performance','maintainability','standards'].map((c) => (
              <MenuItem key={c} value={c}>{c}</MenuItem>
            ))}
          </Select>
        </FormControl>

        <FormControl size="small" sx={{ minWidth: 130 }}>
          <InputLabel>Status</InputLabel>
          <Select value={resolved} label="Status" onChange={(e) => { setResolved(e.target.value); setPage(0); }}>
            <MenuItem value="">All</MenuItem>
            <MenuItem value="false">Open</MenuItem>
            <MenuItem value="true">Resolved</MenuItem>
          </Select>
        </FormControl>

        <FormControl size="small" sx={{ minWidth: 130 }}>
          <InputLabel>Profile</InputLabel>
          <Select value={profile} label="Profile" onChange={(e) => { setProfile(e.target.value); setPage(0); }}>
            <MenuItem value="">Default</MenuItem>
            {PROFILES.map((p) => <MenuItem key={p} value={p}>{p}</MenuItem>)}
          </Select>
        </FormControl>
      </Box>

      {isLoading ? (
        <LinearProgress />
      ) : findings.length === 0 ? (
        <Alert severity="success" sx={{ mt: 1 }}>No findings match the current filters.</Alert>
      ) : (
        <TableContainer component={Card} variant="outlined">
          <Table size="small">
            <TableHead>
              <TableRow sx={{ bgcolor: 'action.hover' }}>
                <TableCell width={32} />
                <TableCell>Rule / Description</TableCell>
                <TableCell>File</TableCell>
                <TableCell width={90}>Severity</TableCell>
                <TableCell width={110}>Category</TableCell>
                <TableCell width={80} align="center">Resolve</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {findings.map((f) => (
                <TableRow
                  key={f.id}
                  sx={{ opacity: f.resolved_at ? 0.5 : 1, '&:hover': { bgcolor: 'action.hover' } }}
                >
                  <TableCell>
                    {f.resolved_at
                      ? <CheckCircle fontSize="small" color="success" />
                      : <RadioButtonUnchecked fontSize="small" color="disabled" />}
                  </TableCell>
                  <TableCell>
                    <Typography variant="body2" fontWeight={600}>{f.rule_name}</Typography>
                    <Typography variant="caption" color="text.secondary" sx={{ display: 'block', maxWidth: 400 }} noWrap>
                      {f.description}
                    </Typography>
                    {f.recommendation && (
                      <Typography variant="caption" color="primary" sx={{ display: 'block' }} noWrap>
                        💡 {f.recommendation}
                      </Typography>
                    )}
                  </TableCell>
                  <TableCell>
                    <Typography variant="caption" fontFamily="monospace" sx={{ wordBreak: 'break-all' }}>
                      {f.file_path}{f.line_number ? `:${f.line_number}` : ''}
                    </Typography>
                  </TableCell>
                  <TableCell>
                    <Chip label={f.severity} size="small" color={SEVERITY_COLOR[f.severity] ?? 'default'} />
                  </TableCell>
                  <TableCell>
                    {f.category && <Chip label={f.category} size="small" variant="outlined" />}
                  </TableCell>
                  <TableCell align="center">
                    <Tooltip title={f.resolved_at ? 'Mark open' : 'Mark resolved'}>
                      <IconButton
                        size="small"
                        disabled={resolve.isPending || unresolve.isPending}
                        onClick={() => f.resolved_at ? unresolve.mutate(f.id) : resolve.mutate(f.id)}
                      >
                        {f.resolved_at
                          ? <RadioButtonUnchecked fontSize="small" />
                          : <CheckCircle fontSize="small" />}
                      </IconButton>
                    </Tooltip>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
          <TablePagination
            component="div"
            count={total}
            page={page}
            rowsPerPage={50}
            rowsPerPageOptions={[50]}
            onPageChange={(_, p) => setPage(p)}
          />
        </TableContainer>
      )}
    </Box>
  );
}

// ─── Category breakdown chart ─────────────────────────────────────────────────
const CAT_COLORS: Record<string, string> = {
  security:        '#f44336',
  performance:     '#ff9800',
  maintainability: '#2196f3',
  standards:       '#9c27b0',
};

function CategoryChart({
  breakdown, onCategoryClick,
}: { breakdown: Record<string, number>; onCategoryClick: (cat: string) => void }) {
  const theme = useTheme();
  const data = Object.entries(breakdown)
    .filter(([, v]) => v > 0)
    .sort(([, a], [, b]) => b - a)
    .map(([key, value]) => ({ category: key, value, fill: CAT_COLORS[key] ?? theme.palette.primary.main }));

  if (!data.length) return null;

  return (
    <Card variant="outlined" sx={{ borderRadius: 2, mb: 3 }}>
      <CardContent>
        <Typography variant="subtitle2" fontWeight={700} mb={0.5}>Issues by Category</Typography>
        <Typography variant="caption" color="text.secondary" display="block" mb={2}>
          Click a bar to filter findings
        </Typography>
        <ResponsiveContainer width="100%" height={140}>
          <BarChart data={data} layout="vertical" margin={{ left: 8, right: 32, top: 0, bottom: 0 }}>
            <CartesianGrid strokeDasharray="3 3" horizontal={false} stroke={theme.palette.divider} />
            <XAxis type="number" tick={{ fontSize: 11 }} />
            <YAxis type="category" dataKey="category" tick={{ fontSize: 11 }} width={100} />
            <ReTooltip
              formatter={(v: number, name: string) => [v, name]}
              cursor={{ fill: theme.palette.action.hover }}
            />
            <Bar dataKey="value" radius={[0, 4, 4, 0]} cursor="pointer"
              onClick={(entry) => onCategoryClick(entry.category)}>
              {data.map((d) => <Cell key={d.category} fill={d.fill} />)}
            </Bar>
          </BarChart>
        </ResponsiveContainer>
      </CardContent>
    </Card>
  );
}

// ─── Main page ────────────────────────────────────────────────────────────────
export default function ProjectDetailPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const projectId = Number(id);

  const { data: project, isLoading: projLoading } = useProject(projectId);
  const { data: scansPage, isLoading: scansLoading } = useScans(projectId);
  const trigger = useTriggerScan(projectId);
  const exportFindings = useExportFindings();

  const scans: Scan[] = scansPage?.data ?? [];
  const [selectedScanId, setSelectedScanId] = useState<number | null>(null);
  const activeScanId = selectedScanId ?? scans.find((s) => s.status === 'complete')?.id ?? null;

  const activeScan = scans.find((s) => s.id === activeScanId) ?? null;
  const activeScanIdx = scans.findIndex((s) => s.id === activeScanId);
  const prevScan = activeScanIdx >= 0 && activeScanIdx + 1 < scans.length ? scans[activeScanIdx + 1] : null;
  const scoreDelta = activeScan?.score != null && prevScan?.score != null
    ? activeScan.score - prevScan.score : null;

  const [editOpen, setEditOpen] = useState(false);

  // Pre-fill filters from URL query params (e.g. ?severity=critical from org dashboard click)
  const [filterSeverity, setFilterSeverity] = useState(searchParams.get('severity') ?? '');
  const [filterCategory, setFilterCategory] = useState(searchParams.get('category') ?? '');

  return (
    <Box sx={{ minHeight: '100vh', bgcolor: 'background.default' }}>
      {project && <EditProjectDialog project={project} open={editOpen} onClose={() => setEditOpen(false)} />}

      {/* Top bar */}
      <Box sx={{ bgcolor: 'background.paper', borderBottom: 1, borderColor: 'divider', px: 3, py: 1.5, display: 'flex', alignItems: 'center', gap: 2 }}>
        <IconButton onClick={() => navigate('/')} size="small">
          <ArrowBack />
        </IconButton>
        {projLoading ? (
          <Skeleton width={200} height={28} />
        ) : (
          <>
            <Box sx={{ flex: 1 }}>
              <Typography variant="h6" fontWeight={700}>{project?.name}</Typography>
              <Typography variant="caption" color="text.secondary">{project?.dr_no}</Typography>
            </Box>
            {project?.repo_url && (
              <Tooltip title="Open repository">
                <IconButton size="small" href={project.repo_url} target="_blank" rel="noreferrer">
                  <GitHub />
                </IconButton>
              </Tooltip>
            )}
            <Tooltip title="Edit project">
              <IconButton size="small" onClick={() => setEditOpen(true)}>
                <Edit />
              </IconButton>
            </Tooltip>
            <Tooltip title={!project?.local_path ? 'No local path configured — edit project to enable scanning' : ''}>
              <span>
                <Button
                  variant="contained"
                  size="small"
                  startIcon={trigger.isPending ? <CircularProgress size={14} color="inherit" /> : <PlayArrow />}
                  onClick={() => { trigger.mutate(); setSelectedScanId(null); }}
                  disabled={trigger.isPending || !project?.local_path}
                >
                  {trigger.isPending ? 'Queued…' : 'Run Scan'}
                </Button>
              </span>
            </Tooltip>
          </>
        )}
      </Box>

      <Container maxWidth="xl" sx={{ py: 3 }}>
        {!projLoading && project && !project.local_path && (
          <Alert
            severity="warning"
            sx={{ mb: 3 }}
            action={
              <Button size="small" color="inherit" onClick={() => setEditOpen(true)}>
                Configure now
              </Button>
            }
          >
            No local path configured for this project — scans cannot run until you set the absolute path to the checked-out repository on this server.
          </Alert>
        )}
        <Grid container spacing={3}>
          {/* ── Scan history sidebar ── */}
          <Grid item xs={12} md={3}>
            <Card variant="outlined" sx={{ borderRadius: 2 }}>
              <CardContent sx={{ pb: '12px !important' }}>
                <Typography variant="subtitle2" fontWeight={700} mb={1}>Scan History</Typography>
                <Divider sx={{ mb: 1 }} />
                {scansLoading ? (
                  [...Array(4)].map((_, i) => <Skeleton key={i} height={52} sx={{ mb: 0.5 }} />)
                ) : scans.length === 0 ? (
                  <Typography variant="caption" color="text.secondary">No scans yet.</Typography>
                ) : (
                  scans.map((s) => (
                    <ScanRow
                      key={s.id}
                      scan={s}
                      selected={s.id === activeScanId}
                      onClick={() => setSelectedScanId(s.id)}
                    />
                  ))
                )}
              </CardContent>
            </Card>
          </Grid>

          {/* ── Findings panel ── */}
          <Grid item xs={12} md={9}>
            {activeScanId ? (
              <>
                {/* Selected scan summary */}
                {activeScan && (
                  <Card variant="outlined" sx={{ borderRadius: 2, mb: 3 }}>
                    <CardContent>
                      <Grid container spacing={2} alignItems="center">
                        <Grid item xs="auto">
                          <Box sx={{ position: 'relative', display: 'inline-flex' }}>
                            <CircularProgress
                              variant="determinate"
                              value={activeScan.score ?? 0}
                              size={64}
                              color={activeScan.score != null && activeScan.score >= 80 ? 'success' : activeScan.score != null && activeScan.score >= 50 ? 'warning' : 'error'}
                            />
                            <Box sx={{ position: 'absolute', inset: 0, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                              <Typography variant="body2" fontWeight={700}>{activeScan.score ?? '—'}</Typography>
                            </Box>
                          </Box>
                        </Grid>
                        <Grid item xs>
                          <Box sx={{ display: 'flex', gap: 1, flexWrap: 'wrap', alignItems: 'center' }}>
                            {activeScan.critical_count > 0 && <Chip label={`${activeScan.critical_count} critical`} size="small" color="error" />}
                            {activeScan.error_count > 0 && <Chip label={`${activeScan.error_count} error`} size="small" color="warning" />}
                            {activeScan.warning_count > 0 && <Chip label={`${activeScan.warning_count} warning`} size="small" variant="outlined" />}
                            {activeScan.info_count > 0 && <Chip label={`${activeScan.info_count} info`} size="small" variant="outlined" color="info" />}
                            {scoreDelta !== null && (
                              <Tooltip title={`vs previous scan (score was ${prevScan?.score})`}>
                                <Chip
                                  size="small"
                                  icon={scoreDelta > 0 ? <TrendingUp /> : scoreDelta < 0 ? <TrendingDown /> : <TrendingFlat />}
                                  label={scoreDelta > 0 ? `+${scoreDelta}` : `${scoreDelta}`}
                                  color={scoreDelta > 0 ? 'success' : scoreDelta < 0 ? 'error' : 'default'}
                                  variant="outlined"
                                />
                              </Tooltip>
                            )}
                          </Box>
                          <Typography variant="caption" color="text.secondary" display="block" mt={0.5}>
                            {activeScan.total_files.toLocaleString()} files · {activeScan.total_lines.toLocaleString()} lines
                            {activeScan.completed_at && ` · ${new Date(activeScan.completed_at).toLocaleString()}`}
                          </Typography>
                        </Grid>
                      </Grid>
                    </CardContent>
                  </Card>
                )}

                <ScoreTrendChart scans={scans} />

                {(() => {
                  const scan = scans.find((s) => s.id === activeScanId);
                  return scan?.category_breakdown
                    ? <CategoryChart breakdown={scan.category_breakdown} onCategoryClick={(cat) => { setFilterCategory(cat); setFilterSeverity(''); }} />
                    : null;
                })()}

                <AcknowledgedSuppressions projectId={projectId} />

                <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', mb: 2 }}>
                  <Typography variant="subtitle1" fontWeight={700}>Findings</Typography>
                  <Box sx={{ display: 'flex', gap: 1 }}>
                    {(filterSeverity || filterCategory) && (
                      <Button size="small" onClick={() => { setFilterSeverity(''); setFilterCategory(''); }}>
                        Clear filter
                      </Button>
                    )}
                    <Tooltip title="Download filtered findings as CSV">
                      <span>
                        <Button
                          size="small" variant="outlined"
                          startIcon={exportFindings.isPending ? <CircularProgress size={12} /> : <Download />}
                          disabled={exportFindings.isPending || !activeScanId}
                          onClick={() => exportFindings.mutate({
                            scanId: activeScanId!,
                            filters: {
                              severity: filterSeverity || undefined,
                              category: filterCategory || undefined,
                            },
                          })}
                        >
                          {exportFindings.isPending ? 'Exporting…' : 'Export CSV'}
                        </Button>
                      </span>
                    </Tooltip>
                  </Box>
                </Box>
                <FindingsTable
                  scanId={activeScanId}
                  initialSeverity={filterSeverity}
                  initialCategory={filterCategory}
                />
              </>
            ) : (
              <Box sx={{ textAlign: 'center', py: 10 }}>
                <Typography color="text.secondary">
                  {scansLoading ? 'Loading scans…' : 'No completed scan selected. Run a scan to see findings.'}
                </Typography>
              </Box>
            )}
          </Grid>
        </Grid>
      </Container>
    </Box>
  );
}

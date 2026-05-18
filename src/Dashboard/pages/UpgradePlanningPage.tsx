import {
  Alert,
  Box,
  Button,
  ButtonGroup,
  Card,
  CardContent,
  CircularProgress,
  Container,
  Divider,
  Grid,
  Skeleton,
  Tab,
  Tabs,
  Typography,
} from '@mui/material';
import { DataGrid, GridColDef } from '@mui/x-data-grid';
import { SystemUpdateAlt } from '@mui/icons-material';
import { useState } from 'react';
import { usePortfolioMatrix, useUpgradeScan, useUpgradeScans, useVersionRegistry } from '../hooks/useServerData';
import PortfolioMatrix from '../components/Upgrade/PortfolioMatrix';
import UpgradeRiskGauge from '../components/Upgrade/UpgradeRiskGauge';
import SprintPlanAccordion from '../components/Upgrade/SprintPlanAccordion';
import DBRiskTable from '../components/Upgrade/DBRiskTable';
import type { UpgradeScan } from '../utils/serverApi';

// ─── Effort stat card ─────────────────────────────────────────────────────────
function StatCard({ label, value, unit = 'h' }: { label: string; value: number | null; unit?: string }) {
  return (
    <Card variant="outlined">
      <CardContent sx={{ py: 1.5, textAlign: 'center' }}>
        <Typography variant="h5" fontWeight={700}>
          {value != null ? `${value.toLocaleString()}${unit}` : '—'}
        </Typography>
        <Typography variant="caption" color="text.secondary">
          {label}
        </Typography>
      </CardContent>
    </Card>
  );
}

// ─── All-issues DataGrid ──────────────────────────────────────────────────────
const allIssueColumns: GridColDef[] = [
  { field: 'severity',    headerName: 'Severity', width: 110 },
  { field: 'type',        headerName: 'Type',     width: 160 },
  { field: 'file_path',   headerName: 'File',     flex: 1, minWidth: 200 },
  { field: 'line_number', headerName: 'Line',     width: 80, type: 'number' },
  { field: 'message',     headerName: 'Message',  flex: 2, minWidth: 200 },
  { field: 'est_hours',   headerName: 'Est Hrs',  width: 90, type: 'number' },
];

// ─── Empty state ─────────────────────────────────────────────────────────────
function EmptyState() {
  return (
    <Card variant="outlined" sx={{ p: 4, textAlign: 'center' }}>
      <SystemUpdateAlt sx={{ fontSize: 56, color: 'text.disabled', mb: 2 }} />
      <Typography variant="h6" gutterBottom>
        No upgrade scans yet
      </Typography>
      <Typography variant="body2" color="text.secondary" sx={{ mb: 3, maxWidth: 480, mx: 'auto' }}>
        Run <code>acqc upgrade:analyze --save --target-version 2.4.8</code> on a project to
        generate an Upgrade Risk Index and persist results. The scan results will appear here
        automatically.
      </Typography>
      <Alert severity="info" sx={{ textAlign: 'left' }}>
        Tip: Add <code>--upgrade-recommendations</code> to include FluffyJaws remediation
        guidance per finding.
      </Alert>
    </Card>
  );
}

// ─── Project detail panel ─────────────────────────────────────────────────────
function ProjectDetail({
  projectId,
  scanId,
}: {
  projectId: number;
  scanId: number;
}) {
  const [tab, setTab] = useState(0);
  const { data: scan, isLoading } = useUpgradeScan(projectId, scanId);

  if (isLoading) {
    return (
      <Box sx={{ display: 'flex', justifyContent: 'center', py: 4 }}>
        <CircularProgress />
      </Box>
    );
  }

  if (!scan) return null;

  const report = scan.full_report_json as {
    sprint_breakdown?: Parameters<typeof SprintPlanAccordion>[0]['sprintBreakdown'];
    findings?: unknown[];
  } | null;

  const sprintBreakdown = report?.sprint_breakdown ?? [];
  const allFindings = (report?.findings ?? []) as Record<string, unknown>[];
  const allFindingRows = allFindings.map((f, i) => ({ id: i, ...f }));

  return (
    <Box>
      {/* Gauge + version */}
      <Box sx={{ display: 'flex', alignItems: 'center', gap: 3, mb: 3 }}>
        <UpgradeRiskGauge
          score={scan.uri_score}
          grade={scan.uri_grade}
          size="lg"
        />
        <Box>
          <Typography variant="h6" fontWeight={700}>
            {scan.source_version} → {scan.target_version}
          </Typography>
          {scan.recommended_timeline && (
            <Typography variant="body2" color="text.secondary">
              Recommended timeline: {scan.recommended_timeline}
            </Typography>
          )}
          <Box sx={{ display: 'flex', gap: 2, mt: 1, flexWrap: 'wrap' }}>
            {scan.blocker_count > 0 && (
              <Typography variant="caption" color="error.main" fontWeight={700}>
                {scan.blocker_count} blockers
              </Typography>
            )}
            {scan.critical_count > 0 && (
              <Typography variant="caption" color="error.main">
                {scan.critical_count} critical
              </Typography>
            )}
            {scan.error_count > 0 && (
              <Typography variant="caption" color="warning.main">
                {scan.error_count} errors
              </Typography>
            )}
            {scan.warning_count > 0 && (
              <Typography variant="caption" color="text.secondary">
                {scan.warning_count} warnings
              </Typography>
            )}
          </Box>
        </Box>
      </Box>

      {/* Effort summary */}
      <Grid container spacing={1.5} sx={{ mb: 3 }}>
        <Grid item xs={6} sm={3}>
          <StatCard label="Engineering" value={scan.engineering_hours} />
        </Grid>
        <Grid item xs={6} sm={3}>
          <StatCard label="QA" value={scan.qa_hours} />
        </Grid>
        <Grid item xs={6} sm={3}>
          <StatCard label="Infra" value={scan.infra_hours} />
        </Grid>
        <Grid item xs={6} sm={3}>
          <StatCard label="Total + Contingency" value={scan.total_hours_with_contingency} />
        </Grid>
      </Grid>

      {/* Tabs */}
      <Tabs
        value={tab}
        onChange={(_, v) => setTab(v)}
        sx={{ borderBottom: 1, borderColor: 'divider', mb: 2 }}
      >
        <Tab label="Sprint Plan" />
        <Tab label="DB Risks" />
        <Tab label="All Issues" />
      </Tabs>

      {tab === 0 && <SprintPlanAccordion sprintBreakdown={sprintBreakdown} />}
      {tab === 1 && <DBRiskTable findings={allFindings as Parameters<typeof DBRiskTable>[0]['findings']} />}
      {tab === 2 && (
        <DataGrid
          rows={allFindingRows}
          columns={allIssueColumns}
          density="compact"
          autoHeight
          disableRowSelectionOnClick
          pageSizeOptions={[25, 50, 100]}
          sx={{ border: 0 }}
        />
      )}
    </Box>
  );
}

// ─── Page ─────────────────────────────────────────────────────────────────────
export default function UpgradePlanningPage() {
  const { data: matrix, isLoading: matrixLoading } = usePortfolioMatrix();
  const { data: registry } = useVersionRegistry();

  const [selectedProjectId, setSelectedProjectId] = useState<number | null>(null);
  const [selectedTargetVersion, setSelectedTargetVersion] = useState<string | null>(null);
  const [selectedScanId, setSelectedScanId] = useState<number | null>(null);
  const [filterVersion, setFilterVersion] = useState<string | null>(null);

  // When clicking a matrix cell, load that project's scans and pick the matching one
  const { data: projectScans } = useUpgradeScans(selectedProjectId ?? 0);

  const handleProjectSelect = (projectId: number, targetVersion: string) => {
    setSelectedProjectId(projectId);
    setSelectedTargetVersion(targetVersion);
    setSelectedScanId(null);
  };

  // Resolve scanId once projectScans loads
  if (
    selectedProjectId &&
    selectedTargetVersion &&
    !selectedScanId &&
    projectScans?.data?.length
  ) {
    const match = projectScans.data.find(
      (s: UpgradeScan) => s.target_version === selectedTargetVersion
    );
    if (match) setSelectedScanId(match.id);
  }

  const versions = registry?.versions ?? [];
  const hasAnyScans = (matrix?.projects ?? []).some((p) => p.scans.length > 0);

  return (
    <Container maxWidth="xl" sx={{ py: 3 }}>
      {/* Header */}
      <Box sx={{ display: 'flex', alignItems: 'center', gap: 2, mb: 3, flexWrap: 'wrap' }}>
        <SystemUpdateAlt color="primary" />
        <Typography variant="h5" fontWeight={700}>
          Upgrade Planning Center
        </Typography>

        {versions.length > 0 && (
          <ButtonGroup size="small" variant="outlined" sx={{ ml: 'auto' }}>
            <Button
              onClick={() => setFilterVersion(null)}
              variant={filterVersion === null ? 'contained' : 'outlined'}
            >
              All
            </Button>
            {versions.slice(-4).map((v) => (
              <Button
                key={v}
                onClick={() => setFilterVersion(v)}
                variant={filterVersion === v ? 'contained' : 'outlined'}
              >
                {v}
              </Button>
            ))}
          </ButtonGroup>
        )}
      </Box>

      <Divider sx={{ mb: 3 }} />

      {matrixLoading ? (
        <Grid container spacing={3}>
          {Array.from({ length: 6 }).map((_, i) => (
            <Grid item xs={12} sm={6} md={4} key={i}>
              <Skeleton variant="rectangular" height={80} sx={{ borderRadius: 1 }} />
            </Grid>
          ))}
        </Grid>
      ) : !hasAnyScans ? (
        <EmptyState />
      ) : (
        <Grid container spacing={3}>
          {/* Portfolio matrix — left */}
          <Grid item xs={12} md={4}>
            <Typography variant="subtitle1" fontWeight={600} gutterBottom>
              Portfolio Matrix
            </Typography>
            <PortfolioMatrix
              matrix={matrix}
              onProjectSelect={handleProjectSelect}
            />
          </Grid>

          {/* Project detail — right */}
          <Grid item xs={12} md={8}>
            {selectedProjectId && selectedScanId ? (
              <>
                <Typography variant="subtitle1" fontWeight={600} gutterBottom>
                  Project Detail
                </Typography>
                <ProjectDetail projectId={selectedProjectId} scanId={selectedScanId} />
              </>
            ) : (
              <Box
                sx={{
                  height: '100%',
                  minHeight: 200,
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'center',
                  color: 'text.disabled',
                  border: 1,
                  borderColor: 'divider',
                  borderRadius: 1,
                  p: 4,
                }}
              >
                <Typography variant="body2">
                  Select a project from the matrix to view upgrade detail.
                </Typography>
              </Box>
            )}
          </Grid>
        </Grid>
      )}

      {/* Portfolio summary strip */}
      {matrix && hasAnyScans && (
        <Box
          sx={{
            mt: 4,
            p: 2,
            bgcolor: 'action.hover',
            borderRadius: 1,
            display: 'flex',
            gap: 4,
            flexWrap: 'wrap',
          }}
        >
          <Typography variant="body2">
            <strong>Total engineering:</strong>{' '}
            {matrix.portfolio_total_hours.toLocaleString()}h
          </Typography>
          <Typography variant="body2">
            <strong>Projects:</strong> {matrix.project_count}
          </Typography>
          {matrix.portfolio_total_hours > 0 && (
            <Typography variant="body2">
              <strong>Recommended parallel teams:</strong>{' '}
              {Math.max(1, Math.ceil(matrix.portfolio_total_hours / 800))}
            </Typography>
          )}
        </Box>
      )}
    </Container>
  );
}

import {
  Box,
  Card,
  CardActionArea,
  CardContent,
  Chip,
  Grid,
  Skeleton,
  Typography,
} from '@mui/material';
import type { PortfolioMatrix as PortfolioMatrixType, PortfolioMatrixProject } from '../../utils/serverApi';

const GRADE_COLORS: Record<string, 'success' | 'info' | 'warning' | 'error' | 'default'> = {
  A: 'success',
  B: 'info',
  C: 'warning',
  D: 'warning',
  F: 'error',
};

interface Props {
  matrix: PortfolioMatrixType | undefined;
  onProjectSelect: (projectId: number, targetVersion: string) => void;
}

function ProjectCard({
  project,
  onSelect,
}: {
  project: PortfolioMatrixProject;
  onSelect: (targetVersion: string) => void;
}) {
  const latest = project.scans[0];

  return (
    <Card variant="outlined" sx={{ height: '100%' }}>
      <CardActionArea
        onClick={() => latest && onSelect(latest.target_version)}
        disabled={!latest}
        sx={{ height: '100%' }}
      >
        <CardContent>
          <Typography variant="subtitle2" fontWeight={700} noWrap title={project.project_name}>
            {project.project_name}
          </Typography>

          {latest ? (
            <Box sx={{ mt: 1, display: 'flex', alignItems: 'center', gap: 1, flexWrap: 'wrap' }}>
              <Chip
                label={latest.uri_grade ?? '—'}
                color={GRADE_COLORS[latest.uri_grade ?? ''] ?? 'default'}
                size="small"
                sx={{ fontWeight: 700 }}
              />
              <Typography variant="caption" color="text.secondary">
                → {latest.target_version}
              </Typography>
              <Typography variant="caption" color="text.secondary">
                {latest.engineering_hours != null ? `${latest.engineering_hours}h` : '—'}
              </Typography>
            </Box>
          ) : (
            <Typography variant="caption" color="text.disabled" sx={{ mt: 1, display: 'block' }}>
              No scan yet
            </Typography>
          )}
        </CardContent>
      </CardActionArea>
    </Card>
  );
}

export default function PortfolioMatrix({ matrix, onProjectSelect }: Props) {
  if (!matrix) {
    return (
      <Grid container spacing={2}>
        {Array.from({ length: 6 }).map((_, i) => (
          <Grid item xs={12} sm={6} key={i}>
            <Skeleton variant="rectangular" height={80} sx={{ borderRadius: 1 }} />
          </Grid>
        ))}
      </Grid>
    );
  }

  return (
    <Box>
      <Grid container spacing={1.5}>
        {matrix.projects.map((project) => (
          <Grid item xs={12} sm={6} key={project.project_id}>
            <ProjectCard
              project={project}
              onSelect={(tv) => onProjectSelect(project.project_id, tv)}
            />
          </Grid>
        ))}
      </Grid>

      {matrix.projects.length === 0 && (
        <Typography variant="body2" color="text.secondary" sx={{ mt: 2 }}>
          No projects found.
        </Typography>
      )}

      <Box
        sx={{
          mt: 2,
          pt: 1,
          borderTop: 1,
          borderColor: 'divider',
          display: 'flex',
          gap: 2,
          flexWrap: 'wrap',
        }}
      >
        <Typography variant="caption" color="text.secondary">
          <strong>{matrix.project_count}</strong> projects
        </Typography>
        <Typography variant="caption" color="text.secondary">
          Total engineering: <strong>{matrix.portfolio_total_hours.toLocaleString()}h</strong>
        </Typography>
      </Box>
    </Box>
  );
}

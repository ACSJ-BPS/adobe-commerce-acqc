import { Box, Paper, Tooltip, Typography } from '@mui/material';

export interface ModuleRisk {
  module: string;
  finding_count: number;
  error_count: number;
  warning_count: number;
  estimated_hours: number;
}

interface Props {
  modules: ModuleRisk[];
}

function riskColor(module: ModuleRisk): string {
  if (module.error_count > 5) return '#dc2626';      // red — critical
  if (module.error_count > 0) return '#ea580c';      // orange — errors
  if (module.warning_count > 5) return '#ca8a04';    // amber — many warnings
  if (module.warning_count > 0) return '#16a34a';    // green — warnings only
  return '#e5e7eb';                                   // gray — clean
}

function cellSize(module: ModuleRisk, maxFindings: number): number {
  if (maxFindings === 0) return 80;
  return Math.max(60, Math.min(140, 60 + (module.finding_count / maxFindings) * 80));
}

export default function ModuleHeatmap({ modules }: Props) {
  if (modules.length === 0) {
    return (
      <Typography variant="body2" color="text.secondary">
        No module risk data available. Run a full upgrade analysis to populate the heatmap.
      </Typography>
    );
  }

  const maxFindings = Math.max(...modules.map((m) => m.finding_count), 1);
  const sorted = [...modules].sort((a, b) => b.finding_count - a.finding_count);

  return (
    <Box>
      <Box sx={{ display: 'flex', flexWrap: 'wrap', gap: 1 }}>
        {sorted.map((mod) => {
          const size = cellSize(mod, maxFindings);
          const color = riskColor(mod);
          const label = mod.module.split('/').pop() ?? mod.module;

          return (
            <Tooltip
              key={mod.module}
              title={
                <Box>
                  <Typography variant="caption" fontWeight={700}>{mod.module}</Typography>
                  <br />
                  <Typography variant="caption">Findings: {mod.finding_count}</Typography>
                  <br />
                  <Typography variant="caption">Errors: {mod.error_count} | Warnings: {mod.warning_count}</Typography>
                  <br />
                  <Typography variant="caption">Est. {mod.estimated_hours}h</Typography>
                </Box>
              }
              arrow
            >
              <Paper
                variant="outlined"
                sx={{
                  width: size,
                  height: size,
                  display: 'flex',
                  flexDirection: 'column',
                  alignItems: 'center',
                  justifyContent: 'center',
                  bgcolor: color,
                  borderColor: color,
                  cursor: 'default',
                  p: 0.5,
                  transition: 'transform 0.15s',
                  '&:hover': { transform: 'scale(1.06)' },
                }}
              >
                <Typography
                  variant="caption"
                  fontWeight={700}
                  align="center"
                  sx={{
                    color: mod.error_count > 0 ? '#fff' : '#111',
                    wordBreak: 'break-word',
                    lineHeight: 1.2,
                    fontSize: '0.65rem',
                  }}
                >
                  {label}
                </Typography>
                <Typography
                  variant="caption"
                  sx={{ color: mod.error_count > 0 ? '#fecaca' : '#6b7280', fontSize: '0.6rem' }}
                >
                  {mod.finding_count} finding{mod.finding_count !== 1 ? 's' : ''}
                </Typography>
              </Paper>
            </Tooltip>
          );
        })}
      </Box>
    </Box>
  );
}

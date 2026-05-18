import { Box, Chip, Paper, Skeleton, Stack, Typography } from '@mui/material';
import ArrowForwardIcon from '@mui/icons-material/ArrowForward';

export interface UpgradeHop {
  from: string;
  to: string;
  risk: 'low' | 'medium' | 'high';
  breaking_changes: number;
  deprecated_symbols: number;
  security_advisories: number;
  release_date?: string | null;
  rationale?: string;
}

export interface UpgradePath {
  from: string;
  to: string;
  hops: UpgradeHop[];
  total_hops: number;
  direct_upgrade_possible: boolean;
}

interface Props {
  path: UpgradePath | undefined;
  loading?: boolean;
}

const RISK_COLOR: Record<string, 'success' | 'warning' | 'error'> = {
  low: 'success',
  medium: 'warning',
  high: 'error',
};

function HopCard({ hop }: { hop: UpgradeHop }) {
  return (
    <Paper
      variant="outlined"
      sx={{
        p: 1.5,
        minWidth: 160,
        borderColor: hop.risk === 'high' ? 'error.main' : hop.risk === 'medium' ? 'warning.main' : 'success.main',
      }}
    >
      <Typography variant="body2" fontWeight={700} gutterBottom>
        {hop.from} → {hop.to}
      </Typography>
      <Chip
        size="small"
        label={`${hop.risk} risk`}
        color={RISK_COLOR[hop.risk]}
        sx={{ mb: 1 }}
      />
      <Stack spacing={0.25}>
        <Typography variant="caption" color="text.secondary">
          Breaking: {hop.breaking_changes}
        </Typography>
        <Typography variant="caption" color="text.secondary">
          Deprecated: {hop.deprecated_symbols}
        </Typography>
        {hop.security_advisories > 0 && (
          <Typography variant="caption" color="error.main">
            Security advisories: {hop.security_advisories}
          </Typography>
        )}
        {hop.release_date && (
          <Typography variant="caption" color="text.secondary">
            Released: {hop.release_date}
          </Typography>
        )}
      </Stack>
      {hop.rationale && (
        <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 0.5, fontStyle: 'italic' }}>
          {hop.rationale}
        </Typography>
      )}
    </Paper>
  );
}

export default function UpgradePathVisualizer({ path, loading = false }: Props) {
  if (loading) {
    return <Skeleton variant="rounded" height={100} />;
  }

  if (!path) {
    return (
      <Typography variant="body2" color="text.secondary">
        Select source and target versions to visualize the upgrade path.
      </Typography>
    );
  }

  return (
    <Box>
      <Box sx={{ mb: 1, display: 'flex', gap: 1, alignItems: 'center' }}>
        <Typography variant="subtitle2">
          {path.from} → {path.to}
        </Typography>
        <Chip
          size="small"
          label={path.direct_upgrade_possible ? 'Direct upgrade' : `${path.total_hops}-hop path`}
          color={path.direct_upgrade_possible ? 'success' : 'info'}
          variant="outlined"
        />
      </Box>

      <Box sx={{ display: 'flex', alignItems: 'center', gap: 1, flexWrap: 'wrap' }}>
        {path.hops.map((hop, idx) => (
          <Box key={idx} sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
            <HopCard hop={hop} />
            {idx < path.hops.length - 1 && (
              <ArrowForwardIcon color="action" />
            )}
          </Box>
        ))}
      </Box>
    </Box>
  );
}

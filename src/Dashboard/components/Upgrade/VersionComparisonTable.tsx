import {
  Box,
  Chip,
  Paper,
  Table,
  TableBody,
  TableCell,
  TableContainer,
  TableHead,
  TableRow,
  Typography,
} from '@mui/material';

export interface VersionComparison {
  target_version: string;
  uri_score: number;
  uri_grade: string;
  engineering_hours: number;
  breaking_changes: number;
  deprecated_symbols: number;
}

interface Props {
  comparisons: VersionComparison[];
}

const GRADE_COLOR: Record<string, 'success' | 'info' | 'warning' | 'error' | 'default'> = {
  A: 'success',
  B: 'info',
  C: 'warning',
  D: 'warning',
  F: 'error',
};

export default function VersionComparisonTable({ comparisons }: Props) {
  if (comparisons.length === 0) {
    return (
      <Typography variant="body2" color="text.secondary">
        No version comparison data. Run with --compare-versions to populate this view.
      </Typography>
    );
  }

  // Sort by URI score ascending (lowest risk first)
  const sorted = [...comparisons].sort((a, b) => a.uri_score - b.uri_score);

  return (
    <Box>
      <TableContainer component={Paper} variant="outlined">
        <Table size="small">
          <TableHead>
            <TableRow sx={{ bgcolor: 'primary.main' }}>
              {['Target Version', 'URI Score', 'Grade', 'Eng. Hours', 'Breaking Changes', 'Deprecated'].map((h) => (
                <TableCell key={h} sx={{ color: 'primary.contrastText', fontWeight: 700 }}>
                  {h}
                </TableCell>
              ))}
            </TableRow>
          </TableHead>
          <TableBody>
            {sorted.map((row) => (
              <TableRow key={row.target_version} hover>
                <TableCell>
                  <Typography variant="body2" fontWeight={600}>
                    {row.target_version}
                  </Typography>
                </TableCell>
                <TableCell>{row.uri_score.toFixed(1)}</TableCell>
                <TableCell>
                  <Chip
                    size="small"
                    label={row.uri_grade}
                    color={GRADE_COLOR[row.uri_grade] ?? 'default'}
                  />
                </TableCell>
                <TableCell>{row.engineering_hours}h</TableCell>
                <TableCell>{row.breaking_changes}</TableCell>
                <TableCell>{row.deprecated_symbols}</TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </TableContainer>
    </Box>
  );
}

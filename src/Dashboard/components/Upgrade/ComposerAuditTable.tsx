import { DataGrid, GridColDef } from '@mui/x-data-grid';
import { Box, Chip, Typography } from '@mui/material';

export interface ComposerFinding {
  id?: number;
  package?: string;
  type?: string;
  severity?: string;
  message?: string;
  [key: string]: unknown;
}

interface Props {
  findings: ComposerFinding[];
}

const SEV_COLOR: Record<string, 'error' | 'warning' | 'default'> = {
  error: 'error',
  warning: 'warning',
};

const columns: GridColDef[] = [
  { field: 'package',  headerName: 'Package',     flex: 1, minWidth: 200 },
  { field: 'type',     headerName: 'Issue Type',  width: 180 },
  {
    field: 'severity',
    headerName: 'Severity',
    width: 120,
    renderCell: (p) => {
      const sev = String(p.value ?? 'info');
      return <Chip size="small" label={sev} color={SEV_COLOR[sev] ?? 'default'} />;
    },
  },
  { field: 'message', headerName: 'Detail', flex: 2, minWidth: 250 },
];

export default function ComposerAuditTable({ findings }: Props) {
  const composerFindings = findings.filter(
    (f) => f.type?.startsWith('composer') || f.package
  );

  if (composerFindings.length === 0) {
    return (
      <Typography variant="body2" color="text.secondary">
        No Composer issues detected. Run with --composer-deep for full audit.
      </Typography>
    );
  }

  const rows = composerFindings.map((f, i) => ({ id: f.id ?? i, ...f }));

  return (
    <Box>
      <DataGrid
        rows={rows}
        columns={columns}
        density="compact"
        autoHeight
        disableRowSelectionOnClick
        pageSizeOptions={[25, 50]}
        sx={{ border: 0 }}
      />
    </Box>
  );
}

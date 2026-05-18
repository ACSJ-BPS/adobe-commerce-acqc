import { DataGrid, GridColDef, GridRowClassNameParams } from '@mui/x-data-grid';
import { Box, Typography } from '@mui/material';

export interface UpgradeFinding {
  id?: number;
  table?: string;
  change_type?: string;
  risk_level?: string;
  est_duration?: string;
  recommendation?: string;
  type?: string;
  [key: string]: unknown;
}

interface Props {
  findings: UpgradeFinding[];
}

const columns: GridColDef[] = [
  { field: 'table',          headerName: 'Table',           flex: 1, minWidth: 150 },
  { field: 'change_type',    headerName: 'Change Type',     width: 160 },
  { field: 'risk_level',     headerName: 'Risk Level',      width: 120 },
  { field: 'est_duration',   headerName: 'Est Duration',    width: 140 },
  { field: 'recommendation', headerName: 'Recommendation',  flex: 2, minWidth: 200 },
];

function rowClass(params: GridRowClassNameParams<UpgradeFinding>) {
  const risk = (params.row.risk_level ?? '').toLowerCase();
  if (risk === 'critical') return 'row-critical';
  if (risk === 'high')     return 'row-high';
  if (risk === 'medium')   return 'row-medium';
  return '';
}

export default function DBRiskTable({ findings }: Props) {
  const dbFindings = findings.filter(
    (f) => f.type?.startsWith('db') || f.table
  );

  if (dbFindings.length === 0) {
    return (
      <Typography variant="body2" color="text.secondary">
        No database schema risks detected.
      </Typography>
    );
  }

  const rows = dbFindings.map((f, i) => ({ id: f.id ?? i, ...f }));

  return (
    <Box
      sx={{
        '& .row-critical': { bgcolor: 'error.light' },
        '& .row-high':     { bgcolor: 'warning.light' },
        '& .row-medium':   { bgcolor: 'warning.light', opacity: 0.7 },
      }}
    >
      <DataGrid
        rows={rows}
        columns={columns}
        density="compact"
        autoHeight
        disableRowSelectionOnClick
        getRowClassName={rowClass}
        pageSizeOptions={[25, 50]}
        sx={{ border: 0 }}
      />
    </Box>
  );
}

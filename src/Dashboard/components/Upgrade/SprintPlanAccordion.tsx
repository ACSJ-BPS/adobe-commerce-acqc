import {
  Accordion,
  AccordionDetails,
  AccordionSummary,
  Box,
  Chip,
  Typography,
} from '@mui/material';
import { DataGrid, GridColDef } from '@mui/x-data-grid';
import { ExpandMore } from '@mui/icons-material';

interface SprintFinding {
  id?: number;
  severity?: string;
  type?: string;
  message?: string;
  est_hours?: number;
  [key: string]: unknown;
}

interface Sprint {
  sprint: number;
  name: string;
  findings: SprintFinding[];
  total_hours: number;
}

interface Props {
  sprintBreakdown: Sprint[];
}

const columns: GridColDef[] = [
  { field: 'severity', headerName: 'Severity', width: 100 },
  { field: 'type',     headerName: 'Type',     width: 140 },
  { field: 'message',  headerName: 'Message',  flex: 1, minWidth: 200 },
  { field: 'est_hours', headerName: 'Est Hours', width: 100, type: 'number' },
];

export default function SprintPlanAccordion({ sprintBreakdown }: Props) {
  if (!sprintBreakdown || sprintBreakdown.length === 0) {
    return (
      <Typography variant="body2" color="text.secondary">
        No sprint plan available.
      </Typography>
    );
  }

  return (
    <Box>
      {sprintBreakdown.map((sprint) => {
        const rows = sprint.findings.map((f, i) => ({ id: f.id ?? i, ...f }));

        return (
          <Accordion key={sprint.sprint} disableGutters>
            <AccordionSummary expandIcon={<ExpandMore />}>
              <Box sx={{ display: 'flex', alignItems: 'center', gap: 1.5, width: '100%' }}>
                <Typography fontWeight={600}>
                  Sprint {sprint.sprint}: {sprint.name}
                </Typography>
                <Chip
                  label={`${sprint.total_hours}h`}
                  size="small"
                  color="primary"
                  variant="outlined"
                />
              </Box>
            </AccordionSummary>
            <AccordionDetails sx={{ p: 0 }}>
              {rows.length > 0 ? (
                <DataGrid
                  rows={rows}
                  columns={columns}
                  density="compact"
                  autoHeight
                  disableRowSelectionOnClick
                  hideFooter={rows.length <= 25}
                  pageSizeOptions={[25]}
                  sx={{ border: 0 }}
                />
              ) : (
                <Typography variant="body2" color="text.secondary" sx={{ p: 2 }}>
                  No findings in this sprint.
                </Typography>
              )}
            </AccordionDetails>
          </Accordion>
        );
      })}
    </Box>
  );
}

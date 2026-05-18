import {
  Accordion,
  AccordionDetails,
  AccordionSummary,
  Box,
  Button,
  Chip,
  CircularProgress,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  TextField,
  Tooltip,
  Typography,
} from '@mui/material';
import { DataGrid, GridColDef } from '@mui/x-data-grid';
import { ExpandMore, Warning } from '@mui/icons-material';
import { useState } from 'react';
import { useAddSuppression, useDeleteSuppression, useSuppressions } from '../hooks/useServerData';
import type { Suppression } from '../utils/serverApi';

function AddSuppressionDialog({ projectId, open, onClose }: { projectId: number; open: boolean; onClose: () => void }) {
  const add = useAddSuppression(projectId);
  const [form, setForm] = useState({ rule_id: '', reason: '', review_by: '' });
  const [error, setError] = useState<string | null>(null);

  const set = (field: string) => (e: React.ChangeEvent<HTMLInputElement>) =>
    setForm((f) => ({ ...f, [field]: e.target.value }));

  const handleSubmit = async () => {
    setError(null);
    try {
      await add.mutateAsync({
        rule_id:   form.rule_id.trim(),
        reason:    form.reason.trim(),
        review_by: form.review_by || undefined,
      });
      setForm({ rule_id: '', reason: '', review_by: '' });
      onClose();
    } catch {
      setError('Failed to add suppression. Check the rule ID format (e.g. AC_DI_001).');
    }
  };

  return (
    <Dialog open={open} onClose={onClose} maxWidth="sm" fullWidth>
      <DialogTitle fontWeight={700}>Acknowledge / Suppress Rule</DialogTitle>
      <DialogContent>
        {error && <Typography color="error" variant="body2" sx={{ mb: 1 }}>{error}</Typography>}
        <TextField
          label="Rule ID" fullWidth required margin="normal"
          value={form.rule_id} onChange={set('rule_id')}
          placeholder="AC_DI_001" helperText="Format: PREFIX_CATEGORY_NNN"
        />
        <TextField
          label="Reason" fullWidth required multiline rows={3} margin="normal"
          value={form.reason} onChange={set('reason')}
          inputProps={{ maxLength: 500 }}
          helperText={`${form.reason.length}/500`}
        />
        <TextField
          label="Review By (optional)" fullWidth margin="normal" type="date"
          value={form.review_by} onChange={set('review_by')}
          InputLabelProps={{ shrink: true }}
          helperText="Set a date to be reminded to re-evaluate this suppression"
        />
      </DialogContent>
      <DialogActions sx={{ px: 3, pb: 2 }}>
        <Button onClick={onClose} disabled={add.isPending}>Cancel</Button>
        <Button
          variant="contained" onClick={handleSubmit}
          disabled={add.isPending || !form.rule_id.trim() || !form.reason.trim()}
          startIcon={add.isPending ? <CircularProgress size={14} color="inherit" /> : undefined}
        >
          {add.isPending ? 'Saving…' : 'Acknowledge'}
        </Button>
      </DialogActions>
    </Dialog>
  );
}

export default function AcknowledgedSuppressions({ projectId }: { projectId: number }) {
  const { data: suppressions = [], isLoading } = useSuppressions(projectId);
  const remove = useDeleteSuppression(projectId);
  const [addOpen, setAddOpen] = useState(false);

  const expiredCount = suppressions.filter((s) => s.review_by && new Date(s.review_by) < new Date()).length;

  const columns: GridColDef<Suppression>[] = [
    { field: 'rule_id',             headerName: 'Rule ID',      width: 130, sortable: true },
    { field: 'reason',              headerName: 'Reason',       flex: 1,    sortable: false },
    { field: 'suppressed_by_email', headerName: 'Suppressed By', width: 200 },
    {
      field: 'suppressed_at',
      headerName: 'Date',
      width: 110,
      renderCell: ({ value }) => value ? new Date(value as string).toLocaleDateString() : '—',
    },
    {
      field: 'review_by',
      headerName: 'Review By',
      width: 120,
      renderCell: ({ value, row }) => {
        if (!value) return <Typography variant="caption" color="text.disabled">—</Typography>;
        const expired = new Date(value as string) < new Date();
        return (
          <Chip
            label={new Date(value as string).toLocaleDateString()}
            size="small"
            color={expired ? 'warning' : 'default'}
            icon={expired ? <Warning fontSize="small" /> : undefined}
          />
        );
      },
    },
    {
      field: 'actions',
      headerName: '',
      width: 90,
      sortable: false,
      renderCell: ({ row }) => (
        <Button
          size="small" color="error" variant="text"
          disabled={remove.isPending}
          onClick={() => remove.mutate(row.id)}
        >
          Remove
        </Button>
      ),
    },
  ];

  return (
    <>
      <AddSuppressionDialog projectId={projectId} open={addOpen} onClose={() => setAddOpen(false)} />
      <Accordion variant="outlined" sx={{ borderRadius: '8px !important', mb: 2 }}>
        <AccordionSummary expandIcon={<ExpandMore />}>
          <Box sx={{ display: 'flex', alignItems: 'center', gap: 1, flex: 1 }}>
            <Typography fontWeight={700}>
              Acknowledged Rules ({isLoading ? '…' : suppressions.length})
            </Typography>
            {expiredCount > 0 && (
              <Tooltip title={`${expiredCount} suppression(s) past review date`}>
                <Chip
                  label={`${expiredCount} expired`}
                  size="small"
                  color="warning"
                  icon={<Warning fontSize="small" />}
                />
              </Tooltip>
            )}
          </Box>
        </AccordionSummary>
        <AccordionDetails sx={{ pt: 0 }}>
          <Box sx={{ display: 'flex', justifyContent: 'flex-end', mb: 1 }}>
            <Button size="small" variant="outlined" onClick={() => setAddOpen(true)}>
              + Acknowledge Rule
            </Button>
          </Box>
          {isLoading ? (
            <Box sx={{ display: 'flex', justifyContent: 'center', py: 3 }}>
              <CircularProgress size={24} />
            </Box>
          ) : suppressions.length === 0 ? (
            <Typography variant="body2" color="text.secondary" sx={{ py: 1 }}>
              No rules have been acknowledged for this project.
            </Typography>
          ) : (
            <DataGrid
              rows={suppressions}
              columns={columns}
              autoHeight
              disableRowSelectionOnClick
              pageSizeOptions={[10, 25]}
              initialState={{ pagination: { paginationModel: { pageSize: 10 } } }}
              sx={{ border: 0 }}
            />
          )}
        </AccordionDetails>
      </Accordion>
    </>
  );
}

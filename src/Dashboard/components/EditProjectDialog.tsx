import {
  Alert,
  Autocomplete,
  Button,
  CircularProgress,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  FormControl,
  InputLabel,
  MenuItem,
  Select,
  TextField,
} from '@mui/material';
import { useEffect, useState } from 'react';
import { useUpdateProject } from '../hooks/useServerData';
import type { Project } from '../utils/serverApi';

const TECH_STACK_OPTIONS = [
  'php', 'magento2', 'mysql', 'elasticsearch', 'redis', 'rabbitmq',
  'varnish', 'nginx', 'javascript', 'typescript', 'react', 'vue',
];

export default function EditProjectDialog({
  project, open, onClose,
}: {
  project: Project;
  open: boolean;
  onClose: () => void;
}) {
  const update = useUpdateProject(project.id);
  const [form, setForm] = useState({
    name: '', description: '', repo_url: '', local_path: '',
    tech_stack: [] as string[], status: 'active' as Project['status'],
  });
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (open) {
      setForm({
        name:        project.name,
        description: project.description ?? '',
        repo_url:    project.repo_url    ?? '',
        local_path:  project.local_path  ?? '',
        tech_stack:  project.tech_stack  ?? [],
        status:      project.status,
      });
      setError(null);
    }
  }, [open, project]);

  const set = (field: string) => (e: React.ChangeEvent<HTMLInputElement>) =>
    setForm((f) => ({ ...f, [field]: e.target.value }));

  const handleSubmit = async () => {
    setError(null);
    try {
      await update.mutateAsync({
        ...form,
        tech_stack: form.tech_stack.length ? form.tech_stack : null,
        local_path: form.local_path.trim() || null,
      });
      onClose();
    } catch {
      setError('Failed to update project. Check the values and try again.');
    }
  };

  const missingPath = !form.local_path.trim();

  return (
    <Dialog open={open} onClose={onClose} maxWidth="sm" fullWidth>
      <DialogTitle fontWeight={700}>Edit Project — {project.dr_no}</DialogTitle>
      <DialogContent>
        {error && <Alert severity="error" sx={{ mb: 2 }}>{error}</Alert>}

        <TextField
          label="Project Name" fullWidth required margin="normal"
          value={form.name} onChange={set('name')}
        />

        <TextField
          label="Description" fullWidth multiline rows={2} margin="normal"
          value={form.description} onChange={set('description')}
        />

        <TextField
          label="Repository URL" fullWidth margin="normal"
          value={form.repo_url} onChange={set('repo_url')}
          placeholder="https://github.com/org/repo"
        />

        <TextField
          label="Local Path (for scanning)" fullWidth margin="normal"
          value={form.local_path} onChange={set('local_path')}
          placeholder="/var/www/magento2"
          color={missingPath ? 'warning' : 'primary'}
          helperText={
            missingPath
              ? '⚠ Required to run scans — absolute path to the repo on this server'
              : 'Absolute path on the server where the code lives'
          }
        />

        <Autocomplete
          multiple freeSolo options={TECH_STACK_OPTIONS}
          value={form.tech_stack}
          onChange={(_, v) => setForm((f) => ({ ...f, tech_stack: v as string[] }))}
          renderInput={(params) => <TextField {...params} label="Tech Stack" margin="normal" />}
        />

        <FormControl fullWidth margin="normal">
          <InputLabel>Status</InputLabel>
          <Select
            value={form.status} label="Status"
            onChange={(e) => setForm((f) => ({ ...f, status: e.target.value as Project['status'] }))}
          >
            <MenuItem value="active">Active</MenuItem>
            <MenuItem value="in_review">In Review</MenuItem>
            <MenuItem value="archived">Archived</MenuItem>
          </Select>
        </FormControl>
      </DialogContent>
      <DialogActions sx={{ px: 3, pb: 2 }}>
        <Button onClick={onClose} disabled={update.isPending}>Cancel</Button>
        <Button
          variant="contained" onClick={handleSubmit}
          disabled={update.isPending || !form.name.trim()}
          startIcon={update.isPending ? <CircularProgress size={14} color="inherit" /> : undefined}
        >
          {update.isPending ? 'Saving…' : 'Save Changes'}
        </Button>
      </DialogActions>
    </Dialog>
  );
}

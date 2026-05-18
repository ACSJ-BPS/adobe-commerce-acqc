import {
  Alert,
  Avatar,
  Box,
  Button,
  Chip,
  CircularProgress,
  Container,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  FormControl,
  IconButton,
  InputLabel,
  MenuItem,
  Select,
  Skeleton,
  Table,
  TableBody,
  TableCell,
  TableContainer,
  TableHead,
  TableRow,
  TextField,
  Tooltip,
  Typography,
  Card,
} from '@mui/material';
import {
  ArrowBack,
  Delete,
  PersonAdd,
} from '@mui/icons-material';
import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { useDeleteUser, useInviteUser, useUpdateUser, useUsers } from '../hooks/useServerData';
import type { User } from '../utils/serverApi';

const ROLE_META: Record<User['role'], { label: string; color: 'error' | 'warning' | 'success' }> = {
  admin:        { label: 'Admin',        color: 'error'   },
  project_lead: { label: 'Project Lead', color: 'warning' },
  member:       { label: 'Member',       color: 'success' },
};

// ─── Invite dialog ────────────────────────────────────────────────────────────
function InviteUserDialog({ open, onClose }: { open: boolean; onClose: () => void }) {
  const invite = useInviteUser();
  const [form, setForm] = useState({ name: '', email: '', role: 'member' as User['role'], password: '' });
  const [showPw, setShowPw] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const set = (f: keyof typeof form) => (e: React.ChangeEvent<HTMLInputElement>) =>
    setForm((prev) => ({ ...prev, [f]: e.target.value }));

  const handleSubmit = async () => {
    setError(null);
    try {
      await invite.mutateAsync(form);
      setForm({ name: '', email: '', role: 'member', password: '' });
      onClose();
    } catch {
      setError('Failed to invite user. Check the fields and try again.');
    }
  };

  const valid = form.name.trim() && form.email.trim() && form.password.length >= 8;

  return (
    <Dialog open={open} onClose={onClose} maxWidth="xs" fullWidth>
      <DialogTitle fontWeight={700}>Invite User</DialogTitle>
      <DialogContent>
        {error && <Alert severity="error" sx={{ mb: 2 }}>{error}</Alert>}
        <TextField label="Full Name" fullWidth required margin="normal" value={form.name} onChange={set('name')} />
        <TextField label="Email" type="email" fullWidth required margin="normal" value={form.email} onChange={set('email')}
          helperText="Must be an adobe.com address" />
        <FormControl fullWidth margin="normal">
          <InputLabel>Role</InputLabel>
          <Select value={form.role} label="Role"
            onChange={(e) => setForm((f) => ({ ...f, role: e.target.value as User['role'] }))}>
            <MenuItem value="member">Member</MenuItem>
            <MenuItem value="project_lead">Project Lead</MenuItem>
            <MenuItem value="admin">Admin</MenuItem>
          </Select>
        </FormControl>
        <TextField
          label="Temporary Password" fullWidth required margin="normal"
          type={showPw ? 'text' : 'password'}
          value={form.password} onChange={set('password')}
          helperText="Min 8 characters — user should change on first login"
          InputProps={{
            endAdornment: (
              <Button size="small" onClick={() => setShowPw((p) => !p)} sx={{ minWidth: 0, px: 1 }}>
                {showPw ? 'Hide' : 'Show'}
              </Button>
            ),
          }}
        />
      </DialogContent>
      <DialogActions sx={{ px: 3, pb: 2 }}>
        <Button onClick={onClose} disabled={invite.isPending}>Cancel</Button>
        <Button
          variant="contained" onClick={handleSubmit}
          disabled={invite.isPending || !valid}
          startIcon={invite.isPending ? <CircularProgress size={14} color="inherit" /> : <PersonAdd />}
        >
          {invite.isPending ? 'Inviting…' : 'Invite'}
        </Button>
      </DialogActions>
    </Dialog>
  );
}

// ─── Delete confirmation dialog ───────────────────────────────────────────────
function ConfirmDeleteDialog({
  user, open, onClose,
}: { user: User | null; open: boolean; onClose: () => void }) {
  const del = useDeleteUser();

  const handleDelete = async () => {
    if (!user) return;
    await del.mutateAsync(user.id);
    onClose();
  };

  return (
    <Dialog open={open} onClose={onClose} maxWidth="xs" fullWidth>
      <DialogTitle fontWeight={700}>Remove User</DialogTitle>
      <DialogContent>
        <Typography>
          Remove <strong>{user?.name}</strong> ({user?.email}) from the platform?
          This cannot be undone.
        </Typography>
      </DialogContent>
      <DialogActions sx={{ px: 3, pb: 2 }}>
        <Button onClick={onClose} disabled={del.isPending}>Cancel</Button>
        <Button
          variant="contained" color="error" onClick={handleDelete}
          disabled={del.isPending}
          startIcon={del.isPending ? <CircularProgress size={14} color="inherit" /> : <Delete />}
        >
          {del.isPending ? 'Removing…' : 'Remove'}
        </Button>
      </DialogActions>
    </Dialog>
  );
}

// ─── Main page ────────────────────────────────────────────────────────────────
export default function AdminUsersPage() {
  const navigate = useNavigate();
  const { user: me } = useAuth();
  const { data: usersPage, isLoading } = useUsers();
  const updateUser = useUpdateUser();

  const [inviteOpen, setInviteOpen] = useState(false);
  const [deleteTarget, setDeleteTarget] = useState<User | null>(null);

  const users: User[] = usersPage?.data ?? [];

  return (
    <Box sx={{ minHeight: '100vh', bgcolor: 'background.default' }}>
      {/* Top bar */}
      <Box sx={{ bgcolor: 'background.paper', borderBottom: 1, borderColor: 'divider', px: 3, py: 1.5, display: 'flex', alignItems: 'center', gap: 2 }}>
        <IconButton size="small" onClick={() => navigate('/')}>
          <ArrowBack />
        </IconButton>
        <Box sx={{ flex: 1 }}>
          <Typography variant="h6" fontWeight={700}>User Management</Typography>
          <Typography variant="caption" color="text.secondary">Admin only · ACQC Central</Typography>
        </Box>
        <Button variant="contained" startIcon={<PersonAdd />} onClick={() => setInviteOpen(true)}>
          Invite User
        </Button>
      </Box>

      <InviteUserDialog open={inviteOpen} onClose={() => setInviteOpen(false)} />
      <ConfirmDeleteDialog user={deleteTarget} open={!!deleteTarget} onClose={() => setDeleteTarget(null)} />

      <Container maxWidth="lg" sx={{ py: 4 }}>
        {/* Summary chips */}
        <Box sx={{ display: 'flex', gap: 2, mb: 3, flexWrap: 'wrap' }}>
          {(['admin', 'project_lead', 'member'] as User['role'][]).map((role) => {
            const count = users.filter((u) => u.role === role).length;
            const { label, color } = ROLE_META[role];
            return (
              <Chip key={role} label={`${count} ${label}${count !== 1 ? 's' : ''}`} color={color} variant="outlined" />
            );
          })}
          <Typography variant="body2" color="text.secondary" sx={{ ml: 'auto', alignSelf: 'center' }}>
            {users.length} total user{users.length !== 1 ? 's' : ''}
          </Typography>
        </Box>

        <TableContainer component={Card} variant="outlined">
          <Table>
            <TableHead>
              <TableRow sx={{ bgcolor: 'action.hover' }}>
                <TableCell>User</TableCell>
                <TableCell width={170}>Role</TableCell>
                <TableCell width={90} align="center">Projects</TableCell>
                <TableCell width={110}>Joined</TableCell>
                <TableCell width={60} align="center">Remove</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {isLoading
                ? [...Array(4)].map((_, i) => (
                    <TableRow key={i}>
                      {[...Array(5)].map((__, j) => (
                        <TableCell key={j}><Skeleton /></TableCell>
                      ))}
                    </TableRow>
                  ))
                : users.map((u) => {
                    const isSelf = u.id === me?.id;
                    return (
                      <TableRow
                        key={u.id}
                        sx={{ opacity: isSelf ? 0.85 : 1, '&:hover': { bgcolor: 'action.hover' } }}
                      >
                        {/* Avatar + name/email */}
                        <TableCell>
                          <Box sx={{ display: 'flex', alignItems: 'center', gap: 1.5 }}>
                            <Avatar sx={{ width: 34, height: 34, bgcolor: ROLE_META[u.role].color + '.main', fontSize: 14 }}>
                              {u.name[0].toUpperCase()}
                            </Avatar>
                            <Box>
                              <Typography variant="body2" fontWeight={600}>
                                {u.name} {isSelf && <Chip label="you" size="small" sx={{ ml: 0.5, height: 16, fontSize: 10 }} />}
                              </Typography>
                              <Typography variant="caption" color="text.secondary">{u.email}</Typography>
                            </Box>
                          </Box>
                        </TableCell>

                        {/* Inline role select */}
                        <TableCell>
                          <FormControl size="small" fullWidth disabled={isSelf || updateUser.isPending}>
                            <Select
                              value={u.role}
                              onChange={(e) => updateUser.mutate({ id: u.id, role: e.target.value as User['role'] })}
                              renderValue={(v) => (
                                <Chip
                                  label={ROLE_META[v as User['role']].label}
                                  size="small"
                                  color={ROLE_META[v as User['role']].color}
                                  sx={{ pointerEvents: 'none' }}
                                />
                              )}
                            >
                              <MenuItem value="member">Member</MenuItem>
                              <MenuItem value="project_lead">Project Lead</MenuItem>
                              <MenuItem value="admin">Admin</MenuItem>
                            </Select>
                          </FormControl>
                        </TableCell>

                        {/* Projects count */}
                        <TableCell align="center">
                          <Typography variant="body2">{u.projects_count ?? '—'}</Typography>
                        </TableCell>

                        {/* Joined date */}
                        <TableCell>
                          <Typography variant="caption" color="text.secondary">
                            {u.created_at ? new Date(u.created_at).toLocaleDateString() : '—'}
                          </Typography>
                        </TableCell>

                        {/* Delete */}
                        <TableCell align="center">
                          <Tooltip title={isSelf ? 'Cannot remove yourself' : 'Remove user'}>
                            <span>
                              <IconButton
                                size="small" color="error"
                                disabled={isSelf}
                                onClick={() => setDeleteTarget(u)}
                              >
                                <Delete fontSize="small" />
                              </IconButton>
                            </span>
                          </Tooltip>
                        </TableCell>
                      </TableRow>
                    );
                  })}
            </TableBody>
          </Table>
        </TableContainer>
      </Container>
    </Box>
  );
}

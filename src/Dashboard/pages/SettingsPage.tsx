import React, { useState } from 'react';
import {
  Box,
  Paper,
  Typography,
  Switch,
  FormControlLabel,
  Button,
  TextField,
  Select,
  MenuItem,
  FormControl,
  InputLabel,
  Divider,
  Stack,
  Card,
  CardContent,
  Alert,
  Tabs,
  Tab
} from '@mui/material';
import {
  Save,
  Refresh,
  Notifications,
  Palette,
  Security,
  Speed,
  Code
} from '@mui/icons-material';

interface TabPanelProps {
  children?: React.ReactNode;
  index: number;
  value: number;
}

function TabPanel(props: TabPanelProps) {
  const { children, value, index, ...other } = props;
  return (
    <div role="tabpanel" hidden={value !== index} {...other}>
      {value === index && <Box sx={{ p: 3 }}>{children}</Box>}
    </div>
  );
}

const SettingsPage: React.FC = () => {
  const [tabValue, setTabValue] = useState(0);
  const [settings, setSettings] = useState({
    theme: 'light',
    notifications: true,
    autoRefresh: true,
    refreshInterval: 30,
    language: 'en',
    timezone: 'UTC',
    emailReports: false,
    slackIntegration: false,
    apiEndpoint: 'http://localhost:8000/api',
    maxViolations: 1000,
    qualityThreshold: 75,
    performanceMode: false,
    debugMode: false
  });
  const [saved, setSaved] = useState(false);

  const handleSave = () => {
    // Save settings logic here
    setSaved(true);
    setTimeout(() => setSaved(false), 3000);
  };

  const handleReset = () => {
    // Reset to defaults
    setSettings({
      theme: 'light',
      notifications: true,
      autoRefresh: true,
      refreshInterval: 30,
      language: 'en',
      timezone: 'UTC',
      emailReports: false,
      slackIntegration: false,
      apiEndpoint: 'http://localhost:8000/api',
      maxViolations: 1000,
      qualityThreshold: 75,
      performanceMode: false,
      debugMode: false
    });
  };

  return (
    <Box sx={{ p: 3 }}>
      <Box sx={{ mb: 4 }}>
        <Typography variant="h4" component="h1">
          Settings
        </Typography>
        <Typography variant="body2" color="text.secondary" sx={{ mt: 1 }}>
          Customize your M2-Quality dashboard experience
        </Typography>
      </Box>

      {saved && (
        <Alert severity="success" sx={{ mb: 3 }}>
          Settings saved successfully!
        </Alert>
      )}

      <Paper sx={{ width: '100%' }}>
        <Tabs value={tabValue} onChange={(e, newValue) => setTabValue(newValue)}>
          <Tab icon={<Palette />} label="Appearance" />
          <Tab icon={<Notifications />} label="Notifications" />
          <Tab icon={<Speed />} label="Performance" />
          <Tab icon={<Code />} label="API & Integration" />
          <Tab icon={<Security />} label="Security" />
        </Tabs>

        {/* Appearance Tab */}
        <TabPanel value={tabValue} index={0}>
          <Stack spacing={3}>
            <FormControl fullWidth>
              <InputLabel>Theme</InputLabel>
              <Select
                value={settings.theme}
                label="Theme"
                onChange={(e) => setSettings({ ...settings, theme: e.target.value })}
              >
                <MenuItem value="light">Light</MenuItem>
                <MenuItem value="dark">Dark</MenuItem>
                <MenuItem value="auto">Auto (System)</MenuItem>
              </Select>
            </FormControl>

            <FormControl fullWidth>
              <InputLabel>Language</InputLabel>
              <Select
                value={settings.language}
                label="Language"
                onChange={(e) => setSettings({ ...settings, language: e.target.value })}
              >
                <MenuItem value="en">English</MenuItem>
                <MenuItem value="es">Español</MenuItem>
                <MenuItem value="fr">Français</MenuItem>
                <MenuItem value="de">Deutsch</MenuItem>
              </Select>
            </FormControl>

            <FormControl fullWidth>
              <InputLabel>Timezone</InputLabel>
              <Select
                value={settings.timezone}
                label="Timezone"
                onChange={(e) => setSettings({ ...settings, timezone: e.target.value })}
              >
                <MenuItem value="UTC">UTC</MenuItem>
                <MenuItem value="America/New_York">Eastern Time</MenuItem>
                <MenuItem value="America/Los_Angeles">Pacific Time</MenuItem>
                <MenuItem value="Europe/London">London</MenuItem>
                <MenuItem value="Asia/Tokyo">Tokyo</MenuItem>
              </Select>
            </FormControl>
          </Stack>
        </TabPanel>

        {/* Notifications Tab */}
        <TabPanel value={tabValue} index={1}>
          <Stack spacing={2}>
            <FormControlLabel
              control={
                <Switch
                  checked={settings.notifications}
                  onChange={(e) => setSettings({ ...settings, notifications: e.target.checked })}
                />
              }
              label="Enable notifications"
            />

            <FormControlLabel
              control={
                <Switch
                  checked={settings.emailReports}
                  onChange={(e) => setSettings({ ...settings, emailReports: e.target.checked })}
                />
              }
              label="Email daily reports"
            />

            <FormControlLabel
              control={
                <Switch
                  checked={settings.slackIntegration}
                  onChange={(e) => setSettings({ ...settings, slackIntegration: e.target.checked })}
                />
              }
              label="Slack integration"
            />

            <Divider sx={{ my: 2 }} />

            <Typography variant="subtitle2" color="text.secondary">
              Notification Preferences
            </Typography>

            <FormControlLabel
              control={<Switch defaultChecked />}
              label="Critical issues"
            />

            <FormControlLabel
              control={<Switch defaultChecked />}
              label="Quality score changes"
            />

            <FormControlLabel
              control={<Switch />}
              label="New violations"
            />

            <FormControlLabel
              control={<Switch />}
              label="Report generation complete"
            />
          </Stack>
        </TabPanel>

        {/* Performance Tab */}
        <TabPanel value={tabValue} index={2}>
          <Stack spacing={3}>
            <FormControlLabel
              control={
                <Switch
                  checked={settings.autoRefresh}
                  onChange={(e) => setSettings({ ...settings, autoRefresh: e.target.checked })}
                />
              }
              label="Auto-refresh dashboard"
            />

            <TextField
              label="Refresh Interval (seconds)"
              type="number"
              value={settings.refreshInterval}
              onChange={(e) => setSettings({ ...settings, refreshInterval: parseInt(e.target.value) })}
              disabled={!settings.autoRefresh}
              fullWidth
              helperText="How often to refresh dashboard data"
            />

            <TextField
              label="Max Violations Display"
              type="number"
              value={settings.maxViolations}
              onChange={(e) => setSettings({ ...settings, maxViolations: parseInt(e.target.value) })}
              fullWidth
              helperText="Maximum number of violations to display in lists"
            />

            <FormControlLabel
              control={
                <Switch
                  checked={settings.performanceMode}
                  onChange={(e) => setSettings({ ...settings, performanceMode: e.target.checked })}
                />
              }
              label="Performance mode (reduced animations)"
            />
          </Stack>
        </TabPanel>

        {/* API & Integration Tab */}
        <TabPanel value={tabValue} index={3}>
          <Stack spacing={3}>
            <TextField
              label="API Endpoint"
              value={settings.apiEndpoint}
              onChange={(e) => setSettings({ ...settings, apiEndpoint: e.target.value })}
              fullWidth
              helperText="Base URL for API requests"
            />

            <Card variant="outlined">
              <CardContent>
                <Typography variant="subtitle2" gutterBottom>
                  API Authentication
                </Typography>
                <Typography variant="body2" color="text.secondary" paragraph>
                  Configure API authentication credentials for external integrations.
                </Typography>
                <Button variant="outlined" size="small">
                  Configure API Keys
                </Button>
              </CardContent>
            </Card>

            <Card variant="outlined">
              <CardContent>
                <Typography variant="subtitle2" gutterBottom>
                  Webhook Configuration
                </Typography>
                <Typography variant="body2" color="text.secondary" paragraph>
                  Set up webhooks for automated notifications to external services.
                </Typography>
                <Button variant="outlined" size="small">
                  Manage Webhooks
                </Button>
              </CardContent>
            </Card>
          </Stack>
        </TabPanel>

        {/* Security Tab */}
        <TabPanel value={tabValue} index={4}>
          <Stack spacing={3}>
            <TextField
              label="Quality Score Threshold"
              type="number"
              value={settings.qualityThreshold}
              onChange={(e) => setSettings({ ...settings, qualityThreshold: parseInt(e.target.value) })}
              fullWidth
              helperText="Minimum acceptable quality score (triggers alerts if below)"
            />

            <FormControlLabel
              control={
                <Switch
                  checked={settings.debugMode}
                  onChange={(e) => setSettings({ ...settings, debugMode: e.target.checked })}
                />
              }
              label="Debug mode (show detailed error messages)"
            />

            <Divider sx={{ my: 2 }} />

            <Card variant="outlined">
              <CardContent>
                <Typography variant="subtitle2" gutterBottom>
                  Session Management
                </Typography>
                <Typography variant="body2" color="text.secondary" paragraph>
                  Configure session timeout and security settings.
                </Typography>
                <Button variant="outlined" size="small">
                  Configure Sessions
                </Button>
              </CardContent>
            </Card>

            <Card variant="outlined">
              <CardContent>
                <Typography variant="subtitle2" gutterBottom>
                  Access Control
                </Typography>
                <Typography variant="body2" color="text.secondary" paragraph>
                  Manage user permissions and access levels.
                </Typography>
                <Button variant="outlined" size="small">
                  Manage Permissions
                </Button>
              </CardContent>
            </Card>
          </Stack>
        </TabPanel>

        <Box sx={{ p: 3, display: 'flex', gap: 2, justifyContent: 'flex-end' }}>
          <Button
            variant="outlined"
            startIcon={<Refresh />}
            onClick={handleReset}
          >
            Reset to Defaults
          </Button>
          <Button
            variant="contained"
            startIcon={<Save />}
            onClick={handleSave}
          >
            Save Changes
          </Button>
        </Box>
      </Paper>
    </Box>
  );
};

export default SettingsPage;


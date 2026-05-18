import React, { useState } from 'react';
import {
  Box,
  Grid,
  Container,
  Typography,
  Card,
  CardContent,
  FormControl,
  InputLabel,
  Select,
  MenuItem,
  IconButton,
  Drawer,
  useTheme,
  useMediaQuery
} from '@mui/material';
import {
  Settings,
  Refresh,
  Fullscreen,
  Menu as MenuIcon
} from '@mui/icons-material';
import { motion } from 'framer-motion';
import QualityOverviewChart from '../components/QualityOverviewChart';
import TrendAnalysisChart from '../components/TrendAnalysisChart';
import ViolationDistributionChart from '../components/ViolationDistributionChart';
import TeamPerformanceChart from '../components/TeamPerformanceChart';
import RiskAssessmentMatrix from '../components/RiskAssessmentMatrix';
import InteractiveCodeViewer from '../components/InteractiveCodeViewer';
import AIChat from '../components/AIChat';
import { useDashboardStore } from '../stores/dashboardStore';

export function MainDashboard() {
  const theme = useTheme();
  const isMobile = useMediaQuery(theme.breakpoints.down('md'));
  const [drawerOpen, setDrawerOpen] = useState(false);
  const {
    selectedProject,
    timeRange,
    layout,
    filters,
    preferences,
    setProject,
    setTimeRange,
    setLayout,
    updatePreferences
  } = useDashboardStore();

  const handleRefresh = () => {
    // Trigger data refresh
    window.location.reload();
  };

  const renderMobileDrawer = () => (
    <Drawer
      anchor="left"
      open={drawerOpen}
      onClose={() => setDrawerOpen(false)}
      sx={{ '& .MuiDrawer-paper': { width: 280, p: 2 } }}
    >
      <Typography variant="h6" gutterBottom>
        Dashboard Controls
      </Typography>
      
      <FormControl fullWidth size="small" sx={{ mb: 2 }}>
        <InputLabel>Time Range</InputLabel>
        <Select
          value={timeRange}
          label="Time Range"
          onChange={(e) => setTimeRange(e.target.value as any)}
        >
          <MenuItem value="7d">7 Days</MenuItem>
          <MenuItem value="30d">30 Days</MenuItem>
          <MenuItem value="90d">90 Days</MenuItem>
          <MenuItem value="1y">1 Year</MenuItem>
        </Select>
      </FormControl>

      <FormControl fullWidth size="small" sx={{ mb: 2 }}>
        <InputLabel>Layout</InputLabel>
        <Select
          value={layout}
          label="Layout"
          onChange={(e) => setLayout(e.target.value as any)}
        >
          <MenuItem value="grid">Grid</MenuItem>
          <MenuItem value="list">List</MenuItem>
        </Select>
      </FormControl>
    </Drawer>
  );

  const renderDesktopControls = () => (
    <Box sx={{ display: 'flex', gap: 2, alignItems: 'center', mb: 3 }}>
      <FormControl size="small" sx={{ minWidth: 120 }}>
        <InputLabel>Time Range</InputLabel>
        <Select
          value={timeRange}
          label="Time Range"
          onChange={(e) => setTimeRange(e.target.value as any)}
        >
          <MenuItem value="7d">7 Days</MenuItem>
          <MenuItem value="30d">30 Days</MenuItem>
          <MenuItem value="90d">90 Days</MenuItem>
          <MenuItem value="1y">1 Year</MenuItem>
        </Select>
      </FormControl>

      <FormControl size="small" sx={{ minWidth: 100 }}>
        <InputLabel>Layout</InputLabel>
        <Select
          value={layout}
          label="Layout"
          onChange={(e) => setLayout(e.target.value as any)}
        >
          <MenuItem value="grid">Grid</MenuItem>
          <MenuItem value="list">List</MenuItem>
        </Select>
      </FormControl>

      <IconButton onClick={handleRefresh} title="Refresh Data">
        <Refresh />
      </IconButton>

      <IconButton title="Settings">
        <Settings />
      </IconButton>

      <IconButton title="Fullscreen">
        <Fullscreen />
      </IconButton>
    </Box>
  );

  const renderGridLayout = () => (
    <Grid container spacing={3}>
      {/* Quality Overview */}
      <Grid item xs={12} lg={8}>
        <motion.div
          initial={{ opacity: 0, y: 20 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ duration: 0.5 }}
        >
          <QualityOverviewChart
            projectId={selectedProject}
            timeRange={timeRange}
            showDetails={!preferences.compactMode}
            compact={preferences.compactMode}
          />
        </motion.div>
      </Grid>

      {/* AI Chat */}
      <Grid item xs={12} lg={4}>
        <motion.div
          initial={{ opacity: 0, y: 20 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ duration: 0.5, delay: 0.1 }}
        >
          <AIChat
            projectId={selectedProject}
            compact={preferences.compactMode}
          />
        </motion.div>
      </Grid>

      {/* Trend Analysis */}
      <Grid item xs={12} lg={8}>
        <motion.div
          initial={{ opacity: 0, y: 20 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ duration: 0.5, delay: 0.2 }}
        >
          <TrendAnalysisChart
            projectId={selectedProject}
            initialTimeRange={typeof timeRange === 'string' ? timeRange as any : undefined}
            compact={preferences.compactMode}
          />
        </motion.div>
      </Grid>

      {/* Violation Distribution */}
      <Grid item xs={12} lg={4}>
        <motion.div
          initial={{ opacity: 0, y: 20 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ duration: 0.5, delay: 0.3 }}
        >
          <ViolationDistributionChart
            projectId={selectedProject}
            timeRange={timeRange}
            showDetails={!preferences.compactMode}
            compact={preferences.compactMode}
          />
        </motion.div>
      </Grid>

      {/* Team Performance */}
      <Grid item xs={12} lg={6}>
        <motion.div
          initial={{ opacity: 0, y: 20 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ duration: 0.5, delay: 0.4 }}
        >
          <TeamPerformanceChart
            projectId={selectedProject}
            timeRange={timeRange}
            compact={preferences.compactMode}
          />
        </motion.div>
      </Grid>

      {/* Risk Assessment Matrix */}
      <Grid item xs={12} lg={6}>
        <motion.div
          initial={{ opacity: 0, y: 20 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ duration: 0.5, delay: 0.5 }}
        >
          <RiskAssessmentMatrix
            projectId={selectedProject}
            timeRange={timeRange}
            compact={preferences.compactMode}
          />
        </motion.div>
      </Grid>

      {/* Interactive Code Viewer */}
      <Grid item xs={12}>
        <motion.div
          initial={{ opacity: 0, y: 20 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ duration: 0.5, delay: 0.6 }}
        >
          <InteractiveCodeViewer
            projectId={selectedProject}
            compact={preferences.compactMode}
          />
        </motion.div>
      </Grid>
    </Grid>
  );

  const renderListLayout = () => (
    <Box sx={{ display: 'flex', flexDirection: 'column', gap: 3 }}>
      <motion.div
        initial={{ opacity: 0, y: 20 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.5 }}
      >
        <QualityOverviewChart
          projectId={selectedProject}
          timeRange={timeRange}
          showDetails={true}
          compact={false}
        />
      </motion.div>

      <motion.div
        initial={{ opacity: 0, y: 20 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.5, delay: 0.1 }}
      >
        <TrendAnalysisChart
          projectId={selectedProject}
          initialTimeRange={typeof timeRange === 'string' ? timeRange as any : undefined}
          compact={false}
        />
      </motion.div>

      <Grid container spacing={3}>
        <Grid item xs={12} md={8}>
          <motion.div
            initial={{ opacity: 0, y: 20 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.5, delay: 0.2 }}
          >
            <ViolationDistributionChart
              projectId={selectedProject}
              timeRange={timeRange}
              showDetails={true}
              compact={false}
            />
          </motion.div>
        </Grid>
        <Grid item xs={12} md={4}>
          <motion.div
            initial={{ opacity: 0, y: 20 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.5, delay: 0.3 }}
          >
            <AIChat
              projectId={selectedProject}
              compact={false}
            />
          </motion.div>
        </Grid>
      </Grid>

      <motion.div
        initial={{ opacity: 0, y: 20 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.5, delay: 0.4 }}
      >
        <TeamPerformanceChart
          projectId={selectedProject}
          timeRange={timeRange}
          compact={false}
        />
      </motion.div>

      <motion.div
        initial={{ opacity: 0, y: 20 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.5, delay: 0.5 }}
      >
        <RiskAssessmentMatrix
          projectId={selectedProject}
          timeRange={timeRange}
          compact={false}
        />
      </motion.div>

      <motion.div
        initial={{ opacity: 0, y: 20 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.5, delay: 0.6 }}
      >
        <InteractiveCodeViewer
          projectId={selectedProject}
          compact={false}
        />
      </motion.div>
    </Box>
  );

  return (
    <Container maxWidth="xl" sx={{ py: 3 }}>
      {/* Header */}
      <Box sx={{ 
        display: 'flex', 
        justifyContent: 'space-between', 
        alignItems: 'center', 
        mb: 3 
      }}>
        <Box sx={{ display: 'flex', alignItems: 'center', gap: 2 }}>
          {isMobile && (
            <IconButton onClick={() => setDrawerOpen(true)}>
              <MenuIcon />
            </IconButton>
          )}
          <Typography variant="h4" component="h1" fontWeight="bold">
            Code Quality Dashboard
          </Typography>
        </Box>
        
        {selectedProject && (
          <Card sx={{ bgcolor: theme.palette.primary.main, color: 'white' }}>
            <CardContent sx={{ p: 1.5, '&:last-child': { pb: 1.5 } }}>
              <Typography variant="body2">
                Project: {selectedProject}
              </Typography>
            </CardContent>
          </Card>
        )}
      </Box>

      {/* Controls */}
      {!isMobile && renderDesktopControls()}
      {isMobile && renderMobileDrawer()}

      {/* Main Content */}
      <Box sx={{ minHeight: '70vh' }}>
        {layout === 'grid' ? renderGridLayout() : renderListLayout()}
      </Box>

      {/* Footer */}
      <Box sx={{ 
        mt: 4, 
        pt: 2, 
        borderTop: 1, 
        borderColor: 'divider',
        textAlign: 'center'
      }}>
        <Typography variant="body2" color="textSecondary">
          Adobe Commerce Quality Dashboard • Last updated: {new Date().toLocaleTimeString()}
        </Typography>
      </Box>
    </Container>
  );
};

export default MainDashboard; 
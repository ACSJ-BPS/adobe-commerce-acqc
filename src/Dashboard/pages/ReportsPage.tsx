import React, { useState } from 'react';
import {
  Box,
  Paper,
  Typography,
  Button,
  Grid,
  Card,
  CardContent,
  CardActions,
  Select,
  MenuItem,
  FormControl,
  InputLabel,
  Chip,
  Stack,
  Dialog,
  DialogTitle,
  DialogContent,
  DialogActions,
  CircularProgress
} from '@mui/material';
import {
  Download,
  Visibility,
  Share,
  Delete,
  PictureAsPdf,
  TableChart,
  Code
} from '@mui/icons-material';
import { format } from 'date-fns';

interface Report {
  id: string;
  name: string;
  type: string;
  format: string;
  createdAt: Date;
  size: string;
  status: 'ready' | 'generating' | 'failed';
  violations: number;
  qualityScore: number;
}

const ReportsPage: React.FC = () => {
  const [reports, setReports] = useState<Report[]>([
    {
      id: '1',
      name: 'Q4 Quality Report',
      type: 'comprehensive',
      format: 'PDF',
      createdAt: new Date('2025-01-06'),
      size: '2.4 MB',
      status: 'ready',
      violations: 234,
      qualityScore: 85
    },
    {
      id: '2',
      name: 'Security Audit',
      type: 'security',
      format: 'HTML',
      createdAt: new Date('2025-01-05'),
      size: '1.8 MB',
      status: 'ready',
      violations: 45,
      qualityScore: 92
    }
  ]);

  const [reportType, setReportType] = useState('comprehensive');
  const [reportFormat, setReportFormat] = useState('html');
  const [generating, setGenerating] = useState(false);
  const [openDialog, setOpenDialog] = useState(false);

  const handleGenerateReport = () => {
    setGenerating(true);
    // Simulate report generation
    setTimeout(() => {
      const newReport: Report = {
        id: String(reports.length + 1),
        name: `${reportType.charAt(0).toUpperCase() + reportType.slice(1)} Report`,
        type: reportType,
        format: reportFormat.toUpperCase(),
        createdAt: new Date(),
        size: '2.1 MB',
        status: 'ready',
        violations: Math.floor(Math.random() * 500),
        qualityScore: Math.floor(Math.random() * 40) + 60
      };
      setReports([newReport, ...reports]);
      setGenerating(false);
      setOpenDialog(false);
    }, 3000);
  };

  const getFormatIcon = (format: string) => {
    switch (format.toLowerCase()) {
      case 'pdf':
        return <PictureAsPdf />;
      case 'excel':
        return <TableChart />;
      case 'json':
        return <Code />;
      default:
        return <Visibility />;
    }
  };

  const getStatusColor = (status: string) => {
    switch (status) {
      case 'ready':
        return 'success';
      case 'generating':
        return 'warning';
      case 'failed':
        return 'error';
      default:
        return 'default';
    }
  };

  return (
    <Box sx={{ p: 3 }}>
      <Box sx={{ mb: 4, display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
        <Typography variant="h4" component="h1">
          Quality Reports
        </Typography>
        <Button
          variant="contained"
          color="primary"
          onClick={() => setOpenDialog(true)}
          disabled={generating}
        >
          Generate New Report
        </Button>
      </Box>

      <Grid container spacing={3}>
        {reports.map((report) => (
          <Grid item xs={12} md={6} lg={4} key={report.id}>
            <Card>
              <CardContent>
                <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'start', mb: 2 }}>
                  <Typography variant="h6" component="div">
                    {report.name}
                  </Typography>
                  <Chip
                    label={report.status}
                    color={getStatusColor(report.status)}
                    size="small"
                  />
                </Box>

                <Stack spacing={1}>
                  <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
                    {getFormatIcon(report.format)}
                    <Typography variant="body2" color="text.secondary">
                      {report.format} • {report.size}
                    </Typography>
                  </Box>

                  <Typography variant="body2" color="text.secondary">
                    Created: {format(report.createdAt, 'MMM dd, yyyy')}
                  </Typography>

                  <Box sx={{ display: 'flex', gap: 2, mt: 1 }}>
                    <Box>
                      <Typography variant="caption" color="text.secondary">
                        Violations
                      </Typography>
                      <Typography variant="h6">
                        {report.violations}
                      </Typography>
                    </Box>
                    <Box>
                      <Typography variant="caption" color="text.secondary">
                        Quality Score
                      </Typography>
                      <Typography variant="h6" color={report.qualityScore >= 80 ? 'success.main' : 'warning.main'}>
                        {report.qualityScore}
                      </Typography>
                    </Box>
                  </Box>
                </Stack>
              </CardContent>

              <CardActions>
                <Button size="small" startIcon={<Visibility />}>
                  View
                </Button>
                <Button size="small" startIcon={<Download />}>
                  Download
                </Button>
                <Button size="small" startIcon={<Share />}>
                  Share
                </Button>
                <Button size="small" color="error" startIcon={<Delete />}>
                  Delete
                </Button>
              </CardActions>
            </Card>
          </Grid>
        ))}
      </Grid>

      {/* Generate Report Dialog */}
      <Dialog open={openDialog} onClose={() => !generating && setOpenDialog(false)} maxWidth="sm" fullWidth>
        <DialogTitle>Generate New Report</DialogTitle>
        <DialogContent>
          <Stack spacing={3} sx={{ mt: 2 }}>
            <FormControl fullWidth>
              <InputLabel>Report Type</InputLabel>
              <Select
                value={reportType}
                label="Report Type"
                onChange={(e) => setReportType(e.target.value)}
                disabled={generating}
              >
                <MenuItem value="comprehensive">Comprehensive</MenuItem>
                <MenuItem value="security">Security Audit</MenuItem>
                <MenuItem value="performance">Performance Analysis</MenuItem>
                <MenuItem value="executive">Executive Summary</MenuItem>
                <MenuItem value="detailed">Detailed Analysis</MenuItem>
              </Select>
            </FormControl>

            <FormControl fullWidth>
              <InputLabel>Format</InputLabel>
              <Select
                value={reportFormat}
                label="Format"
                onChange={(e) => setReportFormat(e.target.value)}
                disabled={generating}
              >
                <MenuItem value="html">HTML (Interactive)</MenuItem>
                <MenuItem value="pdf">PDF (Professional)</MenuItem>
                <MenuItem value="excel">Excel (Data Export)</MenuItem>
                <MenuItem value="json">JSON (API)</MenuItem>
                <MenuItem value="junit">JUnit XML (CI/CD)</MenuItem>
              </Select>
            </FormControl>

            {generating && (
              <Box sx={{ display: 'flex', alignItems: 'center', gap: 2 }}>
                <CircularProgress size={24} />
                <Typography variant="body2" color="text.secondary">
                  Generating report... This may take a few moments.
                </Typography>
              </Box>
            )}
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setOpenDialog(false)} disabled={generating}>
            Cancel
          </Button>
          <Button
            onClick={handleGenerateReport}
            variant="contained"
            disabled={generating}
          >
            {generating ? 'Generating...' : 'Generate Report'}
          </Button>
        </DialogActions>
      </Dialog>
    </Box>
  );
};

export default ReportsPage;


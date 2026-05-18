import React, { useState, useMemo } from 'react';
import {
  Box,
  Card,
  CardContent,
  Typography,
  Grid,
  Chip,
  IconButton,
  Tooltip,
  Dialog,
  DialogTitle,
  DialogContent,
  DialogActions,
  Button,
  useTheme,
  alpha
} from '@mui/material';
import {
  Code,
  BugReport,
  Security,
  Speed,
  Edit,
  Visibility,
  Download,
  Share,
  Comment,
  Warning,
  Error,
  Info
} from '@mui/icons-material';
import { motion } from 'framer-motion';
import { Prism as SyntaxHighlighter } from 'react-syntax-highlighter';
import { materialDark, materialLight } from 'react-syntax-highlighter/dist/esm/styles/prism';

interface CodeIssue {
  id: string;
  line: number;
  column: number;
  severity: 'critical' | 'error' | 'warning' | 'info';
  type: 'security' | 'performance' | 'maintainability' | 'style';
  message: string;
  suggestion: string;
  rule: string;
}

interface CodeFile {
  id: string;
  name: string;
  path: string;
  language: string;
  content: string;
  issues: CodeIssue[];
  metrics: {
    complexity: number;
    lines: number;
    maintainability: number;
    testCoverage: number;
  };
  lastModified: string;
}

interface InteractiveCodeViewerProps {
  projectId?: string;
  fileId?: string;
  compact?: boolean;
}

// Mock data - replace with actual API call
const mockCodeFiles: CodeFile[] = [
  {
    id: 'file-1',
    name: 'UserController.php',
    path: 'app/Http/Controllers/UserController.php',
    language: 'php',
    content: `<?php

namespace App\\Http\\Controllers;

use App\\Models\\User;
use Illuminate\\Http\\Request;
use Illuminate\\Http\\Response;

class UserController extends Controller
{
    public function index()
    {
        // TODO: Add pagination
        $users = User::all();
        return response()->json($users);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users',
            'password' => 'required|min:8'
        ]);

        // SECURITY ISSUE: Password should be hashed
        $user = User::create($validated);
        
        return response()->json($user, 201);
    }

    public function show($id)
    {
        // PERFORMANCE ISSUE: No eager loading
        $user = User::find($id);
        
        if (!$user) {
            return response()->json(['error' => 'User not found'], 404);
        }
        
        return response()->json($user);
    }

    public function update(Request $request, $id)
    {
        $user = User::find($id);
        
        if (!$user) {
            return response()->json(['error' => 'User not found'], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,' . $id
        ]);

        $user->update($validated);
        
        return response()->json($user);
    }

    public function destroy($id)
    {
        $user = User::find($id);
        
        if (!$user) {
            return response()->json(['error' => 'User not found'], 404);
        }

        $user->delete();
        
        return response()->json(null, 204);
    }
}`,
    issues: [
      {
        id: 'issue-1',
        line: 25,
        column: 9,
        severity: 'critical',
        type: 'security',
        message: 'Password is not hashed before storing',
        suggestion: 'Use Hash::make() to hash the password',
        rule: 'security.password-hashing'
      },
      {
        id: 'issue-2',
        line: 35,
        column: 9,
        severity: 'warning',
        type: 'performance',
        message: 'No eager loading for relationships',
        suggestion: 'Use with() to eager load relationships',
        rule: 'performance.eager-loading'
      },
      {
        id: 'issue-3',
        line: 15,
        column: 9,
        severity: 'info',
        type: 'maintainability',
        message: 'TODO comment found',
        suggestion: 'Implement pagination or remove TODO',
        rule: 'maintainability.todo-comments'
      }
    ],
    metrics: {
      complexity: 8,
      lines: 85,
      maintainability: 75,
      testCoverage: 60
    },
    lastModified: '2024-01-15T10:30:00Z'
  }
];

const InteractiveCodeViewer: React.FC<InteractiveCodeViewerProps> = ({
  projectId,
  fileId,
  compact = false
}) => {
  const theme = useTheme();
  const [selectedFile, setSelectedFile] = useState<CodeFile>(mockCodeFiles[0]);
  const [selectedIssue, setSelectedIssue] = useState<CodeIssue | null>(null);
  const [showIssueDialog, setShowIssueDialog] = useState(false);
  const [showLineNumbers, setShowLineNumbers] = useState(true);

  const getSeverityColor = (severity: string) => {
    switch (severity) {
      case 'critical':
        return theme.palette.error.main;
      case 'error':
        return theme.palette.error.light;
      case 'warning':
        return theme.palette.warning.main;
      case 'info':
        return theme.palette.info.main;
      default:
        return theme.palette.grey[500];
    }
  };

  const getTypeIcon = (type: string) => {
    switch (type) {
      case 'security':
        return <Security />;
      case 'performance':
        return <Speed />;
      case 'maintainability':
        return <Code />;
      case 'style':
        return <Edit />;
      default:
        return <BugReport />;
    }
  };

  const highlightedCode = useMemo(() => {
    const lines = selectedFile.content.split('\n');
    const issueLines = selectedFile.issues.reduce((acc, issue) => {
      acc[issue.line] = issue;
      return acc;
    }, {} as Record<number, CodeIssue>);

    return lines.map((line, index) => {
      const lineNumber = index + 1;
      const issue = issueLines[lineNumber];
      
      return {
        lineNumber,
        content: line,
        issue,
        hasIssue: !!issue
      };
    });
  }, [selectedFile]);

  const handleIssueClick = (issue: CodeIssue) => {
    setSelectedIssue(issue);
    setShowIssueDialog(true);
  };

  if (compact) {
    return (
      <Card>
        <CardContent>
          <Typography variant="h6" gutterBottom>
            Code Viewer
          </Typography>
          <Box sx={{ maxHeight: 300, overflow: 'auto' }}>
            <SyntaxHighlighter
              language={selectedFile.language}
              style={theme.palette.mode === 'dark' ? materialDark : materialLight}
              showLineNumbers={showLineNumbers}
              customStyle={{
                margin: 0,
                fontSize: '12px',
                maxHeight: '250px'
              }}
            >
              {selectedFile.content}
            </SyntaxHighlighter>
          </Box>
          <Box sx={{ mt: 2, display: 'flex', gap: 1, flexWrap: 'wrap' }}>
            {selectedFile.issues.slice(0, 3).map((issue) => (
              <Chip
                key={issue.id}
                icon={getTypeIcon(issue.type)}
                label={`Line ${issue.line}: ${issue.message}`}
                color={issue.severity === 'critical' ? 'error' : 
                       issue.severity === 'error' ? 'error' : 
                       issue.severity === 'warning' ? 'warning' : 'info'}
                size="small"
                onClick={() => handleIssueClick(issue)}
              />
            ))}
            {selectedFile.issues.length > 3 && (
              <Chip
                label={`+${selectedFile.issues.length - 3} more issues`}
                size="small"
                variant="outlined"
              />
            )}
          </Box>
        </CardContent>
      </Card>
    );
  }

  return (
    <motion.div
      initial={{ opacity: 0, y: 20 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.5 }}
    >
      <Card>
        <CardContent>
          <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', mb: 2 }}>
            <Typography variant="h5">
              Interactive Code Viewer
            </Typography>
            <Box sx={{ display: 'flex', gap: 1 }}>
              <Tooltip title="Toggle Line Numbers">
                <IconButton onClick={() => setShowLineNumbers(!showLineNumbers)}>
                  <Visibility />
                </IconButton>
              </Tooltip>
              <Tooltip title="Download File">
                <IconButton>
                  <Download />
                </IconButton>
              </Tooltip>
              <Tooltip title="Share">
                <IconButton>
                  <Share />
                </IconButton>
              </Tooltip>
            </Box>
          </Box>

          <Grid container spacing={3}>
            {/* File Information */}
            <Grid item xs={12} md={3}>
              <Typography variant="h6" gutterBottom>
                File Information
              </Typography>
              
              <Box sx={{ mb: 3 }}>
                <Typography variant="subtitle1" gutterBottom>
                  {selectedFile.name}
                </Typography>
                <Typography variant="body2" color="textSecondary" gutterBottom>
                  {selectedFile.path}
                </Typography>
                <Typography variant="body2" color="textSecondary">
                  Last modified: {new Date(selectedFile.lastModified).toLocaleDateString()}
                </Typography>
              </Box>

              <Typography variant="h6" gutterBottom>
                Metrics
              </Typography>
              <Box sx={{ mb: 3 }}>
                <Box sx={{ display: 'flex', justifyContent: 'space-between', mb: 1 }}>
                  <Typography variant="body2">Complexity:</Typography>
                  <Typography variant="body2">{selectedFile.metrics.complexity}</Typography>
                </Box>
                <Box sx={{ display: 'flex', justifyContent: 'space-between', mb: 1 }}>
                  <Typography variant="body2">Lines:</Typography>
                  <Typography variant="body2">{selectedFile.metrics.lines}</Typography>
                </Box>
                <Box sx={{ display: 'flex', justifyContent: 'space-between', mb: 1 }}>
                  <Typography variant="body2">Maintainability:</Typography>
                  <Typography variant="body2">{selectedFile.metrics.maintainability}%</Typography>
                </Box>
                <Box sx={{ display: 'flex', justifyContent: 'space-between', mb: 1 }}>
                  <Typography variant="body2">Test Coverage:</Typography>
                  <Typography variant="body2">{selectedFile.metrics.testCoverage}%</Typography>
                </Box>
              </Box>

              <Typography variant="h6" gutterBottom>
                Issues ({selectedFile.issues.length})
              </Typography>
              <Box sx={{ display: 'flex', flexDirection: 'column', gap: 1 }}>
                {selectedFile.issues.map((issue) => (
                  <Chip
                    key={issue.id}
                    icon={getTypeIcon(issue.type)}
                    label={`Line ${issue.line}: ${issue.message}`}
                    color={issue.severity === 'critical' ? 'error' : 
                           issue.severity === 'error' ? 'error' : 
                           issue.severity === 'warning' ? 'warning' : 'info'}
                    size="small"
                    onClick={() => handleIssueClick(issue)}
                    sx={{ justifyContent: 'flex-start' }}
                  />
                ))}
              </Box>
            </Grid>

            {/* Code Viewer */}
            <Grid item xs={12} md={9}>
              <Typography variant="h6" gutterBottom>
                Code View
              </Typography>
              <Box sx={{ 
                border: 1, 
                borderColor: 'divider', 
                borderRadius: 1,
                maxHeight: 600,
                overflow: 'auto'
              }}>
                <SyntaxHighlighter
                  language={selectedFile.language}
                  style={theme.palette.mode === 'dark' ? materialDark : materialLight}
                  showLineNumbers={showLineNumbers}
                  customStyle={{
                    margin: 0,
                    fontSize: '14px',
                    backgroundColor: 'transparent'
                  }}
                  lineNumberStyle={{
                    color: theme.palette.text.secondary,
                    minWidth: '3em'
                  }}
                >
                  {selectedFile.content}
                </SyntaxHighlighter>
              </Box>
            </Grid>
          </Grid>

          {/* Issue Details Dialog */}
          <Dialog
            open={showIssueDialog}
            onClose={() => setShowIssueDialog(false)}
            maxWidth="md"
            fullWidth
          >
            <DialogTitle>
              <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
                {getTypeIcon(selectedIssue?.type || '')}
                <Typography variant="h6">
                  Issue Details
                </Typography>
                <Chip
                  label={selectedIssue?.severity}
                  color={selectedIssue?.severity === 'critical' ? 'error' : 
                         selectedIssue?.severity === 'error' ? 'error' : 
                         selectedIssue?.severity === 'warning' ? 'warning' : 'info'}
                  size="small"
                />
              </Box>
            </DialogTitle>
            <DialogContent>
              {selectedIssue && (
                <Box>
                  <Typography variant="subtitle1" gutterBottom>
                    {selectedIssue.message}
                  </Typography>
                  <Typography variant="body2" color="textSecondary" gutterBottom>
                    Location: Line {selectedIssue.line}, Column {selectedIssue.column}
                  </Typography>
                  <Typography variant="body2" color="textSecondary" gutterBottom>
                    Rule: {selectedIssue.rule}
                  </Typography>
                  <Typography variant="h6" gutterBottom sx={{ mt: 2 }}>
                    Suggestion
                  </Typography>
                  <Typography variant="body2">
                    {selectedIssue.suggestion}
                  </Typography>
                </Box>
              )}
            </DialogContent>
            <DialogActions>
              <Button onClick={() => setShowIssueDialog(false)}>
                Close
              </Button>
              <Button variant="contained" color="primary">
                Apply Fix
              </Button>
            </DialogActions>
          </Dialog>
        </CardContent>
      </Card>
    </motion.div>
  );
};

export default InteractiveCodeViewer; 
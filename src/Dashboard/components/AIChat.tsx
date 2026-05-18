import React, { useState, useRef, useEffect } from 'react';
import {
  Box,
  Card,
  CardContent,
  TextField,
  IconButton,
  Typography,
  Avatar,
  Chip,
  CircularProgress,
  Fade,
  useTheme
} from '@mui/material';
import {
  Send,
  Mic,
  Stop,
  SmartToy,
  Person,
  Code,
  TrendingUp,
  Security,
  Speed
} from '@mui/icons-material';
import { motion, AnimatePresence } from 'framer-motion';
import { useAIChat } from '../hooks/useAIChat';

interface Message {
  id: string;
  type: 'user' | 'ai';
  content: string;
  timestamp: Date;
  metadata?: {
    intent?: string;
    confidence?: number;
    suggestions?: string[];
    relatedMetrics?: string[];
  };
}

interface AIChatProps {
  projectId?: string;
  compact?: boolean;
  suggestions?: string[];
}

const AIChat: React.FC<AIChatProps> = ({
  projectId,
  compact = false,
  suggestions = [
    "What's my code quality score?",
    "Show me security vulnerabilities",
    "How can I improve performance?",
    "Analyze technical debt trends"
  ]
}) => {
  const theme = useTheme();
  const [messages, setMessages] = useState<Message[]>([]);
  const [input, setInput] = useState('');
  const [isRecording, setIsRecording] = useState(false);
  const messagesEndRef = useRef<HTMLDivElement>(null);
  const inputRef = useRef<HTMLInputElement>(null);
  
  const { 
    sendMessage, 
    isLoading, 
    error,
    startVoiceRecording,
    stopVoiceRecording 
  } = useAIChat(projectId);

  useEffect(() => {
    messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
  }, [messages]);

  useEffect(() => {
    // Add welcome message
    if (messages.length === 0) {
      setMessages([{
        id: '1',
        type: 'ai',
        content: "Hello! I'm your AI quality assistant. I can help you analyze your code quality, explain metrics, and provide recommendations. What would you like to know?",
        timestamp: new Date(),
        metadata: {
          intent: 'greeting',
          confidence: 1.0,
          suggestions: suggestions
        }
      }]);
    }
  }, [suggestions]);

  const handleSendMessage = async () => {
    if (!input.trim() || isLoading) return;

    const userMessage: Message = {
      id: Date.now().toString(),
      type: 'user',
      content: input.trim(),
      timestamp: new Date()
    };

    setMessages(prev => [...prev, userMessage]);
    setInput('');

    try {
      const response = await sendMessage(input.trim());
      
      const aiMessage: Message = {
        id: (Date.now() + 1).toString(),
        type: 'ai',
        content: response.content,
        timestamp: new Date(),
        metadata: response.metadata
      };

      setMessages(prev => [...prev, aiMessage]);
    } catch (err) {
      const errorMessage: Message = {
        id: (Date.now() + 1).toString(),
        type: 'ai',
        content: "I apologize, but I'm having trouble processing your request right now. Please try again.",
        timestamp: new Date()
      };
      setMessages(prev => [...prev, errorMessage]);
    }
  };

  const handleKeyPress = (e: React.KeyboardEvent) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      handleSendMessage();
    }
  };

  const handleSuggestionClick = (suggestion: string) => {
    setInput(suggestion);
    inputRef.current?.focus();
  };

  const handleVoiceToggle = async () => {
    if (isRecording) {
      const transcription = await stopVoiceRecording();
      setIsRecording(false);
      if (transcription) {
        setInput(transcription);
      }
    } else {
      await startVoiceRecording();
      setIsRecording(true);
    }
  };

  const getMessageIcon = (message: Message) => {
    if (message.type === 'user') {
      return <Person />;
    }
    
    const intent = message.metadata?.intent;
    switch (intent) {
      case 'quality': return <TrendingUp />;
      case 'security': return <Security />;
      case 'performance': return <Speed />;
      case 'code': return <Code />;
      default: return <SmartToy />;
    }
  };

  const getConfidenceColor = (confidence?: number) => {
    if (!confidence) return theme.palette.grey[500];
    if (confidence >= 0.8) return theme.palette.success.main;
    if (confidence >= 0.6) return theme.palette.warning.main;
    return theme.palette.error.main;
  };

  return (
    <Card sx={{ height: compact ? 400 : 600, display: 'flex', flexDirection: 'column' }}>
      <CardContent sx={{ flex: 1, display: 'flex', flexDirection: 'column', p: 2 }}>
        {/* Header */}
        <Box sx={{ display: 'flex', alignItems: 'center', mb: 2, pb: 1, borderBottom: 1, borderColor: 'divider' }}>
          <Avatar sx={{ bgcolor: theme.palette.primary.main, mr: 2 }}>
            <SmartToy />
          </Avatar>
          <Box>
            <Typography variant="h6">AI Quality Assistant</Typography>
            <Typography variant="caption" color="textSecondary">
              Ask me anything about your code quality
            </Typography>
          </Box>
        </Box>

        {/* Messages */}
        <Box sx={{ flex: 1, overflow: 'auto', mb: 2 }}>
          <AnimatePresence>
            {messages.map((message) => (
              <motion.div
                key={message.id}
                initial={{ opacity: 0, y: 20 }}
                animate={{ opacity: 1, y: 0 }}
                exit={{ opacity: 0, y: -20 }}
                transition={{ duration: 0.3 }}
              >
                <Box
                  sx={{
                    display: 'flex',
                    mb: 2,
                    flexDirection: message.type === 'user' ? 'row-reverse' : 'row'
                  }}
                >
                  <Avatar
                    sx={{
                      bgcolor: message.type === 'user' ? theme.palette.secondary.main : theme.palette.primary.main,
                      width: 32,
                      height: 32,
                      mx: 1
                    }}
                  >
                    {getMessageIcon(message)}
                  </Avatar>
                  
                  <Box
                    sx={{
                      maxWidth: '70%',
                      bgcolor: message.type === 'user' 
                        ? theme.palette.primary.main 
                        : theme.palette.grey[100],
                      color: message.type === 'user' 
                        ? theme.palette.primary.contrastText 
                        : theme.palette.text.primary,
                      borderRadius: 2,
                      p: 1.5,
                      position: 'relative'
                    }}
                  >
                    <Typography variant="body2" sx={{ whiteSpace: 'pre-wrap' }}>
                      {message.content}
                    </Typography>
                    
                    {/* Message metadata */}
                    {message.metadata && (
                      <Box sx={{ mt: 1 }}>
                        {message.metadata.confidence && (
                          <Chip
                            size="small"
                            label={`${(message.metadata.confidence * 100).toFixed(0)}% confident`}
                            sx={{
                              bgcolor: getConfidenceColor(message.metadata.confidence),
                              color: 'white',
                              fontSize: '0.7rem',
                              height: 20,
                              mr: 0.5
                            }}
                          />
                        )}
                        
                        {message.metadata.relatedMetrics && (
                          <Box sx={{ mt: 0.5 }}>
                            {message.metadata.relatedMetrics.map((metric) => (
                              <Chip
                                key={metric}
                                size="small"
                                label={metric}
                                variant="outlined"
                                sx={{ fontSize: '0.7rem', height: 20, mr: 0.5 }}
                              />
                            ))}
                          </Box>
                        )}
                      </Box>
                    )}
                    
                    <Typography variant="caption" sx={{ opacity: 0.7, display: 'block', mt: 0.5 }}>
                      {message.timestamp.toLocaleTimeString()}
                    </Typography>
                  </Box>
                </Box>
              </motion.div>
            ))}
          </AnimatePresence>
          
          {/* Loading indicator */}
          {isLoading && (
            <Fade in={isLoading}>
              <Box sx={{ display: 'flex', alignItems: 'center', mb: 2 }}>
                <Avatar sx={{ bgcolor: theme.palette.primary.main, width: 32, height: 32, mx: 1 }}>
                  <SmartToy />
                </Avatar>
                <Box sx={{ 
                  bgcolor: theme.palette.grey[100], 
                  borderRadius: 2, 
                  p: 1.5,
                  display: 'flex',
                  alignItems: 'center',
                  gap: 1
                }}>
                  <CircularProgress size={16} />
                  <Typography variant="body2" color="textSecondary">
                    Analyzing...
                  </Typography>
                </Box>
              </Box>
            </Fade>
          )}
          
          <div ref={messagesEndRef} />
        </Box>

        {/* Suggestions */}
        {messages.length <= 1 && suggestions.length > 0 && (
          <Box sx={{ mb: 2 }}>
            <Typography variant="caption" color="textSecondary" gutterBottom>
              Try asking:
            </Typography>
            <Box sx={{ display: 'flex', flexWrap: 'wrap', gap: 0.5 }}>
              {suggestions.slice(0, compact ? 2 : 4).map((suggestion) => (
                <Chip
                  key={suggestion}
                  label={suggestion}
                  size="small"
                  variant="outlined"
                  clickable
                  onClick={() => handleSuggestionClick(suggestion)}
                  sx={{ fontSize: '0.75rem' }}
                />
              ))}
            </Box>
          </Box>
        )}

        {/* Input */}
        <Box sx={{ display: 'flex', gap: 1, alignItems: 'flex-end' }}>
          <TextField
            ref={inputRef}
            fullWidth
            size="small"
            placeholder="Ask about your code quality..."
            value={input}
            onChange={(e) => setInput(e.target.value)}
            onKeyPress={handleKeyPress}
            disabled={isLoading}
            multiline
            maxRows={3}
            sx={{
              '& .MuiOutlinedInput-root': {
                borderRadius: 2,
              }
            }}
          />
          
          <IconButton
            color="primary"
            onClick={handleVoiceToggle}
            disabled={isLoading}
            sx={{
              bgcolor: isRecording ? theme.palette.error.main : theme.palette.action.hover,
              color: isRecording ? 'white' : theme.palette.text.primary,
              '&:hover': {
                bgcolor: isRecording ? theme.palette.error.dark : theme.palette.action.selected,
              }
            }}
          >
            {isRecording ? <Stop /> : <Mic />}
          </IconButton>
          
          <IconButton
            color="primary"
            onClick={handleSendMessage}
            disabled={!input.trim() || isLoading}
            sx={{
              bgcolor: theme.palette.primary.main,
              color: 'white',
              '&:hover': {
                bgcolor: theme.palette.primary.dark,
              },
              '&:disabled': {
                bgcolor: theme.palette.action.disabledBackground,
                color: theme.palette.action.disabled,
              }
            }}
          >
            <Send />
          </IconButton>
        </Box>

        {/* Error display */}
        {error && (
          <Typography variant="caption" color="error" sx={{ mt: 1 }}>
            {error}
          </Typography>
        )}
      </CardContent>
    </Card>
  );
};

export default AIChat; 
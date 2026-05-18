// eslint-disable-next-line @typescript-eslint/ban-ts-comment
// @ts-nocheck
import { useState, useCallback } from 'react';
import { useMutation } from '@tanstack/react-query';
import { apiClient } from '../utils/apiClient';
import { useNotification } from './useNotification';

interface ChatMessage {
  role: 'user' | 'assistant';
  content: string;
  timestamp: Date;
}

interface ChatResponse {
  content: string;
  metadata?: {
    intent?: string;
    confidence?: number;
    suggestions?: string[];
    relatedMetrics?: string[];
  };
}

export const useAIChat = (projectId?: string) => {
  const [messages, setMessages] = useState<ChatMessage[]>([]);
  const [isRecording, setIsRecording] = useState(false);
  const { showNotification } = useNotification();

  const chatMutation = useMutation({
    mutationFn: async (message: string): Promise<ChatResponse> => {
      const response = await apiClient.post('/api/ai/chat', {
        message,
        projectId,
        context: {
          previousMessages: messages.slice(-5), // Last 5 messages for context
          currentMetrics: true
        }
      });
      return response.data;
    },
    onError: (error) => {
      showNotification('Failed to send message to AI', 'error');
      console.error('AI chat error:', error);
    }
  });

  const sendMessage = useCallback(async (content: string): Promise<ChatResponse> => {
    const userMessage: ChatMessage = {
      role: 'user',
      content,
      timestamp: new Date()
    };
    
    setMessages(prev => [...prev, userMessage]);
    
    try {
      const response = await chatMutation.mutateAsync(content);
      
      const assistantMessage: ChatMessage = {
        role: 'assistant',
        content: response.content,
        timestamp: new Date()
      };
      
      setMessages(prev => [...prev, assistantMessage]);
      
      return response;
    } catch (error) {
      throw error;
    }
  }, [chatMutation, messages]);

  const startVoiceRecording = useCallback(async (): Promise<void> => {
    if (!('webkitSpeechRecognition' in window) && !('SpeechRecognition' in window)) {
      showNotification('Speech recognition not supported', 'error');
      return;
    }

    const SpeechRecognition = window.webkitSpeechRecognition || window.SpeechRecognition;
    const recognition = new SpeechRecognition();
    
    recognition.continuous = false;
    recognition.interimResults = false;
    recognition.lang = 'en-US';

    recognition.onstart = () => {
      setIsRecording(true);
    };

    recognition.onresult = (event: SpeechRecognitionEvent) => {
      const transcript = event.results[0][0].transcript;
      sendMessage(transcript);
    };

    recognition.onerror = (event: SpeechRecognitionErrorEvent) => {
      showNotification(`Speech recognition error: ${event.error}`, 'error');
      setIsRecording(false);
    };

    recognition.onend = () => {
      setIsRecording(false);
    };

    recognition.start();
  }, [sendMessage, showNotification]);

  const stopVoiceRecording = useCallback(async (): Promise<string | null> => {
    setIsRecording(false);
    return null; // Placeholder - would return transcription
  }, []);

  const clearMessages = useCallback(() => {
    setMessages([]);
  }, []);

  return {
    messages,
    sendMessage,
    startVoiceRecording,
    stopVoiceRecording,
    clearMessages,
    isLoading: chatMutation.isLoading,
    error: (chatMutation.error as any)?.message || null,
    isRecording
  };
};

// Extend Window interface for speech recognition
declare global {
  interface Window {
    webkitSpeechRecognition: any;
    SpeechRecognition: any;
  }
}

// Web Speech API types
interface SpeechRecognitionEvent {
  results: SpeechRecognitionResultList;
}

interface SpeechRecognitionErrorEvent {
  error: string;
}

interface SpeechRecognitionResultList {
  [index: number]: SpeechRecognitionResult;
  length: number;
}

interface SpeechRecognitionResult {
  [index: number]: SpeechRecognitionAlternative;
  length: number;
}

interface SpeechRecognitionAlternative {
  transcript: string;
  confidence: number;
} 
<?php

declare(strict_types=1);

namespace ACQC\Core\AI;

/**
 * AI Conversation Response
 */
class AIConversationResponse
{
    private bool $success;
    private string $message;
    private array $metadata;

    public function __construct(
        bool $success,
        string $message,
        array $metadata = []
    ) {
        $this->success = $success;
        $this->message = $message;
        $this->metadata = $metadata;
    }

    public function isSuccess(): bool { return $this->success; }
    public function getMessage(): string { return $this->message; }
    public function getMetadata(): array { return $this->metadata; }
    
    public function getSuggestions(): array 
    { 
        return $this->metadata['suggestions'] ?? []; 
    }
    
    public function getFollowUpQuestions(): array 
    { 
        return $this->metadata['follow_up_questions'] ?? []; 
    }
    
    public function getCodeExamples(): array 
    { 
        return $this->metadata['code_examples'] ?? []; 
    }
    
    public function getResources(): array 
    { 
        return $this->metadata['resources'] ?? []; 
    }
    
    public function getConfidence(): float 
    { 
        return $this->metadata['confidence'] ?? 0.8; 
    }
    
    /**
     * Get the answer text
     */
    public function getAnswer(): string
    {
        return $this->message;
    }
    
    /**
     * Get sources referenced in the response
     */
    public function getSources(): array
    {
        return $this->metadata['sources'] ?? [];
    }
    
    /**
     * Convert response to array format
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'answer' => $this->message,
            'message' => $this->message, // For backward compatibility
            'confidence' => $this->getConfidence(),
            'suggestions' => $this->getSuggestions(),
            'follow_up_questions' => $this->getFollowUpQuestions(),
            'code_examples' => $this->getCodeExamples(),
            'resources' => $this->getResources(),
            'sources' => $this->getSources(),
            'metadata' => $this->metadata
        ];
    }
} 
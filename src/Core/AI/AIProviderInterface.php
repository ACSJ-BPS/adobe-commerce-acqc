<?php

declare(strict_types=1);

namespace ACQC\Core\AI;

/**
 * Interface for AI providers (OpenAI, Claude, local models, etc.)
 */
interface AIProviderInterface
{
    /**
     * Check if provider is available and configured
     */
    public function isAvailable(): bool;

    /**
     * Send chat message and get response
     */
    public function chat(string $prompt, array $options = []): string;

    /**
     * Get provider name
     */
    public function getName(): string;

    /**
     * Get provider version/model
     */
    public function getModel(): string;

    /**
     * Get rate limits information
     */
    public function getRateLimits(): array;

    /**
     * Estimate cost for a request
     */
    public function estimateCost(string $prompt): float;
} 
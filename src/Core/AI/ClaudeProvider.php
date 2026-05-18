<?php

declare(strict_types=1);

namespace ACQC\Core\AI;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Claude (Anthropic) API Provider for ACQC
 */
class ClaudeProvider implements AIProviderInterface
{
    private array $config;
    private LoggerInterface $logger;
    private string $apiKey;
    private string $model;
    private string $baseUrl;

    public function __construct(array $config, LoggerInterface $logger = null)
    {
        $this->config = $config;
        $this->logger = $logger ?? new NullLogger();
        $this->apiKey = $config['api_key'] ?? '';
        $this->model = $config['model'] ?? 'claude-3-sonnet-20240229';
        $this->baseUrl = $config['base_url'] ?? 'https://api.anthropic.com/v1';
    }

    public function isAvailable(): bool
    {
        return !empty($this->apiKey) && function_exists('curl_init');
    }

    public function chat(string $prompt, array $options = []): string
    {
        if (!$this->isAvailable()) {
            throw new \Exception('Claude provider not available');
        }

        $data = [
            'model' => $this->model,
            'max_tokens' => $options['max_tokens'] ?? 2000,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ]
        ];

        $response = $this->makeRequest('/messages', $data);
        
        if (!$response || !isset($response['content'][0]['text'])) {
            throw new \Exception('Invalid Claude response');
        }

        return $response['content'][0]['text'];
    }

    public function getName(): string
    {
        return 'claude';
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getRateLimits(): array
    {
        return [
            'requests_per_minute' => 50,
            'tokens_per_minute' => 40000
        ];
    }

    public function estimateCost(string $prompt): float
    {
        $tokens = $this->estimateTokens($prompt);
        
        // Claude pricing (approximate)
        $inputCostPer1k = 0.015;
        $outputCostPer1k = 0.075;
        
        return ($tokens * $inputCostPer1k / 1000) + (1000 * $outputCostPer1k / 1000);
    }

    private function makeRequest(string $endpoint, array $data): ?array
    {
        $url = $this->baseUrl . $endpoint;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'x-api-key: ' . $this->apiKey,
            'anthropic-version: 2023-06-01'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            $this->logger->error('Claude API request failed', [
                'http_code' => $httpCode,
                'response' => $response
            ]);
            throw new \Exception('Claude API request failed: ' . $httpCode);
        }

        return json_decode($response, true);
    }

    private function estimateTokens(string $text): int
    {
        return (int) ceil(strlen($text) / 4);
    }
} 
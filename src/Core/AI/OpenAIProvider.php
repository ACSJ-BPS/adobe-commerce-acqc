<?php

declare(strict_types=1);

namespace ACQC\Core\AI;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * OpenAI API Provider for ACQC
 */
class OpenAIProvider implements AIProviderInterface
{
    private array $config;
    private LoggerInterface $logger;
    private string $apiKey;
    private string $model;
    private string $baseUrl;

    public function __construct(array $config, ?LoggerInterface $logger = null)
    {
        $this->config = $config;
        $this->logger = $logger ?? new NullLogger();
        $this->apiKey = $config['api_key'] ?? '';
        $this->model = $config['model'] ?? 'gpt-4';
        $this->baseUrl = $config['base_url'] ?? 'https://api.openai.com/v1';
    }

    public function isAvailable(): bool
    {
        return !empty($this->apiKey) && function_exists('curl_init');
    }

    public function chat(string $prompt, array $options = []): string
    {
        if (!$this->isAvailable()) {
            throw new \Exception('OpenAI provider not available');
        }

        $data = [
            'model' => $this->model,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ],
            'max_tokens' => $options['max_tokens'] ?? 2000,
            'temperature' => $options['temperature'] ?? 0.7
        ];

        $response = $this->makeRequest('/chat/completions', $data);
        
        if (!$response || !isset($response['choices'][0]['message']['content'])) {
            throw new \Exception('Invalid OpenAI response');
        }

        return $response['choices'][0]['message']['content'];
    }

    public function getName(): string
    {
        return 'openai';
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getRateLimits(): array
    {
        return [
            'requests_per_minute' => 60,
            'tokens_per_minute' => 90000
        ];
    }

    public function estimateCost(string $prompt): float
    {
        $tokens = $this->estimateTokens($prompt);
        
        // GPT-4 pricing (approximate)
        $inputCostPer1k = 0.03;
        $outputCostPer1k = 0.06;
        
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
            'Authorization: Bearer ' . $this->apiKey
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            $this->logger->error('OpenAI API request failed', [
                'http_code' => $httpCode,
                'response' => $response
            ]);
            throw new \Exception('OpenAI API request failed: ' . $httpCode);
        }

        return json_decode($response, true);
    }

    private function estimateTokens(string $text): int
    {
        // Rough estimation: ~4 characters per token
        return (int) ceil(strlen($text) / 4);
    }
} 
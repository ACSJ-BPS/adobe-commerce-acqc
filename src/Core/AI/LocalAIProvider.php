<?php

declare(strict_types=1);

namespace ACQC\Core\AI;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Local AI Provider for self-hosted models (Ollama, etc.)
 */
class LocalAIProvider implements AIProviderInterface
{
    private array $config;
    private LoggerInterface $logger;
    private string $baseUrl;
    private string $model;

    public function __construct(array $config, ?LoggerInterface $logger = null)
    {
        $this->config = $config;
        $this->logger = $logger ?? new NullLogger();
        $this->baseUrl = $config['base_url'] ?? 'http://localhost:11434';
        $this->model = $config['model'] ?? 'codellama';
    }

    public function isAvailable(): bool
    {
        if (!function_exists('curl_init')) {
            return false;
        }

        // Check if local service is running
        try {
            $ch = curl_init($this->baseUrl . '/api/version');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_setopt($ch, CURLOPT_NOBODY, true);
            
            curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            return $httpCode === 200;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function chat(string $prompt, array $options = []): string
    {
        if (!$this->isAvailable()) {
            throw new \Exception('Local AI provider not available');
        }

        $data = [
            'model' => $this->model,
            'prompt' => $prompt,
            'stream' => false,
            'options' => [
                'temperature' => $options['temperature'] ?? 0.7,
                'num_ctx' => $options['max_tokens'] ?? 2048
            ]
        ];

        $response = $this->makeRequest('/api/generate', $data);
        
        if (!$response || !isset($response['response'])) {
            throw new \Exception('Invalid local AI response');
        }

        return $response['response'];
    }

    public function getName(): string
    {
        return 'local';
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getRateLimits(): array
    {
        return [
            'requests_per_minute' => 1000,  // No API limits for local
            'tokens_per_minute' => 100000
        ];
    }

    public function estimateCost(string $prompt): float
    {
        return 0.0; // Local models have no per-request cost
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
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120); // Local models may be slower

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            $this->logger->error('Local AI request failed', [
                'http_code' => $httpCode,
                'response' => $response
            ]);
            throw new \Exception('Local AI request failed: ' . $httpCode);
        }

        return json_decode($response, true);
    }
} 
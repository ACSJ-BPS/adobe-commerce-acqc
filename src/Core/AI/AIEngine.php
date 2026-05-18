<?php

declare(strict_types=1);

namespace ACQC\Core\AI;

use ACQC\Core\Rules\Violation;
use ACQC\Core\Config\ConfigManager;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Main AI Engine orchestrator for ACQC
 * 
 * Coordinates all AI-powered features including code understanding,
 * violation explanations, fix suggestions, and conversational interface.
 */
class AIEngine
{
    private ConfigManager $configManager;
    private LoggerInterface $logger;
    private PromptManager $promptManager;
    private CodeUnderstanding $codeUnderstanding;
    private ViolationExplainer $violationExplainer;
    private FixSuggester $fixSuggester;
    private ConversationalInterface $conversationalInterface;
    
    private array $providers = [];
    private string $defaultProvider = 'openai';
    private array $rateLimits = [];
    private array $metrics = [];
    private bool $enabled = true;
    private array $config = [];
    
    // Test mode support
    private static bool $testMode = false;
    private static array $mockResponses = [];

    public function __construct(
        ?ConfigManager $configManager = null,
        ?LoggerInterface $logger = null
    ) {
        $this->configManager = $configManager ?? new ConfigManager();
        $this->logger = $logger ?? new NullLogger();
        
        $this->initializeComponents();
        $this->loadConfiguration();
    }

    /**
     * Initialize AI components
     */
    private function initializeComponents(): void
    {
        $this->promptManager = new PromptManager($this->configManager, $this->logger);
        $this->codeUnderstanding = new CodeUnderstanding($this, $this->logger);
        $this->violationExplainer = new ViolationExplainer($this, $this->logger);
        $this->fixSuggester = new FixSuggester($this, $this->logger);
        $this->conversationalInterface = new ConversationalInterface($this, $this->logger);
    }

    /**
     * Load AI configuration
     */
    private function loadConfiguration(): void
    {
        $config = $this->configManager->get('ai', []);
        
        $this->enabled = $config['enabled'] ?? true;
        $this->defaultProvider = $config['default_provider'] ?? 'openai';
        
        // Initialize AI providers
        if ($this->enabled) {
            $this->initializeProviders($config['providers'] ?? []);
        }
        
        // Initialize rate limiting
        $this->rateLimits = $config['rate_limits'] ?? [
            'requests_per_minute' => 60,
            'tokens_per_minute' => 90000,
            'daily_cost_limit' => 50.00
        ];
        
        $this->logger->info('AI Engine initialized', [
            'enabled' => $this->enabled,
            'default_provider' => $this->defaultProvider,
            'providers' => array_keys($this->providers)
        ]);
    }

    /**
     * Configure AI engine with runtime options
     * 
     * @param array $config Configuration options
     * @return void
     */
    public function configure(array $config): void
    {
        if (isset($config['provider'])) {
            $this->defaultProvider = $config['provider'];
        }
        
        if (isset($config['enabled'])) {
            $this->enabled = $config['enabled'];
        }
        
        if (isset($config['temperature'])) {
            // Store for use with providers
            $this->config['temperature'] = $config['temperature'];
        }
        
        if (isset($config['max_tokens'])) {
            $this->config['max_tokens'] = $config['max_tokens'];
        }
        
        if (isset($config['model'])) {
            $this->config['model'] = $config['model'];
        }
        
        $this->logger->debug('AI Engine configured', $config);
    }

    /**
     * Initialize AI providers
     */
    private function initializeProviders(array $providersConfig): void
    {
        foreach ($providersConfig as $name => $config) {
            if (!($config['enabled'] ?? false)) {
                continue;
            }

            try {
                $provider = $this->createProvider($name, $config);
                if ($provider && $provider->isAvailable()) {
                    $this->providers[$name] = $provider;
                    $this->logger->info("AI provider '{$name}' initialized successfully");
                }
            } catch (\Exception $e) {
                $this->logger->error("Failed to initialize AI provider '{$name}'", [
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Create AI provider instance
     */
    private function createProvider(string $name, array $config): ?AIProviderInterface
    {
        switch ($name) {
            case 'openai':
                return new OpenAIProvider($config, $this->logger);
            case 'claude':
                return new ClaudeProvider($config, $this->logger);
            case 'local':
                return new LocalAIProvider($config, $this->logger);
            default:
                $this->logger->warning("Unknown AI provider: {$name}");
                return null;
        }
    }

    /**
     * Get AI provider
     */
    public function getProvider(?string $name = null): ?AIProviderInterface
    {
        $providerName = $name ?? $this->defaultProvider;
        
        if (!isset($this->providers[$providerName])) {
            $this->logger->warning("AI provider '{$providerName}' not available");
            return $this->getFallbackProvider();
        }
        
        return $this->providers[$providerName];
    }

    /**
     * Get fallback provider
     */
    private function getFallbackProvider(): ?AIProviderInterface
    {
        foreach ($this->providers as $provider) {
            if ($provider->isAvailable()) {
                return $provider;
            }
        }
        
        return null;
    }

    /**
     * Analyze code with AI understanding
     */
    public function analyzeCode(
        string $code, 
        string $filePath, 
        array $context = []
    ): AICodeAnalysis {
        if (!$this->enabled || !$this->canMakeRequest()) {
            return new AICodeAnalysis(false, 'AI analysis not available');
        }

        try {
            $this->recordRequest();
            return $this->codeUnderstanding->analyzeCode($code, $filePath, $context);
        } catch (\Exception $e) {
            $this->logger->error('AI code analysis failed', [
                'file' => $filePath,
                'error' => $e->getMessage()
            ]);
            return new AICodeAnalysis(false, 'Analysis failed: ' . $e->getMessage());
        }
    }

    /**
     * Explain violations using AI
     */
    public function explainViolations(
        array $violations, 
        string $code = '', 
        array $context = []
    ): array {
        if (!$this->enabled || !$this->canMakeRequest()) {
            return [];
        }

        try {
            $this->recordRequest();
            return $this->violationExplainer->explainViolations($violations, $code, $context);
        } catch (\Exception $e) {
            $this->logger->error('AI violation explanation failed', [
                'error' => $e->getMessage(),
                'violations_count' => count($violations)
            ]);
            return [];
        }
    }

    /**
     * Get fix suggestions for violations
     */
    public function suggestFixes(
        array $violations, 
        string $code, 
        array $context = []
    ): array {
        if (!$this->enabled || !$this->canMakeRequest()) {
            return [];
        }

        try {
            $this->recordRequest();
            return $this->fixSuggester->suggestFixes($violations, $code, $context);
        } catch (\Exception $e) {
            $this->logger->error('AI fix suggestion failed', [
                'error' => $e->getMessage(),
                'violations_count' => count($violations)
            ]);
            return [];
        }
    }

    /**
     * Process natural language query
     */
    public function processConversation(
        string $query, 
        array $context = []
    ): AIConversationResponse {
        if (!$this->enabled || !$this->canMakeRequest()) {
            return new AIConversationResponse(
                false, 
                'AI conversation not available',
                []
            );
        }

        try {
            $this->recordRequest();
            return $this->conversationalInterface->processQuery($query, $context);
        } catch (\Exception $e) {
            $this->logger->error('AI conversation processing failed', [
                'error' => $e->getMessage(),
                'query' => substr($query, 0, 100)
            ]);
            return new AIConversationResponse(
                false,
                'Conversation processing failed: ' . $e->getMessage(),
                []
            );
        }
    }

    /**
     * Predict code quality score
     */
    public function predictQuality(
        string $code, 
        string $filePath, 
        array $existingViolations = []
    ): AIQualityPrediction {
        if (!$this->enabled || !$this->canMakeRequest()) {
            return new AIQualityPrediction(false, 0, 'AI prediction not available');
        }

        try {
            $this->recordRequest();
            
            $prompt = $this->promptManager->getPrompt('quality_prediction', [
                'code' => $code,
                'file_path' => $filePath,
                'existing_violations' => $existingViolations
            ]);

            $provider = $this->getProvider();
            if (!$provider) {
                return new AIQualityPrediction(false, 0, 'No AI provider available');
            }

            $response = $provider->chat($prompt);
            return $this->parseQualityPrediction($response);
            
        } catch (\Exception $e) {
            $this->logger->error('AI quality prediction failed', [
                'file' => $filePath,
                'error' => $e->getMessage()
            ]);
            return new AIQualityPrediction(false, 0, 'Prediction failed: ' . $e->getMessage());
        }
    }

    /**
     * Generate learning path recommendations
     */
    public function recommendLearningPath(
        array $violations, 
        string $userLevel = 'intermediate'
    ): AILearningPath {
        if (!$this->enabled || !$this->canMakeRequest()) {
            return new AILearningPath(false, [], 'AI recommendations not available');
        }

        try {
            $this->recordRequest();
            
            $prompt = $this->promptManager->getPrompt('learning_path', [
                'violations' => $violations,
                'user_level' => $userLevel
            ]);

            $provider = $this->getProvider();
            if (!$provider) {
                return new AILearningPath(false, [], 'No AI provider available');
            }

            $response = $provider->chat($prompt);
            return $this->parseLearningPath($response);
            
        } catch (\Exception $e) {
            $this->logger->error('AI learning path generation failed', [
                'error' => $e->getMessage(),
                'violations_count' => count($violations)
            ]);
            return new AILearningPath(false, [], 'Learning path generation failed: ' . $e->getMessage());
        }
    }

    /**
     * Check if AI engine can make a request
     */
    private function canMakeRequest(): bool
    {
        if (!$this->enabled) {
            return false;
        }

        $this->purgeStaleMetrics();

        // Check rate limits
        $now = time();
        $minute = intval($now / 60);

        // Check requests per minute
        if (!isset($this->metrics[$minute])) {
            $this->metrics[$minute] = ['requests' => 0, 'tokens' => 0, 'cost' => 0.0];
        }

        if ($this->metrics[$minute]['requests'] >= $this->rateLimits['requests_per_minute']) {
            $this->logger->warning('AI rate limit exceeded: requests per minute');
            return false;
        }

        // Check daily cost limit
        $today = date('Y-m-d');
        $dailyCost = $this->getDailyCost($today);
        if ($dailyCost >= $this->rateLimits['daily_cost_limit']) {
            $this->logger->warning('AI rate limit exceeded: daily cost limit');
            return false;
        }

        return true;
    }

    /**
     * Record API request for rate limiting
     */
    private function recordRequest(): void
    {
        $now = time();
        $minute = intval($now / 60);

        if (!isset($this->metrics[$minute])) {
            $this->metrics[$minute] = ['requests' => 0, 'tokens' => 0, 'cost' => 0.0];
        }

        $this->metrics[$minute]['requests']++;
    }

    /**
     * Record token usage
     */
    public function recordTokenUsage(int $tokens, float $cost = 0.0): void
    {
        $now = time();
        $minute = intval($now / 60);

        if (!isset($this->metrics[$minute])) {
            $this->metrics[$minute] = ['requests' => 0, 'tokens' => 0, 'cost' => 0.0];
        }

        $this->metrics[$minute]['tokens'] += $tokens;
        $this->metrics[$minute]['cost'] += $cost;
    }

    /**
     * Get daily cost
     */
    private function getDailyCost(string $date): float
    {
        $cost = 0.0;
        $dayStart = strtotime($date . ' 00:00:00') / 60;
        $dayEnd = strtotime($date . ' 23:59:59') / 60;

        foreach ($this->metrics as $minute => $data) {
            if ($minute >= $dayStart && $minute <= $dayEnd) {
                $cost += $data['cost'];
            }
        }

        return $cost;
    }

    /**
     * Parse quality prediction response
     */
    private function parseQualityPrediction(string $response): AIQualityPrediction
    {
        try {
            $data = json_decode($response, true);
            
            if (!$data || !isset($data['score'])) {
                return new AIQualityPrediction(false, 0, 'Invalid prediction response');
            }

            return new AIQualityPrediction(
                true,
                (float) $data['score'],
                $data['explanation'] ?? '',
                $data['recommendations'] ?? [],
                $data['confidence'] ?? 0.0
            );
        } catch (\Exception $e) {
            return new AIQualityPrediction(false, 0, 'Failed to parse prediction: ' . $e->getMessage());
        }
    }

    /**
     * Parse learning path response
     */
    private function parseLearningPath(string $response): AILearningPath
    {
        try {
            $data = json_decode($response, true);
            
            if (!$data || !isset($data['path'])) {
                return new AILearningPath(false, [], 'Invalid learning path response');
            }

            return new AILearningPath(
                true,
                $data['path'],
                $data['explanation'] ?? '',
                $data['estimated_time'] ?? '',
                $data['difficulty'] ?? 'intermediate'
            );
        } catch (\Exception $e) {
            return new AILearningPath(false, [], 'Failed to parse learning path: ' . $e->getMessage());
        }
    }

    /**
     * Get AI metrics
     */
    public function getMetrics(): array
    {
        return [
            'enabled' => $this->enabled,
            'providers' => array_keys($this->providers),
            'current_metrics' => $this->metrics,
            'rate_limits' => $this->rateLimits,
            'daily_cost' => $this->getDailyCost(date('Y-m-d'))
        ];
    }

    /**
     * Check if AI engine is enabled and available
     */
    public function isAvailable(): bool
    {
        return $this->enabled && !empty($this->providers);
    }

    /**
     * Send a raw prompt to the active provider and return a normalized response array.
     * In test mode, returns the matching mock response instead of calling the provider.
     */
    public function generateResponse(string $prompt, array $options = []): array
    {
        if (self::$testMode) {
            $mock = self::$mockResponses[$prompt] ?? self::$mockResponses['default'] ?? null;
            if ($mock !== null) {
                return ['content' => $mock];
            }
            return ['error' => 'No mock response configured for prompt'];
        }

        $provider = $this->getProvider($options['provider'] ?? null);
        if (!$provider) {
            return ['error' => 'No AI provider available for: ' . ($options['provider'] ?? $this->defaultProvider)];
        }

        try {
            $this->recordRequest();
            $raw = $provider->chat($prompt);
            return ['content' => $raw];
        } catch (\Exception $e) {
            $this->logger->error('generateResponse failed', ['error' => $e->getMessage()]);
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get prompt manager
     */
    public function getPromptManager(): PromptManager
    {
        return $this->promptManager;
    }

    // --- Test-mode helpers (static for global toggle, instance proxies for test isolation) ---

    public static function setTestMode(bool $enabled): void
    {
        self::$testMode = $enabled;
    }

    public static function setMockResponses(array $responses): void
    {
        self::$mockResponses = $responses;
    }

    public static function clearMockResponses(): void
    {
        self::$mockResponses = [];
        self::$testMode = false;
    }

    public static function isTestMode(): bool
    {
        return self::$testMode;
    }

    public static function getMockResponse(string $key): ?string
    {
        return self::$mockResponses[$key] ?? self::$mockResponses['default'] ?? null;
    }

    /**
     * Purge metrics older than the current day to prevent unbounded memory growth.
     * Called automatically during rate-limit checks.
     */
    private function purgeStaleMetrics(): void
    {
        $cutoff = intval(strtotime('today') / 60);
        foreach (array_keys($this->metrics) as $minute) {
            if ($minute < $cutoff) {
                unset($this->metrics[$minute]);
            }
        }
    }
} 
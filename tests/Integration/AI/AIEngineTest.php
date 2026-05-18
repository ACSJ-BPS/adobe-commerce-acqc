<?php

declare(strict_types=1);

namespace ACQC\Tests\Integration\AI;

use ACQC\Core\AI\AIEngine;
use ACQC\Core\AI\OpenAIProvider;
use ACQC\Core\AI\ClaudeProvider;
use ACQC\Core\AI\LocalAIProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ACQC\Core\AI\AIEngine
 * @group integration
 * @group ai
 */
class AIEngineTest extends TestCase
{
    private AIEngine $aiEngine;

    protected function setUp(): void
    {
        $this->aiEngine = new AIEngine();
        $this->aiEngine->setTestMode(true);
    }

    /**
     * @test
     * @group fast
     */
    public function testMockResponseGeneration(): void
    {
        $this->aiEngine->setMockResponses([
            'default' => 'This is a test response'
        ]);

        $response = $this->aiEngine->generateResponse('Test prompt');
        
        $this->assertIsArray($response);
        $this->assertArrayHasKey('content', $response);
        $this->assertEquals('This is a test response', $response['content']);
    }

    /**
     * @test
     * @group fast
     */
    public function testProviderSwitching(): void
    {
        $providers = ['openai', 'claude', 'local'];

        foreach ($providers as $provider) {
            $this->aiEngine->configure(['provider' => $provider]);
            $response = $this->aiEngine->generateResponse('Test');
            
            $this->assertIsArray($response);
            $this->assertArrayHasKey('content', $response);
        }
    }

    /**
     * @test
     * @group fast
     */
    public function testErrorHandling(): void
    {
        $this->aiEngine->setTestMode(false);
        $this->aiEngine->configure(['provider' => 'invalid']);

        $response = $this->aiEngine->generateResponse('Test');
        
        $this->assertIsArray($response);
        $this->assertArrayHasKey('error', $response);
    }
} 
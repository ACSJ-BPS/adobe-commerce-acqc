<?php

declare(strict_types=1);

namespace ACQC\Tests\Unit\Core\Upgrade;

use ACQC\Core\Upgrade\NullRecommendationClient;
use ACQC\Core\Upgrade\UpgradeRecommendation;
use ACQC\Core\Upgrade\UpgradeRecommendationClientInterface;
use ACQC\Core\Upgrade\UpgradeRecommendationEngine;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * @covers \ACQC\Core\Upgrade\UpgradeRecommendationEngine
 * @group unit
 * @group fast
 */
class UpgradeRecommendationEngineTest extends TestCase
{
    private ArrayAdapter $cache;

    protected function setUp(): void
    {
        $this->cache = new ArrayAdapter();

        // Point NullRecommendationClient at the real template directory
        NullRecommendationClient::setTemplateDir(
            dirname(__DIR__, 4) . '/config/upgrade/recommendation-templates'
        );
    }

    private function makeFixedResponse(): array
    {
        return [
            'root_cause'       => 'Test root cause',
            'code_example'     => "// before\n// after",
            'step_by_step'     => "1. Do this\n2. Do that",
            'doc_links'        => ['https://example.com'],
            'confidence_score' => 8,
        ];
    }

    private function makeFinding(string $type = 'class_removed', string $severity = 'error', string $file = 'app/code/Vendor/Module/Foo.php'): array
    {
        return [
            'type'     => $type,
            'severity' => $severity,
            'file'     => $file,
            'message'  => 'Test finding',
        ];
    }

    /** @test */
    public function testReturnsCachedResultOnSecondCall(): void
    {
        /** @var UpgradeRecommendationClientInterface&MockObject $client */
        $client = $this->createMock(UpgradeRecommendationClientInterface::class);
        $client->method('isConfigured')->willReturn(true);
        $client->expects($this->once())          // only called once
               ->method('recommend')
               ->willReturn($this->makeFixedResponse());

        $engine  = new UpgradeRecommendationEngine($client, $this->cache);
        $finding = $this->makeFinding();

        $first  = $engine->getRecommendation($finding);
        $second = $engine->getRecommendation($finding);

        $this->assertNotNull($first);
        $this->assertSame($first->rootCause, $second?->rootCause);
    }

    /** @test */
    public function testBatchLimitedToMaxFindings(): void
    {
        /** @var UpgradeRecommendationClientInterface&MockObject $client */
        $client = $this->createMock(UpgradeRecommendationClientInterface::class);
        $client->method('isConfigured')->willReturn(true);
        $client->expects($this->atMost(20))
               ->method('recommend')
               ->willReturn($this->makeFixedResponse());

        $engine   = new UpgradeRecommendationEngine($client, $this->cache);
        $findings = [];
        for ($i = 0; $i < 30; $i++) {
            $findings[] = $this->makeFinding('class_removed', 'error', "app/code/V/M/File{$i}.php");
        }

        $results = $engine->batchGetRecommendations($findings, 20);

        $this->assertLessThanOrEqual(20, count($results));
    }

    /** @test */
    public function testSkipsLowSeverityFindings(): void
    {
        /** @var UpgradeRecommendationClientInterface&MockObject $client */
        $client = $this->createMock(UpgradeRecommendationClientInterface::class);
        $client->expects($this->never())->method('recommend');

        $engine   = new UpgradeRecommendationEngine($client, $this->cache);
        $findings = [
            $this->makeFinding('class_removed', 'info'),
            $this->makeFinding('class_removed', 'notice'),
            $this->makeFinding('class_removed', 'warning'),
            $this->makeFinding('class_removed', 'info'),
            $this->makeFinding('class_removed', 'notice'),
        ];

        $results = $engine->batchGetRecommendations($findings);

        $this->assertEmpty($results);
    }

    /** @test */
    public function testSkipsIneligibleFindingTypes(): void
    {
        /** @var UpgradeRecommendationClientInterface&MockObject $client */
        $client = $this->createMock(UpgradeRecommendationClientInterface::class);
        $client->expects($this->never())->method('recommend');

        $engine  = new UpgradeRecommendationEngine($client, $this->cache);
        $finding = $this->makeFinding('unknown_custom_check', 'error');

        $result = $engine->getRecommendation($finding);

        $this->assertNull($result);
    }

    /** @test */
    public function testNullClientFallsBackToStaticTemplate(): void
    {
        $client  = new NullRecommendationClient();
        $engine  = new UpgradeRecommendationEngine($client, $this->cache);
        $finding = $this->makeFinding('class_removed', 'error');

        $result = $engine->getRecommendation($finding);

        $this->assertNotNull($result);
        $this->assertInstanceOf(UpgradeRecommendation::class, $result);
        $this->assertFalse($result->isFromFluffyJaws);
        $this->assertSame(5, $result->confidenceScore);
        $this->assertNotEmpty($result->rootCause);
    }

    /** @test */
    public function testFluffyJawsClientReturnsNullOnNetworkFailure(): void
    {
        /** @var UpgradeRecommendationClientInterface&MockObject $client */
        $client = $this->createMock(UpgradeRecommendationClientInterface::class);
        $client->method('isConfigured')->willReturn(true);
        $client->method('recommend')->willReturn(null);

        $engine  = new UpgradeRecommendationEngine($client, $this->cache);
        $finding = $this->makeFinding('class_removed', 'error');

        $result = $engine->getRecommendation($finding);

        $this->assertNull($result);
    }
}

<?php

declare(strict_types=1);

namespace ACQC\Tests\Performance;

use ACQC\Utils\PerformanceProfiler;
use ACQC\Utils\MemoryManager;
use ACQC\Utils\CacheManager;
use ACQC\Utils\ParallelProcessor;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ACQC\Utils\PerformanceProfiler
 * @covers \ACQC\Utils\MemoryManager
 * @covers \ACQC\Utils\CacheManager
 * @covers \ACQC\Utils\ParallelProcessor
 * @group performance
 * @group optimization
 */
class PerformanceOptimizationTest extends TestCase
{
    private PerformanceProfiler $profiler;
    private MemoryManager $memoryManager;
    private CacheManager $cacheManager;
    private ParallelProcessor $parallelProcessor;

    protected function setUp(): void
    {
        $this->profiler = new PerformanceProfiler();
        $this->memoryManager = new MemoryManager();
        $this->cacheManager = new CacheManager();
        $this->parallelProcessor = new ParallelProcessor(4);
    }

    /**
     * @test
     */
    public function testPerformanceProfilerAccuracy(): void
    {
        $this->profiler->startTimer('test_operation');
        
        // Simulate work
        usleep(100000); // 100ms
        
        $duration = $this->profiler->stopTimer('test_operation');
        
        // Should be approximately 100ms (allow 20ms variance)
        $this->assertGreaterThan(0.08, $duration);
        $this->assertLessThan(0.15, $duration);
    }

    /**
     * @test
     */
    public function testMemoryPoolEfficiency(): void
    {
        $pool = $this->memoryManager->createPool('test_pool', 1024 * 1024); // 1MB limit
        
        $memoryBefore = memory_get_usage(true);
        
        // Allocate data in pool
        for ($i = 0; $i < 100; $i++) {
            $data = str_repeat('x', 1000); // 1KB each
            $pool->allocate("item_$i", $data);
        }
        
        $memoryAfter = memory_get_usage(true);
        $memoryIncrease = $memoryAfter - $memoryBefore;
        
        // Memory increase should be reasonable
        $this->assertLessThanOrEqual(2 * 1024 * 1024, $memoryIncrease); // At most 2MB
        
        // Clean up pool
        $pool->forceCleanup();
    }

    /**
     * @test
     */
    public function testCachePerformance(): void
    {
        $testData = ['complex' => 'data', 'with' => ['nested' => 'structures']];
        
        // Time cache set operation
        $startTime = microtime(true);
        $this->cacheManager->set('test_key', $testData);
        $setDuration = microtime(true) - $startTime;
        
        // Time cache get operation
        $startTime = microtime(true);
        $retrievedData = $this->cacheManager->get('test_key');
        $getDuration = microtime(true) - $startTime;
        
        $this->assertEquals($testData, $retrievedData);
        
        // Cache operations should be fast
        $this->assertLessThan(0.01, $setDuration); // Less than 10ms
        $this->assertLessThan(0.01, $getDuration); // Less than 10ms
    }

    /**
     * @test
     */
    public function testParallelProcessingEfficiency(): void
    {
        $tasks = range(1, 20);
        
        // Sequential processing
        $startTime = microtime(true);
        $sequentialResults = [];
        foreach ($tasks as $task) {
            $sequentialResults[] = $this->simulateWork($task);
        }
        $sequentialDuration = microtime(true) - $startTime;
        
        // Parallel processing
        $startTime = microtime(true);
        $parallelResults = $this->parallelProcessor->processBatch(
            $tasks,
            [$this, 'simulateWork']
        );
        $parallelDuration = microtime(true) - $startTime;
        
        // Parallel should be faster (allowing for overhead and CI variance)
        $this->assertLessThan($sequentialDuration * 1.5, $parallelDuration);
        $this->assertCount(20, $parallelResults['completed']);
    }

    /**
     * @test
     */
    public function testMemoryLeakDetection(): void
    {
        $initialMemory = memory_get_usage(true);
        
        // Create and destroy many objects
        for ($i = 0; $i < 1000; $i++) {
            $data = str_repeat('test', 1000);
            unset($data);
            
            if ($i % 100 === 0) {
                $this->memoryManager->forceGarbageCollection();
            }
        }
        
        $finalMemory = memory_get_usage(true);
        $memoryIncrease = $finalMemory - $initialMemory;
        
        // Memory increase should be minimal
        $this->assertLessThan(1024 * 1024, $memoryIncrease); // Less than 1MB
    }

    /**
     * @test
     */
    public function testBatchProcessingOptimization(): void
    {
        $largeDataset = range(1, 10000);
        
        $startTime = microtime(true);
        
        $processedCount = 0;
        foreach ($this->memoryManager->processBatch($largeDataset, function($batch) use (&$processedCount) {
            $processedCount += count($batch);
            return count($batch);
        }, 500) as $result) {
            // Process results
        }
        
        $duration = microtime(true) - $startTime;
        
        $this->assertEquals(10000, $processedCount);
        $this->assertLessThan(5.0, $duration); // Should complete within 5 seconds
    }

    public function simulateWork(int $value): int
    {
        usleep(10000); // 10ms work simulation
        return $value * 2;
    }
} 
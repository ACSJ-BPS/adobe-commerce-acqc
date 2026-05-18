<?php

declare(strict_types=1);

namespace ACQC\Tests\Extensions;

use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Performance monitoring extension for PHPUnit tests
 */
class PerformanceMonitor implements Extension
{
    private static array $testTimes = [];
    private static float $startTime;

    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        self::$startTime = microtime(true);
        
        $facade->registerSubscriber(new PerformanceTestSubscriber());
    }

    public static function recordTestTime(string $testName, float $duration): void
    {
        self::$testTimes[$testName] = $duration;
        
        // Log slow tests
        if ($duration > 1.0) {
            error_log("SLOW TEST: $testName took {$duration}s");
        }
    }

    public static function generateReport(): void
    {
        $totalTime = microtime(true) - self::$startTime;
        $slowTests = array_filter(self::$testTimes, fn($time) => $time > 0.5);
        
        echo "\n=== Performance Report ===\n";
        echo "Total execution time: " . number_format($totalTime, 3) . "s\n";
        echo "Slow tests (>0.5s): " . count($slowTests) . "\n";
        
        if (!empty($slowTests)) {
            arsort($slowTests);
            echo "\nSlowest tests:\n";
            foreach (array_slice($slowTests, 0, 5, true) as $test => $time) {
                echo "  - $test: " . number_format($time, 3) . "s\n";
            }
        }
        echo "==========================\n\n";
    }
}

class PerformanceTestSubscriber implements \PHPUnit\Event\Test\FinishedSubscriber
{
    public function notify(\PHPUnit\Event\Test\Finished $event): void
    {
        $test = $event->test();
        // PHPUnit 10.5+ uses time() instead of duration()
        $telemetry = $event->telemetryInfo();
        $duration = method_exists($telemetry, 'time') ? $telemetry->time()->seconds() : 0.0;
        
        PerformanceMonitor::recordTestTime($test->name(), $duration);
    }
} 
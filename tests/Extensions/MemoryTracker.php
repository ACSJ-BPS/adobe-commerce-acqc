<?php

declare(strict_types=1);

namespace ACQC\Tests\Extensions;

use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Memory usage tracking extension for PHPUnit tests.
 * Logs tests that consume excessive memory.
 */
class MemoryTracker implements Extension
{
    private static array $memorySnapshots = [];

    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscriber(new MemoryTestSubscriber());
    }

    public static function recordMemory(string $testName, int $before, int $after): void
    {
        $delta = $after - $before;
        self::$memorySnapshots[$testName] = $delta;

        // Warn on tests that allocate more than 10 MB
        if ($delta > 10 * 1024 * 1024) {
            error_log(sprintf('HIGH MEMORY: %s used %s', $testName, self::formatBytes($delta)));
        }
    }

    public static function getSnapshots(): array
    {
        return self::$memorySnapshots;
    }

    private static function formatBytes(int $bytes): string
    {
        return number_format($bytes / 1024 / 1024, 2) . ' MB';
    }
}

class MemoryTestSubscriber implements \PHPUnit\Event\Test\FinishedSubscriber
{
    private static int $memoryBefore = 0;

    public function notify(\PHPUnit\Event\Test\Finished $event): void
    {
        $after = memory_get_usage(true);
        MemoryTracker::recordMemory($event->test()->name(), self::$memoryBefore, $after);
        self::$memoryBefore = $after;
    }
}

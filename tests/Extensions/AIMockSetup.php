<?php

declare(strict_types=1);

namespace ACQC\Tests\Extensions;

use ACQC\Core\AI\AIEngine;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Enables AI test mode globally and installs standard mock responses
 * before the test suite runs. Ensures no real AI provider calls are made.
 */
class AIMockSetup implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        AIEngine::setTestMode(true);
        AIEngine::setMockResponses([
            'default' => json_encode(['success' => true, 'content' => 'Mock AI response']),
        ]);

        $facade->registerSubscriber(new AIMockTeardownSubscriber());
    }
}

/**
 * Resets AI mock state after each test to prevent cross-test pollution.
 */
class AIMockTeardownSubscriber implements \PHPUnit\Event\Test\FinishedSubscriber
{
    public function notify(\PHPUnit\Event\Test\Finished $event): void
    {
        // Only reset mock responses between tests; keep test mode on for the suite
        AIEngine::setMockResponses([
            'default' => json_encode(['success' => true, 'content' => 'Mock AI response']),
        ]);
    }
}

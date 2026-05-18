<?php

declare(strict_types=1);

namespace ACQC\Tests\Core;

use ACQC\Core\Application;
use PHPUnit\Framework\TestCase;

class ApplicationTest extends TestCase
{
    private Application $application;

    protected function setUp(): void
    {
        $this->application = new Application();
    }

    public function testApplicationCanBeInstantiated(): void
    {
        $this->assertInstanceOf(Application::class, $this->application);
    }

    public function testApplicationHasCorrectName(): void
    {
        $this->assertEquals('M2 Quality Audit Tool', $this->application->getName());
    }

    public function testApplicationHasVersion(): void
    {
        $this->assertNotEmpty($this->application->getVersion());
    }

    public function testApplicationHasCommands(): void
    {
        $commands = $this->application->all();
        
        // Should have at least the default commands plus our custom ones
        $this->assertGreaterThan(3, count($commands));
        
        // Check for our specific commands
        $this->assertTrue($this->application->has('audit'));
        $this->assertTrue($this->application->has('analyze'));
        $this->assertTrue($this->application->has('report'));
    }
} 
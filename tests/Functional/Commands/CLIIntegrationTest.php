<?php

declare(strict_types=1);

namespace ACQC\Tests\Functional\Commands;

use ACQC\Core\Application;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * Functional tests that run commands through the full CLI stack.
 *
 * @covers \ACQC\Core\Application
 * @group functional
 * @group commands
 */
class CLIIntegrationTest extends TestCase
{
    private Application $app;
    private ApplicationTester $tester;

    protected function setUp(): void
    {
        $this->app = new Application();
        $this->app->setAutoExit(false);
        $this->tester = new ApplicationTester($this->app);
    }

    /**
     * @test
     * @group fast
     */
    public function testApplicationBootsSuccessfully(): void
    {
        $this->tester->run(['command' => 'list']);
        $this->assertEquals(0, $this->tester->getStatusCode());
    }

    /**
     * @test
     * @group fast
     */
    public function testApplicationDisplaysCorrectName(): void
    {
        $this->tester->run(['--version' => true]);
        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('Adobe Commerce Quality Control', $output);
    }

    /**
     * @test
     * @group fast
     */
    public function testAllCommandsAreRegistered(): void
    {
        $commands = $this->app->all();
        $expectedCommands = [
            'analyze', 'audit', 'report', 'config', 'baseline',
            'detect-version', 'upgrade-readiness', 'auto-fix', 'backup',
            'scan', 'validate', 'init', 'doctor', 'checklist',
            'tech-spec', 'project-quality', 'history', 'aggregate',
            'watch', 'policy:check', 'dashboard', 'export',
            'rules:list', 'rules:validate', 'rules:suppress',
            'rules:unsuppress', 'rules:sync', 'rules:new',
        ];

        foreach ($expectedCommands as $name) {
            $this->assertArrayHasKey($name, $commands, "Command '{$name}' is not registered");
        }
    }

    /**
     * @test
     * @group fast
     */
    public function testHelpCommandWorks(): void
    {
        $this->tester->run(['command' => 'help', 'command_name' => 'analyze']);
        $this->assertEquals(0, $this->tester->getStatusCode());
        $this->assertStringContainsString('analyze', $this->tester->getDisplay());
    }

    /**
     * @test
     * @group fast
     */
    public function testInvalidCommandReturnsError(): void
    {
        $this->tester->run(['command' => 'non-existent-command']);
        $this->assertNotEquals(0, $this->tester->getStatusCode());
    }
}

<?php

declare(strict_types=1);

namespace ACQC\Tests\Unit\Core\AutoFix;

use ACQC\Core\AutoFix\FixCommand;
use PHPUnit\Framework\TestCase;

class FixCommandTest extends TestCase
{
    public function testFixCommandCreation(): void
    {
        $command = new FixCommand(
            command: 'bin/magento cache:flush',
            description: 'Flush Magento cache'
        );
        
        $this->assertEquals('bin/magento cache:flush', $command->command);
        $this->assertEquals('Flush Magento cache', $command->description);
        $this->assertEquals(5, $command->priority); // Default priority
        $this->assertFalse($command->requiresConfirmation);
    }
    
    public function testFixCommandWithSafetyChecks(): void
    {
        $command = new FixCommand(
            command: 'bin/magento setup:upgrade',
            description: 'Run setup upgrade',
            safetyChecks: [
                [
                    'condition' => '[ -f "bin/magento" ]',
                    'message' => 'Magento CLI not found'
                ]
            ]
        );
        
        $this->assertNotEmpty($command->safetyChecks);
        $this->assertCount(1, $command->safetyChecks);
    }
    
    public function testFixCommandPriorityValidation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Priority must be between 1 and 10');
        
        new FixCommand(
            command: 'test',
            description: 'Test',
            priority: 11  // Invalid priority
        );
    }
    
    public function testToShellScript(): void
    {
        $command = new FixCommand(
            command: 'bin/magento cache:flush',
            description: 'Flush cache',
            priority: 8
        );
        
        $script = $command->toShellScript();
        
        $this->assertStringContainsString('# Flush cache', $script);
        $this->assertStringContainsString('# Priority: 8/10', $script);
        $this->assertStringContainsString('bin/magento cache:flush', $script);
        $this->assertStringContainsString('✅ Fix applied successfully', $script);
    }
    
    public function testToShellScriptWithSafetyChecks(): void
    {
        $command = new FixCommand(
            command: 'bin/magento setup:upgrade',
            description: 'Run setup upgrade',
            safetyChecks: [
                [
                    'condition' => '[ -f "bin/magento" ]',
                    'message' => 'Magento CLI not found'
                ]
            ]
        );
        
        $script = $command->toShellScript();
        
        $this->assertStringContainsString('# Safety checks', $script);
        $this->assertStringContainsString('[ -f "bin/magento" ]', $script);
        $this->assertStringContainsString('❌ Safety check failed: Magento CLI not found', $script);
    }
    
    public function testToShellScriptWithRollback(): void
    {
        $command = new FixCommand(
            command: 'bin/magento config:set test/config/value 1',
            description: 'Set config',
            rollback: 'bin/magento config:set test/config/value 0'
        );
        
        $script = $command->toShellScript();
        
        $this->assertStringContainsString('rollback', $script);
        $this->assertStringContainsString('bin/magento config:set test/config/value 0', $script);
    }
    
    public function testToShellScriptWithConfirmation(): void
    {
        $command = new FixCommand(
            command: 'bin/magento deploy:mode:set production',
            description: 'Switch to production mode',
            requiresConfirmation: true
        );
        
        $script = $command->toShellScript();
        
        $this->assertStringContainsString('read -p', $script);
        $this->assertStringContainsString('🤔 Execute:', $script);
        $this->assertStringContainsString('⏭️  Skipped', $script);
    }
    
    public function testGetSummary(): void
    {
        $command = new FixCommand(
            command: 'bin/magento cache:flush',
            description: 'Flush cache',
            priority: 9
        );
        
        $summary = $command->getSummary();
        
        $this->assertStringContainsString('[Priority 9]', $summary);
        $this->assertStringContainsString('Flush cache', $summary);
        $this->assertStringContainsString('bin/magento cache:flush', $summary);
    }
    
    public function testGetSummaryTruncatesLongCommands(): void
    {
        $longCommand = str_repeat('a', 100);
        $command = new FixCommand(
            command: $longCommand,
            description: 'Long command',
            priority: 5
        );
        
        $summary = $command->getSummary();
        
        $this->assertStringContainsString('...', $summary);
        $this->assertLessThan(strlen($longCommand) + 50, strlen($summary));
    }
}

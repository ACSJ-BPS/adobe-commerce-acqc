<?php

declare(strict_types=1);

namespace ACQC\Tests\Unit\Core\Config;

use ACQC\Core\Config\ConfigManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * @covers \ACQC\Core\Config\ConfigManager
 * @group unit
 * @group config
 */
class ConfigManagerTest extends TestCase
{
    private ConfigManager $configManager;
    private string $tempConfigFile;

    protected function setUp(): void
    {
        $this->configManager = new ConfigManager(null, false); // useCache=false avoids disk I/O in tests
        $this->tempConfigFile = createTempFile('', '.yaml');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempConfigFile)) {
            unlink($this->tempConfigFile);
        }
    }

    /**
     * @test
     * @group fast
     */
    public function testLoadDefaultConfiguration(): void
    {
        $config = $this->configManager->loadConfiguration();

        $this->assertIsArray($config);
        $this->assertArrayHasKey('analyzers', $config);
        $this->assertArrayHasKey('rules', $config);
    }

    /**
     * @test
     * @group fast
     */
    public function testLoadCustomConfiguration(): void
    {
        $customConfig = [
            'analyzers' => ['phpcs', 'phpmd'],
            'rules' => ['severity' => 'error']
        ];

        file_put_contents($this->tempConfigFile, Yaml::dump($customConfig));

        $config = $this->configManager->loadConfiguration($this->tempConfigFile);

        // Custom analyzer names should be present in the merged config
        $this->assertContains('phpcs', $config['analyzers']);
        $this->assertContains('phpmd', $config['analyzers']);
        $this->assertArrayHasKey('rules', $config);
    }

    /**
     * @test
     * @group fast
     */
    public function testValidateConfiguration(): void
    {
        $validConfig = [
            'analyzers' => ['phpcs'],
            'rules' => ['severity' => 'warning']
        ];

        $isValid = $this->configManager->validateConfiguration($validConfig);

        $this->assertTrue($isValid);
    }

    /**
     * @test
     * @group fast
     */
    public function testInvalidConfiguration(): void
    {
        $invalidConfig = [
            'invalid_key' => 'invalid_value'
        ];

        $isValid = $this->configManager->validateConfiguration($invalidConfig);

        $this->assertFalse($isValid);
    }

    /**
     * @test
     * @group fast
     */
    public function testGetConfigurationValue(): void
    {
        $config = [
            'analyzers' => ['phpcs', 'phpmd'],
            'nested' => ['key' => 'value']
        ];

        $this->configManager->setConfiguration($config);

        $this->assertEquals(['phpcs', 'phpmd'], $this->configManager->get('analyzers'));
        $this->assertEquals('value', $this->configManager->get('nested.key'));
        $this->assertNull($this->configManager->get('non.existent'));
    }

    /**
     * @test
     * @group fast
     */
    public function testSetConfigurationValue(): void
    {
        $this->configManager->set('new.key', 'new.value');

        $this->assertEquals('new.value', $this->configManager->get('new.key'));
    }
} 
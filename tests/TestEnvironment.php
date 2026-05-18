<?php

declare(strict_types=1);

namespace ACQC\Tests;

use ACQC\Core\Config\ConfigManager;
use ACQC\Core\AI\AIEngine;

/**
 * Test Environment Setup
 * 
 * Handles initialization and cleanup of the test environment.
 */
class TestEnvironment
{
    private static bool $initialized = false;
    private static array $originalConfig = [];

    public static function initialize(): void
    {
        if (self::$initialized) {
            return;
        }

        // Set up test configuration
        self::setupTestConfig();
        
        // Initialize AI mocking
        self::setupAIMocking();
        
        // Set up file system for tests
        self::setupFileSystem();
        
        // Configure error handling
        self::setupErrorHandling();

        self::$initialized = true;
    }

    public static function cleanup(): void
    {
        // Restore original configuration
        self::restoreConfig();
        
        // Clean up test files
        self::cleanupFileSystem();
        
        // Reset AI mocking
        self::resetAIMocking();
    }

    private static function setupTestConfig(): void
    {
        // Store original environment variables
        self::$originalConfig = [
            'APP_ENV' => getenv('APP_ENV'),
            'AI_PROVIDER' => getenv('AI_PROVIDER'),
            'CACHE_DRIVER' => getenv('CACHE_DRIVER')
        ];

        // Set test environment variables
        putenv('APP_ENV=testing');
        putenv('AI_PROVIDER=mock');
        putenv('CACHE_DRIVER=array');
        putenv('AI_MOCK_RESPONSES=true');
    }

    private static function setupAIMocking(): void
    {
        // Configure AI engine for testing
        if (class_exists(AIEngine::class)) {
            AIEngine::setTestMode(true);
            AIEngine::setMockResponses([
                'default' => 'This is a test response from the AI system.',
                'code_explanation' => 'This code demonstrates a common PHP pattern.',
                'suggestion' => 'Consider refactoring this method to improve readability.',
                'prediction' => 'Quality score is expected to improve by 15% over the next month.'
            ]);
        }
    }

    private static function setupFileSystem(): void
    {
        $testDirs = [
            ACQC_TEST_OUTPUT,
            ACQC_TEST_OUTPUT . '/reports',
            ACQC_TEST_OUTPUT . '/cache',
            ACQC_TEST_OUTPUT . '/logs'
        ];

        foreach ($testDirs as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
    }

    private static function setupErrorHandling(): void
    {
        // Custom error handler for tests
        set_error_handler(function($severity, $message, $file, $line) {
            if (error_reporting() === 0) {
                return false;
            }
            
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
    }

    private static function restoreConfig(): void
    {
        foreach (self::$originalConfig as $key => $value) {
            if ($value !== false) {
                putenv("$key=$value");
            }
        }
    }

    private static function cleanupFileSystem(): void
    {
        $testDirs = [
            ACQC_TEST_OUTPUT . '/reports',
            ACQC_TEST_OUTPUT . '/cache', 
            ACQC_TEST_OUTPUT . '/logs'
        ];

        foreach ($testDirs as $dir) {
            if (is_dir($dir)) {
                self::removeDirectory($dir);
            }
        }
    }

    private static function resetAIMocking(): void
    {
        if (class_exists(AIEngine::class)) {
            AIEngine::setTestMode(false);
            AIEngine::clearMockResponses();
        }
    }

    private static function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                self::removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
} 
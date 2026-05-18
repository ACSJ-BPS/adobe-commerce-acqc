<?php

declare(strict_types=1);

/**
 * ACQC Test Suite Bootstrap
 *
 * Sets up the testing environment, autoloading, and test utilities.
 */

// Composer autoloader
require_once dirname(__DIR__) . '/vendor/autoload.php';

// Test environment setup
define('ACQC_TEST_MODE', true);
define('ACQC_TEST_ROOT', __DIR__);
define('ACQC_PROJECT_ROOT', dirname(__DIR__));
define('ACQC_FIXTURES_PATH', __DIR__ . '/Fixtures');
define('ACQC_TEST_OUTPUT', dirname(__DIR__) . '/build/test-output');

// Create test output directory
if (!is_dir(ACQC_TEST_OUTPUT)) {
    mkdir(ACQC_TEST_OUTPUT, 0755, true);
}

// Set up error reporting for tests
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('memory_limit', '512M');

// Set timezone
date_default_timezone_set('UTC');

// Test utilities autoloader
spl_autoload_register(function ($class) {
    if (strpos($class, 'ACQC\\Tests\\') === 0) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, 15)) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
});

// Initialize test environment
ACQC\Tests\TestEnvironment::initialize();

// Clean up function for tests
register_shutdown_function(function() {
    ACQC\Tests\TestEnvironment::cleanup();
});

// Test helper functions
function getFixtureContent(string $filename): string
{
    $path = ACQC_FIXTURES_PATH . '/' . $filename;
    if (!file_exists($path)) {
        throw new InvalidArgumentException("Fixture file not found: $filename");
    }
    return file_get_contents($path);
}

function getFixturePath(string $filename): string
{
    return ACQC_FIXTURES_PATH . '/' . $filename;
}

function createTempFile(string $content = '', string $suffix = '.php'): string
{
    $tempFile = tempnam(sys_get_temp_dir(), 'acqc_test_') . $suffix;
    file_put_contents($tempFile, $content);
    return $tempFile;
}

function cleanupTempFiles(): void
{
    $tempFiles = glob(sys_get_temp_dir() . '/acqc_test_*');
    foreach ($tempFiles as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
}

// Global test cleanup
function globalTestCleanup(): void
{
    cleanupTempFiles();
    
    // Clean test output directory
    if (is_dir(ACQC_TEST_OUTPUT)) {
        $files = glob(ACQC_TEST_OUTPUT . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
}

// Register cleanup
 
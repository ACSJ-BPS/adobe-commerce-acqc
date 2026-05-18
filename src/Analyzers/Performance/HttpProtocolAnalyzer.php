<?php
declare(strict_types=1);

namespace ACQC\Analyzers\Performance;

use ACQC\Analyzers\AbstractAnalyzer;
use ACQC\Core\Rules\Violation;
use Psr\Log\LoggerInterface;

/**
 * Analyzes HTTP protocol configuration and optimization
 *
 * Checks:
 * - HSTS (HTTP Strict Transport Security) header
 * - Gzip/Brotli compression
 * - Keep-Alive configuration
 * - Connection pooling
 *
 * @package ACQC\Analyzers\Performance
 */
class HttpProtocolAnalyzer extends AbstractAnalyzer
{
    /**
     * Minimum recommended HSTS max-age (1 year in seconds)
     */
    private const MIN_HSTS_MAX_AGE = 31536000;

    /**
     * Minimum file size for compression (bytes)
     */
    private const MIN_COMPRESSION_SIZE = 1000;

    public function __construct(array $configuration = [], ?LoggerInterface $logger = null)
    {
        parent::__construct($configuration, $logger);
    }

    public function getName(): string
    {
        return 'http_protocol';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function isAvailable(): bool
    {
        return function_exists('curl_init'); // Requires cURL
    }

    public function getDefaultConfiguration(): array
    {
        return [
            'enabled' => true,
            'check_hsts' => true,
            'check_compression' => true,
            'check_keepalive' => true,
            'check_connection_pooling' => true,
        ];
    }

    public function analyze(array $files, array $options = []): array
    {
        $violations = [];
        $magentoRoot = $this->findMagentoRoot($files, $options);

        if (!$magentoRoot) {
            $this->logger->warning('Could not determine Magento root directory');
            return $violations;
        }

        // Get base URL
        $baseUrl = $this->getBaseUrl($magentoRoot);

        if (!$baseUrl) {
            $this->logger->info('Could not determine base URL, skipping HTTP checks');
            return $violations;
        }

        $this->logger->info('Starting HTTP protocol analysis', ['base_url' => $baseUrl]);

        // Check 1: HSTS header
        $violations = array_merge(
            $violations,
            $this->checkHstsHeader($baseUrl)
        );

        // Check 2: Compression
        $violations = array_merge(
            $violations,
            $this->checkCompression($baseUrl)
        );

        // Check 3: Keep-Alive
        $violations = array_merge(
            $violations,
            $this->checkKeepAlive($baseUrl)
        );

        // Check 4: Connection pooling (PHP-FPM config)
        $violations = array_merge(
            $violations,
            $this->checkConnectionPooling($magentoRoot)
        );

        $this->logger->info('HTTP protocol analysis complete', [
            'violations' => count($violations)
        ]);

        return $violations;
    }

    /**
     * Check HSTS header configuration
     */
    private function checkHstsHeader(string $url): array
    {
        $violations = [];

        try {
            // Ensure URL is HTTPS
            if (!str_starts_with($url, 'https://')) {
                $this->logger->debug('Skipping HSTS check for non-HTTPS URL');
                return $violations;
            }

            $headers = $this->getHttpHeaders($url);

            if (!isset($headers['strict-transport-security'])) {
                $violation = new Violation(
                    'missing-hsts-header',
                    'HTTP Strict Transport Security (HSTS) header is missing. This weakens security and can impact SEO rankings. Add HSTS header in your web server configuration.',
                    '',
                    0,
                    0,
                    Violation::SEVERITY_WARNING,
                    Violation::TYPE_PERFORMANCE
                );
                $violation->setCategory('performance');
                $violation->setMetadata([
                    'url' => $url,
                    'recommendation' => 'Add Strict-Transport-Security header with max-age >= 31536000',
                ]);
                $violations[] = $violation;
            } else {
                // Check max-age value
                $hstsValue = $headers['strict-transport-security'];
                if (preg_match('/max-age=(\d+)/', $hstsValue, $matches)) {
                    $maxAge = (int)$matches[1];
                    if ($maxAge < self::MIN_HSTS_MAX_AGE) {
                        $violation = new Violation(
                            'hsts-max-age-too-low',
                            "HSTS max-age is {$maxAge} seconds which is less than the recommended 31536000 (1 year). Increase max-age for better security.",
                            '',
                            0,
                            0,
                            Violation::SEVERITY_INFO,
                            Violation::TYPE_PERFORMANCE
                        );
                        $violation->setCategory('performance');
                        $violation->setMetadata([
                            'current_max_age' => $maxAge,
                            'recommended_max_age' => self::MIN_HSTS_MAX_AGE,
                        ]);
                        $violations[] = $violation;
                    }
                }
            }
        } catch (\Exception $e) {
            $this->logger->debug('Could not check HSTS header', [
                'error' => $e->getMessage()
            ]);
        }

        return $violations;
    }

    /**
     * Check compression configuration
     */
    private function checkCompression(string $url): array
    {
        $violations = [];

        try {
            $headers = $this->getHttpHeaders($url, [
                'Accept-Encoding: gzip, deflate, br'
            ]);

            $contentEncoding = $headers['content-encoding'] ?? '';

            if (empty($contentEncoding) || 
                (!str_contains($contentEncoding, 'gzip') && 
                 !str_contains($contentEncoding, 'br') && 
                 !str_contains($contentEncoding, 'deflate'))) {
                
                $violation = new Violation(
                    'compression-not-enabled',
                    'Gzip or Brotli compression is not enabled. This results in larger response sizes and slower page loads. Enable compression in your web server configuration.',
                    '',
                    0,
                    0,
                    Violation::SEVERITY_WARNING,
                    Violation::TYPE_PERFORMANCE
                );
                $violation->setCategory('performance');
                $violation->setMetadata([
                    'url' => $url,
                    'current_encoding' => $contentEncoding ?: 'none',
                    'recommended' => 'gzip or br (brotli)',
                ]);
                $violations[] = $violation;
            }
        } catch (\Exception $e) {
            $this->logger->debug('Could not check compression', [
                'error' => $e->getMessage()
            ]);
        }

        return $violations;
    }

    /**
     * Check Keep-Alive configuration
     */
    private function checkKeepAlive(string $url): array
    {
        $violations = [];

        try {
            $headers = $this->getHttpHeaders($url);

            $connection = $headers['connection'] ?? '';
            $keepAlive = $headers['keep-alive'] ?? '';

            if (stripos($connection, 'close') !== false || empty($keepAlive)) {
                $violation = new Violation(
                    'keepalive-disabled',
                    'HTTP Keep-Alive is not configured optimally. This causes unnecessary connection overhead. Enable Keep-Alive in your web server configuration.',
                    '',
                    0,
                    0,
                    Violation::SEVERITY_INFO,
                    Violation::TYPE_PERFORMANCE
                );
                $violation->setCategory('performance');
                $violation->setMetadata([
                    'connection' => $connection ?: 'not set',
                    'keep_alive' => $keepAlive ?: 'not set',
                ]);
                $violations[] = $violation;
            }
        } catch (\Exception $e) {
            $this->logger->debug('Could not check Keep-Alive', [
                'error' => $e->getMessage()
            ]);
        }

        return $violations;
    }

    /**
     * Check connection pooling configuration
     */
    private function checkConnectionPooling(string $magentoRoot): array
    {
        $violations = [];

        try {
            // Check PHP-FPM configuration (if available)
            $phpVersion = PHP_VERSION;
            $majorMinor = substr($phpVersion, 0, 3); // e.g., "8.1"
            
            $possiblePaths = [
                "/etc/php/{$majorMinor}/fpm/pool.d/www.conf",
                "/etc/php-fpm.d/www.conf",
                "/usr/local/etc/php-fpm.d/www.conf",
            ];

            $fpmConfig = null;
            foreach ($possiblePaths as $path) {
                if (is_file($path) && is_readable($path)) {
                    $fpmConfig = $path;
                    break;
                }
            }

            if ($fpmConfig) {
                $content = file_get_contents($fpmConfig);
                
                // Check pm.max_children
                if (preg_match('/pm\.max_children\s*=\s*(\d+)/', $content, $matches)) {
                    $maxChildren = (int)$matches[1];
                    if ($maxChildren < 20) {
                        $violation = new Violation(
                            'low-php-fpm-max-children',
                            "PHP-FPM pm.max_children is set to {$maxChildren}, which is low for production. Consider increasing to at least 50 for better concurrency.",
                            $fpmConfig,
                            0,
                            0,
                            Violation::SEVERITY_INFO,
                            Violation::TYPE_PERFORMANCE
                        );
                        $violation->setCategory('performance');
                        $violation->setMetadata([
                            'current_value' => $maxChildren,
                            'recommended_min' => 50,
                        ]);
                        $violations[] = $violation;
                    }
                }
            }
        } catch (\Exception $e) {
            $this->logger->debug('Could not check connection pooling', [
                'error' => $e->getMessage()
            ]);
        }

        return $violations;
    }

    /**
     * Get HTTP headers from URL
     */
    private function getHttpHeaders(string $url, array $requestHeaders = []): array
    {
        $headers = [];

        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, true);
            curl_setopt($ch, CURLOPT_NOBODY, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

            if (!empty($requestHeaders)) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, $requestHeaders);
            }

            $response = curl_exec($ch);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error) {
                throw new \RuntimeException("cURL error: {$error}");
            }

            // Parse headers
            $headerLines = explode("\r\n", $response);
            foreach ($headerLines as $line) {
                if (strpos($line, ':') !== false) {
                    [$key, $value] = explode(':', $line, 2);
                    $headers[strtolower(trim($key))] = trim($value);
                }
            }
        } catch (\Exception $e) {
            $this->logger->debug('Failed to get HTTP headers', [
                'url' => $url,
                'error' => $e->getMessage()
            ]);
        }

        return $headers;
    }

    /**
     * Get base URL from Magento configuration
     */
    private function getBaseUrl(string $magentoRoot): ?string
    {
        try {
            $envFile = $magentoRoot . '/app/etc/env.php';
            if (!is_file($envFile)) {
                return null;
            }

            $config = include $envFile;
            
            // Try to get from env.php
            $baseUrl = $config['system']['default']['web']['unsecure']['base_url'] ?? null;
            
            if (!$baseUrl) {
                $baseUrl = $config['system']['default']['web']['secure']['base_url'] ?? null;
            }

            if ($baseUrl) {
                return rtrim($baseUrl, '/');
            }

            // Try to get from bin/magento config:show
            $binMagento = $magentoRoot . '/bin/magento';
            if (is_file($binMagento) && is_executable($binMagento)) {
                $output = $this->runCommand("cd {$magentoRoot} && php bin/magento config:show web/secure/base_url 2>&1");
                $baseUrl = trim($output);
                
                if ($baseUrl && filter_var($baseUrl, FILTER_VALIDATE_URL)) {
                    return rtrim($baseUrl, '/');
                }
            }
        } catch (\Exception $e) {
            $this->logger->debug('Could not determine base URL', [
                'error' => $e->getMessage()
            ]);
        }

        return null;
    }

    /**
     * Execute a shell command
     */
    private function runCommand(string $command): string
    {
        $output = '';
        $returnVar = 0;
        
        exec($command, $outputArray, $returnVar);
        
        if ($returnVar === 0) {
            $output = implode("\n", $outputArray);
        }
        
        return $output;
    }

    /**
     * Find Magento root directory
     */
    private function findMagentoRoot(array $files, array $options): ?string
    {
        // Check if provided in options
        if (!empty($options['magento_root'])) {
            return $options['magento_root'];
        }

        // Try to find from files
        foreach ($files as $file) {
            if (is_dir($file)) {
                if (is_file($file . '/bin/magento')) {
                    return $file;
                }
            } else {
                // Go up directories to find bin/magento
                $dir = dirname($file);
                $maxDepth = 10;
                while ($maxDepth-- > 0) {
                    if (is_file($dir . '/bin/magento')) {
                        return $dir;
                    }
                    $parent = dirname($dir);
                    if ($parent === $dir) {
                        break;
                    }
                    $dir = $parent;
                }
            }
        }

        return null;
    }

    protected function executeAnalysis(array $files, array $options = []): array
    {
        // Not used for this analyzer - all logic in analyze() method
        return [];
    }

    protected function parseOutput(string $output, array $files): array
    {
        // Not used for this analyzer - all logic in analyze() method
        return [];
    }
}

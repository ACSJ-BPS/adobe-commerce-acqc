<?php

declare(strict_types=1);

namespace ACQC\Core\Rules;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Contracts\Cache\ItemInterface;

class RuleEngine
{
    private RuleLoader $ruleLoader;
    private LoggerInterface $logger;
    private FilesystemAdapter $cache;
    private bool $enableCaching = true;
    private bool $enableParallelExecution = true;
    private int $maxWorkers = 4;
    private array $externalTools = [];
    private array $executionMetrics = [];
    private array $ruleResults = [];
    private $progressCallback = null;

    public function __construct(?RuleLoader $ruleLoader = null, ?LoggerInterface $logger = null)
    {
        $this->ruleLoader = $ruleLoader ?? new RuleLoader($logger);
        $this->logger = $logger ?? new NullLogger();
        $this->cache = new FilesystemAdapter('acqc_rules', 0, sys_get_temp_dir());
        
        $this->initializeMetrics();
        $this->registerExternalTools();
    }

    /**
     * Execute rules on a file
     */
    public function executeRules(string $filePath, ?RuleSet $ruleSet = null): array
    {
        $startTime = microtime(true);
        
        if ($ruleSet === null) {
            $ruleSet = $this->ruleLoader->loadRules();
        }

        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException("File not found: {$filePath}");
        }

        $content = file_get_contents($filePath);
        $violations = [];

        // Get applicable rules for this file
        $applicableRules = $ruleSet->getRulesForFile($filePath);
        
        if (empty($applicableRules)) {
            $this->logger->debug("No applicable rules for file: {$filePath}");
            return [];
        }

        // Resolve dependencies and get execution order
        $orderedRules = $this->resolveExecutionOrder($applicableRules);

        $this->logger->info("Executing " . count($orderedRules) . " rules on {$filePath}");

        // Execute rules
        if ($this->enableParallelExecution && count($orderedRules) > 10) {
            $violations = $this->executeRulesParallel($filePath, $content, $orderedRules);
        } else {
            $violations = $this->executeRulesSequential($filePath, $content, $orderedRules);
        }

        // Post-process violations
        $violations = $this->postProcessViolations($violations, $filePath);

        // Update metrics
        $executionTime = microtime(true) - $startTime;
        $this->updateMetrics('execution_time', $executionTime);
        $this->updateMetrics('files_processed', 1);
        $this->updateMetrics('violations_found', count($violations));

        $this->logger->info("Found " . count($violations) . " violations in {$filePath} (took " . round($executionTime * 1000, 2) . "ms)");

        return $violations;
    }

    /**
     * Execute rules on multiple files
     */
    public function executeRulesOnFiles(array $filePaths, ?RuleSet $ruleSet = null): array
    {
        $startTime = microtime(true);
        $allViolations = [];
        
        if ($ruleSet === null) {
            $ruleSet = $this->ruleLoader->loadRules();
        }

        $total = count($filePaths);
        $processed = 0;

        foreach ($filePaths as $filePath) {
            try {
                $violations = $this->executeRules($filePath, $ruleSet);
                $allViolations[$filePath] = $violations;
                $processed++;

                if ($this->progressCallback) {
                    call_user_func($this->progressCallback, $processed, $total, $filePath);
                }
            } catch (\Exception $e) {
                $this->logger->error("Failed to process file {$filePath}: " . $e->getMessage());
                $allViolations[$filePath] = [];
            }
        }

        $totalTime = microtime(true) - $startTime;
        $this->logger->info("Processed {$processed} files in " . round($totalTime, 2) . " seconds");

        return $allViolations;
    }

    /**
     * Execute a specific rule on a file
     */
    public function executeRule(Rule $rule, string $filePath, ?string $content = null): array
    {
        $cacheKey = $this->generateCacheKey($rule, $filePath, $content);
        
        // Check cache first
        if ($this->enableCaching) {
            $cached = $this->getFromCache($cacheKey);
            if ($cached !== null) {
                $this->logger->debug("Retrieved cached results for rule {$rule->getId()} on {$filePath}");
                return $cached;
            }
        }

        $startTime = microtime(true);
        
        if ($content === null) {
            $content = file_get_contents($filePath);
        }

        try {
            if (!$rule->appliesTo($filePath)) {
                return [];
            }

            $violations = $rule->execute($filePath, $content);
            
            // Validate violations
            $validatedViolations = [];
            foreach ($violations as $violation) {
                if ($violation instanceof Violation) {
                    $validatedViolations[] = $violation;
                } else {
                    $this->logger->warning("Rule {$rule->getId()} returned invalid violation");
                }
            }

            $executionTime = microtime(true) - $startTime;
            $this->updateRuleMetrics($rule->getId(), $executionTime, count($validatedViolations));

            // Cache results
            if ($this->enableCaching) {
                $this->saveToCache($cacheKey, $validatedViolations);
            }

            return $validatedViolations;

        } catch (\Exception $e) {
            $this->logger->error("Rule {$rule->getId()} failed on {$filePath}: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Fix violations using auto-fixable rules
     */
    public function fixViolations(string $filePath, array $violations, ?RuleSet $ruleSet = null): string
    {
        if ($ruleSet === null) {
            $ruleSet = $this->ruleLoader->loadRules();
        }

        $content = file_get_contents($filePath);
        $fixableViolations = array_filter($violations, fn(Violation $v) => $v->isFixable());

        if (empty($fixableViolations)) {
            return $content;
        }

        $this->logger->info("Attempting to fix " . count($fixableViolations) . " violations in {$filePath}");

        // Group violations by rule
        $violationsByRule = [];
        foreach ($fixableViolations as $violation) {
            $ruleId = $violation->getRuleId();
            if (!isset($violationsByRule[$ruleId])) {
                $violationsByRule[$ruleId] = [];
            }
            $violationsByRule[$ruleId][] = $violation;
        }

        // Apply fixes
        foreach ($violationsByRule as $ruleId => $ruleViolations) {
            $rule = $ruleSet->getRule($ruleId);
            if ($rule && $rule->isFixable()) {
                try {
                    $content = $rule->fix($content, $ruleViolations);
                    $this->logger->debug("Applied fixes for rule {$ruleId}");
                } catch (\Exception $e) {
                    $this->logger->error("Failed to apply fixes for rule {$ruleId}: " . $e->getMessage());
                }
            }
        }

        return $content;
    }

    /**
     * Execute external tools (PHPCS, PHPMD, PHPStan, PHPCPD)
     */
    public function executeExternalTools(string $filePath, array $tools = []): array
    {
        if (empty($tools)) {
            $tools = array_keys($this->externalTools);
        }

        $violations = [];

        foreach ($tools as $toolName) {
            if (!isset($this->externalTools[$toolName])) {
                $this->logger->warning("Unknown external tool: {$toolName}");
                continue;
            }

            try {
                $toolViolations = $this->executeExternalTool($toolName, $filePath);
                $violations = array_merge($violations, $toolViolations);
                $this->logger->debug("Executed {$toolName} on {$filePath}, found " . count($toolViolations) . " violations");
            } catch (\Exception $e) {
                $this->logger->error("Failed to execute {$toolName} on {$filePath}: " . $e->getMessage());
            }
        }

        return $violations;
    }

    /**
     * Set progress callback
     */
    public function setProgressCallback(callable $callback): self
    {
        $this->progressCallback = $callback;
        return $this;
    }

    /**
     * Enable or disable caching
     */
    public function setCaching(bool $enabled): self
    {
        $this->enableCaching = $enabled;
        return $this;
    }

    /**
     * Enable or disable parallel execution
     */
    public function setParallelExecution(bool $enabled, int $maxWorkers = 4): self
    {
        $this->enableParallelExecution = $enabled && extension_loaded('pcntl');
        $this->maxWorkers = max(1, min(16, $maxWorkers));
        return $this;
    }

    /**
     * Clear all caches
     */
    public function clearCache(): self
    {
        $this->cache->clear();
        $this->ruleLoader->clearCache();
        $this->logger->debug("Cleared rule engine cache");
        return $this;
    }

    /**
     * Get execution metrics
     */
    public function getMetrics(): array
    {
        return $this->executionMetrics;
    }

    /**
     * Get rule-specific metrics
     */
    public function getRuleMetrics(): array
    {
        return $this->ruleResults;
    }

    /**
     * Reset metrics
     */
    public function resetMetrics(): self
    {
        $this->initializeMetrics();
        $this->ruleResults = [];
        return $this;
    }

    /**
     * Execute rules sequentially
     */
    private function executeRulesSequential(string $filePath, string $content, array $rules): array
    {
        $violations = [];

        foreach ($rules as $rule) {
            $ruleViolations = $this->executeRule($rule, $filePath, $content);
            $violations = array_merge($violations, $ruleViolations);
        }

        return $violations;
    }

    /**
     * Execute rules in parallel (if PCNTL is available)
     */
    private function executeRulesParallel(string $filePath, string $content, array $rules): array
    {
        if (!extension_loaded('pcntl')) {
            $this->logger->warning("PCNTL extension not available, falling back to sequential execution");
            return $this->executeRulesSequential($filePath, $content, $rules);
        }

        $violations = [];
        $chunks = array_chunk($rules, max(1, intval(count($rules) / $this->maxWorkers)));
        $workers = [];
        $pipes = [];

        foreach ($chunks as $chunkIndex => $chunk) {
            if (pipe($pipes[$chunkIndex]) === false) {
                continue;
            }

            $pid = pcntl_fork();
            if ($pid === 0) {
                // Child process
                $chunkViolations = [];
                foreach ($chunk as $rule) {
                    $ruleViolations = $this->executeRule($rule, $filePath, $content);
                    $chunkViolations = array_merge($chunkViolations, $ruleViolations);
                }

                // Send results to parent
                $serialized = serialize($chunkViolations);
                fwrite($pipes[$chunkIndex][1], $serialized);
                fclose($pipes[$chunkIndex][1]);
                exit(0);
            } else {
                // Parent process
                $workers[$chunkIndex] = $pid;
                fclose($pipes[$chunkIndex][1]);
            }
        }

        // Collect results
        foreach ($workers as $chunkIndex => $pid) {
            $data = stream_get_contents($pipes[$chunkIndex][0]);
            fclose($pipes[$chunkIndex][0]);

            if ($data) {
                $chunkViolations = unserialize($data);
                if (is_array($chunkViolations)) {
                    $violations = array_merge($violations, $chunkViolations);
                }
            }

            pcntl_waitpid($pid, $status);
        }

        return $violations;
    }

    /**
     * Resolve execution order based on dependencies
     */
    private function resolveExecutionOrder(array $rules): array
    {
        $ruleSet = new RuleSet($rules);
        return $ruleSet->resolveDependencies();
    }

    /**
     * Post-process violations (deduplication, filtering, etc.)
     */
    private function postProcessViolations(array $violations, string $filePath): array
    {
        // Remove duplicates based on fingerprint
        $unique = [];
        $fingerprints = [];

        foreach ($violations as $violation) {
            $fingerprint = $violation->getFingerprint();
            if (!in_array($fingerprint, $fingerprints)) {
                $fingerprints[] = $fingerprint;
                $unique[] = $violation;
            }
        }

        // Sort by severity and line number
        usort($unique, function(Violation $a, Violation $b) {
            $severityDiff = $b->getSeverityWeight() <=> $a->getSeverityWeight();
            if ($severityDiff !== 0) {
                return $severityDiff;
            }
            return $a->getLine() <=> $b->getLine();
        });

        return $unique;
    }

    /**
     * Execute a specific external tool
     */
    private function executeExternalTool(string $toolName, string $filePath): array
    {
        $tool = $this->externalTools[$toolName];
        $command = $tool['command'];
        $parser = $tool['parser'];

        // Replace placeholders in command
        $command = str_replace('{file}', escapeshellarg($filePath), $command);

        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);

        if ($returnCode !== 0 && $returnCode !== 1) { // Many tools return 1 when violations found
            throw new \RuntimeException("External tool {$toolName} failed with code {$returnCode}");
        }

        return call_user_func($parser, $output, $filePath);
    }

    /**
     * Register external tools
     */
    private function registerExternalTools(): void
    {
        // PHPCS (PHP_CodeSniffer)
        $this->externalTools['phpcs'] = [
            'command' => 'phpcs --report=json {file}',
            'parser' => function(array $output, string $filePath): array {
                $json = implode("\n", $output);
                $data = json_decode($json, true);
                $violations = [];

                if (isset($data['files'][$filePath]['messages'])) {
                    foreach ($data['files'][$filePath]['messages'] as $message) {
                        $violation = new Violation(
                            'phpcs.' . ($message['source'] ?? 'unknown'),
                            $message['message'] ?? '',
                            $filePath,
                            $message['line'] ?? 0,
                            $message['column'] ?? 0,
                            $this->mapPhpcseSeverity($message['type'] ?? 'WARNING'),
                            Violation::TYPE_STYLE
                        );
                        $violations[] = $violation;
                    }
                }

                return $violations;
            }
        ];

        // PHPMD (PHP Mess Detector)
        $this->externalTools['phpmd'] = [
            'command' => 'phpmd {file} json cleancode,codesize,controversial,design,naming,unusedcode',
            'parser' => function(array $output, string $filePath): array {
                $json = implode("\n", $output);
                $data = json_decode($json, true);
                $violations = [];

                if (isset($data['files'])) {
                    foreach ($data['files'] as $file) {
                        if (isset($file['violations'])) {
                            foreach ($file['violations'] as $violation) {
                                $v = new Violation(
                                    'phpmd.' . ($violation['rule'] ?? 'unknown'),
                                    $violation['description'] ?? '',
                                    $filePath,
                                    $violation['beginLine'] ?? 0,
                                    0,
                                    $this->mapPhpmdPriority($violation['priority'] ?? 3),
                                    Violation::TYPE_MAINTAINABILITY
                                );
                                $violations[] = $v;
                            }
                        }
                    }
                }

                return $violations;
            }
        ];

        // PHPStan
        $this->externalTools['phpstan'] = [
            'command' => 'phpstan analyse --error-format=json --no-progress {file}',
            'parser' => function(array $output, string $filePath): array {
                $json = implode("\n", $output);
                $data = json_decode($json, true);
                $violations = [];

                if (isset($data['files'][$filePath]['messages'])) {
                    foreach ($data['files'][$filePath]['messages'] as $message) {
                        $violation = new Violation(
                            'phpstan.analysis',
                            $message['message'] ?? '',
                            $filePath,
                            $message['line'] ?? 0,
                            0,
                            Violation::SEVERITY_ERROR,
                            Violation::TYPE_LOGIC
                        );
                        $violations[] = $violation;
                    }
                }

                return $violations;
            }
        ];

        // PHPCPD (PHP Copy/Paste Detector)
        $this->externalTools['phpcpd'] = [
            'command' => 'phpcpd --log-pmd=/dev/stdout {file}',
            'parser' => function(array $output, string $filePath): array {
                $xml = implode("\n", $output);
                $violations = [];

                if (strpos($xml, '<pmd') !== false) {
                    $doc = new \DOMDocument();
                    if (@$doc->loadXML($xml)) {
                        $duplicationNodes = $doc->getElementsByTagName('duplication');
                        foreach ($duplicationNodes as $node) {
                            $violation = new Violation(
                                'phpcpd.duplication',
                                'Code duplication detected (' . $node->getAttribute('lines') . ' lines)',
                                $filePath,
                                (int) $node->getAttribute('beginline'),
                                0,
                                Violation::SEVERITY_WARNING,
                                Violation::TYPE_MAINTAINABILITY
                            );
                            $violations[] = $violation;
                        }
                    }
                }

                return $violations;
            }
        ];
    }

    /**
     * Map PHPCS severity to our severity levels
     */
    private function mapPhpcseSeverity(string $type): string
    {
        return match (strtoupper($type)) {
            'ERROR' => Violation::SEVERITY_ERROR,
            'WARNING' => Violation::SEVERITY_WARNING,
            default => Violation::SEVERITY_INFO,
        };
    }

    /**
     * Map PHPMD priority to our severity levels
     */
    private function mapPhpmdPriority(int $priority): string
    {
        return match ($priority) {
            1 => Violation::SEVERITY_CRITICAL,
            2 => Violation::SEVERITY_ERROR,
            3 => Violation::SEVERITY_WARNING,
            default => Violation::SEVERITY_INFO,
        };
    }

    /**
     * Generate cache key for rule execution
     */
    private function generateCacheKey(Rule $rule, string $filePath, ?string $content): string
    {
        $contentHash = $content ? md5($content) : md5_file($filePath);
        return 'rule_' . $rule->getId() . '_' . md5($filePath) . '_' . $contentHash;
    }

    /**
     * Get cached results
     */
    private function getFromCache(string $cacheKey): ?array
    {
        return $this->cache->get($cacheKey, function(ItemInterface $item) {
            $item->expiresAfter(3600); // 1 hour
            return null;
        });
    }

    /**
     * Save results to cache
     */
    private function saveToCache(string $cacheKey, array $violations): void
    {
        $this->cache->get($cacheKey, function(ItemInterface $item) use ($violations) {
            $item->expiresAfter(3600); // 1 hour
            return $violations;
        });
    }

    /**
     * Initialize metrics
     */
    private function initializeMetrics(): void
    {
        $this->executionMetrics = [
            'start_time' => microtime(true),
            'files_processed' => 0,
            'violations_found' => 0,
            'execution_time' => 0.0,
            'cache_hits' => 0,
            'cache_misses' => 0,
        ];
    }

    /**
     * Update execution metrics
     */
    private function updateMetrics(string $key, $value): void
    {
        if (isset($this->executionMetrics[$key])) {
            $this->executionMetrics[$key] += $value;
        } else {
            $this->executionMetrics[$key] = $value;
        }
    }

    /**
     * Update rule-specific metrics
     */
    private function updateRuleMetrics(string $ruleId, float $executionTime, int $violationCount): void
    {
        if (!isset($this->ruleResults[$ruleId])) {
            $this->ruleResults[$ruleId] = [
                'executions' => 0,
                'total_time' => 0.0,
                'average_time' => 0.0,
                'violations_found' => 0,
            ];
        }

        $this->ruleResults[$ruleId]['executions']++;
        $this->ruleResults[$ruleId]['total_time'] += $executionTime;
        $this->ruleResults[$ruleId]['average_time'] = $this->ruleResults[$ruleId]['total_time'] / $this->ruleResults[$ruleId]['executions'];
        $this->ruleResults[$ruleId]['violations_found'] += $violationCount;
    }
} 
<?php

declare(strict_types=1);

namespace ACQC\Core\Scanner;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class ParallelScanner
{
    private FileScanner $fileScanner;
    private LoggerInterface $logger;
    private array $workers = [];
    private array $results = [];
    private $progressCallback = null;

    public function __construct(FileScanner $fileScanner, ?LoggerInterface $logger = null)
    {
        $this->fileScanner = $fileScanner;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Scan multiple files in parallel
     */
    public function scanFiles(array $filePaths, ScanContext $context): array
    {
        if (!$this->isParallelProcessingAvailable()) {
            $this->logger->warning("Parallel processing not available, falling back to sequential scanning");
            return $this->scanFilesSequentially($filePaths, $context);
        }

        if (!$context->isParallelProcessingEnabled()) {
            $this->logger->info("Parallel processing disabled in context, using sequential scanning");
            return $this->scanFilesSequentially($filePaths, $context);
        }

        $maxWorkers = $context->getMaxWorkers();
        $this->logger->info("Starting parallel scan with {$maxWorkers} workers for " . count($filePaths) . " files");

        return $this->scanWithWorkers($filePaths, $context, $maxWorkers);
    }

    /**
     * Check if parallel processing is available
     */
    private function isParallelProcessingAvailable(): bool
    {
        return extension_loaded('pcntl') && extension_loaded('posix') && function_exists('pcntl_fork');
    }

    /**
     * Scan files sequentially (fallback)
     */
    private function scanFilesSequentially(array $filePaths, ScanContext $context): array
    {
        $results = [];
        $total = count($filePaths);
        
        foreach ($filePaths as $index => $filePath) {
            $result = $this->fileScanner->scanFile($filePath, $context);
            $results[] = $result;
            
            if ($this->progressCallback) {
                call_user_func($this->progressCallback, $index + 1, $total, $filePath);
            }
        }

        return $results;
    }

    /**
     * Scan files using multiple worker processes
     */
    private function scanWithWorkers(array $filePaths, ScanContext $context, int $maxWorkers): array
    {
        $total = count($filePaths);
        $chunks = array_chunk($filePaths, max(1, intval($total / $maxWorkers)));
        $pipes = [];
        $this->workers = [];
        $this->results = [];

        // Create worker processes
        foreach ($chunks as $chunkIndex => $chunk) {
            if (empty($chunk)) {
                continue;
            }

            $pipes[$chunkIndex] = [];
            
            // Create pipe for communication
            if (pipe($pipes[$chunkIndex]) === false) {
                $this->logger->error("Failed to create pipe for worker {$chunkIndex}");
                continue;
            }

            $pid = pcntl_fork();
            
            if ($pid === -1) {
                $this->logger->error("Failed to fork worker process {$chunkIndex}");
                continue;
            } elseif ($pid === 0) {
                // Child process - worker
                $this->runWorker($chunk, $context, $pipes[$chunkIndex][1], $chunkIndex);
                exit(0);
            } else {
                // Parent process
                $this->workers[$chunkIndex] = $pid;
                fclose($pipes[$chunkIndex][1]); // Close write end in parent
                
                $this->logger->debug("Started worker {$chunkIndex} (PID: {$pid}) for " . count($chunk) . " files");
            }
        }

        // Collect results from workers
        $completedWorkers = 0;
        $totalWorkers = count($this->workers);
        
        while ($completedWorkers < $totalWorkers) {
            foreach ($pipes as $chunkIndex => $pipe) {
                if (!isset($pipe[0])) {
                    continue;
                }

                // Check if data is available to read
                $read = [$pipe[0]];
                $write = null;
                $except = null;
                
                if (stream_select($read, $write, $except, 0, 100000) > 0) { // 100ms timeout
                    $data = fread($pipe[0], 8192);
                    
                    if ($data !== false && !empty($data)) {
                        $this->processWorkerData($data, $chunkIndex);
                    }
                    
                    if (feof($pipe[0])) {
                        fclose($pipe[0]);
                        unset($pipes[$chunkIndex][0]);
                        $completedWorkers++;
                        
                        $this->logger->debug("Worker {$chunkIndex} completed");
                    }
                }
            }

            // Check for completed workers
            foreach ($this->workers as $chunkIndex => $pid) {
                $status = null;
                $result = pcntl_waitpid($pid, $status, WNOHANG);
                
                if ($result === $pid) {
                    unset($this->workers[$chunkIndex]);
                    
                    if (pcntl_wexitstatus($status) !== 0) {
                        $this->logger->error("Worker {$chunkIndex} exited with error status: " . pcntl_wexitstatus($status));
                    }
                }
            }

            usleep(10000); // 10ms sleep to prevent busy waiting
        }

        // Wait for any remaining workers
        foreach ($this->workers as $chunkIndex => $pid) {
            $status = null;
            pcntl_waitpid($pid, $status);
            
            if (pcntl_wexitstatus($status) !== 0) {
                $this->logger->error("Worker {$chunkIndex} final exit status: " . pcntl_wexitstatus($status));
            }
        }

        // Sort results by original file order
        usort($this->results, function($a, $b) {
            return strcmp($a->getFilePath(), $b->getFilePath());
        });

        $this->logger->info("Parallel scan completed. Processed " . count($this->results) . " files");
        
        return $this->results;
    }

    /**
     * Worker process that scans a chunk of files
     */
    private function runWorker(array $filePaths, ScanContext $context, $pipe, int $workerId): void
    {
        try {
            $results = [];
            
            foreach ($filePaths as $filePath) {
                try {
                    $result = $this->fileScanner->scanFile($filePath, $context);
                    $results[] = $result;
                    
                    // Send progress update
                    $this->sendWorkerData($pipe, [
                        'type' => 'progress',
                        'worker_id' => $workerId,
                        'file_path' => $filePath,
                        'completed' => true,
                    ]);
                    
                } catch (\Exception $e) {
                    $this->logger->error("Worker {$workerId} failed to scan {$filePath}: " . $e->getMessage());
                    
                    // Create error result
                    $result = new ScanResult($filePath);
                    $result->addError("Worker scan failed: " . $e->getMessage());
                    $results[] = $result;
                }
            }

            // Send final results
            $this->sendWorkerData($pipe, [
                'type' => 'results',
                'worker_id' => $workerId,
                'results' => array_map(function($result) {
                    return $result->toArray();
                }, $results),
            ]);

        } catch (\Exception $e) {
            $this->logger->error("Worker {$workerId} crashed: " . $e->getMessage());
            
            $this->sendWorkerData($pipe, [
                'type' => 'error',
                'worker_id' => $workerId,
                'error' => $e->getMessage(),
            ]);
        } finally {
            fclose($pipe);
        }
    }

    /**
     * Send data from worker to parent
     */
    private function sendWorkerData($pipe, array $data): void
    {
        $serialized = serialize($data) . "\n";
        fwrite($pipe, $serialized);
        fflush($pipe);
    }

    /**
     * Process data received from worker
     */
    private function processWorkerData(string $data, int $workerId): void
    {
        $lines = explode("\n", trim($data));
        
        foreach ($lines as $line) {
            if (empty($line)) {
                continue;
            }

            try {
                $workerData = unserialize($line);
                
                if (!is_array($workerData)) {
                    continue;
                }

                switch ($workerData['type'] ?? '') {
                    case 'progress':
                        $this->handleWorkerProgress($workerData);
                        break;
                        
                    case 'results':
                        $this->handleWorkerResults($workerData);
                        break;
                        
                    case 'error':
                        $this->handleWorkerError($workerData);
                        break;
                }
                
            } catch (\Exception $e) {
                $this->logger->error("Failed to process worker data from worker {$workerId}: " . $e->getMessage());
            }
        }
    }

    /**
     * Handle progress update from worker
     */
    private function handleWorkerProgress(array $data): void
    {
        if ($this->progressCallback && isset($data['file_path'])) {
            call_user_func($this->progressCallback, 1, 1, $data['file_path'], $data['worker_id'] ?? 0);
        }
    }

    /**
     * Handle results from worker
     */
    private function handleWorkerResults(array $data): void
    {
        if (!isset($data['results']) || !is_array($data['results'])) {
            return;
        }

        foreach ($data['results'] as $resultData) {
            try {
                $result = ScanResult::fromArray($resultData);
                $this->results[] = $result;
            } catch (\Exception $e) {
                $this->logger->error("Failed to deserialize scan result: " . $e->getMessage());
            }
        }
    }

    /**
     * Handle error from worker
     */
    private function handleWorkerError(array $data): void
    {
        $workerId = $data['worker_id'] ?? 'unknown';
        $error = $data['error'] ?? 'Unknown error';
        
        $this->logger->error("Worker {$workerId} reported error: {$error}");
    }

    /**
     * Set progress callback function
     */
    public function setProgressCallback(callable $callback): void
    {
        $this->progressCallback = $callback;
    }

    /**
     * Get memory usage statistics
     */
    public function getMemoryStats(): array
    {
        return [
            'current_usage' => memory_get_usage(true),
            'peak_usage' => memory_get_peak_usage(true),
            'current_usage_formatted' => $this->formatBytes(memory_get_usage(true)),
            'peak_usage_formatted' => $this->formatBytes(memory_get_peak_usage(true)),
        ];
    }

    /**
     * Format bytes to human readable format
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $unitIndex = 0;
        
        while ($bytes >= 1024 && $unitIndex < count($units) - 1) {
            $bytes /= 1024;
            $unitIndex++;
        }
        
        return round($bytes, 2) . ' ' . $units[$unitIndex];
    }

    /**
     * Kill all worker processes (emergency cleanup)
     */
    public function killWorkers(): void
    {
        foreach ($this->workers as $chunkIndex => $pid) {
            if (posix_kill($pid, SIGTERM)) {
                $this->logger->info("Terminated worker {$chunkIndex} (PID: {$pid})");
            } else {
                $this->logger->warning("Failed to terminate worker {$chunkIndex} (PID: {$pid})");
                
                // Force kill if gentle termination fails
                posix_kill($pid, SIGKILL);
            }
        }
        
        $this->workers = [];
    }

    /**
     * Get active worker count
     */
    public function getActiveWorkerCount(): int
    {
        return count($this->workers);
    }

    /**
     * Check if any workers are still running
     */
    public function hasActiveWorkers(): bool
    {
        return !empty($this->workers);
    }

    /**
     * Get worker statistics
     */
    public function getWorkerStats(): array
    {
        $stats = [
            'parallel_available' => $this->isParallelProcessingAvailable(),
            'active_workers' => count($this->workers),
            'total_results' => count($this->results),
        ];

        // Add per-worker stats if workers are active
        foreach ($this->workers as $chunkIndex => $pid) {
            $stats['workers'][$chunkIndex] = [
                'pid' => $pid,
                'status' => 'running',
            ];
        }

        return $stats;
    }

    /**
     * Cleanup resources
     */
    public function cleanup(): void
    {
        if ($this->hasActiveWorkers()) {
            $this->killWorkers();
        }
        
        $this->results = [];
    }

    /**
     * Destructor - ensure cleanup
     */
    public function __destruct()
    {
        $this->cleanup();
    }
} 